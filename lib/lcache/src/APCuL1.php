<?php

namespace LCache;

class APCuL1 extends L1
{
    public function __construct($pool = null)
    {
        if (!is_null($pool)) {
            $this->pool = $pool;
        } elseif (isset($_SERVER['SERVER_ADDR']) && isset($_SERVER['SERVER_PORT'])) {
            $this->pool = $_SERVER['SERVER_ADDR'] . ':' . $_SERVER['SERVER_PORT'];
        } else {
            $this->pool = $this->generateUniqueID();
        }
    }

    protected function getLocalKey($address)
    {
        return 'lcache:' . $this->pool . ':' . $address->serialize();
    }

    public function setWithExpiration($event_id, Address $address, $value, $created, $expiration = null)
    {
        $apcu_key = $this->getLocalKey($address);
        // Don't overwrite local entries that are even newer.
        $entry = apcu_fetch($apcu_key);
        if ($entry !== false && $entry->event_id > $event_id) {
            return true;
        }
        $entry = new Entry($event_id, $this->pool, $address, $value, REQUEST_TIME, $expiration);
        return apcu_store($apcu_key, $entry, is_null($expiration) ? 0 : $expiration);
    }

    public function getEntry(Address $address)
    {
        $apcu_key = $this->getLocalKey($address);
        $entry = apcu_fetch($apcu_key, $success);
        if (!$success) {
            $this->recordMiss();
            return null;
        }
        $this->recordHit();
        return $entry;
    }

    public function exists(Address $address)
    {
        $apcu_key = $this->getLocalKey($address);
        return apcu_exists($apcu_key);
    }

    // @TODO: Remove APCIterator support once we only support PHP 7+
    protected function getIterator($prefix)
    {
        $pattern = '/^' . $prefix . '.*/';
        if (class_exists('APCIterator')) {
            // @codeCoverageIgnoreStart
            return new \APCIterator('user', $pattern);
            // @codeCoverageIgnoreEnd
        }
        // @codeCoverageIgnoreStart
        return new \APCUIterator($pattern);
        // @codeCoverageIgnoreEnd
    }

    public function delete($event_id, Address $address)
    {
        if ($address->isEntireCache()) {
            // @TODO: Consider flushing only LCache L1 storage by using an iterator.
            return apcu_clear_cache();
        } elseif ($address->isEntireBin()) {
            $prefix = $this->getLocalKey($address);
            $matching = $this->getIterator($prefix);
            if (!$matching) {
                // @codeCoverageIgnoreStart
                return false;
                // @codeCoverageIgnoreEnd
            }
            foreach ($matching as $match) {
                if (!apcu_delete($match['key'])) {
                    // @codeCoverageIgnoreStart
                    return false;
                    // @codeCoverageIgnoreEnd
                }
            }
            $this->setLastAppliedEventID($event_id);
            return true;
        }
        $apcu_key = $this->getLocalKey($address);
        $this->setLastAppliedEventID($event_id);
        // @TODO: Consider adding race protection here, like for set.
        // @TODO: Consider using an expiring tombstone to prevent the race
        //        condition of an older set replacing a newer deletion.
        return apcu_delete($apcu_key);
    }

    protected function recordHit()
    {
        apcu_inc('lcache_status:' . $this->pool . ':hits', 1, $success);
        if (!$success) {
            // @TODO: Remove this fallback when we drop APCu 4.x support.
            // @codeCoverageIgnoreStart
            // Ignore coverage because (1) it's tested with other code and
            // (2) APCu 5.x does not use it.
            apcu_store('lcache_status:' . $this->pool . ':hits', 1);
            // @codeCoverageIgnoreEnd
        }
    }

    protected function recordMiss()
    {
        apcu_inc('lcache_status:' . $this->pool . ':misses', 1, $success);
        if (!$success) {
            // @TODO: Remove this fallback when we drop APCu 4.x support.
            // @codeCoverageIgnoreStart
            // Ignore coverage because (1) it's tested with other code and
            // (2) APCu 5.x does not use it.
            apcu_store('lcache_status:' . $this->pool . ':misses', 1);
            // @codeCoverageIgnoreEnd
        }
    }

    public function getHits()
    {
        $value = apcu_fetch('lcache_status:' . $this->pool . ':hits');
        return $value ? $value : 0;
    }

    public function getMisses()
    {
        $value = apcu_fetch('lcache_status:' . $this->pool . ':misses');
        return $value ? $value : 0;
    }

    public function getLastAppliedEventID()
    {
        $value = apcu_fetch('lcache_status:' . $this->pool . ':last_applied_event_id');
        if ($value === false) {
            $value = 0;
        }
        return $value;
    }

    public function setLastAppliedEventID($eid)
    {
        return apcu_store('lcache_status:' . $this->pool . ':last_applied_event_id', $eid);
    }
}
