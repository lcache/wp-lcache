<?php

namespace LCache;

//use phpunit\framework\TestCase;

class LCacheTest extends \PHPUnit_Extensions_Database_TestCase
{
    protected $dbh = null;

    /**
   * @return PHPUnit_Extensions_Database_DB_IDatabaseConnection
   */
    protected function getConnection()
    {
        $this->dbh = new \PDO('sqlite::memory:');
        $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        return $this->createDefaultDBConnection($this->dbh, ':memory:');
    }

    protected function createSchema($prefix='')
    {
        $this->dbh->exec('PRAGMA foreign_keys = ON');

        $this->dbh->exec('CREATE TABLE ' . $prefix . 'lcache_events("event_id" INTEGER PRIMARY KEY AUTOINCREMENT, "pool" TEXT NOT NULL, "address" TEXT, "value" BLOB, "expiration" INTEGER, "created" INTEGER NOT NULL)');
        $this->dbh->exec('CREATE INDEX ' . $prefix . 'latest_entry ON ' . $prefix . 'lcache_events ("address", "event_id")');

        // @TODO: Set a proper primary key and foreign key relationship.
        $this->dbh->exec('CREATE TABLE ' . $prefix . 'lcache_tags("tag" TEXT, "event_id" INTEGER, PRIMARY KEY ("tag", "event_id"), FOREIGN KEY("event_id") REFERENCES ' . $prefix . 'lcache_events("event_id") ON DELETE CASCADE)');
        $this->dbh->exec('CREATE INDEX ' . $prefix . 'rewritten_entry ON ' . $prefix . 'lcache_tags ("event_id")');
    }

    public function testNullL1()
    {
        $event_id = 1;
        $cache = new NullL1();
        $myaddr = new Address('mybin', 'mykey');
        $cache->set($event_id++, $myaddr, 'myvalue');
        $entry = $cache->get($myaddr);
        $this->assertEquals(null, $entry);
        $this->assertEquals(0, $cache->getHits());
        $this->assertEquals(1, $cache->getMisses());

        // Because this cache stores nothing it should be perpetually
        // up-to-date.
        $this->assertEquals(PHP_INT_MAX, $cache->getLastAppliedEventID());
    }

    public function testStaticL1SetGetDelete()
    {
        $event_id = 1;
        $cache = new StaticL1();

        $myaddr = new Address('mybin', 'mykey');

        $this->assertEquals(0, $cache->getHits());
        $this->assertEquals(0, $cache->getMisses());

        // Try to get an entry from an empty cache.
        $entry = $cache->get($myaddr);
        $this->assertNull($entry);
        $this->assertEquals(0, $cache->getHits());
        $this->assertEquals(1, $cache->getMisses());

        // Set and get an entry.
        $cache->set($event_id++, $myaddr, 'myvalue');
        $entry = $cache->get($myaddr);
        $this->assertEquals('myvalue', $entry);
        $this->assertEquals(1, $cache->getHits());
        $this->assertEquals(1, $cache->getMisses());

        // Delete the entry and try to get it again.
        $cache->delete($event_id++, $myaddr);
        $entry = $cache->get($myaddr);
        $this->assertNull($entry);
        $this->assertEquals(1, $cache->getHits());
        $this->assertEquals(2, $cache->getMisses());

        // Clear everything and try to read.
        $cache->delete($event_id++, new Address());
        $entry = $cache->get($myaddr);
        $this->assertNull($entry);
        $this->assertEquals(1, $cache->getHits());
        $this->assertEquals(3, $cache->getMisses());
    }

    public function testStaticL1Antirollback()
    {
        $l1 = new StaticL1();
        $this->performL1AntirollbackTest($l1);
    }

