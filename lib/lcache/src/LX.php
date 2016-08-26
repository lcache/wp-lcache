<?php

namespace LCache;

abstract class LX
{
    abstract public function getEntry(Address $address);
    abstract public function getHits();
    abstract public function getMisses();

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
        $value = $this->get($address);
        return !is_null($value);
    }
}
