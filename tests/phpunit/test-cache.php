<?php

/**
 * Test the persistent object cache using core's cache tests
 */
class CacheTest extends WP_UnitTestCase {

	private $cache;

	private static $exists;

	private static $get;

	private static $set;

	private static $incr;

	private static $decr;

	private static $delete;

	private $altered_value_column = false;

	public function setUp() {
		parent::setUp();
		// create two cache objects with a shared cache dir
		// this simulates a typical cache situation, two separate requests interacting
		$this->cache =& $this->init_cache();
		$this->cache->cache_hits = $this->cache->cache_misses = 0;
		$this->cache->lcache_calls = array();

		self::$exists = 'exists';
		self::$get = 'get';
		self::$set = 'set';
		self::$incr = 'incr';
		self::$decr = 'decr';
		self::$delete = 'delete';

	}

	public function &init_cache() {
		$cache = new WP_Object_Cache();
		$cache->add_global_groups( array( 'global-cache-test', 'users', 'userlogins', 'usermeta', 'user_meta', 'site-transient', 'site-options', 'site-lookup', 'blog-lookup', 'blog-details', 'rss', 'global-posts', 'blog-id-cache' ) );
		return $cache;
	}

	public function test_loaded() {
		$this->assertTrue( WP_LCACHE_OBJECT_CACHE );
	}

