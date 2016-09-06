<?php

namespace LCache;

final class Address implements \Serializable
{
    protected $bin;
    protected $key;
    public function __construct($bin = null, $key = null)
    {
        assert(!is_null($bin) || is_null($key));
        $this->bin = $bin;
        $this->key = $key;
    }

    public function getBin()
    {
        return $this->bin;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function isEntireBin()
    {
        return is_null($this->key);
    }

    public function isEntireCache()
    {
        return is_null($this->bin);
    }

    public function isMatch(Address $address)
    {
        if (!is_null($address->getBin()) && !is_null($this->bin) && $address->getBin() !== $this->bin) {
            return false;
        }
        if (!is_null($address->getKey()) && !is_null($this->key) && $address->getKey() !== $this->key) {
            return false;
        }
        return true;
    }

    // The serialized form must:
    //  - Place the bin first
    //  - Return a prefix matching all entries in a bin with a NULL key
    //  - Return a prefix matching all entries with a NULL bin
    public function serialize()
    {
        if (is_null($this->bin)) {
            return '';
        }

        $length_prefixed_bin = strlen($this->bin) . ':' . $this->bin;

        if (is_null($this->key)) {
            return $length_prefixed_bin . ':';
        }
        return $length_prefixed_bin . ':' . $this->key;
    }

    public function unserialize($serialized)
    {
        $entries = explode(':', $serialized, 2);
        $this->bin = null;
        $this->key = null;
        if (count($entries) === 2) {
            list($bin_length, $bin_and_key) = $entries;
            $bin_length = intval($bin_length);
            $this->bin = substr($bin_and_key, 0, $bin_length);
            $this->key = substr($bin_and_key, $bin_length + 1);
        }

        // @TODO: Remove check against false for PHP 7+
        if ($this->key === false || $this->key === '') {
            $this->key = null;
        }
    }
}


// Operate properly when testing in case Drupal isn't running this code.
if (!defined('REQUEST_TIME')) {
    define('REQUEST_TIME', time());
}
