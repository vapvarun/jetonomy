<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "WordPress test library not found at {$_tests_dir}. Skipping integration tests.\n";
	define( 'JETONOMY_TESTING', true );
	define( 'ABSPATH', '/tmp/wordpress/' );
	define( 'JETONOMY_DIR', dirname( __DIR__ ) . '/' );
	return;
}

// PHPUnit Polyfills — required by WP test suite since WP 5.9.
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/' );

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/jetonomy.php';

		// Load Pro plugin if present so tests/pro/* runs against a real Pro
		// stack. Set JETONOMY_TEST_SKIP_PRO=1 to force "free standalone" mode
		// even when Pro is checked out — used by `composer test:free` and the
		// matching CI job so the free plugin's behavior is verified in
		// isolation without having to move directories around.
		if ( getenv( 'JETONOMY_TEST_SKIP_PRO' ) ) {
			return;
		}

		$pro_path = dirname( __DIR__, 2 ) . '/jetonomy-pro/jetonomy-pro.php';
		if ( file_exists( $pro_path ) ) {
			require $pro_path;
		}
	}
);

// `register_activation_hook` callbacks never fire under PHPUnit because no
// `activate_plugin()` event runs. Manually invoke the activation routine
// after WP finishes loading so dbDelta creates the `wptests_jt_*` tables
// (and Pro tables when present). Without this every integration test that
// touches a Jetonomy table fails with "Table doesn't exist".
tests_add_filter(
	'wp_loaded',
	function () {
		if ( class_exists( '\Jetonomy\Jetonomy' ) ) {
			\Jetonomy\Jetonomy::instance()->activate();
		}
		if ( ! getenv( 'JETONOMY_TEST_SKIP_PRO' ) && class_exists( '\Jetonomy_Pro\Jetonomy_Pro' ) ) {
			\Jetonomy_Pro\Jetonomy_Pro::instance()->activate();
		}
	},
	1
);

// Shared test fixtures / traits. There is no PSR-4 autoload for the
// `Jetonomy\Tests\*` namespace, and PHPUnit only loads *Test.php files, so
// any reusable trait or base class under a `Support/` dir must be required
// explicitly here before the test classes that `use` it are loaded.
// Two plain glob() calls rather than a GLOB_BRACE pattern — GLOB_BRACE is not
// defined on every PHP build (e.g. the Alpine PHP in the wp-env test container).
$_support_files = array_merge(
	glob( __DIR__ . '/unit/Support/*.php' ) ?: array(),
	glob( __DIR__ . '/pro/Support/*.php' ) ?: array()
);
foreach ( $_support_files as $_support_file ) {
	require_once $_support_file;
}

require $_tests_dir . '/includes/bootstrap.php';