	public function test_miss() {
		$this->assertEquals( null, $this->cache->get( rand_str() ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$get     => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_add_get() {
		$key = rand_str();
		$val = rand_str();

		$this->cache->add( $key, $val );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_add_get_0() {
		$key = rand_str();
		$val = 0;

		// you can store zero in the cache
		$this->cache->add( $key, $val );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_add_get_null() {
		$key = rand_str();
		$val = null;

		$this->assertTrue( $this->cache->add( $key, $val ) );
		// null is converted to empty string
		$this->assertEquals( '', $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_add() {
		$key = rand_str();
		$val1 = rand_str();
		$val2 = rand_str();

		// add $key to the cache
		$this->assertTrue( $this->cache->add( $key, $val1 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		// $key is in the cache, so reject new calls to add()
		$this->assertFalse( $this->cache->add( $key, $val2 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_replace() {
		$key = rand_str();
		$val = rand_str();
		$val2 = rand_str();

		// memcached rejects replace() if the key does not exist
		$this->assertFalse( $this->cache->replace( $key, $val ) );
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->add( $key, $val ) );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertTrue( $this->cache->replace( $key, $val2 ) );
		$this->assertEquals( $val2, $this->cache->get( $key ) );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 2,
				self::$set        => 2,
				self::$get        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_set() {
		$key = rand_str();
		$val1 = rand_str();
		$val2 = rand_str();

		// memcached accepts set() if the key does not exist
		$this->assertTrue( $this->cache->set( $key, $val1 ) );
		$this->assertEquals( $val1, $this->cache->get( $key ) );
		// Second set() with same key should be allowed
		$this->assertTrue( $this->cache->set( $key, $val2 ) );
		$this->assertEquals( $val2, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$set        => 2,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_flush() {

		$key = rand_str();
		$val = rand_str();

		$this->cache->add( $key, $val );
		// item is visible to both cache objects
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->flush();
		// If there is no value get returns false.
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$get        => 1,
				self::$set        => 1,
				self::$delete     => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	// Make sure objects are cloned going to and from the cache
	public function test_object_refs() {
		$key = rand_str();
		$object_a = new stdClass;
		$object_a->foo = 'alpha';
		$this->cache->set( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b = $this->cache->get( $key );
		$this->assertEquals( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertEquals( 'bravo', $object_a->foo );

		$key = rand_str();
		$object_a = new stdClass;
		$object_a->foo = 'alpha';
		$this->cache->add( $key, $object_a );
		$object_a->foo = 'bravo';
		$object_b = $this->cache->get( $key );
		$this->assertEquals( 'alpha', $object_b->foo );
		$object_b->foo = 'charlie';
		$this->assertEquals( 'bravo', $object_a->foo );
	}

	public function test_get_already_exists_internal() {
		$key = rand_str();
		$this->cache->set( $key, 'alpha' );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
		$this->cache->lcache_calls = array(); // reset to limit scope of test
		$this->assertEquals( 'alpha', $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	public function test_get_missing_persistent() {
		$key = rand_str();
		$this->cache->get( $key );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->cache->get( $key );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$get        => 2,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_get_non_persistent_group() {
		$key = rand_str();
		$group = 'nonpersistent';
		$this->cache->add_non_persistent_groups( $group );
		$this->cache->get( $key, $group );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->assertEmpty( $this->cache->lcache_calls );
		$this->cache->get( $key, $group );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		$this->assertEmpty( $this->cache->lcache_calls );
		$this->cache->set( $key, 'alpha', $group );
		$this->cache->get( $key, $group );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		$this->assertEmpty( $this->cache->lcache_calls );
		$this->cache->get( $key, $group );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 2, $this->cache->cache_misses );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	public function test_get_false_value_persistent_cache() {
		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}
		$key = rand_str();
		$this->cache->set( $key, false );
		$this->cache->cache_hits = $this->cache->cache_misses = 0; // reset everything
		$this->cache->lcache_calls = $this->cache->cache = array(); // reset everything
		$found = null;
		$this->assertFalse( $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$get           => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_get_true_value_persistent_cache() {
		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}
		$key = rand_str();
		$this->cache->set( $key, true );
		$this->cache->cache_hits = $this->cache->cache_misses = 0; // reset everything
		$this->cache->lcache_calls = $this->cache->cache = array(); // reset everything
		$found = null;
		$this->assertTrue( $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$get           => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_get_null_value_persistent_cache() {
		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}
		$key = rand_str();
		$this->cache->set( $key, null );
		$this->cache->cache_hits = $this->cache->cache_misses = 0; // reset everything
		$this->cache->lcache_calls = $this->cache->cache = array(); // reset everything
		$found = null;
		// APCu coherses `null` to an empty string
		$this->assertEquals( '', $this->cache->get( $key, 'default', false, $found ) );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$get           => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_get_force() {
		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}

		$key = rand_str();
		$group = 'default';
		$this->cache->set( $key, 'alpha', $group );
		$this->assertEquals( 'alpha', $this->cache->get( $key, $group ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		// Duplicate of _set_internal()
		$multisite_safe_group = $this->get_multisite_safe_group( $group );
		$this->cache->cache[ $multisite_safe_group ][ $key ] = 'beta';
		$this->assertEquals( 'beta', $this->cache->get( $key, $group ) );
		$this->assertEquals( 'alpha', $this->cache->get( $key, $group, true ) );
		$this->assertEquals( 3, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->assertEquals( array(
			self::$get        => 1,
			self::$set        => 1,
		), $this->cache->lcache_calls );
	}

	public function test_get_found() {
		$key = rand_str();
		$found = null;
		$this->cache->get( $key, 'default', false, $found );
		$this->assertFalse( $found );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
		$this->cache->set( $key, 'alpha', 'default' );
		$this->cache->get( $key, 'default', false, $found );
		$this->assertTrue( $found );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );
	}

	public function test_incr() {
		$key = rand_str();

		$this->assertFalse( $this->cache->incr( $key ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0 );
		$this->cache->incr( $key );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->incr( $key, 2 );
		$this->assertEquals( 3, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
				self::$incr     => 2,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_incr_separate_groups() {
		$key = rand_str();
		$group1 = 'group1';
		$group2 = 'group2';

		$this->assertFalse( $this->cache->incr( $key, 1, $group1 ) );
		$this->assertFalse( $this->cache->incr( $key, 1, $group2 ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0, $group1 );
		$this->cache->incr( $key, 1, $group1 );
		$this->cache->set( $key, 0, $group2 );
		$this->cache->incr( $key, 1, $group2 );
		$this->assertEquals( 1, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 1, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->incr( $key, 2, $group1 );
		$this->cache->incr( $key, 1, $group2 );
		$this->assertEquals( 3, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 2, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 4, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 2,
				self::$set        => 2,
				self::$incr       => 4,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_incr_never_below_zero() {
		$key = rand_str();
		$this->cache->set( $key, 1 );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->incr( $key, -2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$incr     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_incr_non_persistent() {
		$key = rand_str();

		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->assertFalse( $this->cache->incr( $key, 1, 'nonpersistent' ) );

		$this->cache->set( $key, 0, 'nonpersistent' );
		$this->cache->incr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->incr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 3, $this->cache->get( $key, 'nonpersistent' ) );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	public function test_incr_non_persistent_never_below_zero() {
		$key = rand_str();
		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->cache->set( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );
		$this->cache->incr( $key, -2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	public function test_wp_cache_incr() {
		$key = rand_str();

		$this->assertFalse( wp_cache_incr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_incr( $key );
		$this->assertEquals( 1, wp_cache_get( $key ) );

		wp_cache_incr( $key, 2 );
		$this->assertEquals( 3, wp_cache_get( $key ) );
	}

	public function test_decr() {
		$key = rand_str();

		$this->assertFalse( $this->cache->decr( $key ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0 );
		$this->cache->decr( $key );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 3 );
		$this->cache->decr( $key );
		$this->assertEquals( 2, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->decr( $key, 2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 3, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 2,
				self::$decr       => 3,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_decr_separate_groups() {
		$key = rand_str();
		$group1 = 'group1';
		$group2 = 'group2';

		$this->assertFalse( $this->cache->decr( $key, 1, $group1 ) );
		$this->assertFalse( $this->cache->decr( $key, 1, $group2 ) );
		$this->assertEquals( 0, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 0, $group1 );
		$this->cache->decr( $key, 1, $group1 );
		$this->cache->set( $key, 0, $group2 );
		$this->cache->decr( $key, 1, $group2 );
		$this->assertEquals( 0, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 0, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->set( $key, 3, $group1 );
		$this->cache->decr( $key, 1, $group1 );
		$this->cache->set( $key, 2, $group2 );
		$this->cache->decr( $key, 1, $group2 );
		$this->assertEquals( 2, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 1, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 4, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		$this->cache->decr( $key, 2, $group1 );
		$this->cache->decr( $key, 2, $group2 );
		$this->assertEquals( 0, $this->cache->get( $key, $group1 ) );
		$this->assertEquals( 0, $this->cache->get( $key, $group2 ) );
		$this->assertEquals( 6, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 2,
				self::$set        => 4,
				self::$decr       => 6,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_decr_never_below_zero() {
		$key = rand_str();
		$this->cache->set( $key, 1 );
		$this->assertEquals( 1, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		$this->cache->decr( $key, 2 );
		$this->assertEquals( 0, $this->cache->get( $key ) );
		$this->assertEquals( 2, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$decr     => 1,
				self::$set        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_decr_non_persistent() {
		$key = rand_str();

		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->assertFalse( $this->cache->decr( $key, 1, 'nonpersistent' ) );

		$this->cache->set( $key, 0, 'nonpersistent' );
		$this->cache->decr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->set( $key, 3, 'nonpersistent' );
		$this->cache->decr( $key, 1, 'nonpersistent' );
		$this->assertEquals( 2, $this->cache->get( $key, 'nonpersistent' ) );

		$this->cache->decr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	public function test_decr_non_persistent_never_below_zero() {
		$key = rand_str();
		$this->cache->add_non_persistent_groups( array( 'nonpersistent' ) );
		$this->cache->set( $key, 1, 'nonpersistent' );
		$this->assertEquals( 1, $this->cache->get( $key, 'nonpersistent' ) );
		$this->cache->decr( $key, 2, 'nonpersistent' );
		$this->assertEquals( 0, $this->cache->get( $key, 'nonpersistent' ) );
		$this->assertEmpty( $this->cache->lcache_calls );
	}

	/**
	 * @group 21327
	 */
	public function test_wp_cache_decr() {
		$key = rand_str();

		$this->assertFalse( wp_cache_decr( $key ) );

		wp_cache_set( $key, 0 );
		wp_cache_decr( $key );
		$this->assertEquals( 0, wp_cache_get( $key ) );

		wp_cache_set( $key, 3 );
		wp_cache_decr( $key );
		$this->assertEquals( 2, wp_cache_get( $key ) );

		wp_cache_decr( $key, 2 );
		$this->assertEquals( 0, wp_cache_get( $key ) );
	}

	public function test_delete() {
		$key = rand_str();
		$val = rand_str();

		// Verify set
		$this->assertTrue( $this->cache->set( $key, $val ) );
		$this->assertEquals( $val, $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 0, $this->cache->cache_misses );

		// Verify successful delete
		$this->assertTrue( $this->cache->delete( $key ) );
		$this->assertFalse( $this->cache->get( $key ) );
		$this->assertEquals( 1, $this->cache->cache_hits );
		$this->assertEquals( 1, $this->cache->cache_misses );

		$this->assertFalse( $this->cache->delete( $key, 'default' ) );
		if ( $this->cache->is_lcache_available() ) {
			$this->assertEquals( array(
				self::$exists     => 1,
				self::$set        => 1,
				self::$delete     => 1,
				self::$get        => 1,
			), $this->cache->lcache_calls );
		} else {
			$this->assertEmpty( $this->cache->lcache_calls );
		}
	}

	public function test_wp_cache_delete() {
		$key = rand_str();
		$val = rand_str();

		// Verify set
		$this->assertTrue( wp_cache_set( $key, $val ) );
		$this->assertEquals( $val, wp_cache_get( $key ) );

		// Verify successful delete
		$this->assertTrue( wp_cache_delete( $key ) );
		$this->assertFalse( wp_cache_get( $key ) );

		// wp_cache_delete() does not have a $force method.
		// Delete returns (bool) true when key is not set and $force is true
		// $this->assertTrue( wp_cache_delete( $key, 'default', true ) );

		$this->assertFalse( wp_cache_delete( $key, 'default' ) );
	}

	public function test_delete_group() {
		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';

		// Set up the values
		$this->cache->set( $key1, $val1, $group );
		$this->cache->set( $key2, $val2, $group );
		$this->cache->set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, $this->cache->get( $key1, $group ) );
		$this->assertEquals( $val2, $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );

		$this->assertFalse( $this->cache->get( $key1, $group ) );
		$this->assertFalse( $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );
	}

	public function test_delete_group_non_persistent() {
		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';
		$this->cache->add_non_persistent_groups( array( $group, $group2 ) );

		// Set up the values
		$this->cache->set( $key1, $val1, $group );
		$this->cache->set( $key2, $val2, $group );
		$this->cache->set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, $this->cache->get( $key1, $group ) );
		$this->assertEquals( $val2, $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertTrue( $this->cache->delete_group( $group ) );

		$this->assertFalse( $this->cache->get( $key1, $group ) );
		$this->assertFalse( $this->cache->get( $key2, $group ) );
		$this->assertEquals( $val3, $this->cache->get( $key3, $group2 ) );

		$this->assertFalse( $this->cache->delete_group( $group ) );
	}

	public function test_wp_cache_delete_group() {

		$key1 = rand_str();
		$val1 = rand_str();
		$key2 = rand_str();
		$val2 = rand_str();
		$key3 = rand_str();
		$val3 = rand_str();
		$group = 'foo';
		$group2 = 'bar';

		// Set up the values
		wp_cache_set( $key1, $val1, $group );
		wp_cache_set( $key2, $val2, $group );
		wp_cache_set( $key3, $val3, $group2 );
		$this->assertEquals( $val1, wp_cache_get( $key1, $group ) );
		$this->assertEquals( $val2, wp_cache_get( $key2, $group ) );
		$this->assertEquals( $val3, wp_cache_get( $key3, $group2 ) );

		$this->assertTrue( wp_cache_delete_group( $group ) );

		$this->assertFalse( wp_cache_get( $key1, $group ) );
		$this->assertFalse( wp_cache_get( $key2, $group ) );
		$this->assertEquals( $val3, wp_cache_get( $key3, $group2 ) );

		$this->assertTrue( wp_cache_delete_group( $group ) );

	}

	public function test_switch_to_blog() {
		if ( ! method_exists( $this->cache, 'switch_to_blog' ) ) {
			return;
		}

		$key = rand_str();
		$val = rand_str();
		$val2 = rand_str();

		if ( ! is_multisite() ) {
			// Single site ingnores switch_to_blog().
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
		} else {
			// Multisite should have separate per-blog caches
			$this->assertTrue( $this->cache->set( $key, $val ) );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertFalse( $this->cache->get( $key ) );
			$this->assertTrue( $this->cache->set( $key, $val2 ) );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( 999 );
			$this->assertEquals( $val2, $this->cache->get( $key ) );
			$this->cache->switch_to_blog( get_current_blog_id() );
			$this->assertEquals( $val, $this->cache->get( $key ) );
		}

		// Global group
		$this->assertTrue( $this->cache->set( $key, $val, 'global-cache-test' ) );
		$this->assertEquals( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( 999 );
		$this->assertEquals( $val, $this->cache->get( $key, 'global-cache-test' ) );
		$this->assertTrue( $this->cache->set( $key, $val2, 'global-cache-test' ) );
		$this->assertEquals( $val2, $this->cache->get( $key, 'global-cache-test' ) );
		$this->cache->switch_to_blog( get_current_blog_id() );
		$this->assertEquals( $val2, $this->cache->get( $key, 'global-cache-test' ) );
	}

	public function test_wp_cache_init() {
		$new_blank_cache_object = new WP_Object_Cache();
		wp_cache_init();

		global $wp_object_cache;
		// Differs from core tests because we'll have two different Redis sockets
		$this->assertEquals( $wp_object_cache->cache, $new_blank_cache_object->cache );
	}

	public function test_cache_syncronize() {
		global $wpdb, $wp_object_cache, $table_prefix;

		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}

		// Set the initial cache
		$key = 'test_cache_syncronize';
		$group = 'test_group';
		wp_cache_set( $key, 'first_val', $group );

		$address = new \LCache\Address( $this->get_multisite_safe_group( $group ), $key );

		// Create a new integrated cache
		$second_pool = 'second_pool';
		$l1 = new \LCache\NullL1( $second_pool );

		// L2 isn't available as a public resource, so we need to recreate
		list( $port, $socket ) = self::get_port_socket_from_host( DB_HOST );
		$dsn = 'mysql:host='. DB_HOST. ';port='. $port .';dbname='. DB_NAME;
		$options = array( PDO::ATTR_TIMEOUT => 2, PDO::MYSQL_ATTR_INIT_COMMAND => 'SET sql_mode="ANSI_QUOTES"' );
		$dbh = new PDO( $dsn, DB_USER, DB_PASSWORD, $options );
		$dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$l2 = new \LCache\DatabaseL2( $dbh, $table_prefix, $second_pool );
		$integrated = new \LCache\Integrated( $l1, $l2 );

		// Writing an event to the second pool will propagate to the first on reload
		$integrated->set( $address, serialize( 'second_val' ) );

		// Reloading the object cache will syncronize the event.
		$this->assertEquals( 'first_val', wp_cache_get( $key, $group ) );
		wp_cache_init();
		$this->assertEquals( 'second_val', wp_cache_get( $key, $group ) );
	}

	public function test_wp_cache_replace() {
		$key  = 'my-key';
		$val1 = 'first-val';
		$val2 = 'second-val';

		$fake_key = 'my-fake-key';

		// Save the first value to cache and verify
		wp_cache_set( $key, $val1 );
		$this->assertEquals( $val1, wp_cache_get( $key ) );

		// Replace the value and verify
		wp_cache_replace( $key, $val2 );
		$this->assertEquals( $val2, wp_cache_get( $key ) );

		// Non-existant key should fail
		$this->assertFalse( wp_cache_replace( $fake_key, $val1 ) );

		// Make sure $fake_key is not stored
		$this->assertFalse( wp_cache_get( $fake_key ) );
	}

	public function test_cache_set_high_ttl() {
		$this->cache->set( 'foo', 'bar', 'group', 50 * YEAR_IN_SECONDS );
		$this->assertEquals( 'bar', $this->cache->get( 'foo', 'group' ) );
	}

	/**
	 * @expectedException PDOException
	 */
	public function test_invalid_schema_produces_warning() {
		global $wpdb, $table_prefix;
		if ( ! $this->cache->is_lcache_available() ) {
			$this->markTestSkipped( 'LCache is not available.' );
		}
		// @codingStandardsIgnoreStart
		$wpdb->query( "ALTER TABLE `{$table_prefix}lcache_events` MODIFY COLUMN value varchar(2)" );
		$this->altered_value_column = true;
		$ret = $wpdb->get_results( "SHOW CREATE TABLE `{$table_prefix}lcache_events`" );
		// @codingStandardsIgnoreEnd
		$this->assertContains( '`value` varchar(2) DEFAULT NULL,', $ret[0]->{'Create Table'} );
		$this->cache->set( 'foo', 'basjkfsdfsdksd' );
	}

	public function tearDown() {
		global $wpdb, $table_prefix;
		if ( $this->altered_value_column ) {
			// @codingStandardsIgnoreStart
			$wpdb->query( "ALTER TABLE `{$table_prefix}lcache_events` MODIFY COLUMN value LONGBLOB" );
			// @codingStandardsIgnoreEnd
			$this->altered_value_column = false;
		}
		parent::tearDown();
		$this->flush_cache();
	}

	/**
	 * Remove the object-cache.php from the place we've dropped it
	 */
	static function tearDownAfterClass() {
		parent::tearDownAfterClass();
		// @codingStandardsIgnoreStart
		$table_prefix = $GLOBALS['table_prefix'] ;
		$GLOBALS['wpdb']->query( "TRUNCATE TABLE " . $table_prefix . "lcache_events" );
		$GLOBALS['wpdb']->query( "TRUNCATE TABLE " . $table_prefix . "lcache_tags" );
		unlink( ABSPATH . 'wp-content/object-cache.php' );
		// @codingStandardsIgnoreEnd
	}

	function _create_temporary_tables( $query ) {
		global $table_prefix;
		if ( 0 === stripos( trim( $query ), "CREATE TABLE IF NOT EXISTS `{$table_prefix}lcache" ) ) {
			return $query;
		}
		return parent::_create_temporary_tables( $query );
	}

	function _drop_temporary_tables( $query ) {
		global $table_prefix;
		if ( 0 === stripos( trim( $query ), "DROP TABLE `{$table_prefix}lcache" ) ) {
			return $query;
		}
		return parent::_drop_temporary_tables( $query );
	}

	/**
	 * Get the port or the socket from the host.
	 *
	 * @param string $host
	 * @return array
	 */
	private static function get_port_socket_from_host( $host ) {
		$port = null;
		$socket = null;
		$port_or_socket = strstr( $host, ':' );
		if ( ! empty( $port_or_socket ) ) {
			$host = substr( $host, 0, strpos( $host, ':' ) );
			$port_or_socket = substr( $port_or_socket, 1 );
			if ( 0 !== strpos( $port_or_socket, '/' ) ) {
				$port = intval( $port_or_socket );
				$maybe_socket = strstr( $port_or_socket, ':' );
				if ( ! empty( $maybe_socket ) ) {
					$socket = substr( $maybe_socket, 1 );
				}
			} else {
				$socket = $port_or_socket;
			}
		}
		return array( $port, $socket );
	}

	/**
	 * Get the multisite-safe version of a group name
	 *
	 * @param string $group
	 * @return string
	 */
	private function get_multisite_safe_group( $group ) {
		return $this->cache->multisite && ! isset( $this->cache->global_groups[ $group ] ) ? $this->cache->blog_prefix . $group : $group;
	}
}
