module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		// RTL CSS generation
		rtlcss: {
			dist: {
				files: [
					{
						expand: true,
						cwd: 'assets/css/',
						src: [ '*.css', '!*-rtl.css', '!*.min.css' ],
						dest: 'assets/css/',
						ext: '-rtl.css',
					},
				],
			},
		},

		// Generate .pot file
		makepot: {
			target: {
				options: {
					domainPath: 'languages/',
					potFilename: 'jetonomy.pot',
					type: 'wp-plugin',
					updateTimestamp: false,
					// Never scan build/staging/vendor/test trees. A leftover
					// dist/ (the zip-staging copy) otherwise doubles every
					// source reference with phantom dist/jetonomy/... lines.
					exclude: [ 'dist/.*', 'vendor/.*', 'node_modules/.*', 'tests/.*' ],
					potHeaders: {
						poedit: true,
						'x-poedit-keywordslist': true,
					},
				},
			},
		},

		// CSS minification
		cssmin: {
			dist: {
				files: [
					{
						expand: true,
						cwd: 'assets/css/',
						src: [ '*.css', '!*.min.css' ],
						dest: 'assets/css/',
						ext: '.min.css',
					},
				],
			},
		},

		// JS minification
		uglify: {
			dist: {
				options: {
					mangle: {
						reserved: [ 'jQuery' ],
					},
				},
				files: [
					{
						expand: true,
						cwd: 'assets/js/',
						src: [ '**/*.js', '!**/*.min.js' ],
						dest: 'assets/js/',
						ext: '.min.js',
					},
				],
			},
		},

		// Clean dist folder
		clean: {
			dist: [ 'dist/' ],
		},

		// Copy files to dist (excluding .distignore entries)
		copy: {
			dist: {
				files: [
					{
						expand: true,
						src: [
							'**',
							'!.git/**',
							'!.gitignore',
							'!.distignore',
							'!.github/**',
							'!node_modules/**',
							'!tests/**',
							'!docs/**',
							'!plans/**',
							'!bin/**',
							'!dist/**',
							'!phpunit.xml.dist',
							'!phpunit.xml',
							'!phpstan.neon.dist',
							'!phpstan-baseline.neon',
							'!phpstan-pro.neon.dist',
							'!phpstan-baseline-pro.neon',
							'!phpcs.xml',
							'!package.json',
							'!package-lock.json',
							'!composer.json',
							'!composer.lock',
							'!Gruntfile.js',
							'!CLAUDE.md',
							'!seed-*.php',
							'!**/*.md',
							'!vendor/**',
							'!marketing/**',
							'!.playwright-mcp/**',
						],
						dest: 'dist/jetonomy/',
					},
				],
			},
		},

		// Create zip (version from package.json)
		compress: {
			dist: {
				options: {
					archive: 'dist/jetonomy-<%= pkg.version %>.zip',
					mode: 'zip',
				},
				files: [
					{
						expand: true,
						cwd: 'dist/',
						src: [ 'jetonomy/**' ],
					},
				],
			},
		},
	} );

	// Load plugins
	grunt.loadNpmTasks( 'grunt-rtlcss' );
	grunt.loadNpmTasks( 'grunt-wp-i18n' );
	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-uglify' );
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-compress' );

	// Registers `grunt i18n`: sync new strings (msgmerge) -> AI-translate ->
	// compile .mo + .json, per .wbcom-i18n.json. Run before a release to refresh
	// locale translations, then commit the .po/.mo. Standalone (not in `build`)
	// so day-to-day builds don't re-translate. See @wbcom/i18n-ai.
	require( '@wbcom/i18n-ai/grunt' )( grunt );

	// CI gate: abort if the latest GitHub Actions run is not passing.
	grunt.registerTask( 'ci-check', 'Verify GitHub Actions CI is green before release.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;

		grunt.log.writeln( 'Checking GitHub Actions status...' );

		execFile( 'gh', [ 'run', 'list', '--branch', 'main', '--limit', '1', '--json', 'status,conclusion,name', '--jq', '.[0]' ], function( err, stdout ) {
			if ( err ) {
				grunt.log.error( 'Could not check CI. Is `gh` CLI installed and authenticated?' );
				grunt.log.error( err.message );
				done( false );
				return;
			}

			var run;
			try {
				run = JSON.parse( stdout.trim() );
			} catch ( e ) {
				grunt.log.error( 'No CI runs found. Push to main first.' );
				done( false );
				return;
			}

			if ( run.status === 'in_progress' || run.status === 'queued' ) {
				grunt.log.error( 'CI is still running (' + run.name + '). Wait for it to finish.' );
				done( false );
				return;
			}

			if ( run.conclusion !== 'success' ) {
				grunt.log.error( 'CI failed (' + run.name + ' → ' + run.conclusion + '). Fix before releasing.' );
				done( false );
				return;
			}

			grunt.log.ok( 'CI passed (' + run.name + ' → ' + run.conclusion + ')' );
			done();
		} );
	} );

	// Build task: pot first, then RTL + minify.
	// makepot scans source PHP, so it runs before the minifiers touch assets.
	grunt.registerTask( 'build', [ 'makepot', 'rtlcss', 'cssmin', 'uglify' ] );

	// Dist task: CI check + build + package zip
	grunt.registerTask( 'dist', [ 'ci-check', 'build', 'clean:dist', 'copy:dist', 'compress:dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
