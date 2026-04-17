<?php
/**
 * Release smoke test — boots the plugin in a minimal WP stub and fires
 * `plugins_loaded` + `init`. Catches load-time fatals before any zip ships.
 *
 * The stubs live in wp-stubs.php so the Pro plugin's smoke test can reuse
 * them. See that file for the caveats.
 *
 * Usage:   php tools/smoke-test.php <path-to-plugin-main.php>
 * Exit:    0 OK, 1 fatal, 2 usage
 *
 * @package Jetonomy
 */

declare(strict_types=1);

$plugin_file = $argv[1] ?? null;
if ( null === $plugin_file || ! is_file( $plugin_file ) ) {
	fwrite( STDERR, "usage: php smoke-test.php /path/to/plugin-main.php\n" );
	exit( 2 );
}

// WP constants — caller-set before requiring the stubs. Each define() is
// guarded by defined() so this is safe to run multiple times in one process.
foreach ( array(
	'ABSPATH'             => dirname( $plugin_file ) . '/',
	'WPINC'               => 'wp-includes',
	'WP_CONTENT_DIR'      => sys_get_temp_dir(),
	'WP_DEBUG'            => false,
	'WP_DEBUG_LOG'        => false,
	'WP_DEBUG_DISPLAY'    => false,
	'DB_NAME'             => 'smoke_test_db',
	'DB_USER'             => 'root',
	'DB_PASSWORD'         => '',
	'DB_HOST'             => 'localhost',
	'DB_CHARSET'          => 'utf8mb4',
	'DB_COLLATE'          => '',
	'AUTH_KEY'            => 'smoke',
	'SECURE_AUTH_KEY'     => 'smoke',
	'LOGGED_IN_KEY'       => 'smoke',
	'NONCE_KEY'           => 'smoke',
	'AUTH_SALT'           => 'smoke',
	'SECURE_AUTH_SALT'    => 'smoke',
	'LOGGED_IN_SALT'      => 'smoke',
	'NONCE_SALT'          => 'smoke',
) as $k => $v ) {
	if ( ! defined( $k ) ) {
		define( $k, $v );
	}
}

// Seed options so Migrator::run() actually fires (this is the code path
// that fataled in 1.3.5).
$GLOBALS['__options'] = array( 'jetonomy_db_version' => '0.0.0' );

require __DIR__ . '/wp-stubs.php';

try {
	require $plugin_file;
} catch ( \Throwable $e ) {
	fwrite( STDERR, "\n[smoke-test] FATAL at plugin load: " . $e->getMessage() . "\n" );
	fwrite( STDERR, "[smoke-test] " . $e->getFile() . ':' . $e->getLine() . "\n" );
	exit( 1 );
}

try {
	do_action( 'plugins_loaded' );
	do_action( 'init' );
} catch ( \Throwable $e ) {
	fwrite( STDERR, "\n[smoke-test] FATAL during plugins_loaded/init: " . $e->getMessage() . "\n" );
	fwrite( STDERR, "[smoke-test] " . $e->getFile() . ':' . $e->getLine() . "\n" );
	exit( 1 );
}

// 1.3.5-specific regression guard: Jetonomy\table() must be callable here.
if ( function_exists( 'Jetonomy\\table' ) ) {
	try {
		$t = \Jetonomy\table( 'posts' );
		if ( ! is_string( $t ) || '' === $t ) {
			throw new \RuntimeException( 'Jetonomy\\table() returned empty/non-string' );
		}
	} catch ( \Throwable $e ) {
		fwrite( STDERR, "\n[smoke-test] Jetonomy\\table() check failed: " . $e->getMessage() . "\n" );
		exit( 1 );
	}
}

echo "[smoke-test] OK\n";
exit( 0 );
