<?php

namespace LCache;

class StaticL2 extends L2
{
    protected $events;
    protected $current_event_id;
    protected $hits;
    protected $misses;
    protected $tags;

    public function __construct()
    {
        $this->events = array();
        $this->current_event_id = 0;
        $this->hits = 0;
        $this->misses = 0;
        $this->tags = [];
    }

    public function countGarbage()
    {
        $garbage = 0;
        foreach ($this->events as $event_id => $entry) {
            if ($entry->expiration < REQUEST_TIME) {
                $garbage++;
            }
        }
        return $garbage;
    }

    public function collectGarbage($item_limit = null)
    {
        $deleted = 0;
        foreach ($this->events as $event_id => $entry) {
            if ($entry->expiration < REQUEST_TIME) {
                unset($this->events[$event_id]);
                $deleted++;
            }
            if ($deleted === $item_limit) {
                break;
            }
        }
    }

    // Returns an LCache\Entry
    public function getEntry(Address $address)
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

        $last_matching_entry->value = unserialize($last_matching_entry->value);

        $this->hits++;
        return $last_matching_entry;
    }

    public function set($pool, Address $address, $value = null, $ttl = null, array $tags = [], $value_is_serialized = false)
    {
        $expiration = $ttl ? (REQUEST_TIME + $ttl) : null;
        $this->current_event_id++;

        // Serialize the value if it isn't already. We serialize the values
        // in static storage to make it more similar to other persistent stores.
        if (!$value_is_serialized) {
            $value = serialize($value);
        }
        $this->events[$this->current_event_id] = new Entry($this->current_event_id, $pool, $address, $value, REQUEST_TIME, $expiration);

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

    public function delete($pool, Address $address)
    {
        if ($address->isEntireCache()) {
            $this->events = array();
        }
        return $this->set($pool, $address, null, null, [], true);
    }

    public function getAddressesForTag($tag)
    {
        return isset($this->tags[$tag]) ? $this->tags[$tag] : [];
    }

    public function deleteTag(L1 $l1, $tag)
    {
        // Materialize the tag deletion as individual key deletions.
        foreach ($this->getAddressesForTag($tag) as $address) {
            $event_id = $this->delete($l1->getPool(), $address);
            $l1->delete($event_id, $address);
        }
        unset($this->tags[$tag]);
        return $this->current_event_id;
    }

    public function applyEvents(L1 $l1)
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
                    $unserialized_value = @unserialize($event->value);
                    if ($unserialized_value === false && $event->value !== serialize(false)) {
                        // Delete the L1 entry, if any, when we fail to unserialize.
                        $l1->delete($event->event_id, $event->getAddress());
                    } else {
                        $l1->setWithExpiration($event->event_id, $event->getAddress(), $unserialized_value, $event->created, $event->expiration);
                    }
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
