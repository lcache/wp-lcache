<?php

namespace LCache;

abstract class L1 extends LX
{
    protected $pool;

    public function __construct()
    {
        if (!isset($this->pool)) {
            $this->pool = $this->generateUniqueID();
        }
    }

    protected function generateUniqueID()
    {
        return uniqid('', true) . ':' . mt_rand();
    }

    abstract public function getLastAppliedEventID();
    abstract public function setLastAppliedEventID($event_id);

    public function getPool()
    {
        return $this->pool;
    }

    public function set($event_id, Address $address, $value = null, $ttl = null)
    {
        return $this->setWithExpiration($event_id, $address, $value, REQUEST_TIME, is_null($ttl) ? null : $ttl);
    }

    abstract public function setWithExpiration($event_id, Address $address, $value, $created, $expiration = null);
    abstract public function delete($event_id, Address $address);
}
