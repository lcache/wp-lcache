<?php

#####################
# This plugin is placed in mu-plugins by the testing scripts. Once in mu-plugins
# it will be loaded on all requests to WordPress and add Lcache debug information
# to to the footer of the site when a GET param is present. Behat can then
# examine this debug output.
#####################

add_action( 'wp_footer', function(){
	if ( isset( $_GET['lcache_debug'] ) ) {
		$GLOBALS['wp_object_cache']->stats();
	}
});
