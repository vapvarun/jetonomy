<?php
/**
 * Every shipped code file must be claimed by a smoke-runbook journey.
 *
 * check-runbook-fresh.php re-walks a journey when a file in its **Covers:**
 * changes, but a file no journey claims never triggers a walk. In 2.0.2 the
 * vote-denied fix shipped on the topic page and missed the topic-list and feed
 * cards: C.member.vote covered single-post.php and reply-card.php, nothing
 * covered feed-card.php, so QA found it instead of the release smoke.
 *
 * This gate lists every shipped PHP / JS / CSS file in Free and, when it sits
 * next to Free, Pro, and fails while any is not matched by a journey's
 * **Covers:** glob or by an exemption with a written reason in
 * docs/qa/journey-exempt.txt (`glob - reason`, Pro globs prefixed `pro:`).
 * Covers entries for Pro files use the same `pro:` prefix.
 *
 * Usage:
 *   php bin/check-journey-coverage.php           # exit 1 while any file is unclaimed
 *   php bin/check-journey-coverage.php --list    # also print every unclaimed file
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

$jt_root = dirname( __DIR__ );
$jt_pro  = realpath( $jt_root . '/../jetonomy-pro' );
$jt_list = in_array( '--list', $argv, true );
chdir( $jt_root );

/**
 * Split Covers / exemption globs into Free and Pro pathspecs.
 *
 * @param string[] $globs Raw globs, Pro ones prefixed `pro:`.
 * @return array{free: string[], pro: string[]}
 */
function jt_cov_specs( array $globs ): array {
	$out = array(
		'free' => array(),
		'pro'  => array(),
	);
	foreach ( $globs as $g ) {
		$g = trim( $g, " `\t" );
		if ( '' === $g ) {
			continue;
		}
		$side           = str_starts_with( $g, 'pro:' ) ? 'pro' : 'free';
		$out[ $side ][] = ':(glob)' . ( 'pro' === $side ? substr( $g, 4 ) : $g );
	}
	return $out;
}

/**
 * Tracked files in a repo matching pathspecs.
 *
 * @param string   $dir   Repo root.
 * @param string[] $specs Git pathspecs.
 * @return string[]
 */
function jt_cov_ls( string $dir, array $specs ): array {
	if ( ! $specs ) {
		return array();
	}
	$cmd = 'git -C ' . escapeshellarg( $dir ) . ' ls-files -- ' . implode( ' ', array_map( 'escapeshellarg', array_unique( $specs ) ) );
	return array_filter( explode( "\n", trim( (string) shell_exec( $cmd . ' 2>/dev/null' ) ) ) );
}

/**
 * Shipped code in a repo: our PHP, JS and CSS sources. Third-party libs,
 * generated .min / -rtl files and the dev-only includes/qa are excluded.
 *
 * @param string $dir Repo root.
 * @return string[]
 */
function jt_cov_shipped( string $dir ): array {
	$files = jt_cov_ls( $dir, array( ':(glob)includes/**', ':(glob)templates/**', ':(glob)assets/js/**', ':(glob)assets/css/**', ':(glob)blocks/**', ':(glob)*.php' ) );
	return array_values(
		array_filter(
			$files,
			static fn( $f ) => preg_match( '/\.(php|js|css)$/', $f )
				&& ! preg_match( '#(\.min\.(js|css)|-rtl\.css|(^|/)index\.php)$|^includes/qa/|/vendor/|^(phpstan-bootstrap|playwright\.config)\.#', $f )
		)
	);
}

// Covers globs from every journey, and exemption globs (reason required).
$jt_md = (string) file_get_contents( 'docs/qa/AGENT_SMOKE_RUNBOOK.md' );
preg_match_all( '/^\*\*Covers:\*\*\s*(.+)$/m', $jt_md, $jt_m );
$jt_globs = array();
foreach ( $jt_m[1] as $jt_line ) {
	$jt_globs = array_merge( $jt_globs, explode( ',', $jt_line ) );
}
$jt_bad_exempt = array();
$jt_exempt     = array();
foreach ( ( is_readable( 'docs/qa/journey-exempt.txt' ) ? file( 'docs/qa/journey-exempt.txt', FILE_IGNORE_NEW_LINES ) : array() ) as $jt_n => $jt_line ) {
	$jt_line = trim( $jt_line );
	if ( '' === $jt_line || str_starts_with( $jt_line, '#' ) ) {
		continue;
	}
	if ( ! preg_match( '/^(\S+)\s+-\s+\S/', $jt_line, $jt_e ) ) {
		$jt_bad_exempt[] = 'docs/qa/journey-exempt.txt:' . ( $jt_n + 1 ) . ' has no reason (format: `glob - reason`)';
		continue;
	}
	$jt_exempt[] = $jt_e[1];
}

$jt_claim  = jt_cov_specs( array_merge( $jt_globs, $jt_exempt ) );
$jt_repos  = array( 'free' => $jt_root ) + ( $jt_pro ? array( 'pro' => $jt_pro ) : array() );
$jt_report = array();
$jt_fail   = (bool) $jt_bad_exempt;
foreach ( $jt_repos as $jt_side => $jt_dir ) {
	$jt_all = jt_cov_shipped( $jt_dir );
	$jt_un  = array_values( array_diff( $jt_all, jt_cov_ls( $jt_dir, $jt_claim[ $jt_side ] ) ) );
	$jt_pct = $jt_all ? 100 * ( count( $jt_all ) - count( $jt_un ) ) / count( $jt_all ) : 100;
	printf( "journey-coverage %s: %d of %d shipped files claimed (%.0f%%)\n", $jt_side, count( $jt_all ) - count( $jt_un ), count( $jt_all ), floor( $jt_pct ) );
	if ( $jt_un ) {
		$jt_fail = true;
		$jt_dirs = array_count_values( array_map( static fn( $f ) => dirname( $f ), $jt_un ) );
		arsort( $jt_dirs );
		foreach ( $jt_dirs as $jt_d => $jt_c ) {
			$jt_report[] = sprintf( '  %-4s %3d unclaimed in %s/', $jt_side, $jt_c, $jt_d );
		}
		if ( $jt_list ) {
			foreach ( $jt_un as $jt_f ) {
				$jt_report[] = '       ' . ( 'pro' === $jt_side ? 'pro:' : '' ) . $jt_f;
			}
		}
	}
}

if ( $jt_fail ) {
	echo implode( "\n", array_merge( $jt_bad_exempt, $jt_report ) ), "\n";
	echo "\nAdd each file to the **Covers:** of the journey that walks it (Pro files as `pro:path`), write a new journey if none does, or exempt it in docs/qa/journey-exempt.txt with a reason. --list prints every file.\n";
	exit( 1 );
}
echo "journey-coverage: every shipped file is walked by a journey\n";
exit( 0 );
