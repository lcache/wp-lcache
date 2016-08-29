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
require dirname( dirname( dirname( __FILE__ ) ) ) . '/wp-lcache.php';

// Easiest way to get this to where WordPress will load it
define( 'WP_LCACHE_AUTOLOADER', dirname( dirname( dirname( __FILE__ ) ) ) . '/vendor/autoload.php' );


print_r("WP_LCACHE_AUTOLOADER is "   .   WP_LCACHE_AUTOLOADER);

copy( dirname( dirname( dirname( __FILE__ ) ) ) . '/object-cache.php', $_core_dir . '/wp-content/object-cache.php' );

require $_tests_dir . '/includes/bootstrap.php';

error_log( PHP_EOL );
$lcache_state = $GLOBALS['wp_object_cache']->is_lcache_available() ? 'enabled' : 'disabled';
error_log( 'LCache: ' . $lcache_state . PHP_EOL );
error_log( PHP_EOL );