    public function testStaticL1FullDelete()
    {
        $event_id = 1;
        $cache = new StaticL1();

        $myaddr = new Address('mybin', 'mykey');

        // Set an entry and clear the storage.
        $cache->set($event_id++, $myaddr, 'myvalue');
        $cache->delete($event_id++, new Address());
        $entry = $cache->get($myaddr);
        $this->assertEquals(null, $entry);
        $this->assertEquals(0, $cache->getHits());
        $this->assertEquals(1, $cache->getMisses());
    }

    public function testStaticL1Expiration()
    {
        $event_id = 1;
        $cache = new StaticL1();

        $myaddr = new Address('mybin', 'mykey');

        // Set and get an entry.
        $cache->set($event_id++, $myaddr, 'myvalue', -1);
        $this->assertNull($cache->get($myaddr));
        $this->assertEquals(0, $cache->getHits());
        $this->assertEquals(1, $cache->getMisses());
    }

    public function testClearStaticL2()
    {
        $l2 = new StaticL2();
        $myaddr = new Address('mybin', 'mykey');
        $l2->set('mypool', $myaddr, 'myvalue');
        $l2->delete('mypool', new Address());
        $this->assertNull($l2->get($myaddr));
    }

    public function testStaticL2Expiration()
    {
        $l2 = new StaticL2();
        $myaddr = new Address('mybin', 'mykey');
        $l2->set('mypool', $myaddr, 'myvalue', -1);
        $this->assertNull($l2->get($myaddr));
    }

    public function testNewPoolSynchronization()
    {
        $central = new StaticL2();
        $pool1 = new Integrated(new StaticL1(), $central);

        $myaddr = new Address('mybin', 'mykey');

        // Initialize sync for Pool 1.
        $applied = $pool1->synchronize();
        $this->assertEquals(null, $applied);
        $current_event_id = $pool1->getLastAppliedEventID();
        $this->assertEquals(1, $current_event_id);

        // Add a new entry to Pool 1. The last applied event should be our
        // change. However, because the event is from the same pool, applied
        // should be zero.
        $pool1->set($myaddr, 'myvalue');
        $applied = $pool1->synchronize();
        $this->assertEquals(0, $applied);
        $this->assertEquals($current_event_id + 1, $pool1->getLastAppliedEventID());

        // Add a new pool. Sync should return NULL applied changes but should
        // bump the last applied event ID.
        $pool2 = new Integrated(new StaticL1(), $central);
        $applied = $pool2->synchronize();
        $this->assertEquals(null, $applied);
        $this->assertEquals($pool1->getLastAppliedEventID(), $pool2->getLastAppliedEventID());
    }

