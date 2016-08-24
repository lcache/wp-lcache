<?php

final class LCacheAddress implements Serializable {
  protected $bin;
  protected $key;
  public function __construct($bin=NULL, $key=NULL) {
    assert(!is_null($bin) || is_null($key));
    assert(strpos($bin, ':') === FALSE);
    $this->bin = $bin;
    $this->key = $key;
  }

  public function getBin() {
    return $this->bin;
  }

  public function getKey() {
    return $this->key;
  }

  public function isEntireBin() {
    return is_null($this->key);
  }

  public function isEntireCache() {
    return is_null($this->bin);
  }

  public function isMatch(LCacheAddress $address) {
    if (!is_null($address->getBin()) && !is_null($this->bin) && $address->getBin() !== $this->bin) {
      return FALSE;
    }
    if (!is_null($address->getKey()) && !is_null($this->key) && $address->getKey() !== $this->key) {
      return FALSE;
    }
    return TRUE;
  }

  // The serialized form must:
  //  - Place the bin first
  //  - Return a prefix matching all entries in a bin with a NULL key
  //  - Return a prefix matching all entries with a NULL bin
  public function serialize() {
    if (is_null($this->bin)) {
      return '';
    }
    else if (is_null($this->key)) {
      return $this->bin . ':';
    }
    return $this->bin . ':' . $this->key;
  }

  public function unserialize($serialized) {
    $entries = explode(':', $serialized, 2);
    $this->bin = NULL;
    $this->key = NULL;
    if (count($entries) === 2) {
      list($this->bin, $this->key) = $entries;
    }
    if ($this->key === '') {
      $this->key = NULL;
    }
  }
}

final class LCacheEntry {
  public $event_id;
  public $pool;
  protected $address;
  public $value;
  public $created;
  public $expiration;
  public $tags;

  public function __construct($event_id, $pool, LCacheAddress $address, $value, $created, $expiration=NULL, array $tags=[]) {
    $this->event_id = $event_id;
    $this->pool = $pool;
    $this->address = $address;
    $this->value = $value;
    $this->created = $created;
    $this->expiration = $expiration;
    $this->tags = $tags;
  }

  public function getAddress() {
    return $this->address;
  }
}

abstract class LCacheX {
  abstract public function getEntry(LCacheAddress $address);
  abstract public function getHits();
  abstract public function getMisses();

  public function get(LCacheAddress $address) {
    $entry = $this->getEntry($address);
    if (is_null($entry)) {
      return NULL;
    }
    return $entry->value;
  }

  public function exists(LCacheAddress $address) {
    $value = $this->get($address);
    return !is_null($value);
  }
}

abstract class LCacheL1 extends LCacheX {
  protected $pool;

  public function __construct() {
    if (!isset($this->pool)) {
      $this->pool = $this->generateUniqueID();
    }
  }

  protected function generateUniqueID() {
    return uniqid('', TRUE) . ':' . mt_rand();
  }

  abstract public function getLastAppliedEventID();
  abstract public function setLastAppliedEventID($event_id);

  public function getPool() {
    return $this->pool;
  }

  public function set($event_id, LCacheAddress $address, $value=NULL, $ttl=NULL) {
    return $this->setWithExpiration($event_id, $address, $value, REQUEST_TIME, is_null($ttl) ? NULL : $ttl);
  }

  abstract public function setWithExpiration($event_id, LCacheAddress $address, $value, $created, $expiration=NULL);
  abstract public function delete($event_id, LCacheAddress $address);
}

abstract class LCacheL2 extends LCacheX {
  abstract public function applyEvents(LCacheL1 $l1);
  abstract public function set($pool, LCacheAddress $address, $value=NULL, $ttl=NULL, array $tags=[]);
  abstract public function delete($pool, LCacheAddress $address);
  abstract public function deleteTag(LCacheL1 $l1, $tag);
  abstract public function getAddressesForTag($tag);
}

class LCacheAPCuL1 extends LCacheL1 {
  public function __construct($pool=NULL) {
    if (!is_null($pool)) {
      $this->pool = $pool;
    }
    else if (isset($_SERVER['SERVER_ADDR']) && isset($_SERVER['SERVER_PORT'])) {
      $this->pool = $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'];
    }
    else {
      $this->pool = $this->generateUniqueID();
    }
  }

