<?php
/**
 * Docs-consistency guard.
 *
 * The realtime adapter was never implemented, but docs kept re-advertising
 * one ("swap in a real-time layer", "extend real-time integrations") long
 * after the inventory said otherwise - three more surfaced on QA's fourth
 * pass of Basecamp 10114333907 AFTER a manual scrub claimed the trees were
 * clean. Manual sweeps demonstrably miss; this greps every customer-facing
 * markdown tree for feature claims the code does not back and fails the
 * build when one appears.
 *
 * Positive claims are matched; negations ("There is no realtime adapter",
 * "Real-time delivery is not adapter-based") are the documented truth and
 * are allowlisted by their negating phrases.
 *
 * Usage: php bin/audit-docs-consistency.php   (exit 0 clean, 1 offenders)
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$root  = dirname( __DIR__ );
$trees = array( 'docs/website', 'docs/architecture', 'docs/developer', 'readme.txt', 'ARCHITECTURE.md' );

// pattern => why it is a lie. Extend as new phantom features get caught.
$claims = array(
	'/real-?time (adapter|layer|integration|backend|seam)/i' => 'no realtime adapter exists (membership, search, email, AI only)',
	'/register_realtime/i'                                   => 'Adapter_Registry has no register_realtime()',
);

// A line matching any of these is DOCUMENTING the absence, not claiming the feature.
$negations = array(
	'no realtime',
	'no real-time',
	'not adapter-based',
	'never implemented',
	'that was never',
	'do not implement',
);

$offenders = array();

$scan = function ( string $file ) use ( $claims, $negations, $root, &$offenders ): void {
	$lines = file( $file );
	if ( false === $lines ) {
		return;
	}
	foreach ( $lines as $i => $line ) {
		foreach ( $claims as $pattern => $why ) {
			if ( ! preg_match( $pattern, $line ) ) {
				continue;
			}
			$lower = strtolower( $line );
			foreach ( $negations as $negation ) {
				if ( false !== strpos( $lower, $negation ) ) {
					continue 2;
				}
			}
			$offenders[] = sprintf( '%s:%d — %s [%s]', substr( $file, strlen( $root ) + 1 ), $i + 1, trim( $line ), $why );
		}
	}
};

foreach ( $trees as $tree ) {
	$path = $root . '/' . $tree;
	if ( is_file( $path ) ) {
		$scan( $path );
		continue;
	}
	if ( ! is_dir( $path ) ) {
		continue;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( in_array( strtolower( $file->getExtension() ), array( 'md', 'txt' ), true ) ) {
			$scan( $file->getPathname() );
		}
	}
}

if ( $offenders ) {
	fwrite( STDERR, "audit-docs-consistency: FAIL — docs claim features the code does not have:\n" );
	foreach ( $offenders as $offender ) {
		fwrite( STDERR, "  {$offender}\n" );
	}
	exit( 1 );
}

echo "audit-docs-consistency: OK (no phantom-feature claims)\n";
exit( 0 );