    protected function performSynchronizationTest($central, $first_l1, $second_l1)
    {
        // Create two integrated pools with independent L1s.
        $pool1 = new Integrated($first_l1, $central);
        $pool2 = new Integrated($second_l1, $central);

        $myaddr = new Address('mybin', 'mykey');

        // Set and get an entry in Pool 1.
        $pool1->set($myaddr, 'myvalue');
        $this->assertEquals('myvalue', $pool1->get($myaddr));
        $this->assertEquals(1, $pool1->getHitsL1());
        $this->assertEquals(0, $pool1->getHitsL2());
        $this->assertEquals(0, $pool1->getMisses());

        // Read the entry in Pool 2.
        $this->assertEquals('myvalue', $pool2->get($myaddr));
        $this->assertEquals(0, $pool2->getHitsL1());
        $this->assertEquals(1, $pool2->getHitsL2());
        $this->assertEquals(0, $pool2->getMisses());

        // Initialize Pool 2 synchronization.
        $pool2->synchronize();

        // Alter the item in Pool 1. Pool 2 should hit its L1 again
        // with the out-of-date item. Synchronizing should fix it.
        $pool1->set($myaddr, 'myvalue2');
        $this->assertEquals('myvalue', $pool2->get($myaddr));
        $applied = $pool2->synchronize();
        $this->assertEquals(1, $applied);
        $this->assertEquals('myvalue2', $pool2->get($myaddr));

        // Delete the item in Pool 1. Pool 2 should hit its L1 again
        // with the now-deleted item. Synchronizing should fix it.
        $pool1->delete($myaddr);
        $this->assertEquals('myvalue2', $pool2->get($myaddr));
        $applied = $pool2->synchronize();
        $this->assertEquals(1, $applied);
        $this->assertNull($pool2->get($myaddr));

        // Try to get an entry that has never existed.
        $myaddr_nonexistent = new Address('mybin', 'mykeynonexistent');
        $this->assertNull($pool1->get($myaddr_nonexistent));

        // Test out bins and clearing.
        $mybin1_mykey = new Address('mybin1', 'mykey');
        $mybin1 = new Address('mybin1');
        $mybin2_mykey = new Address('mybin2', 'mykey');
        $pool1->set($mybin1_mykey, 'myvalue1');
        $pool1->set($mybin2_mykey, 'myvalue2');
        $pool2->synchronize();
        $pool1->delete($mybin1);

        // The deleted bin should be evident in pool1 but not in pool2.
        $this->assertNull($pool1->get($mybin1_mykey));
        $this->assertEquals('myvalue2', $pool1->get($mybin2_mykey));
        $this->assertEquals('myvalue1', $pool2->get($mybin1_mykey));
        $this->assertEquals('myvalue2', $pool2->get($mybin2_mykey));

        // Synchronizing should propagate the bin clearing to pool2.
        $pool2->synchronize();
        $this->assertNull($pool2->get($mybin1_mykey));
        $this->assertEquals('myvalue2', $pool2->get($mybin2_mykey));
    }

    protected function performClearSynchronizationTest($central, $first_l1, $second_l1)
    {
        // Create two integrated pools with independent L1s.
        $pool1 = new Integrated($first_l1, $central);
        $pool2 = new Integrated($second_l1, $central);

        $myaddr = new Address('mybin', 'mykey');

        // Create an item, synchronize, and then do a complete clear.
        $pool1->set($myaddr, 'mynewvalue');
        $this->assertEquals('mynewvalue', $pool1->get($myaddr));
        $pool2->synchronize();
        $this->assertEquals('mynewvalue', $pool2->get($myaddr));
        $pool1->delete(new Address());
        $this->assertNull($pool1->get($myaddr));

        // Pool 2 should lag until it synchronizes.
        $this->assertEquals('mynewvalue', $pool2->get($myaddr));
        $pool2->synchronize();
        $this->assertNull($pool2->get($myaddr));
    }