  protected function getLocalKey($address) {
    return 'lcache:' . $this->pool . ':' . $address->serialize();
  }

  public function setWithExpiration($event_id, LCacheAddress $address, $value, $created, $expiration=NULL) {
    $apcu_key = $this->getLocalKey($address);
    // Don't overwrite local entries that are even newer.
    $entry = apcu_fetch($apcu_key);
    if ($entry !== FALSE && $entry->event_id > $event_id) {
      return TRUE;
    }
    $entry = new LCacheEntry($event_id, $this->pool, $address, $value, REQUEST_TIME, $expiration);
    return apcu_store($apcu_key, $entry, is_null($expiration) ? 0 : $expiration);
  }

  public function getEntry(LCacheAddress $address) {
    $apcu_key = $this->getLocalKey($address);
    $entry = apcu_fetch($apcu_key, $success);
    if (!$success) {
      $this->recordMiss();
      return NULL;
    }
    $this->recordHit();
    return $entry;
  }

  public function exists(LCacheAddress $address) {
    $apcu_key = $this->getLocalKey($address);
    return apcu_exists($apcu_key);
  }

  // @TODO: Remove APCIterator support once we only support PHP 7+
  protected function getIterator($prefix) {
    $pattern = '/^' . $prefix . '.*/';
    if (class_exists('APCIterator')) {
      // @codeCoverageIgnoreStart
      return new APCIterator('user', $pattern);
      // @codeCoverageIgnoreEnd
    }
    // @codeCoverageIgnoreStart
    return new APCUIterator($pattern);
    // @codeCoverageIgnoreEnd
  }

  public function delete($event_id, LCacheAddress $address) {
    if ($address->isEntireCache()) {
      // @TODO: Consider flushing only LCache L1 storage by using an iterator.
      return apcu_clear_cache();
    }
    else if ($address->isEntireBin()) {
      $prefix = $this->getLocalKey($address);
      $matching = $this->getIterator($prefix);
      if (!$matching) {
        // @codeCoverageIgnoreStart
        return FALSE;
        // @codeCoverageIgnoreEnd
      }
      foreach ($matching as $match) {
        if (!apcu_delete($match['key'])) {
          // @codeCoverageIgnoreStart
          return FALSE;
          // @codeCoverageIgnoreEnd
        }
      }
      $this->setLastAppliedEventID($event_id);
      return TRUE;
    }
    $apcu_key = $this->getLocalKey($address);
    $this->setLastAppliedEventID($event_id);
    // @TODO: Consider adding race protection here, like for set.
    // @TODO: Consider using an expiring tombstone to prevent the race
    //        condition of an older set replacing a newer deletion.
    return apcu_delete($apcu_key);
  }

  protected function recordHit() {
    apcu_inc('lcache_status:' . $this->pool . ':hits', 1, $success);
    if (!$success) {
      apcu_store('lcache_status:' . $this->pool . ':hits', 1);
    }
  }

  protected function recordMiss() {
    apcu_inc('lcache_status:' . $this->pool . ':misses', 1, $success);
    if (!$success) {
      apcu_store('lcache_status:' . $this->pool . ':misses', 1);
    }
  }

  public function getHits() {
    $value = apcu_fetch('lcache_status:' . $this->pool . ':hits');
    return $value ? $value : 0;
  }

  public function getMisses() {
    $value = apcu_fetch('lcache_status:' . $this->pool . ':misses');
    return $value ? $value : 0;
  }

  public function getLastAppliedEventID() {
    $value = apcu_fetch('lcache_status:' . $this->pool . ':last_applied_event_id');
    if ($value === FALSE) {
      $value = 0;
    }
    return $value;
  }

  public function setLastAppliedEventID($eid) {
    return apcu_store('lcache_status:' . $this->pool . ':last_applied_event_id', $eid);
  }

}

class LCacheStaticL1 extends LCacheL1 {
  protected $hits;
  protected $misses;
  protected $storage;
  protected $last_applied_event_id;

