<?php

final class LCacheEntry {
  public $event_id;
  public $pool;
  public $key;
  public $value;
  public $created;
  public $expiration;
  public $tags;

  public function __construct($event_id, $pool, $key, $value, $created, $expiration=NULL, array $tags=[]) {
    $this->event_id = $event_id;
    $this->pool = $pool;
    $this->key = $key;
    $this->value = $value;
    $this->created = $created;
    $this->expiration = $expiration;
    $this->tags = $tags;
  }
}

abstract class LCacheX {
  abstract public function getEntry($key);
  abstract public function getHits();
  abstract public function getMisses();

  public function get($key) {
    $entry = $this->getEntry($key);
    if (is_null($entry)) {
      return NULL;
    }
    return $entry->value;
  }

  public function exists($key) {
    $value = $this->get($key);
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

  abstract public function setWithExpiration($event_id, $key, $value, $created, $expiration=NULL);
  abstract public function set($event_id, $key, $value, $ttl=NULL);
  abstract public function delete($event_id, $key=NULL);
}

abstract class LCacheL2 extends LCacheX {
  abstract public function applyEvents(LCacheL1 $l1);
  abstract public function set($pool, $key, $value, $ttl=NULL, array $tags=[]);
  abstract public function delete($pool, $key=NULL);
  abstract public function deleteTag(LCacheL1 $l1, $tag);
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

  public function setWithExpiration($event_id, $key, $value, $created, $expiration=NULL) {
    $apcu_key = $this->getLocalKey($key);
    // Don't overwrite local entries that are even newer.
    $entry = apcu_fetch($apcu_key);
    if ($entry !== FALSE && $entry->event_id > $event_id) {
      return TRUE;
    }
    $entry = new LCacheEntry($event_id, $this->pool, $key, $value, REQUEST_TIME, $expiration);
    return apcu_store($apcu_key, $entry, is_null($expiration) ? 0 : $expiration);
  }

  public function set($event_id, $key, $value, $ttl=NULL) {
    return $this->setWithExpiration($event_id, $key, $value, REQUEST_TIME, is_null($ttl) ? NULL : $ttl);
  }

  public function getEntry($key) {
    $apcu_key = $this->getLocalKey($key);
    $entry = apcu_fetch($apcu_key, $success);
    if (!$success) {
      $this->recordMiss();
      return NULL;
    }
    $this->recordHit();
    return $entry;
  }

  public function exists($key) {
    $apcu_key = $this->getLocalKey($key);
    return apcu_exists($apcu_key);
  }

