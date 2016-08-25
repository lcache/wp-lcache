<?php

namespace LCache\LCache;

abstract class LCacheL2 extends LCacheX
{
    abstract public function applyEvents(LCacheL1 $l1);
    abstract public function set($pool, LCacheAddress $address, $value = null, $ttl = null, array $tags = []);
    abstract public function delete($pool, LCacheAddress $address);
    abstract public function deleteTag(LCacheL1 $l1, $tag);
    abstract public function getAddressesForTag($tag);
}
