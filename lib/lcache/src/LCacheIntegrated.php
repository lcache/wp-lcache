<?php

namespace LCache\LCache;

final class LCacheIntegrated
{
    protected $l1;
    protected $l2;

    public function __construct($l1, $l2)
    {
        $this->l1 = $l1;
        $this->l2 = $l2;
    }

    public function set(LCacheAddress $address, $value, $ttl = null, array $tags = [])
    {
        $event_id = $this->l2->set($this->l1->getPool(), $address, $value, $ttl, $tags);
        if (!is_null($event_id)) {
            $this->l1->set($event_id, $address, $value, $ttl);
        }
        return $event_id;
    }

    public function getEntry(LCacheAddress $address)
    {
        $entry = $this->l1->getEntry($address);
        if (!is_null($entry)) {
            return $entry;
        }
        $entry = $this->l2->getEntry($address);
        if (is_null($entry)) {
            return null;
        }
        $this->l1->setWithExpiration($entry->event_id, $address, $entry->value, $entry->created, $entry->expiration);
        return $entry;
    }

    public function get(LCacheAddress $address)
    {
        $entry = $this->getEntry($address);
        if (is_null($entry)) {
            return null;
        }
        return $entry->value;
    }

    public function exists(LCacheAddress $address)
    {
        $exists = $this->l1->exists($address);
        if ($exists) {
            return true;
        }
        return $this->l2->exists($address);
    }

    public function delete(LCacheAddress $address)
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
}
