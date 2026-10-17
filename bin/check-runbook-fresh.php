<?php
/**
 * Every smoke-runbook journey must describe the code as it is now.
 *
 * The release smoke walks docs/qa/AGENT_SMOKE_RUNBOOK.md. When a journey's
 * code changes and its text does not, the walk keeps "passing" against
 * behaviour that no longer exists: in 2.0.2 the invite flow gained a Join
 * button, and C.admin.invite-links still promised the link lands straight in
 * the space. Nothing linked the two.
 *
 * Each journey (### heading) carries two lines:
 *
 *   **Covers:** includes/models/class-invite-link.php, templates/views/invite.php
 *   **Verified:** 1ffc1fa6
 *
 * Covers lists the files (git pathspec globs) the journey exercises; Pro files
 * carry a `pro:` prefix and are diffed in ../jetonomy-pro. Verified is the
 * commit the journey was last WALKED in the browser and passed at, plus
 * `pro:<sha>` when it covers Pro files:
 *
 *   **Verified:** 1ffc1fa6 pro:1d52a95
 *
 * If any covered file has changed since, the journey is stale: walk it again
 * against the current code, correct its text if the behaviour changed, and set
 * Verified to the commits it passed at. bin/check-journey-coverage.php makes
 * sure every shipped file sits in some journey's Covers, so no change escapes.
 *
 * Usage:
 *   php bin/check-runbook-fresh.php           # exit 1 on stale or unmapped journeys
 *   php bin/check-runbook-fresh.php --list    # every journey and its state
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

$jt_root = dirname( __DIR__ );
chdir( $jt_root );
$jt_list = in_array( '--list', $argv, true );
$jt_file = 'docs/qa/AGENT_SMOKE_RUNBOOK.md';
$jt_md   = (string) file_get_contents( $jt_file );

// Split into ### sections.
$jt_parts = preg_split( '/^### /m', $jt_md );
array_shift( $jt_parts );

/**
 * Files matching globs that changed in a repo since a commit.
 *
 * @param string   $dir   Repo root.
 * @param string   $since Commit.
 * @param string[] $globs Git glob pathspecs.
 * @return string[]
 */
function jt_changed( string $dir, string $since, array $globs ): array {
	if ( ! $globs ) {
		return array();
	}
	$cmd = 'git -C ' . escapeshellarg( $dir ) . ' diff --name-only ' . escapeshellarg( $since ) . ' HEAD -- ' . implode( ' ', array_map( static fn( $g ) => escapeshellarg( ':(glob)' . $g ), $globs ) );
	return array_filter( explode( "\n", trim( (string) shell_exec( $cmd . ' 2>/dev/null' ) ) ) );
}

$jt_problems = array();
$jt_fresh    = 0;
foreach ( $jt_parts as $jt_part ) {
	$jt_id = trim( strtok( $jt_part, "\n" ) );
	if ( ! preg_match( '/^\*\*Covers:\*\*\s*(.+)$/m', $jt_part, $jt_c ) ) {
		$jt_problems[] = "{$jt_id}: no **Covers:** line, so nothing can tell when it goes stale";
		continue;
	}
	if ( ! preg_match( '/^\*\*Verified:\*\*\s*([0-9a-f]{7,40})\b/m', $jt_part, $jt_v ) ) {
		$jt_problems[] = "{$jt_id}: not walked yet (no **Verified:** commit)";
		continue;
	}
	exec( 'git rev-parse --verify --quiet ' . escapeshellarg( $jt_v[1] . '^{commit}' ), $jt_out, $jt_code );
	if ( 0 !== $jt_code ) {
		$jt_problems[] = "{$jt_id}: Verified commit {$jt_v[1]} is not in this repository's history";
		continue;
	}
	$jt_pro_root = realpath( $jt_root . '/../jetonomy-pro' );
	$jt_globs    = array_filter( array_map( static fn( $g ) => trim( $g, " `\t" ), explode( ',', $jt_c[1] ) ) );
	$jt_free     = array_filter( $jt_globs, static fn( $g ) => ! str_starts_with( $g, 'pro:' ) );
	$jt_progl    = array_map( static fn( $g ) => substr( $g, 4 ), array_diff( $jt_globs, $jt_free ) );
	$jt_diff     = jt_changed( '.', $jt_v[1], $jt_free );
	if ( $jt_progl && $jt_pro_root ) {
		if ( ! preg_match( '/^\*\*Verified:\*\*.*\bpro:([0-9a-f]{7,40})\b/m', $jt_part, $jt_pv ) ) {
			$jt_problems[] = "{$jt_id}: covers Pro files but Verified has no pro:<commit>";
			continue;
		}
		$jt_diff = array_merge( $jt_diff, array_map( static fn( $f ) => 'pro:' . $f, jt_changed( $jt_pro_root, $jt_pv[1], $jt_progl ) ) );
	}
	if ( $jt_diff ) {
		$jt_problems[] = sprintf( '%s: stale, changed since its last walk: %s', $jt_id, implode( ', ', array_slice( $jt_diff, 0, 6 ) ) . ( count( $jt_diff ) > 6 ? ', ...' : '' ) );
	} else {
		++$jt_fresh;
		if ( $jt_list ) {
			echo "  fresh  {$jt_id}\n";
		}
	}
}

require_once __DIR__ . '/release-exception.php';
$jt_exception = jetonomy_release_exception( $jt_root );
if ( $jt_problems && $jt_exception ) {
	echo implode( "\n", $jt_problems ), "\n";
	printf( "\nrunbook-fresh: WAIVED for %s by owner exception (%s, %s): %s\n%d journey(s) not re-walked; walked instead: %s\n", $jt_exception['version'], $jt_exception['by'], $jt_exception['approved'], $jt_exception['reason'], count( $jt_problems ), implode( ', ', (array) ( $jt_exception['walked'] ?? array() ) ) );
	exit( 0 );
}
if ( $jt_problems ) {
	echo implode( "\n", $jt_problems ), "\n";
	printf( "\nrunbook-fresh: %d of %d journey(s) need a walk. Walk each in the browser against the current code, fix its text if the behaviour changed, then set **Verified:** to the commits it passed at (`git rev-parse --short HEAD`, plus `pro:<sha>` for Pro files).\n", count( $jt_problems ), count( $jt_parts ) );
	exit( 1 );
}
printf( "runbook-fresh: all %d journeys verified against the current code\n", $jt_fresh );
exit( 0 );
