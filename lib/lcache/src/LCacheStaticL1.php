<?php

namespace LCache\LCache;

class LCacheStaticL1 extends LCacheL1
{
    protected $hits;
    protected $misses;
    protected $storage;
    protected $last_applied_event_id;

    public function __construct($pool = null)
    {
        if (!is_null($pool)) {
            $this->pool = $pool;
        }
        $this->hits = 0;
        $this->misses = 0;
        $this->storage = array();
        $this->last_applied_event_id = null;
        parent::__construct();
    }

    public function setWithExpiration($event_id, LCacheAddress $address, $value, $created, $expiration = null)
    {
        $local_key = $address->serialize();

        // Don't overwrite local entries that are even newer.
        if (isset($this->storage[$local_key]) && $this->storage[$local_key]->event_id > $event_id) {
            return true;
        }
        $this->storage[$local_key] = new LCacheEntry($event_id, $this->getPool(), $address, $value, $created, $expiration);
        return true;
    }

    public function getEntry(LCacheAddress $address)
    {
        $local_key = $address->serialize();

        if (!array_key_exists($local_key, $this->storage)) {
            $this->misses++;
            return null;
        }
        $entry = $this->storage[$local_key];
        if (!is_null($entry->expiration) && $entry->expiration < REQUEST_TIME) {
            unset($this->storage[$local_key]);
            $this->misses++;
            return null;
        }
        $this->hits++;
        return $entry;
    }

    public function delete($event_id, LCacheAddress $address)
    {
        $local_key = $address->serialize();
        if ($address->isEntireCache()) {
            $this->storage = array();
            return true;
        } elseif ($address->isEntireBin()) {
            foreach ($this->storage as $index => $value) {
                if (strpos($index, $local_key) === 0) {
                    unset($this->storage[$index]);
                }
            }
            return true;
        }
        $this->setLastAppliedEventID($event_id);
        // @TODO: Consider adding "race" protection here, like for set.
        unset($this->storage[$local_key]);
        return true;
    }

    public function getHits()
    {
        return $this->hits;
    }

    public function getMisses()
    {
        return $this->misses;
    }

    public function getLastAppliedEventID()
    {
        return $this->last_applied_event_id;
    }

    public function setLastAppliedEventID($eid)
    {
        $this->last_applied_event_id = $eid;
        return true;
    }
}
