/* global module, require */

module.exports = function( grunt ) {

	'use strict';

	var pkg = grunt.file.readJSON( 'package.json' );

	grunt.initConfig( {

		pkg: pkg,

		devUpdate: {
			check: {},
			update: {
				options: {
					updateType: 'force'
				}
			}
		},

		wp_readme_to_markdown: {
			options: {
				post_convert: function( readme ) {
					var matches = readme.match( /\*\*Tags:\*\*(.*)\r?\n/ ),
					    tags    = matches[1].trim().split( ', ' ),
					    section = matches[0];

					for ( var i = 0; i < tags.length; i++ ) {
						section = section.replace( tags[i], '[' + tags[i] + '](https://wordpress.org/plugins/tags/' + tags[i] + '/)' );
					}

					// Tag links
					readme = readme.replace( matches[0], section );

					// Badges
					readme = readme.replace( '## Description ##', grunt.template.process( pkg.badges.join( ' ' ) ) + "  \r\n\r\n## Description ##" );

					return readme;
				},
				screenshot_url: 'https://ps.w.org/{plugin}/assets/{screenshot}.png'
			},
			main: {
				files: {
					'readme.md': 'readme.txt'
				}
			}
		}

	} );

	require( 'matchdep' ).filterDev( 'grunt-*' ).forEach( grunt.loadNpmTasks );

	grunt.registerTask( 'dev-check',  [ 'devUpdate:check' ] );
	grunt.registerTask( 'dev-update', [ 'devUpdate:update' ] );
	grunt.registerTask( 'readme',     [ 'wp_readme_to_markdown' ] );

	grunt.util.linefeed = '\n';

};