  public function delete($event_id=NULL, $key=NULL) {
    if (is_null($key)) {
      return apcu_clear_cache();
    }
    $apcu_key = $this->getLocalKey($key);
    // @TODO: Consider adding race protection here, like for set.
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

  protected function getLocalKey($key) {
    return 'lcache:' . $this->pool . ':' . $key;
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

  public function setWithExpiration($event_id, $key, $value, $created, $expiration=NULL) {
    // Don't overwrite local entries that are even newer.
    if (array_key_exists($key, $this->storage) && $this->storage[$key]->event_id > $event_id) {
      return TRUE;
    }
    $this->storage[$key] = new LCacheEntry($event_id, $this->getPool(), $key, $value, $created, $expiration);
    return TRUE;
  }

  public function set($event_id, $key, $value, $ttl=NULL) {
    $expiration = is_null($ttl) ? NULL : (REQUEST_TIME + $ttl);
    return $this->setWithExpiration($event_id, $key, $value, REQUEST_TIME, $expiration);
  }

  public function getEntry($key) {
    if (!array_key_exists($key, $this->storage)) {
      $this->misses++;
      return NULL;
    }
    $entry = $this->storage[$key];
    if (!is_null($entry->expiration) && $entry->expiration < REQUEST_TIME) {
      unset($this->storage[$key]);
      $this->misses++;
      return NULL;
    }
    $this->hits++;
    return $entry;
  }

  public function delete($event_id, $key=NULL) {
    if ($key === NULL) {
      $this->storage = array();
      return TRUE;
    }
    // @TODO: Consider adding race protection here, like for set.
    unset($this->storage[$key]);
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
  public function getEntry($key) {
    $last_matching_entry = NULL;
    foreach ($this->events as $event_id => $entry) {
      if (is_null($entry->key)) {
        // Cache was cleared.
        $last_matching_entry = NULL;
      }
      else if ($entry->key === $key && (is_null($entry->expiration) || $entry->expiration >= REQUEST_TIME)) {
        $last_matching_entry = $entry;
      }
    }

    // Last event was a deletion, so miss.
    if (is_null($last_matching_entry) || is_null($last_matching_entry->value)) {
      return NULL;
    }
    $this->hits++;
    return $last_matching_entry;
  }

  public function set($pool, $key, $value, $ttl=NULL, array $tags=[]) {
    $expiration = $ttl ? (REQUEST_TIME + $ttl) : NULL;
    $this->current_event_id++;
    $this->events[$this->current_event_id] = new LCacheEntry($this->current_event_id, $pool, $key, $value, REQUEST_TIME, $expiration);

    // Clear existing tags linked to the item. This is much more
    // efficient with database-style indexes.
    foreach ($this->tags as $tag) {
      $this->tags[$tag] = array_diff($this->tags[$tag], [$key]);
    }

    // Set the tags on the new item.
    foreach ($tags as $tag) {
      if (isset($this->tags[$tag])) {
        $this->tags[$tag][] = $key;
      }
      else {
        $this->tags[$tag] = [$key];
      }
    }

    return $this->current_event_id;
  }

  public function delete($pool, $key=NULL) {
    // @TODO: Remove stale tag associations for the deleted key.

    $this->current_event_id++;
    if (is_null($key)) {
      $this->events = array();
      $this->events[$this->current_event_id] = new LCacheEntry($this->current_event_id, $pool, NULL, NULL, REQUEST_TIME);
      return parent::delete($key, $tags);
    }
    $this->events[$this->current_event_id] = new LCacheEntry($this->current_event_id, $pool, $key, NULL, REQUEST_TIME);
    return $this->current_event_id;
  }

  public function deleteTag(LCacheL1 $l1, $tag) {
    // If the tag has no items in it, it's the trivial case.
    if (!isset($this->tags[$tag])) {
      return TRUE;
    }

    // Materialize the tag deletion as individual key deletions.
    foreach ($this->tags[$tag] as $key) {
      $event_id = $this->delete($l1->getPool(), $key);
      $l1->delete($event_id, $key);
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
        if (is_null($event->key)) {
          $l1->delete();
        }
        else if (is_null($event->value)) {
          $l1->delete($event->key);
        }
        else {
          $l1->setWithExpiration($event->event_id, $event->key, $event->value, $event->created, $event->expiration);
        }
        $applied++;
      }
    }
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
    $log_only = array('HY000');

    if (in_array($pdo_exception->getCode(), $log_only, TRUE)) {
      $this->degrated = TRUE;
      $text = 'LCache Database: ' . $description . ' : ' . $pdo_exception->getMessage();
      if ($this->log_locally) {
        $this->errors[] = $text;
      } else {
        trigger_error($text, E_USER_WARNING);
      }
      return;
    }

    // Rethrow anything not whitelisted.
    throw $pdo_exception;
  }

  public function getErrors() {
    if (!$this->log_locally) {
      throw new Exception('Requires setting $log_locally=TRUE on instantiation.');
    }
    return $this->errors;
  }

  // Returns an LCacheEntry
  public function getEntry($key) {
    try {
      $sth = $this->dbh->prepare('SELECT "event_id", "pool", "key", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') .' WHERE "key" = :key AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
      $sth->bindValue(':key', $key, PDO::PARAM_STR);
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

  public function exists($key) {
    try {
      $sth = $this->dbh->prepare('SELECT "event_id", ("value" IS NOT NULL) AS value_not_null, "value" FROM ' . $this->prefixTable('lcache_events') .' WHERE "key" = :key AND ("expiration" >= :now OR "expiration" IS NULL) ORDER BY "event_id" DESC LIMIT 1');
      $sth->bindValue(':key', $key, PDO::PARAM_STR);
      $sth->bindValue(':now', REQUEST_TIME, PDO::PARAM_INT);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to search database for cache item existence', $e);
      return NULL;
    }
    $result = $sth->fetchObject();
    return ($result !== FALSE && $result->value_not_null);
  }

  public function debugDumpEvents() {
    $sth = $this->dbh->prepare('SELECT * FROM lcache_events ORDER BY "event_id"');
    $sth->execute();
    while ($event = $sth->fetchObject()) {
      print_r($event);
    }
  }

  public function set($pool, $key, $value, $ttl=NULL, array $tags=[]) {
    $expiration = $ttl ? (REQUEST_TIME + $ttl) : NULL;
    try {
      $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_events') . ' ("pool", "key", "value", "created", "expiration") VALUES (:pool, :key, :value, :now, :expiration)');
      $sth->bindValue(':pool', $pool, PDO::PARAM_STR);
      $sth->bindValue(':key', $key, PDO::PARAM_STR);
      $sth->bindValue(':value', is_null($value) ? NULL : serialize($value), PDO::PARAM_LOB);
      $sth->bindValue(':expiration', $expiration, PDO::PARAM_INT);
      $sth->bindValue(':now', REQUEST_TIME, PDO::PARAM_INT);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to store cache event', $e);
      return NULL;
    }
    $event_id = $this->dbh->lastInsertId();

    // Delete any existing cache tags on the entry.
    try {
      $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_tags') . ' WHERE "key" = :key');
      $sth->bindValue(':key', $key, PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to delete old cache tags', $e);
      // This, at worst, causes over-invalidation later.
    }

    // Store any new cache tags.
    // @TODO: Turn into one query.
    foreach ($tags as $tag) {
      try {
        $sth = $this->dbh->prepare('INSERT INTO ' . $this->prefixTable('lcache_tags') . ' ("tag", "key") VALUES (:tag, :key)');
        $sth->bindValue(':tag', $pool, PDO::PARAM_STR);
        $sth->bindValue(':key', $key, PDO::PARAM_STR);
        $sth->execute();
      } catch (PDOException $e) {
        $this->logSchemaIssueOrRethrow('Failed to associate cache tags', $e);
        return NULL;
      }
    }

    return $event_id;
  }

  public function delete($pool, $key=NULL) {
    $event_id = $this->set($pool, $key, NULL);

    // On a full clear, prune old events.
    if (is_null($key)) {
      try {
        $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_events') . ' WHERE event_id < :new_event_id');
        $sth->bindValue(':new_event_id', $event_id, PDO::PARAM_INT);
        $sth->execute();
      } catch (PDOException $e) {
        $this->logSchemaIssueOrRethrow('Failed to delete obsolete events', $e);
        return NULL;
      }
    }
    return $event_id;
  }

  public function deleteTag(LCacheL1 $l1, $tag) {
    // Find the matching keys and create tombstones for them.
    try {
      $sth = $this->dbh->prepare('SELECT "key" FROM ' . $this->prefixTable('lcache_tags') . ' WHERE "tag" = :tag');
      $sth->bindValue(':tag', $tag, PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to delete cache items associated with tag', $e);
      return NULL;
    }
    while ($tag_entry = $sth->fetchObject()) {
      $event_id = $this->delete($l1->getPool(), $tag_entry->key);
      $l1->delete($event_id, $key);
    }

    // Delete the tag, which has now been invalidated.
    // @TODO: Move to a transaction, collect the list of deleted keys,
    // or delete individual tag/key pairs in the loop above.
    try {
      $sth = $this->dbh->prepare('DELETE FROM ' . $this->prefixTable('lcache_tags') . ' WHERE "tag" = :tag');
      $sth->bindValue(':tag', $tag, PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to delete obsolete tag associations', $e);
      return NULL;
    }

    return $this->current_event_id;
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
      $sth = $this->dbh->prepare('SELECT "event_id", "pool", "key", "value", "created", "expiration" FROM ' . $this->prefixTable('lcache_events') . ' WHERE "event_id" > :last_applied_event_id AND "pool" <> :exclude_pool ORDER BY event_id');
      $sth->bindValue(':last_applied_event_id', $last_applied_event_id, PDO::PARAM_INT);
      $sth->bindValue(':exclude_pool', $l1->getPool(), PDO::PARAM_STR);
      $sth->execute();
    } catch (PDOException $e) {
      $this->logSchemaIssueOrRethrow('Failed to fetch events', $e);
      return NULL;
    }

    //while ($event = $sth->fetchObject('LCacheEntry')) {
    while ($event = $sth->fetchObject()) {
      $l1->setLastAppliedEventID($event->event_id);

      if (is_null($event->key)) {
        $l1->delete();
        $applied++;
        continue;
      }

      $event->value = unserialize($event->value);
      if (is_null($event->value)) {
        $l1->delete($event->key);
      }
      else {
        $l1->setWithExpiration($event->event_id, $event->key, $event->value, $event->created, $event->expiration);
      }
      $applied++;
    }
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
  public function set($event_id, $key, $value, $ttl = '') {
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

  public function set($key, $value, $ttl=NULL, array $tags=[]) {
    $event_id = $this->l2->set($this->l1->getPool(), $key, $value, $ttl, $tags);
    if (!is_null($event_id)) {
      $this->l1->set($event_id, $key, $value, $ttl);
    }
    return $event_id;
  }

  public function getEntry($key) {
    $entry = $this->l1->getEntry($key);
    if (!is_null($entry)) {
      return $entry;
    }
    $entry = $this->l2->getEntry($key);
    if (is_null($entry)) {
      return NULL;
    }
    $this->l1->setWithExpiration($entry->event_id, $key, $entry->value, $entry->created, $entry->expiration);
    return $entry;
  }

  public function get($key) {
    $entry = $this->getEntry($key);
    if (is_null($entry)) {
      return NULL;
    }
    return $entry->value;
  }

  public function exists($key) {
  $exists = $this->l1->exists($key);
    if ($exists) {
      return TRUE;
    }
    return $this->l2->exists($key);
  }

  public function delete($key=NULL) {
    $event_id = $this->l2->delete($this->l1->getPool(), $key);
    if (!is_null($event_id)) {
      $this->l1->delete($event_id, $key);
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
