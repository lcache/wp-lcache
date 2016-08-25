<?php

namespace LCache\LCache;

class LCacheDatabaseL2 extends LCacheL2
{
    protected $hits;
    protected $misses;
    protected $dbh;
    protected $degraded;
    protected $log_locally;
    protected $errors;
    protected $table_prefix;

    public function __construct($dbh, $table_prefix = '', $log_locally = false)
    {
        $this->hits = 0;
        $this->misses = 0;
        $this->dbh = $dbh;
        $this->degraded = false;
        $this->log_locally = $log_locally;
        $this->errors = array();
        $this->table_prefix = $table_prefix;
    }

    protected function prefixTable($base_name)
    {
        return $this->table_prefix . $base_name;
    }

    protected function logSchemaIssueOrRethrow($description, $pdo_exception)
    {
        $log_only = array(/* General error */ 'HY000',
                      /* Unknown column */ '42S22',
                      /* Base table for view not found */ '42S02');

        if (in_array($pdo_exception->getCode(), $log_only, true)) {
            $this->degrated = true;
            $text = 'LCache Database: ' . $description . ' : ' . $pdo_exception->getMessage();
            if ($this->log_locally) {
                $this->errors[] = $text;
            } else {
                // @codeCoverageIgnoreStart
                trigger_error($text, E_USER_WARNING);
                // @codeCoverageIgnoreEnd
            }
            return;
        }

        // Rethrow anything not whitelisted.
        // @codeCoverageIgnoreStart
        throw $pdo_exception;
        // @codeCoverageIgnoreEnd
    }

    public function getErrors()
    {
        if (!$this->log_locally) {
            // @codeCoverageIgnoreStart
            throw new Exception('Requires setting $log_locally=TRUE on instantiation.');
            // @codeCoverageIgnoreEnd
        }
        return $this->errors;
    }

    // Returns an LCacheEntry
    public function getEntry(LCacheAddress $address)
    {
        try {
            $sth = $this->dbh->prepare('SELECT "event_id", "pool", "address", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') .' WHERE "address" = :address AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
            $sth->bindValue(':address', $address->serialize(), \PDO::PARAM_STR);
            $sth->bindValue(':now', REQUEST_TIME, \PDO::PARAM_INT);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to search database for cache item', $e);
            return null;
        }
        //$last_matching_entry = $sth->fetchObject('LCacheEntry');
        $last_matching_entry = $sth->fetchObject();

        if ($last_matching_entry === false) {
            $this->misses++;
            return null;
        }

        // If last event was a deletion, miss.
        if (is_null($last_matching_entry->value)) {
            $this->misses++;
            return null;
        }

        $last_matching_entry->value = unserialize($last_matching_entry->value);

        $this->hits++;
        return $last_matching_entry;
    }

    public function exists(LCacheAddress $address)
    {
        try {
            $sth = $this->dbh->prepare('SELECT "event_id", ("value" IS NOT NULL) AS value_not_null, "value" FROM ' . $this->prefixTable('lcache_events') .' WHERE "address" = :address AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
            $sth->bindValue(':address', $address->serialize(), \PDO::PARAM_STR);
            $sth->bindValue(':now', REQUEST_TIME, \PDO::PARAM_INT);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to search database for cache item existence', $e);
            return null;
        }
        $result = $sth->fetchObject();
        return ($result !== false && $result->value_not_null);
    }

    /**
   * @codeCoverageIgnore
   */
    public function debugDumpState()
    {
        echo PHP_EOL . PHP_EOL . 'Events:' . PHP_EOL;
        $sth = $this->dbh->prepare('SELECT * FROM lcache_events ORDER BY "event_id"');
        $sth->execute();
        while ($event = $sth->fetchObject()) {
            print_r($event);
        }
        echo PHP_EOL;
        echo 'Tags:' . PHP_EOL;
        $sth = $this->dbh->prepare('SELECT * FROM lcache_tags ORDER BY "tag"');
        $sth->execute();
        $tags_found = false;
        while ($event = $sth->fetchObject()) {
            print_r($event);
            $tags_found = true;
        }
        if (!$tags_found) {
            echo 'No tag data.' . PHP_EOL;
        }
        echo PHP_EOL;
    }

    public function set($pool, LCacheAddress $address, $value = null, $ttl = null, array $tags = [])
    {
        $expiration = $ttl ? (REQUEST_TIME + $ttl) : null;
        try {
            $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_events') . ' ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, :now, :expiration)');
            $sth->bindValue(':pool', $pool, \PDO::PARAM_STR);
            $sth->bindValue(':address', $address->serialize(), \PDO::PARAM_STR);
            $sth->bindValue(':value', is_null($value) ? null : serialize($value), \PDO::PARAM_LOB);
            $sth->bindValue(':expiration', $expiration, \PDO::PARAM_INT);
            $sth->bindValue(':now', REQUEST_TIME, \PDO::PARAM_INT);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to store cache event', $e);
            return null;
        }
        $event_id = $this->dbh->lastInsertId();

        // Delete obsolete events.
        $pattern = $address->serialize();
        // On a full or bin clear, prune old events.
        if ($address->isEntireBin() || $address->isEntireCache()) {
            $pattern = $address->serialize() . '%';
        }
        $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_events') . ' WHERE "address" LIKE :address AND "event_id" < :new_event_id');
        $sth->bindValue(':address', $pattern, \PDO::PARAM_STR);
        $sth->bindValue(':new_event_id', $event_id, \PDO::PARAM_INT);
        $sth->execute();

        // Store any new cache tags.
        // @TODO: Turn into one query.
        foreach ($tags as $tag) {
            try {
                $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_tags') . ' ("tag", "event_id") VALUES (:tag, :new_event_id)');
                $sth->bindValue(':tag', $tag, \PDO::PARAM_STR);
                $sth->bindValue(':new_event_id', $event_id, \PDO::PARAM_INT);
                $sth->execute();
            } catch (\PDOException $e) {
                $this->logSchemaIssueOrRethrow('Failed to associate cache tags', $e);
                return null;
            }
        }

        return $event_id;
    }