  public function __construct($pool=NULL) {
    if (!is_null($pool)) {
      $this->pool = $pool;
    }
    $this->hits = 0;
    $this->misses = 0;
    $this->storage = array();
    $this->last_applied_event_id = NULL;
    parent::__construct();
  }

  public function setWithExpiration($event_id, LCacheAddress $address, $value, $created, $expiration=NULL) {
    $local_key = $address->serialize();

    // Don't overwrite local entries that are even newer.
    if (isset($this->storage[$local_key]) && $this->storage[$local_key]->event_id > $event_id) {
      return TRUE;
    }
    $this->storage[$local_key] = new LCacheEntry($event_id, $this->getPool(), $address, $value, $created, $expiration);
    return TRUE;
  }

  public function getEntry(LCacheAddress $address) {
    $local_key = $address->serialize();

    if (!array_key_exists($local_key, $this->storage)) {
      $this->misses++;
      return NULL;
    }
    $entry = $this->storage[$local_key];
    if (!is_null($entry->expiration) && $entry->expiration < REQUEST_TIME) {
      unset($this->storage[$local_key]);
      $this->misses++;
      return NULL;
    }
    $this->hits++;
    return $entry;
  }

  public function delete($event_id, LCacheAddress $address) {
    $local_key = $address->serialize();
    if ($address->isEntireCache()) {
      $this->storage = array();
      return TRUE;
    }
    else if ($address->isEntireBin()) {
      foreach ($this->storage as $index => $value) {
        if (strpos($index, $local_key) === 0) {
          unset($this->storage[$index]);
        }
      }
      return TRUE;
    }
    $this->setLastAppliedEventID($event_id);
    // @TODO: Consider adding "race" protection here, like for set.
    unset($this->storage[$local_key]);
    return TRUE;
  }

  public function getHits() {
    return $this->hits;
  }

  public function getMisses() {
    return $this->misses;
  }

  public function getLastAppliedEventID() {
    return $this->last_applied_event_id;
  }

  public function setLastAppliedEventID($eid) {
    $this->last_applied_event_id = $eid;
    return TRUE;
  }
}

class LCacheStaticL2 extends LCacheL2 {
  protected $events;
  protected $current_event_id;
  protected $hits;
  protected $misses;
  protected $tags;

  public function __construct() {
    $this->events = array();
    $this->current_event_id = 1;
    $this->hits = 0;
    $this->misses = 0;
    $this->tags = [];
  }

  // Returns an LCacheEntry
  public function getEntry(LCacheAddress $address) {
    $last_matching_entry = NULL;
    foreach ($this->events as $event_id => $entry) {
      if ($entry->getAddress()->isMatch($address)) {
        if ($entry->getAddress()->isEntireCache() || $entry->getAddress()->isEntireBin()) {
          $last_matching_entry = NULL;
        }
        else if (!is_null($entry->expiration) && $entry->expiration < REQUEST_TIME) {
          $last_matching_entry = NULL;
        }
        else {
          $last_matching_entry = $entry;
        }
      }
    }
    // Last event was a deletion, so miss.
    if (is_null($last_matching_entry) || is_null($last_matching_entry->value)) {
      return NULL;
    }
    $this->hits++;
    return $last_matching_entry;
  }

  public function set($pool, LCacheAddress $address, $value=NULL, $ttl=NULL, array $tags=[]) {
    $expiration = $ttl ? (REQUEST_TIME + $ttl) : NULL;
    $this->current_event_id++;
    $this->events[$this->current_event_id] = new LCacheEntry($this->current_event_id, $pool, $address, $value, REQUEST_TIME, $expiration);

    // Clear existing tags linked to the item. This is much more
    // efficient with database-style indexes.
    foreach ($this->tags as $tag => $addresses) {
      $addresses_to_keep = [];
      foreach ($addresses as $current_address) {
        if ($address !== $current_address) {
          $addresses_to_keep[] = $current_address;
        }
      }
      $this->tags[$tag] = $addresses_to_keep;
    }

    // Set the tags on the new item.
    foreach ($tags as $tag) {
      if (isset($this->tags[$tag])) {
        $this->tags[$tag][] = $address;
      }
      else {
        $this->tags[$tag] = [$address];
      }
    }

    return $this->current_event_id;
  }

