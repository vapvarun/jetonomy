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
 * Covers lists the files (git pathspec globs) the journey exercises. Verified
 * is the commit at which someone last confirmed the journey's text against
 * that code. If any covered file has changed since, the journey is stale:
 * re-read it against the diff, correct the text, and set Verified to the
 * current commit (`git rev-parse --short HEAD`).
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

$jt_problems = array();
$jt_fresh    = 0;
foreach ( $jt_parts as $jt_part ) {
	$jt_id = trim( strtok( $jt_part, "\n" ) );
	if ( ! preg_match( '/^\*\*Covers:\*\*\s*(.+)$/m', $jt_part, $jt_c ) ) {
		$jt_problems[] = "{$jt_id}: no **Covers:** line, so nothing can tell when it goes stale";
		continue;
	}
	if ( ! preg_match( '/^\*\*Verified:\*\*\s*([0-9a-f]{7,40})\b/m', $jt_part, $jt_v ) ) {
		$jt_problems[] = "{$jt_id}: no **Verified:** commit";
		continue;
	}
	exec( 'git rev-parse --verify --quiet ' . escapeshellarg( $jt_v[1] . '^{commit}' ), $jt_out, $jt_code );
	if ( 0 !== $jt_code ) {
		$jt_problems[] = "{$jt_id}: Verified commit {$jt_v[1]} is not in this repository's history";
		continue;
	}
	$jt_specs = array_map( static fn( $g ) => ':(glob)' . trim( $g, " `\t" ), array_filter( array_map( 'trim', explode( ',', $jt_c[1] ) ) ) );
	$jt_cmd   = 'git diff --name-only ' . escapeshellarg( $jt_v[1] ) . ' HEAD -- ' . implode( ' ', array_map( 'escapeshellarg', $jt_specs ) );
	$jt_diff  = array_filter( explode( "\n", trim( (string) shell_exec( $jt_cmd . ' 2>/dev/null' ) ) ) );
	if ( $jt_diff ) {
		$jt_problems[] = sprintf( '%s: stale since %s; changed: %s', $jt_id, $jt_v[1], implode( ', ', array_slice( $jt_diff, 0, 6 ) ) . ( count( $jt_diff ) > 6 ? ', ...' : '' ) );
	} else {
		++$jt_fresh;
		if ( $jt_list ) {
			echo "  fresh  {$jt_id}\n";
		}
	}
}

if ( $jt_problems ) {
	echo implode( "\n", $jt_problems ), "\n";
	printf( "\nrunbook-fresh: %d of %d journey(s) need review. Re-read each against the diff, fix its text, then set **Verified:** to `git rev-parse --short HEAD`.\n", count( $jt_problems ), count( $jt_parts ) );
	exit( 1 );
}
printf( "runbook-fresh: all %d journeys verified against the current code\n", $jt_fresh );
exit( 0 );
