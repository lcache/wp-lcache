<?php

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

if ( getenv( 'WP_CORE_DIR' ) ) {
	$_core_dir = getenv( 'WP_CORE_DIR' );
} else if ( getenv( 'WP_DEVELOP_DIR' ) ) {
	$_core_dir = getenv( 'WP_DEVELOP_DIR' ) . '/src/';
} else {
	$_core_dir = '/tmp/wordpress';
}

define( 'WP_LCACHE_RUNNING_TESTS', true );
$_SERVER['SERVER_ADDR'] = 'example.org';
$_SERVER['SERVER_PORT'] = 80;
require dirname( dirname( dirname( __FILE__ ) ) ) . '/wp-lcache.php';

// Easiest way to get this to where WordPress will load it
define( 'WP_LCACHE_AUTOLOADER', dirname( dirname( __DIR__ ) ) . '/vendor/autoload.php' );
copy( dirname( dirname( dirname( __FILE__ ) ) ) . '/object-cache.php', $_core_dir . '/wp-content/object-cache.php' );

require $_tests_dir . '/includes/bootstrap.php';

// If PHP_APCU is enabled but LCache isn't available, something broke
if ( 'enabled' === getenv( 'PHP_APCU' ) && ! $GLOBALS['wp_object_cache']->is_lcache_available() ) {
	error_log( PHP_EOL );
	error_log( "LCache isn't available when it should be." );
	exit( 1 );
}

error_log( PHP_EOL );
$lcache_state = $GLOBALS['wp_object_cache']->is_lcache_available() ? 'enabled' : 'disabled';
error_log( 'LCache: ' . $lcache_state . PHP_EOL );
error_log( PHP_EOL );