  public function delete($pool, LCacheAddress $address) {
    if ($address->isEntireCache()) {
      $this->events = array();
    }
    return $this->set($pool, $address);
  }

  public function getAddressesForTag($tag) {
    return isset($this->tags[$tag]) ? $this->tags[$tag] : [];
  }

  public function deleteTag(LCacheL1 $l1, $tag) {
    // Materialize the tag deletion as individual key deletions.
    foreach ($this->getAddressesForTag($tag) as $address) {
      $event_id = $this->delete($l1->getPool(), $address);
      $l1->delete($event_id, $address);
    }
    unset($this->tags[$tag]);
    return $this->current_event_id;
  }

  public function applyEvents(LCacheL1 $l1) {
    $last_applied_event_id = $l1->getLastAppliedEventID();

    // If the L1 cache is empty, bump the last applied ID
    // to the current high-water mark.
    if (is_null($last_applied_event_id)) {
      $l1->setLastAppliedEventID($this->current_event_id);
      return NULL;
    }

    $applied = 0;
    foreach ($this->events as $event_id => $event) {
      // Skip events that are too old or were created by the local L1.
      if ($event_id > $last_applied_event_id && $event->pool !== $l1->getPool()) {
        if (is_null($event->value)) {
          $l1->delete($event->event_id, $event->getAddress());
        }
        else {
          $l1->setWithExpiration($event->event_id, $event->getAddress(), $event->value, $event->created, $event->expiration);
        }
        $applied++;
      }
    }

    // Just in case there were skipped events, set the high water mark.
    $l1->setLastAppliedEventID($this->current_event_id);
    return $applied;
  }

  public function getHits() {
    return $this->hits;
  }

  public function getMisses() {
    return $this->misses;
  }
}

class LCacheDatabaseL2 extends LCacheL2 {
  protected $hits;
  protected $misses;
  protected $dbh;
  protected $degraded;
  protected $log_locally;
  protected $errors;
  protected $table_prefix;

  public function __construct($dbh, $table_prefix='', $log_locally=FALSE) {
    $this->hits = 0;
    $this->misses = 0;
    $this->dbh = $dbh;
    $this->degraded = FALSE;
    $this->log_locally = $log_locally;
    $this->errors = array();
    $this->table_prefix = $table_prefix;
  }

  protected function prefixTable($base_name) {
    return $this->table_prefix . $base_name;
  }

