<?php
/**
 * Prove that our regression guards can actually fail.
 *
 * WHY THIS EXISTS
 * ---------------
 * A test suite reports what it ran, not what it proved. Three separate guards
 * shipped in 1.9.5 that could not fail on the bug they were written for:
 *
 *   R1  required the class file itself, then asserted the class existed.
 *   R2  regex-matched the stored database row, while the bug corrupted the
 *       value on the way OUT of the code, not on the way in.
 *   SM1 (as first drafted) asserted a 200, and the broken state returned 200.
 *
 * All three were green against the exact defect they guarded. That is worse
 * than having no guard: the bug then looks tested, and nobody revisits it.
 *
 * The only thing that distinguishes a guard from a comment that returns true
 * is watching it go red. This script does that mechanically: for each entry in
 * the registry it reintroduces the original bug, runs `wp jetonomy qa-actions`,
 * and requires that the named guard FAILS. A guard that stays green is
 * reported as a defect in the guard.
 *
 * USAGE
 *   php bin/check-guards-can-fail.php [--wp-path=/path/to/wp] [--only=DC1,SM2]
 *
 * Every mutation is reverted in a shutdown handler, so an interrupted run does
 * not leave the working tree modified. Run it against a dev site, never prod:
 * it edits plugin files in place for a few seconds at a time.
 *
 * @package Jetonomy
 */

declare( strict_types = 1 );

// ---------------------------------------------------------------------------
// Registry: one entry per guard. `find` must match exactly once.
// Adding a guard without adding its mutation here is how the rot starts again.
// ---------------------------------------------------------------------------
$plugin_dir = dirname( __DIR__ );
$pro_dir    = dirname( $plugin_dir ) . '/jetonomy-pro';

$mutations = [
	[
		'guard' => 'DC1',
		'what'  => 'Post::delete stops firing jetonomy_after_delete_post',
		'file'  => $plugin_dir . '/includes/models/class-post.php',
		'find'  => "do_action( 'jetonomy_after_delete_post', \$id );",
		'to'    => '/* guard-mutation */',
	],
	[
		'guard' => 'DC2',
		'what'  => 'trashing also fires the hard-delete hook',
		'file'  => $plugin_dir . '/includes/models/class-post.php',
		'find'  => "\tpublic static function update( int \$id, array \$data ): bool {\n\t\t\$data = self::sanitize_content_fields( \$data );",
		'to'    => "\tpublic static function update( int \$id, array \$data ): bool {\n\t\tdo_action( 'jetonomy_after_delete_post', \$id );\n\t\t\$data = self::sanitize_content_fields( \$data );",
	],
	[
		'guard' => 'DC3',
		'what'  => 'Reply::delete stops firing jetonomy_after_delete_reply',
		'file'  => $plugin_dir . '/includes/models/class-reply.php',
		'find'  => "do_action( 'jetonomy_after_delete_reply', \$id );",
		'to'    => '/* guard-mutation */',
	],
	[
		'guard' => 'SR2',
		'what'  => 'shortcode notices leak to logged-out visitors',
		'file'  => $plugin_dir . '/includes/class-shortcodes.php',
		'find'  => "\t\tif ( ! current_user_can( 'edit_posts' ) ) {\n\t\t\treturn '';\n\t\t}",
		'to'    => "\t\t/* guard-mutation */",
	],
	[
		'guard' => 'SM1',
		'what'  => 'sitemap serves HTML instead of XML (the 200-not-404 shape)',
		'file'  => $plugin_dir . '/includes/seo/class-sitemap-emitter.php',
		'find'  => "header( 'Content-Type: application/xml; charset=UTF-8' );",
		'to'    => "header( 'Content-Type: text/html; charset=UTF-8' );",
	],
	[
		'guard' => 'SM2',
		'what'  => 'sitemap claim demoted to priority 10, losing to AIOSEO',
		'file'  => $plugin_dir . '/includes/class-router.php',
		'find'  => "add_action( 'parse_request', [ \$this, 'claim_sitemap_request' ], 0 );",
		'to'    => "add_action( 'parse_request', [ \$this, 'claim_sitemap_request' ], 10 );",
	],
	[
		'guard' => 'R1',
		'what'  => 'nothing in the product requires Spam_Detector',
		'file'  => $pro_dir . '/includes/cli/journeys/class-ai-journey.php',
		'find'  => "\t\t\$detector_file = \\JETONOMY_PRO_DIR . 'includes/extensions/ai/class-spam-detector.php';\n\t\tif ( is_readable( \$detector_file ) ) {\n\t\t\trequire_once \$detector_file;\n\t\t}",
		'to'    => "\t\t/* guard-mutation */",
	],
	[
		'guard' => 'R2',
		'what'  => 'digest timestamp truncated back to the year',
		'file'  => $pro_dir . '/includes/cli/journeys/class-email-digest-journey.php',
		'find'  => "\$ts = strtotime( \$latest . ' UTC' );\n\t\treturn ( false !== \$ts && \$ts > 0 ) ? \$ts : null;",
		'to'    => "\$ts = (int) \$latest;\n\t\treturn \$ts > 0 ? \$ts : null;",
	],
];

