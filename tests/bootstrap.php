<?php
$_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "WordPress test library not found at {$_tests_dir}. Skipping integration tests.\n";
    define( 'JETONOMY_TESTING', true );
    define( 'ABSPATH', '/tmp/wordpress/' );
    define( 'JETONOMY_DIR', dirname( __DIR__ ) . '/' );
    return;
}

require_once $_tests_dir . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', function () {
    require dirname( __DIR__ ) . '/jetonomy.php';
} );

require $_tests_dir . '/includes/bootstrap.php';
