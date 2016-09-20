<?php

class WP_LCache_CLI {

	/**
	 * Enable WP LCache by creating the symlink for object-cache.php
	 */
	public function enable() {
		if ( defined( 'WP_LCACHE_OBJECT_CACHE' ) && WP_LCACHE_OBJECT_CACHE ) {
			WP_CLI::success( 'WP LCache is already enabled.' );
			return;
		}
		$drop_in = WP_CONTENT_DIR . '/object-cache.php';
		if ( file_exists( $drop_in ) ) {
			WP_CLI::error( 'Unknown wp-content/object-cache.php already exists.' );
		}
		$object_cache = dirname( __FILE__ ) . '/object-cache.php';
		$target = self::get_relative_path( $drop_in, $object_cache );
		chdir( WP_CONTENT_DIR );
		// @codingStandardsIgnoreStart
		if ( symlink( $target, 'object-cache.php' ) ) {
			// @codingStandardsIgnoreEnd
			WP_CLI::success( 'Enabled WP LCache by creating wp-content/object-cache.php symlink.' );
		} else {
			WP_CLI::error( 'Failed create wp-content/object-cache.php symlink and enable WP LCache.' );
		}
	}

	/**
	 * Get the relative path between two files
	 *
	 * @see http://stackoverflow.com/questions/2637945/getting-relative-path-from-absolute-path-in-php
	 */
	private static function get_relative_path( $from, $to ) {
		// some compatibility fixes for Windows paths
		$from = is_dir( $from ) ? rtrim( $from, '\/' ) . '/' : $from;
		$to   = is_dir( $to )   ? rtrim( $to, '\/' ) . '/'   : $to;
		$from = str_replace( '\\', '/', $from );
		$to   = str_replace( '\\', '/', $to );

		$from     = explode( '/', $from );
		$to       = explode( '/', $to );
		$relPath  = $to;

		foreach ( $from as $depth => $dir ) {
			// find first non-matching dir
			if ( $dir === $to[ $depth ] ) {
				// ignore this directory
				array_shift( $relPath );
			} else {
				// get number of remaining dirs to $from
				$remaining = count( $from ) - $depth;
				if ( $remaining > 1 ) {
					// add traversals up to first matching dir
					$padLength = ( count( $relPath ) + $remaining - 1 ) * -1;
					$relPath = array_pad( $relPath, $padLength, '..' );
					break;
				} else {
					$relPath[0] = './' . $relPath[0];
				}
			}
		}
		return implode( '/', $relPath );
	}

}

WP_CLI::add_command( 'lcache', 'WP_LCache_CLI' );
