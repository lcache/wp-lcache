<?php

spl_autoload_register( function( $class ) {
	$class = ltrim( $class, '\\' );
	if ( 0 !== stripos( $class, 'LCache\\' ) ) {
		return;
	}

	$parts = explode( '\\', $class );
	 // Don't need "LCache"
	array_shift( $parts );
	$last = array_pop( $parts ); // File should be '[...].php'
	$last = $last . '.php';
	$file = dirname( __FILE__ ) . '/src/' . $last;
	if ( file_exists( $file ) ) {
		require $file;
	}

});