// ---------------------------------------------------------------------------

$opts     = getopt( '', [ 'wp-path::', 'only::' ] );
$wp_path  = $opts['wp-path'] ?? dirname( $plugin_dir, 3 );
$only     = isset( $opts['only'] ) ? array_map( 'trim', explode( ',', (string) $opts['only'] ) ) : [];
$restore  = [];

// Revert on ANY exit path, including a fatal or Ctrl-C.
register_shutdown_function(
	static function () use ( &$restore ) {
		foreach ( $restore as $file => $original ) {
			file_put_contents( $file, $original );
		}
	}
);

echo "== guards must be able to fail ==\n\n";

$bad = [];
$ok  = [];

foreach ( $mutations as $m ) {
	if ( $only && ! in_array( $m['guard'], $only, true ) ) {
		continue;
	}

	if ( ! is_file( $m['file'] ) ) {
		printf( "  %-5s SKIP           %s not present\n", $m['guard'], basename( $m['file'] ) );
		continue;
	}

	$source = (string) file_get_contents( $m['file'] );
	$hits   = substr_count( $source, $m['find'] );

	if ( 1 !== $hits ) {
		// The code moved. That is a real problem: the mutation no longer
		// reintroduces the bug, so this guard is unverified from here on.
		printf( "  %-5s STALE          mutation target matched %d times, expected 1\n", $m['guard'], $hits );
		$bad[] = $m['guard'] . ' (stale mutation)';
		continue;
	}

	$restore[ $m['file'] ] = $source;
	file_put_contents( $m['file'], str_replace( $m['find'], $m['to'], $source ) );

	$lint = 0;
	exec( 'php -l ' . escapeshellarg( $m['file'] ) . ' 2>&1', $lint_out, $lint );

	$out  = [];
	$code = 0;
	if ( 0 === $lint ) {
		exec(
			'cd ' . escapeshellarg( $wp_path ) . ' && wp jetonomy qa-actions 2>&1',
			$out,
			$code
		);
	}

	file_put_contents( $m['file'], $source );
	unset( $restore[ $m['file'] ] );

	$joined = implode( "\n", $out );
	$failed = (bool) preg_match( '/FAIL\s+' . preg_quote( $m['guard'], '/' ) . ':/', $joined );

	if ( 0 !== $lint ) {
		printf( "  %-5s INVALID        mutation broke syntax, cannot conclude\n", $m['guard'] );
		$bad[] = $m['guard'] . ' (mutation invalid)';
	} elseif ( $failed ) {
		printf( "  %-5s ok             went red on: %s\n", $m['guard'], $m['what'] );
		$ok[] = $m['guard'];
	} else {
		printf( "  %-5s CANNOT FAIL    stayed green with: %s\n", $m['guard'], $m['what'] );
		$bad[] = $m['guard'];
	}
}

echo "\n";
printf( "  %d guard(s) proven, %d defective\n", count( $ok ), count( $bad ) );

if ( $bad ) {
	echo "\nThese guards do not test what they claim:\n";
	foreach ( $bad as $g ) {
		echo "  - {$g}\n";
	}
	echo "\nA guard that cannot fail is worse than no guard: the bug looks tested.\n";
	exit( 1 );
}

echo "  every registered guard fails when its bug returns.\n";
exit( 0 );
