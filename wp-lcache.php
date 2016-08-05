<?php
/**
 * Plugin Name: WP LCache
 * Plugin URI: http://github.com/pantheon-systems/wp-lcache/
 * Description: WordPress Object Cache using APCu. Requires APCu installed.
 * Version: 0.1.0-alpha
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


/**
 * Create the requisite tables if they don't yet exist
 */
function wp_lcache_initialize_database_schema() {
	global $wpdb;

	$wpdb->query( "CREATE TABLE IF NOT EXISTS `lcache_events` (
		`event_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		`pool` varchar(255) NOT NULL DEFAULT '' COMMENT 'PHP process pool that wrote the change.',
		`key` varchar(255) DEFAULT '' COMMENT 'Cache key within bin.',
		`value` blob COMMENT 'A collection of data to cache.',
		`expiration` int(11) DEFAULT NULL COMMENT 'A Unix timestamp indicating when the cache entry should expire, or NULL for never.',
		`created` int(11) DEFAULT '0' COMMENT 'A Unix timestamp indicating when the cache entry was created.',
		PRIMARY KEY (`event_id`),
		UNIQUE KEY `event_id` (`event_id`),
		KEY `expiration` (`expiration`),
		KEY `lookup_miss` (`key`,`event_id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;" );
}

register_activation_hook( __FILE__, 'wp_lcache_initialize_database_schema' );
