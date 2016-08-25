<?php

namespace LCache\LCache;

class LCacheStaticL2 extends LCacheL2
{
    protected $events;
    protected $current_event_id;
    protected $hits;
    protected $misses;
    protected $tags;

    public function __construct()
    {
        $this->events = array();
        $this->current_event_id = 1;
        $this->hits = 0;
        $this->misses = 0;
        $this->tags = [];
    }

    // Returns an LCacheEntry
    public function getEntry(LCacheAddress $address)
    {
        $last_matching_entry = null;
        foreach ($this->events as $event_id => $entry) {
            if ($entry->getAddress()->isMatch($address)) {
                if ($entry->getAddress()->isEntireCache() || $entry->getAddress()->isEntireBin()) {
                    $last_matching_entry = null;
                } elseif (!is_null($entry->expiration) && $entry->expiration < REQUEST_TIME) {
                    $last_matching_entry = null;
                } else {
                    $last_matching_entry = $entry;
                }
            }
        }
        // Last event was a deletion, so miss.
        if (is_null($last_matching_entry) || is_null($last_matching_entry->value)) {
            return null;
        }
        $this->hits++;
        return $last_matching_entry;
    }

    public function set($pool, LCacheAddress $address, $value = null, $ttl = null, array $tags = [])
    {
        $expiration = $ttl ? (REQUEST_TIME + $ttl) : null;
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
            } else {
                $this->tags[$tag] = [$address];
            }
        }

        return $this->current_event_id;
    }

    public function delete($pool, LCacheAddress $address)
    {
        if ($address->isEntireCache()) {
            $this->events = array();
        }
        return $this->set($pool, $address);
    }

    public function getAddressesForTag($tag)
    {
        return isset($this->tags[$tag]) ? $this->tags[$tag] : [];
    }

    public function deleteTag(LCacheL1 $l1, $tag)
    {
        // Materialize the tag deletion as individual key deletions.
        foreach ($this->getAddressesForTag($tag) as $address) {
            $event_id = $this->delete($l1->getPool(), $address);
            $l1->delete($event_id, $address);
        }
        unset($this->tags[$tag]);
        return $this->current_event_id;
    }

    public function applyEvents(LCacheL1 $l1)
    {
        $last_applied_event_id = $l1->getLastAppliedEventID();

        // If the L1 cache is empty, bump the last applied ID
        // to the current high-water mark.
        if (is_null($last_applied_event_id)) {
            $l1->setLastAppliedEventID($this->current_event_id);
            return null;
        }

        $applied = 0;
        foreach ($this->events as $event_id => $event) {
            // Skip events that are too old or were created by the local L1.
            if ($event_id > $last_applied_event_id && $event->pool !== $l1->getPool()) {
                if (is_null($event->value)) {
                    $l1->delete($event->event_id, $event->getAddress());
                } else {
                    $l1->setWithExpiration($event->event_id, $event->getAddress(), $event->value, $event->created, $event->expiration);
                }
                $applied++;
            }
        }

        // Just in case there were skipped events, set the high water mark.
        $l1->setLastAppliedEventID($this->current_event_id);
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
