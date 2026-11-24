<?php
/**
 * Never-fatal gate: setAccessible() and calls into optional PHP extensions.
 *
 * 1. setAccessible( anywhere in includes/ or tests/ fails: a no-op since PHP 8.1
 *    and deprecated in 8.5 (reflection reaches private members without it).
 * 2. Extension calls: the family gate bin/lib/extension-guard-check.php, an
 *    identical copy of MediaVerse's (change the canonical file and copy it into
 *    each plugin; `diff` them). Its self-test runs first, then it scans the
 *    plugin's top-level PHP files, includes/, templates/ and src/. Blind spots:
 *    calls through a variable or a string ($f(), call_user_func( 'mb_...' ))
 *    cannot be caught. Not scanned on purpose: libs/, vendor/ (third-party,
 *    fixed upstream), bin/, tools/, tests/ (developer tooling), build/, dist/.
 *
 * Usage: php bin/check-never-fatal.php [plugin-dir]   (default: this plugin)
 *
 * @package Jetonomy
 */

$root  = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$lib   = __DIR__ . '/lib/extension-guard-check';
$found = array();

foreach ( array( 'includes', 'tests' ) as $dir ) {
	if ( ! is_dir( "$root/$dir" ) ) {
		continue;
	}
	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) ) as $f ) {
		if ( 'php' !== $f->getExtension() ) {
			continue;
		}
		foreach ( file( $f->getPathname() ) as $n => $line ) {
			if ( preg_match( '/setaccessible\s*\(/i', $line ) ) { // Any spacing or case: `setAccessible (true)` is the same call.
				$found[] = substr( $f->getPathname(), strlen( $root ) + 1 ) . ':' . ( $n + 1 ) . ' setAccessible() (no-op since PHP 8.1, deprecated in 8.5)';
			}
		}
	}
}

$run = static function ( string $cmd ) use ( &$found ): void {
	exec( $cmd . ' 2>&1', $out, $code ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- dev CLI gate.
	if ( 0 !== $code ) {
		$found = array_merge( $found, $out ? $out : array( "failed: $cmd" ) );
	}
};
$run( 'php ' . escapeshellarg( "$lib.test.php" ) );
$run( 'php ' . escapeshellarg( "$lib.php" ) . ' ' . escapeshellarg( $root ) );

if ( $found ) {
	fwrite( STDERR, implode( "\n", $found ) . "\n\nnever-fatal: " . count( $found ) . " problem(s).\n" );
	exit( 1 );
}
echo "never-fatal: OK\n";
