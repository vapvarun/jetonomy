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
						src: [ '*.js', '!*.min.js' ],
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

		// Create zip
		compress: {
			dist: {
				options: {
					archive: 'dist/jetonomy.zip',
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

	// Build task: RTL + minify + pot
	grunt.registerTask( 'build', [ 'rtlcss', 'cssmin', 'uglify', 'makepot' ] );

	// Dist task: build + package zip
	grunt.registerTask( 'dist', [ 'build', 'clean:dist', 'copy:dist', 'compress:dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
