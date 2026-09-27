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
	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-uglify' );
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-compress' );

	// `grunt makepot`: languages/jetonomy.pot via WP-CLI `wp i18n make-pot`, which
	// extracts __()/_x()/_n() from JS (wp.i18n) as well as PHP. It replaced
	// grunt-wp-i18n, which scanned PHP only, so no JS string could ever reach a
	// translator or a languages/*.json file. WP-CLI is required: failing loudly
	// beats silently shipping a PHP-only POT. Never scan build/staging/vendor/test
	// trees: dist/ (zip staging) doubled every reference with phantom
	// dist/jetonomy/... lines, and libs/ are vendored bundles with their own text
	// domains (QA 10150516732).
	grunt.registerTask( 'makepot', 'Generate the .pot (PHP + JS) with wp i18n make-pot.', function() {
		var fs = require( 'fs' );
		var pot = 'languages/jetonomy.pot';
		var before = fs.existsSync( pot ) ? fs.readFileSync( pot, 'utf8' ) : '';
		var result = require( 'child_process' ).spawnSync( 'wp', [
			'i18n', 'make-pot', '.', pot,
			'--slug=jetonomy',
			'--domain=jetonomy',
			'--exclude=dist,vendor,node_modules,tests,libs,build,*.min.js',
			'--headers=' + JSON.stringify( {
				'Plural-Forms': 'nplurals=2; plural=(n != 1);',
				'X-Poedit-Country': 'United States',
				'X-Poedit-SourceCharset': 'UTF-8',
				'X-Poedit-KeywordsList': '__;_e;_x:1,2c;_ex:1,2c;_n:1,2;_nx:1,2,4c;_n_noop:1,2;_nx_noop:1,2,3c;esc_attr__;esc_html__;esc_attr_e;esc_html_e;esc_attr_x:1,2c;esc_html_x:1,2c;',
				'X-Poedit-Basepath': '../',
				'X-Poedit-SearchPath-0': '.',
				'X-Poedit-Bookmarks': '',
				'X-Textdomain-Support': 'yes',
			} ),
		], { stdio: 'inherit' } );
		if ( result.error || 0 !== result.status ) {
			grunt.fail.fatal( 'wp i18n make-pot failed' + ( result.error ? ' (' + result.error.message + ')' : '' ) + '. WP-CLI (https://wp-cli.org) must be on PATH to build the .pot: it is the only extractor here that reads JS strings.' );
		}
		// Idempotent builds: a rerun whose only change is POT-Creation-Date keeps the old file.
		var strip = function( s ) {
			return s.replace( /^"POT-Creation-Date: .*\n/m, '' );
		};
		if ( before && strip( before ) === strip( fs.readFileSync( pot, 'utf8' ) ) ) {
			fs.writeFileSync( pot, before );
		}
	} );

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
	// makepot scans source PHP + JS, so it runs before the minifiers touch assets.
	grunt.registerTask( 'build', [ 'makepot', 'rtlcss', 'cssmin', 'uglify' ] );

	// Dist task: CI check + build + package zip
	grunt.registerTask( 'dist', [ 'ci-check', 'build', 'clean:dist', 'copy:dist', 'compress:dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
