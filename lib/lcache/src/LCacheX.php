<?php

namespace LCache\LCache;

abstract class LCacheX
{
    abstract public function getEntry(LCacheAddress $address);
    abstract public function getHits();
    abstract public function getMisses();

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
        $value = $this->get($address);
        return !is_null($value);
    }
}
