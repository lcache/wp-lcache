<?php

class WP_LCache_CLI {

	/**
	 * Enable WP LCache by creating a stub for object-cache.php
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
		$target = str_replace( WP_CONTENT_DIR, '', $object_cache );
		// @codingStandardsIgnoreStart
		if ( self::make_stub( $target ) ) {
			// @codingStandardsIgnoreEnd
			WP_CLI::success( 'Enabled WP LCache by creating wp-content/object-cache.php stub file.' );
		} else {
			WP_CLI::error( 'Failed create wp-content/object-cache.php stub to enable WP LCache.' );
		}
	}

	/**
	 * Stub contents.
	 */
	private function make_stub( $target ) {
		$stub = <<<EndPHPBlock
<?php
# Engage LCache object caching system.
# We use a 'require_once()' here because in PHP 5.5+ changes to symlinks
# are not detected by the opcode cache, making it frustrating to deploy.
#
# More info: http://codinghobo.com/opcache-and-symlink-based-deployments/
#

\$lcache_path = dirname( realpath( __FILE__ ) ) . '$target';
require_once( \$lcache_path );
EndPHPBlock;
		chdir( WP_CONTENT_DIR );
		try {
			$fp = fopen( 'object-cache.php', 'w' );
			// @codingStandardsIgnoreStart
			// It's ok to write files.
			fwrite( $fp, $stub );
			// @codingStandardsIgnoreEnd
			fclose( $fp );
		} catch (Exception $e) {
			// TODO: more granular exception handling?
			return false;
		}
		return true;
	}

}

WP_CLI::add_command( 'lcache', 'WP_LCache_CLI' );
