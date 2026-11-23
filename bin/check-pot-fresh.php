<?php
/**
 * Fail when the shipped languages/<slug>.pot misses a msgid the code has, or keeps one it no longer has.
 *
 * Usage: php bin/check-pot-fresh.php [plugin-dir]   (default: this plugin). Needs WP-CLI (`wp i18n make-pot`).
 * Fix:   npx grunt makepot (in the plugin dir), then commit the .pot.
 *
 * @package Jetonomy
 */

$dir  = rtrim( $argv[1] ?? dirname( __DIR__ ), '/' );
$slug = basename( $dir );
$pot  = "{$dir}/languages/{$slug}.pot";
if ( ! is_file( $pot ) ) {
	fwrite( STDERR, "FAIL: {$pot} is missing\n" );
	exit( 1 );
}
$fresh = tempnam( sys_get_temp_dir(), 'pot' );
// Same extraction as the Gruntfile's makepot task.
$cmd = sprintf(
	'wp i18n make-pot %s %s --slug=%s --domain=%s --exclude=dist,vendor,node_modules,tests,libs,build,*.min.js --skip-audit 2>&1',
	escapeshellarg( $dir ),
	escapeshellarg( $fresh ),
	escapeshellarg( $slug ),
	escapeshellarg( $slug )
);
exec( $cmd, $out, $code );
if ( 0 !== $code ) {
	fwrite( STDERR, "FAIL: wp i18n make-pot failed:\n" . implode( "\n", $out ) . "\n" );
	exit( 1 );
}

/** Msgid keys (context + msgid) of a .pot file. */
function pot_ids( string $file ): array {
	preg_match_all( '/^(?:msgctxt ("(?:[^"\\\\]|\\\\.)*")\n)?msgid ((?:"(?:[^"\\\\]|\\\\.)*"\n?)+)/m', (string) file_get_contents( $file ), $m, PREG_SET_ORDER );
	$ids = array();
	foreach ( $m as $row ) {
		$id = preg_replace( '/"\s*\n\s*"/', '', trim( $row[2] ) );
		if ( '""' !== $id ) {
			$ids[ ( $row[1] ?? '' ) . '|' . $id ] = true;
		}
	}
	return $ids;
}

$shipped = pot_ids( $pot );
$code_ids = pot_ids( $fresh );
unlink( $fresh );
$missing = array_diff_key( $code_ids, $shipped );
$stale   = array_diff_key( $shipped, $code_ids );
if ( $missing || $stale ) {
	fwrite( STDERR, sprintf( "FAIL: %s is stale: %d msgids missing, %d no longer in the code (run npx grunt makepot)\n", basename( $pot ), count( $missing ), count( $stale ) ) );
	foreach ( array_slice( array_keys( $missing ), 0, 10 ) as $id ) {
		fwrite( STDERR, "  missing {$id}\n" );
	}
	foreach ( array_slice( array_keys( $stale ), 0, 10 ) as $id ) {
		fwrite( STDERR, "  stale   {$id}\n" );
	}
	exit( 1 );
}
printf( "OK %s: %d msgids match the code\n", basename( $pot ), count( $shipped ) );
