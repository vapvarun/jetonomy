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

tests_add_filter( 'muplugins_loaded', function () {
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
} );

require $_tests_dir . '/includes/bootstrap.php';
