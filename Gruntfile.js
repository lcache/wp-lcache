/* global module, require */

module.exports = function( grunt ) {

	'use strict';

	var pkg = grunt.file.readJSON( 'package.json' );

	grunt.initConfig( {

		pkg: pkg,

		clean: {
			build: [ 'build/' ]
		},

		copy: {
			build: {
				files: [
					{
						expand: true,
						src: [
							'*.php',
							'composer.{json,lock}',
							'{license,readme}.txt',
							'vendor/**'
						],
						dest: 'build/'
					}
				]
			}
		},

		devUpdate: {
			packages: {
				options: {
					updateType: 'force'
				}
			}
		},

		phpcs: {
			options: {
				bin: 'vendor/bin/phpcs --extensions=php --ignore="*/vendor/*,*/lib/*,*/node_modules/*"',
				standard: 'phpcs.ruleset.xml'
			},
			main: {
				src: '*.php'
			}
		},

		replace: {
			php: {
				overwrite: true,
				replacements: [
					{
						from: /Version:(\s*?)[a-zA-Z0-9\.\-\+]+$/m,
						to: 'Version:$1' + pkg.version
					},
					{
						from: /@since(.*?)NEXT/mg,
						to: '@since$1' + pkg.version
					}
				],
				src: [ '*.php' ]
			},
			readme: {
				overwrite: true,
				replacements: [
					{
						from: /^(\*\*|)Stable tag:(\*\*|)(\s*?)[a-zA-Z0-9.-]+(\s*?)$/mi,
						to: '$1Stable tag:$2$3<%= pkg.version %>$4'
					}
				],
				src: 'readme.{md,txt}'
			}
		},

		wp_deploy: {
			plugin: {
				options: {
					build_dir: 'build/',
					plugin_main_file: pkg.name + '.php',
					plugin_slug: pkg.name,
					svn_user: grunt.file.exists( 'svn-username' ) ? grunt.file.read( 'svn-username' ).trim() : false
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
				screenshot_url: 'https://s.w.org/plugins/{plugin}/{screenshot}.png'
			},
			main: {
				files: {
					'readme.md': 'readme.txt'
				}
			}
		}

	} );

	require( 'matchdep' ).filterDev( 'grunt-*' ).forEach( grunt.loadNpmTasks );

	grunt.registerTask( 'build',   [ 'clean', 'copy' ] );
	grunt.registerTask( 'check',   [ 'devUpdate' ] );
	grunt.registerTask( 'deploy',  [ 'build', 'wp_deploy', 'clean' ] );
	grunt.registerTask( 'readme',  [ 'wp_readme_to_markdown' ] );
	grunt.registerTask( 'version', [ 'replace', 'readme' ] );

	grunt.util.linefeed = '\n';

};
