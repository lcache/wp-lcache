<?php

namespace LCache\LCache;

class LCacheNullL1 extends LCacheStaticL1
{
    public function set($event_id, LCacheAddress $address, $value = null, $ttl = '')
    {
        // Store nothing; always succeed.
        return true;
    }

    public function getLastAppliedEventID()
    {
        // Because we store nothing locally, behave as if all events
        // are applied.
        return PHP_INT_MAX;
    }
}