    public function delete($pool, LCacheAddress $address)
    {
        $event_id = $this->set($pool, $address);
        return $event_id;
    }

    public function getAddressesForTag($tag)
    {
        try {
            // @TODO: Convert this to using a subquery to only match with the latest event_id.
            $sth = $this->dbh->prepare('SELECT DISTINCT "address" FROM ' . $this->prefixTable('lcache_events') . ' e INNER JOIN ' . $this->prefixTable('lcache_tags') . ' t ON t.event_id = e.event_id WHERE "tag" = :tag');
            $sth->bindValue(':tag', $tag, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to find cache items associated with tag', $e);
            return null;
        }
        $addresses = [];
        while ($tag_entry = $sth->fetchObject()) {
            $address = new LCacheAddress();
            $address->unserialize($tag_entry->address);
            $addresses[] = $address;
        }
        return $addresses;
    }

    public function deleteTag(LCacheL1 $l1, $tag)
    {
        // Find the matching keys and create tombstones for them.
        try {
            $sth = $this->dbh->prepare('SELECT DISTINCT "address" FROM ' . $this->prefixTable('lcache_events') . ' e INNER JOIN ' . $this->prefixTable('lcache_tags') . ' t ON t.event_id = e.event_id WHERE "tag" = :tag');
            $sth->bindValue(':tag', $tag, \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to find cache items associated with tag', $e);
            return null;
        }

        $last_applied_event_id = null;
        while ($tag_entry = $sth->fetchObject()) {
            $address = new LCacheAddress();
            $address->unserialize($tag_entry->address);
            $last_applied_event_id = $this->delete($l1->getPool(), $address);
            $l1->delete($last_applied_event_id, $address);
        }

        // Delete the tag, which has now been invalidated.
        // @TODO: Move to a transaction, collect the list of deleted keys,
        // or delete individual tag/key pairs in the loop above.
        //$sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_tags') . ' WHERE "tag" = :tag');
        //$sth->bindValue(':tag', $tag, PDO::PARAM_STR);
        //$sth->execute();

        return $last_applied_event_id;
    }

    public function applyEvents(LCacheL1 $l1)
    {
        $last_applied_event_id = $l1->getLastAppliedEventID();

        // If the L1 cache is empty, bump the last applied ID
        // to the current high-water mark.
        if (is_null($last_applied_event_id)) {
            try {
                $sth = $this->dbh->prepare('SELECT "event_id" FROM ' . $this->prefixTable('lcache_events') . ' ORDER BY "event_id" DESC LIMIT 1');
                $sth->execute();
            } catch (\PDOException $e) {
                $this->logSchemaIssueOrRethrow('Failed to initialize local event application status', $e);
                return null;
            }
            $last_event = $sth->fetchObject();
            $l1->setLastAppliedEventID($last_event->event_id);
            return null;
        }

        $applied = 0;
        try {
            $sth = $this->dbh->prepare('SELECT "event_id", "pool", "address", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') . ' WHERE "event_id" > :last_applied_event_id AND "pool" <> :exclude_pool ORDER BY event_id');
            $sth->bindValue(':last_applied_event_id', $last_applied_event_id, \PDO::PARAM_INT);
            $sth->bindValue(':exclude_pool', $l1->getPool(), \PDO::PARAM_STR);
            $sth->execute();
        } catch (\PDOException $e) {
            $this->logSchemaIssueOrRethrow('Failed to fetch events', $e);
            return null;
        }

        //while ($event = $sth->fetchObject('LCacheEntry')) {
        while ($event = $sth->fetchObject()) {
            $address = new LCacheAddress();
            $address->unserialize($event->address);
            if (is_null($event->value)) {
                $l1->delete($event->event_id, $address);
            } else {
                $event->value = unserialize($event->value);
                $address = new LCacheAddress();
                $address->unserialize($event->address);
                $l1->setWithExpiration($event->event_id, $address, $event->value, $event->created, $event->expiration);
            }
            $last_applied_event_id = $event->event_id;
            $applied++;
        }

        // Just in case there were skipped events, set the high water mark.
        $l1->setLastAppliedEventID($last_applied_event_id);

        return $applied;
    }

    public function getHits()
    {
        return $this->hits;
    }

    public function getMisses()
    {
        return $this->misses;
    }
}