  protected function logSchemaIssueOrRethrow($description, $pdo_exception) {
    $log_only = array(/* General error */ 'HY000',
                      /* Unknown column */ '42S22',
                      /* Base table for view not found */ '42S02');

    if (in_array($pdo_exception->getCode(), $log_only, TRUE)) {
      $this->degrated = TRUE;
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

  public function getErrors() {
    if (!$this->log_locally) {
      // @codeCoverageIgnoreStart
      throw new Exception('Requires setting $log_locally=TRUE on instantiation.');
      // @codeCoverageIgnoreEnd
    }
    return $this->errors;
  }

  // Returns an LCacheEntry
  public function getEntry(LCacheAddress $address) {
    try {
      $sth = $this->dbh->prepare('SELECT "event_id", "pool", "address", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') .' WHERE "address" = :address AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
      $sth->bindValue(':address', $address->serialize(), PDO::PARAM_STR);
      $sth->bindValue(':now', REQUEST_TIME, PDO::PARAM_INT);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to search database for cache item', $e);
      return NULL;
    }
    //$last_matching_entry = $sth->fetchObject('LCacheEntry');
    $last_matching_entry = $sth->fetchObject();

    if ($last_matching_entry === FALSE) {
      $this->misses++;
      return NULL;
    }

    // If last event was a deletion, miss.
    if (is_null($last_matching_entry->value)) {
      $this->misses++;
      return NULL;
    }

    $last_matching_entry->value = unserialize($last_matching_entry->value);

    $this->hits++;
    return $last_matching_entry;
  }

  public function exists(LCacheAddress $address) {
    try {
      $sth = $this->dbh->prepare('SELECT "event_id", ("value" IS NOT NULL) AS value_not_null, "value" FROM ' . $this->prefixTable('lcache_events') .' WHERE "address" = :address AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
      $sth->bindValue(':address', $address->serialize(), PDO::PARAM_STR);
      $sth->bindValue(':now', REQUEST_TIME, PDO::PARAM_INT);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to search database for cache item existence', $e);
      return NULL;
    }
    $result = $sth->fetchObject();
    return ($result !== FALSE && $result->value_not_null);
  }

  /**
   * @codeCoverageIgnore
   */
  public function debugDumpState() {
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
    $tags_found = FALSE;
    while ($event = $sth->fetchObject()) {
      print_r($event);
      $tags_found = TRUE;
    }
    if (!$tags_found) {
      echo 'No tag data.' . PHP_EOL;
    }
    echo PHP_EOL;
  }

  public function set($pool, LCacheAddress $address, $value=NULL, $ttl=NULL, array $tags=[]) {
    $expiration = $ttl ? (REQUEST_TIME + $ttl) : NULL;
    try {
      $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_events') . ' ("pool", "address", "value", "created", "expiration") VALUES (:pool, :address, :value, :now, :expiration)');
      $sth->bindValue(':pool', $pool, PDO::PARAM_STR);
      $sth->bindValue(':address', $address->serialize(), PDO::PARAM_STR);
      $sth->bindValue(':value', is_null($value) ? NULL : serialize($value), PDO::PARAM_LOB);
      $sth->bindValue(':expiration', $expiration, PDO::PARAM_INT);
      $sth->bindValue(':now', REQUEST_TIME, PDO::PARAM_INT);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to store cache event', $e);
      return NULL;
    }
    $event_id = $this->dbh->lastInsertId();

    // Delete obsolete events.
    $pattern = $address->serialize();
    // On a full or bin clear, prune old events.
    if ($address->isEntireBin() || $address->isEntireCache()) {
        $pattern = $address->serialize() . '%';
    }
    $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_events') . ' WHERE "address" LIKE :address AND "event_id" < :new_event_id');
    $sth->bindValue(':address', $pattern, PDO::PARAM_STR);
    $sth->bindValue(':new_event_id', $event_id, PDO::PARAM_INT);
    $sth->execute();

    // Store any new cache tags.
    // @TODO: Turn into one query.
    foreach ($tags as $tag) {
      try {
        $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_tags') . ' ("tag", "event_id") VALUES (:tag, :new_event_id)');
        $sth->bindValue(':tag', $tag, PDO::PARAM_STR);
        $sth->bindValue(':new_event_id', $event_id, PDO::PARAM_INT);
        $sth->execute();
      } catch (PDOException $e) {
        $this->logSchemaIssueOrRethrow('Failed to associate cache tags', $e);
        return NULL;
      }
    }

    return $event_id;
  }

  public function delete($pool, LCacheAddress $address) {
    $event_id = $this->set($pool, $address);
    return $event_id;
  }

  public function getAddressesForTag($tag) {
    try {
      // @TODO: Convert this to using a subquery to only match with the latest event_id.
      $sth = $this->dbh->prepare('SELECT DISTINCT "address" FROM ' . $this->prefixTable('lcache_events') . ' e INNER JOIN ' . $this->prefixTable('lcache_tags') . ' t ON t.event_id = e.event_id WHERE "tag" = :tag');
      $sth->bindValue(':tag', $tag, PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to find cache items associated with tag', $e);
      return NULL;
    }
    $addresses = [];
    while ($tag_entry = $sth->fetchObject()) {
      $address = new LCacheAddress();
      $address->unserialize($tag_entry->address);
      $addresses[] = $address;
    }
    return $addresses;
  }

  public function deleteTag(LCacheL1 $l1, $tag) {
    // Find the matching keys and create tombstones for them.
    try {
      $sth = $this->dbh->prepare('SELECT DISTINCT "address" FROM ' . $this->prefixTable('lcache_events') . ' e INNER JOIN ' . $this->prefixTable('lcache_tags') . ' t ON t.event_id = e.event_id WHERE "tag" = :tag');
      $sth->bindValue(':tag', $tag, PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to find cache items associated with tag', $e);
      return NULL;
    }

    $last_applied_event_id = NULL;
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

  public function applyEvents(LCacheL1 $l1) {
    $last_applied_event_id = $l1->getLastAppliedEventID();

    // If the L1 cache is empty, bump the last applied ID
    // to the current high-water mark.
    if (is_null($last_applied_event_id)) {
      try {
        $sth = $this->dbh->prepare('SELECT "event_id" FROM ' . $this->prefixTable('lcache_events') . ' ORDER BY "event_id" DESC LIMIT 1');
        $sth->execute();
      } catch (PDOException $e) {
        $this->logSchemaIssueOrRethrow('Failed to initialize local event application status', $e);
        return NULL;
      }
      $last_event = $sth->fetchObject();
      $l1->setLastAppliedEventID($last_event->event_id);
      return NULL;
    }

    $applied = 0;
    try {
      $sth = $this->dbh->prepare('SELECT "event_id", "pool", "address", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') . ' WHERE "event_id" > :last_applied_event_id AND "pool" <> :exclude_pool ORDER BY event_id');
      $sth->bindValue(':last_applied_event_id', $last_applied_event_id, PDO::PARAM_INT);
      $sth->bindValue(':exclude_pool', $l1->getPool(), PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to fetch events', $e);
      return NULL;
    }

    //while ($event = $sth->fetchObject('LCacheEntry')) {
    while ($event = $sth->fetchObject()) {
      $address = new LCacheAddress();
      $address->unserialize($event->address);
      if (is_null($event->value)) {
        $l1->delete($event->event_id, $address);
      }
      else {
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

  public function getHits() {
    return $this->hits;
  }

  public function getMisses() {
    return $this->misses;
  }
}

class LCacheNullL1 extends LCacheStaticL1 {
  public function set($event_id, LCacheAddress $address, $value=NULL, $ttl='') {
    // Store nothing; always succeed.
    return TRUE;
  }

  public function getLastAppliedEventID() {
    // Because we store nothing locally, behave as if all events
    // are applied.
    return PHP_INT_MAX;
  }
}

final class LCacheIntegrated {
  protected $l1;
  protected $l2;

  public function __construct($l1, $l2) {
    $this->l1 = $l1;
    $this->l2 = $l2;
  }

  public function set(LCacheAddress $address, $value, $ttl=NULL, array $tags=[]) {
    $event_id = $this->l2->set($this->l1->getPool(), $address, $value, $ttl, $tags);
    if (!is_null($event_id)) {
      $this->l1->set($event_id, $address, $value, $ttl);
    }
    return $event_id;
  }

  public function getEntry(LCacheAddress $address) {
    $entry = $this->l1->getEntry($address);
    if (!is_null($entry)) {
      return $entry;
    }
    $entry = $this->l2->getEntry($address);
    if (is_null($entry)) {
      return NULL;
    }
    $this->l1->setWithExpiration($entry->event_id, $address, $entry->value, $entry->created, $entry->expiration);
    return $entry;
  }

  public function get(LCacheAddress $address) {
    $entry = $this->getEntry($address);
    if (is_null($entry)) {
      return NULL;
    }
    return $entry->value;
  }

  public function exists(LCacheAddress $address) {
  $exists = $this->l1->exists($address);
    if ($exists) {
      return TRUE;
    }
    return $this->l2->exists($address);
  }

  public function delete(LCacheAddress $address) {
    $event_id = $this->l2->delete($this->l1->getPool(), $address);
    if (!is_null($event_id)) {
      $this->l1->delete($event_id, $address);
    }
    return $event_id;
  }

  public function deleteTag($tag) {
    $event_id = $this->l2->deleteTag($this->l1, $tag);
    return $event_id;
  }

  public function synchronize() {
    return $this->l2->applyEvents($this->l1);
  }

  public function getHitsL1() {
    return $this->l1->getHits();
  }

  public function getHitsL2() {
    return $this->l2->getHits();
  }

  public function getMisses() {
    return $this->l2->getMisses();
  }

  public function getLastAppliedEventID() {
    return $this->l1->getLastAppliedEventID();
  }

  public function getPool() {
    return $this->l1->getPool();
  }
}

// Operate properly when testing in case Drupal isn't running this code.
if (!defined('REQUEST_TIME')) {
  define('REQUEST_TIME', time());
}
