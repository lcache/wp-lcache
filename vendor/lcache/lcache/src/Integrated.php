<?php

namespace LCache;

final class Integrated
{
    protected $l1;
    protected $l2;

    public function __construct($l1, $l2)
    {
        $this->l1 = $l1;
        $this->l2 = $l2;
    }

    public function set(Address $address, $value, $ttl = null, array $tags = [])
    {
        $event_id = $this->l2->set($this->l1->getPool(), $address, $value, $ttl, $tags);
        if (!is_null($event_id)) {
            $this->l1->set($event_id, $address, $value, $ttl);
        }
        return $event_id;
    }

    protected function getEntryOrTombstone(Address $address)
    {
        $entry = $this->l1->getEntry($address);
        if (!is_null($entry)) {
            return $entry;
        }
        $entry = $this->l2->getEntry($address);
        if (is_null($entry)) {
            // On an L2 miss, construct a negative cache entry that will be
            // overwritten on any update.
            $entry = new Entry(0, $this->l1->getPool(), $address, null, REQUEST_TIME, null);
        }
        $this->l1->setWithExpiration($entry->event_id, $address, $entry->value, $entry->created, $entry->expiration);
        return $entry;
    }


    public function getEntry(Address $address, $return_tombstone = false)
    {
        $entry = $this->getEntryOrTombstone($address);
        if (!is_null($entry) && (!is_null($entry->value) || $return_tombstone)) {
            return $entry;
        }
        return null;
    }

    public function get(Address $address)
    {
        $entry = $this->getEntry($address);
        if (is_null($entry)) {
            return null;
        }
        return $entry->value;
    }

    public function exists(Address $address)
    {
        $exists = $this->l1->exists($address);
        if ($exists) {
            return true;
        }
        return $this->l2->exists($address);
    }

    public function delete(Address $address)
    {
        $event_id = $this->l2->delete($this->l1->getPool(), $address);
        if (!is_null($event_id)) {
            $this->l1->delete($event_id, $address);
        }
        return $event_id;
    }

    public function deleteTag($tag)
    {
        $event_id = $this->l2->deleteTag($this->l1, $tag);
        return $event_id;
    }

    public function synchronize()
    {
        return $this->l2->applyEvents($this->l1);
    }

    public function getHitsL1()
    {
        return $this->l1->getHits();
    }

    public function getHitsL2()
    {
        return $this->l2->getHits();
    }

    public function getMisses()
    {
        return $this->l2->getMisses();
    }

    public function getLastAppliedEventID()
    {
        return $this->l1->getLastAppliedEventID();
    }

    public function getPool()
    {
        return $this->l1->getPool();
    }

    public function collectGarbage($item_limit = null)
    {
        return $this->l2->collectGarbage($item_limit);
    }
}
