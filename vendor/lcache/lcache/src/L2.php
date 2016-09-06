<?php

namespace LCache;

abstract class L2 extends LX
{
    abstract public function applyEvents(L1 $l1);
    abstract public function set($pool, Address $address, $value = null, $ttl = null, array $tags = [], $value_is_serialized = false);
    abstract public function delete($pool, Address $address);
    abstract public function deleteTag(L1 $l1, $tag);
    abstract public function getAddressesForTag($tag);
    abstract public function collectGarbage($item_limit = null);
    abstract public function countGarbage();
}
