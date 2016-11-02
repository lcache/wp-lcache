<?php
/**
 * Plugin Name: WP LCache
 * Plugin URI: http://github.com/pantheon-systems/wp-lcache/
 * Description: Supercharge your WP Object Cache with LCache, a persistent, performant, and multi-layer cache library.
 * Version: 0.5.0
 * Author: Pantheon, Daniel Bachhuber
 * Author URI: https://pantheon.io/
 */
/*  This program is free software; you can redistribute it and/or modify
	it under the terms of the GNU General Public License as published by
	the Free Software Foundation; either version 2 of the License, or
	(at your option) any later version.

	This program is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU General Public License for more details.

	You should have received a copy of the GNU General Public License
	along with this program; if not, write to the Free Software
	Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once dirname( __FILE__ ) . '/cli.php';
}

/**
 * Create the requisite tables if they don't yet exist
 */
function wp_lcache_initialize_database_schema() {
	global $wpdb;

	$events_table = $GLOBALS['table_prefix'] . 'lcache_events';
	// @codingStandardsIgnoreStart
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$events_table}` (
		`event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		`pool` varchar(255) NOT NULL DEFAULT '' COMMENT 'PHP process pool that wrote the change.',
		`address` varchar(255) DEFAULT NULL COMMENT 'Cache entry address (bin and key).',
		`value` longblob COMMENT 'A collection of data to cache.',
		`expiration` int(11) DEFAULT NULL COMMENT 'A Unix timestamp indicating when the cache entry should expire, or NULL for never.',
		`created` int(11) DEFAULT '0' COMMENT 'A Unix timestamp indicating when the cache entry was created.',
		PRIMARY KEY (`event_id`),
		UNIQUE KEY `event_id` (`event_id`),
		KEY `expiration` (`expiration`),
		KEY `lookup_miss` (`address`,`event_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;" );

	$tags_table = $GLOBALS['table_prefix'] . 'lcache_tags';
	$wpdb->query( "CREATE TABLE IF NOT EXISTS `{$tags_table}` (
		`tag` varchar(255) NOT NULL DEFAULT '',
		`address` varchar(255) NOT NULL DEFAULT '',
		PRIMARY KEY (`tag`,`address`),
		KEY `rewritten_entry` (`address`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;" );
	// @codingStandardsIgnoreEnd
}

if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, 'wp_lcache_initialize_database_schema' );
}

/**
 * Warn the end user when object-cache.php is missing
 */
function wp_lcache_warn_object_cache_missing() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$message = 'Warning! WP LCache object-cache.php is missing. <a href="https://wordpress.org/plugins/wp-lcache/installation/" target="_blank">See "Installation" for more details</a>.';
	echo '<div class="message error"><p>' . wp_kses_post( $message ) . '</p></div>';
}
if ( ! defined( 'WP_LCACHE_OBJECT_CACHE' ) && function_exists( 'add_action' ) ) {
	add_action( 'admin_notices', 'wp_lcache_warn_object_cache_missing' );
}
