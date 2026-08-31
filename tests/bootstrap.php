<?php
/*
 * Query logging, so the N+1 guards actually run.
 *
 * Without this, every "rendering N rows must issue zero extra queries" test
 * markTestSkipped()s — which reads green while protecting nothing. The
 * attachment batch-prime test was skipped for exactly that reason, and the
 * big-site rule (no per-row queries in a list) is precisely what it exists to
 * defend.
 */
if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}

// Registered in phpunit.xml.dist <extensions>; must be loadable before the
// runner instantiates it, which is earlier than the WP test lib bootstraps.
require_once __DIR__ . '/class-memo-reset-extension.php';

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

/*
 * Run the suite at READ-COMMITTED.
 *
 * WP_UnitTestCase wraps every test in a transaction it rolls back, so under
 * MySQL's default REPEATABLE-READ the suite holds gap locks (a next-key lock
 * covers the range around each indexed row, not just the row) for the whole of
 * each test. That is what produced this suite's long-standing intermittent
 * failures: 4-7 "Deadlock found when trying to get lock" errors per run,
 * overwhelmingly on wptests_usermeta capability writes, each one killing an
 * unrelated test whose fixture happened to be mid-write. The failures moved
 * between runs, which reads as flaky product code and is not.
 *
 * Setting it here rather than on the server keeps the fix with the suite: it
 * travels with the repo instead of depending on how somebody's MySQL container
 * happened to be started. Measured on this machine: 4-7 deadlocks per run
 * before, 1-3 after.
 *
 * READ-COMMITTED is safe for this suite - the tests assert against their own
 * writes within a single connection, and nothing here depends on
 * repeatable-read semantics across statements.
 */
global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	$wpdb->query( "SET SESSION transaction_isolation = 'READ-COMMITTED'" );
}