    protected function performTaggedSynchronizationTest($central, $first_l1, $second_l1)
    {
        // Create two integrated pools with independent L1s.
        $pool1 = new Integrated($first_l1, $central);
        $pool2 = new Integrated($second_l1, $central);

        $myaddr = new Address('mybin', 'mykey');

        // Test deleting a tag that doesn't exist yet.
        $pool1->deleteTag('mytag');

        // Set and get an entry in Pool 1.
        $pool1->set($myaddr, 'myvalue', null, ['mytag']);
        $this->assertEquals([$myaddr], $central->getAddressesForTag('mytag'));
        $this->assertEquals('myvalue', $pool1->get($myaddr));
        $this->assertEquals(1, $pool1->getHitsL1());
        $this->assertEquals(0, $pool1->getHitsL2());
        $this->assertEquals(0, $pool1->getMisses());

        // Read the entry in Pool 2.
        $this->assertEquals('myvalue', $pool2->get($myaddr));
        $this->assertEquals(0, $pool2->getHitsL1());
        $this->assertEquals(1, $pool2->getHitsL2());
        $this->assertEquals(0, $pool2->getMisses());


        // Initialize Pool 2 synchronization.
        $pool2->synchronize();

        // Delete the tag. The item should now be missing from Pool 1.
        $pool1->deleteTag('mytag'); // TKTK
        $this->assertNull($central->get($myaddr));
        $this->assertNull($first_l1->get($myaddr));
        $this->assertNull($pool1->get($myaddr));


        // Pool 2 should hit its L1 again with the tag-deleted item.
        // Synchronizing should fix it.
        $this->assertEquals('myvalue', $pool2->get($myaddr));
        $applied = $pool2->synchronize();
        $this->assertEquals(1, $applied);
        $this->assertNull($pool2->get($myaddr));

        // Ensure the addition of a second tag still works for deletion.
        $myaddr2 = new Address('mybin', 'mykey2');
        $pool1->set($myaddr2, 'myvalue', null, ['mytag']);
        $pool1->set($myaddr2, 'myvalue', null, ['mytag', 'mytag2']);
        $pool1->deleteTag('mytag2');
        $this->assertNull($pool1->get($myaddr2));

        // Ensure updating a second item with a tag doesn't remove it from the
        // first.
        $pool1->delete(new Address());
        $pool1->set($myaddr, 'myvalue', null, ['mytag', 'mytag2']);
        $pool1->set($myaddr2, 'myvalue', null, ['mytag', 'mytag2']);
        $pool1->set($myaddr, 'myvalue', null, ['mytag']);

        $found_addresses = $central->getAddressesForTag('mytag2');
        // getAddressesForTag() may return additional addresses, but it should
        // always return at least the current tagged address.
        $found = false;
        foreach ($found_addresses as $found_address) {
            if ($found_address->serialize() === $myaddr2->serialize()) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }


    public function testSynchronizationStatic()
    {
        $central = new StaticL2();
        $this->performSynchronizationTest($central, new StaticL1(), new StaticL1());
    }

    public function testTaggedSynchronizationStatic()
    {
        $central = new StaticL2();
        $this->performTaggedSynchronizationTest($central, new StaticL1(), new StaticL1());
    }

    public function testSynchronizationAPCu()
    {
        // Warning: As long as LCache\APCuL1 flushes all of APCu on a wildcard
        // deletion, it is not possible to test such functionality in a
        // single process.

        $run_test = false;
        if (function_exists('apcu_store')) {
            apcu_store('test_key', 'test_value');
            $value = apcu_fetch('test_key');
            if ($value === 'test_value') {
                $run_test = true;
            }
        }

        if ($run_test) {
            $central = new StaticL2();
            $this->performSynchronizationTest($central, new APCuL1('testSynchronizationAPCu1'), new APCuL1('testSynchronizationAPCu2'));

            // Because of how APCu only offers full cache clears, we test against a static cache for the other L1.
            $this->performClearSynchronizationTest($central, new APCuL1('testSynchronizationAPCu1b'), new StaticL1());
            $this->performClearSynchronizationTest($central, new StaticL1(), new APCuL1('testSynchronizationAPCu1c'));
        }
        else {
            $this->markTestSkipped('The APCu extension is not installed, enabled (for the CLI), or functional.');
        }
    }

    public function testSynchronizationDatabase()
    {
        $this->createSchema();
        $central = new DatabaseL2($this->dbh);
        $this->performSynchronizationTest($central, new StaticL1('testSynchronizationDatabase1'), new StaticL1('testSynchronizationDatabase2'));
        $this->performClearSynchronizationTest($central, new StaticL1('testSynchronizationDatabase1a'), new StaticL1('testSynchronizationDatabase2a'));
    }

    public function testTaggedSynchronizationDatabase()
    {
        $this->createSchema();
        $central = new DatabaseL2($this->dbh);
        $this->performTaggedSynchronizationTest($central, new StaticL1('testTaggedSynchronizationDatabase1'), new StaticL1('testTaggedSynchronizationDatabase2'));
    }

    public function testBrokenDatabaseFallback()
    {
        $this->createSchema();
        $l2 = new DatabaseL2($this->dbh, '', true);
        $l1 = new StaticL1('first');
        $pool = new Integrated($l1, $l2);

        $myaddr = new Address('mybin', 'mykey');

        // Break the schema and try operations.
        $this->dbh->exec('DROP TABLE lcache_tags');
        $this->assertNull($pool->set($myaddr, 'myvalue', null, ['mytag']));
        $this->assertGreaterThanOREqual(1, count($l2->getErrors()));
        $this->assertNull($pool->deleteTag('mytag'));
        $pool->synchronize();

        $myaddr2 = new Address('mybin', 'mykey2');

        $this->dbh->exec('DROP TABLE lcache_events');
        $this->assertNull($pool->synchronize());
        $this->assertNull($pool->get($myaddr2));
        $this->assertNull($pool->exists($myaddr2));
        $this->assertNull($pool->set($myaddr, 'myvalue'));
        $this->assertNull($pool->delete($myaddr));
        $this->assertNull($pool->delete(new Address()));
        $this->assertNull($l2->getAddressesForTag('mytag'));

        // Try applying events to an uninitialized L1.
        $this->assertNull($l2->applyEvents(new StaticL1()));
    }

    public function testExistsDatabaseL2()
    {
        $this->createSchema();
        $l2 = new DatabaseL2($this->dbh);
        $myaddr = new Address('mybin', 'mykey');
        $l2->set('mypool', $myaddr, 'myvalue');
        $this->assertTrue($l2->exists($myaddr));
        $l2->delete('mypool', $myaddr);
        $this->assertFalse($l2->exists($myaddr));
    }

    public function testEmptyCleanUpDatabaseL2()
    {
        $this->createSchema();
        $l2 = new DatabaseL2($this->dbh);
    }

    public function testExistsAPCuL1()
    {
        $l1 = new APCuL1('first');
        $myaddr = new Address('mybin', 'mykey');
        $l1->set(1, $myaddr, 'myvalue');
        $this->assertTrue($l1->exists($myaddr));
        $l1->delete(2, $myaddr);
        $this->assertFalse($l1->exists($myaddr));
    }

    public function testExistsStaticL1()
    {
        $l1 = new StaticL1();
        $myaddr = new Address('mybin', 'mykey');
        $l1->set(1, $myaddr, 'myvalue');
        $this->assertTrue($l1->exists($myaddr));
        $l1->delete(2, $myaddr);
        $this->assertFalse($l1->exists($myaddr));
    }

    public function testExistsIntegrated()
    {
        $this->createSchema();
        $l2 = new DatabaseL2($this->dbh);
        $l1 = new APCuL1('first');
        $pool = new Integrated($l1, $l2);
        $myaddr = new Address('mybin', 'mykey');
        $pool->set($myaddr, 'myvalue');
        $this->assertTrue($pool->exists($myaddr));
        $pool->delete($myaddr);
        $this->assertFalse($pool->exists($myaddr));
    }

    public function testDatabaseL2Prefix()
    {
        $this->createSchema('myprefix_');
        $l2 = new DatabaseL2($this->dbh, 'myprefix_');
        $myaddr = new Address('mybin', 'mykey');
        $l2->set('mypool', $myaddr, 'myvalue', null, ['mytag']);
        $this->assertEquals('myvalue', $l2->get($myaddr));
    }

    public function testAPCuL1PoolIDs()
    {
        // Test unique ID generation.
        $l1 = new APCuL1();
        $this->assertNotNull($l1->getPool());

        // Test host-based generation.
        $_SERVER['SERVER_ADDR'] = 'localhost';
        $_SERVER['SERVER_PORT'] = '80';
        $l1 = new APCuL1();
        $this->assertEquals('localhost:80', $l1->getPool());
    }

    protected function performL1AntirollbackTest($l1)
    {
        $myaddr = new Address('mybin', 'mykey');
        $current_event_id = $l1->getLastAppliedEventID();
        if (is_null($current_event_id)) {
            $current_event_id = 1;
        }
        $l1->set($current_event_id++, $myaddr, 'myvalue');
        $this->assertEquals('myvalue', $l1->get($myaddr));
        $l1->set($current_event_id - 2, $myaddr, 'myoldvalue');
        $this->assertEquals('myvalue', $l1->get($myaddr));
    }

    public function testAPCuL1Antirollback()
    {
        $l1 = new APCuL1('first');
        $this->performL1AntirollbackTest($l1);
    }

    protected function performL1HitMissTest($l1)
    {
        $myaddr = new Address('mybin', 'mykey');
        $current_hits = $l1->getHits();
        $current_misses = $l1->getMisses();
        $current_event_id = $l1->getLastAppliedEventID();
        $l1->get($myaddr);
        $this->assertEquals($current_misses + 1, $l1->getMisses());
        $l1->set($current_event_id++, $myaddr, 'myvalue');
        $l1->get($myaddr);
        $this->assertEquals($current_hits + 1, $l1->getHits());
    }

    public function testAPCuHitMiss()
    {
        $l1 = new APCuL1('testAPCuHitMiss');
        $this->performL1HitMissTest($l1);
    }

    public function testPoolIntegrated()
    {
        $l2 = new StaticL2();
        $l1 = new APCuL1('first');
        $pool = new Integrated($l1, $l2);
        $this->assertEquals('first', $pool->getPool());
    }

    public function testAddressMatching()
    {
        $entire_cache = new Address();
        $entire_mybin = new Address('mybin');
        $mybin_mykey = new Address('mybin', 'mykey');
        $mybin_mykey2 = new Address('mybin', 'mykey2');
        $mybin2_mykey2 = new Address('mybin2', 'mykey2');

        $this->assertTrue($entire_cache->isMatch($mybin_mykey));
        $this->assertTrue($mybin_mykey->isMatch($entire_cache));

        $this->assertTrue($entire_mybin->isMatch($mybin_mykey));
        $this->assertTrue($mybin_mykey->isMatch($entire_mybin));

        $this->assertFalse($mybin_mykey->isMatch($mybin_mykey2));
        $this->assertFalse($mybin_mykey2->isMatch($mybin_mykey));

        $this->assertFalse($entire_mybin->isMatch($mybin2_mykey2));
        $this->assertFalse($mybin2_mykey2->isMatch($entire_mybin));
    }

    protected function performSerializationTest($address)
    {
        // The bin and key should persist across native serialization and
        // unserialization.
        $rehydrated = unserialize(serialize($address));
        $this->assertEquals($rehydrated->getKey(), $address->getKey());
        $this->assertEquals($rehydrated->getBin(), $address->getBin());

        if (is_null($address->getBin())) {
            $this->assertNull($rehydrated->getBin());
        }
        if (is_null($address->getKey())) {
            $this->assertNull($rehydrated->getKey());
        }

        // Same for non-native.
        $rehydrated = new Address();
        $rehydrated->unserialize($address->serialize());
        $this->assertEquals($rehydrated->getKey(), $address->getKey());
        $this->assertEquals($rehydrated->getBin(), $address->getBin());
    }

    public function testAddressSerialization()
    {
        $mybin_mykey = new Address('mybin', 'mykey');
        $this->performSerializationTest($mybin_mykey);

        // An entire bin address should match against any entry in the bin.
        $entire_mybin = new Address('mybin');
        $this->performSerializationTest($entire_mybin);
        $this->assertEquals(strpos($entire_mybin->serialize(), $mybin_mykey->serialize()), 0);

        // An entire bin address should match against any entry.
        $entire_cache = new Address();
        $this->performSerializationTest($entire_cache);
        $this->assertEquals(strpos($entire_mybin->serialize(), $mybin_mykey->serialize()), 0);
    }

    /**
    * @return PHPUnit_Extensions_Database_DataSet_IDataSet
    */
    protected function getDataSet()
    {
        return new \PHPUnit_Extensions_Database_DataSet_DefaultDataSet();
    }
}
