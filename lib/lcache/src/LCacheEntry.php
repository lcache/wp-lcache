<?php

namespace LCache\LCache;

final class LCacheEntry
{
    public $event_id;
    public $pool;
    protected $address;
    public $value;
    public $created;
    public $expiration;
    public $tags;

    public function __construct($event_id, $pool, LCacheAddress $address, $value, $created, $expiration = null, array $tags = [])
    {
        $this->event_id = $event_id;
        $this->pool = $pool;
        $this->address = $address;
        $this->value = $value;
        $this->created = $created;
        $this->expiration = $expiration;
        $this->tags = $tags;
    }

    public function getAddress()
    {
        return $this->address;
    }
}
