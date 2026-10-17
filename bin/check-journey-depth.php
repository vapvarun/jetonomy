<?php
/**
 * Every journey is a checklist deep enough to catch what QA catches, and every
 * stamp is backed by a record of each check passing.
 *
 * A journey's "What to verify" paragraph said what to look at, but nothing
 * said how deep: walks were stamped while listing "not tested" items inside
 * them (vote without the daily limit, flags without spam or silence), and QA
 * then found bugs in exactly those gaps. So each journey also carries a
 * **Checks:** list of single, testable lines:
 *
 *   - `vote.4` @member #ui +limit card:10403154422: At the daily vote limit, take a vote back -> it is removed and says "Vote removed".
 *
 *   `id`      unique across the runbook, prefixed by the journey's short name
 *   @role     who does it: a qa-config persona, or guest (one or more)
 *   #surface  where: ui, admin, rest, cli, email, block (one or more)
 *   +state    the condition under test (+closed, +long, +private, +empty...)
 *   !refuse   the action must be refused, with the reason shown
 *   card:<id> the Basecamp bug this check exists to catch again
 *
 * Rules (exit 1 on any breach):
 *   1. Every journey has checks; every check has an id, a role and a surface;
 *      ids are unique.
 *   2. Every C.* and E.* journey has a !refuse check and walks at least two
 *      roles, unless it carries a "**Depth note:**" line saying why not.
 *   3. Every escape in audit/escapes.json has a check tagged card:<id>: an
 *      escaped bug also deepens the journey that should have caught it.
 *   4. A journey's **Verified:** stamp is backed by docs/qa/walks/<id>.json
 *      recorded at the same commits, with every check passed and no proposed
 *      check left unmerged into the runbook.
 *
 * The walk record is the self-healing loop: a walker that meets a state no
 * check covers writes it under "proposed_checks", and the journey cannot be
 * stamped until that check is in the runbook and has been walked.
 *
 * Usage:
 *   php bin/check-journey-depth.php [--list]
 *   php bin/check-journey-depth.php --ready <journey-id> <free-sha> [<pro-sha>]
 *       exit 0 only when that journey's walk record is at those commits with
 *       every check passed (bin/self-qa.sh --stamp asks this before stamping)
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

$jd_root = dirname( __DIR__ );
chdir( $jd_root );
$jd_list  = in_array( '--list', $argv, true );
$jd_ready = '--ready' === ( $argv[1] ?? '' ) ? array( (string) ( $argv[2] ?? '' ), (string) ( $argv[3] ?? '' ), (string) ( $argv[4] ?? '' ) ) : null;
$jd_md   = (string) file_get_contents( 'docs/qa/AGENT_SMOKE_RUNBOOK.md' );
$jd_cfg  = json_decode( (string) file_get_contents( 'docs/qa/qa-config.json' ), true );
$jd_cfg  = is_array( $jd_cfg ) ? $jd_cfg : array();

$jd_roles    = array_merge( array( 'guest' ), array_keys( (array) ( $jd_cfg['personas'] ?? array() ) ), (array) ( $jd_cfg['journey_depth']['extra_roles'] ?? array() ) );
$jd_surfaces = (array) ( $jd_cfg['journey_depth']['surfaces'] ?? array( 'ui', 'admin', 'rest', 'cli', 'email', 'block' ) );

/**
 * Why a journey's walk record does not back a stamp at these commits.
 *
 * @param string   $id     Journey id.
 * @param string[] $checks Check ids the runbook lists for it.
 * @param string   $free   Free commit the stamp names.
 * @param string   $pro    Pro commit the stamp names, or ''.
 * @return string[] Problems; empty when the record backs the stamp.
 */
function jd_record_problems( string $id, array $checks, string $free, string $pro ): array {
	$same = static fn( string $a, string $b ): bool => '' !== $a && '' !== $b && ( str_starts_with( $a, $b ) || str_starts_with( $b, $a ) );
	$file = 'docs/qa/walks/' . $id . '.json';
	$rec  = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
	if ( ! is_array( $rec ) ) {
		return array( "{$id}: no walk record ({$file}) - walk every check and record it" );
	}
	if ( ! $same( (string) ( $rec['free'] ?? '' ), $free ) || ( '' !== $pro && ! $same( (string) ( $rec['pro'] ?? '' ), $pro ) ) ) {
		return array( "{$id}: walk record is at free " . ( $rec['free'] ?? '?' ) . ' pro ' . ( $rec['pro'] ?? '-' ) . ", not {$free}" . ( $pro ? " pro {$pro}" : '' ) );
	}
	$out = array();
	foreach ( $checks as $cid ) {
		$res = $rec['checks'][ $cid ]['result'] ?? 'missing';
		if ( 'pass' !== $res ) {
			$out[] = "{$id}: check `{$cid}` is {$res} in its walk record";
		}
	}
	if ( ! empty( $rec['proposed_checks'] ) ) {
		$out[] = "{$id}: walk record proposes " . count( (array) $rec['proposed_checks'] ) . ' new check(s); add them to **Checks:**, walk them, then stamp';
	}
	return $out;
}

$jd_parts = preg_split( '/^### /m', $jd_md );
array_shift( $jd_parts );

$jd_problems = array();
$jd_waivable = array(); // Rule 4 (walk freshness): the only rule an owner release exception may waive.
$jd_seen     = array();
$jd_cards    = array();
$jd_total    = 0;

foreach ( $jd_parts as $jd_part ) {
	$jd_id = trim( strtok( $jd_part, "\n" ) );
	if ( ! preg_match( '/^\*\*Checks:\*\*\s*\n((?:[ \t]*-[^\n]*\n?)+)/m', $jd_part, $jd_m ) ) {
		if ( $jd_ready && $jd_ready[0] === $jd_id ) {
			echo "{$jd_id}: no **Checks:** list, so there is nothing a walk could have passed\n";
			exit( 1 );
		}
		$jd_problems[] = "{$jd_id}: no **Checks:** list";
		continue;
	}
	$jd_checks = array();
	foreach ( preg_split( '/\n/', trim( $jd_m[1] ) ) as $jd_line ) {
		// Tags are read token by token: `card:<id>` carries its own colon.
		if ( ! preg_match( '/^\s*-\s*`([a-z0-9][a-z0-9._-]*)`((?:\s+(?:[@#+!][a-z0-9_-]+|card:\d+))*)\s*:\s+(\S.*)$/i', $jd_line, $jd_c ) ) {
			$jd_problems[] = "{$jd_id}: unreadable check line: " . trim( $jd_line );
			continue;
		}
		list( , $jd_cid, $jd_tags ) = $jd_c;
		if ( isset( $jd_seen[ $jd_cid ] ) ) {
			$jd_problems[] = "{$jd_id}: check id `{$jd_cid}` is also used by {$jd_seen[ $jd_cid ]}";
		}
		$jd_seen[ $jd_cid ] = $jd_id;
		preg_match_all( '/@([a-z0-9_]+)/i', $jd_tags, $jd_r );
		preg_match_all( '/#([a-z0-9_]+)/i', $jd_tags, $jd_s );
		preg_match_all( '/card:(\d+)/', $jd_tags, $jd_k );
		foreach ( array_diff( $jd_r[1], $jd_roles ) as $jd_bad ) {
			$jd_problems[] = "{$jd_id}: `{$jd_cid}` names role @{$jd_bad}, which is not guest or a qa-config persona";
		}
		foreach ( array_diff( $jd_s[1], $jd_surfaces ) as $jd_bad ) {
			$jd_problems[] = "{$jd_id}: `{$jd_cid}` names surface #{$jd_bad}; use one of " . implode( ', ', $jd_surfaces );
		}
		if ( ! $jd_r[1] || ! $jd_s[1] ) {
			$jd_problems[] = "{$jd_id}: `{$jd_cid}` needs a @role and a #surface";
		}
		foreach ( $jd_k[1] as $jd_card ) {
			$jd_cards[ $jd_card ] = true;
		}
		$jd_checks[ $jd_cid ] = array(
			'roles'  => $jd_r[1],
			'refuse' => str_contains( $jd_tags, '!refuse' ),
		);
	}
	$jd_total += count( $jd_checks );

	// Rule 2: depth for user and extension flows.
	if ( preg_match( '/^[CE]\./', $jd_id ) && ! preg_match( '/^\*\*Depth note:\*\*\s*\S/m', $jd_part ) ) {
		if ( ! array_filter( $jd_checks, static fn( $c ) => $c['refuse'] ) ) {
			$jd_problems[] = "{$jd_id}: no !refuse check (what must this journey refuse, and to whom?) - or add a **Depth note:** saying why none applies";
		}
		$jd_walk_roles = array_unique( array_merge( ...array_values( array_map( static fn( $c ) => $c['roles'], $jd_checks ) ?: array( array() ) ) ) );
		if ( count( $jd_walk_roles ) < 2 ) {
			$jd_problems[] = "{$jd_id}: walks one role only (" . implode( ', ', $jd_walk_roles ) . ') - add the next rung, or a **Depth note:** saying why not';
		}
	}

	// Rule 4: a stamp is backed by a complete walk record at the same commits.
	if ( $jd_ready && $jd_ready[0] === $jd_id ) {
		$jd_why = jd_record_problems( $jd_id, array_keys( $jd_checks ), $jd_ready[1], $jd_ready[2] );
		echo $jd_why ? implode( "\n", $jd_why ) . "\n" : "{$jd_id}: walk record complete at {$jd_ready[1]}\n";
		exit( $jd_why || ! $jd_checks ? 1 : 0 );
	}
	if ( ! $jd_ready && preg_match( '/^\*\*Verified:\*\*\s*([0-9a-f]{7,40})(?:\s+pro:([0-9a-f]{7,40}))?/m', $jd_part, $jd_v ) ) {
		array_push( $jd_waivable, ...jd_record_problems( $jd_id, array_keys( $jd_checks ), $jd_v[1], $jd_v[2] ?? '' ) );
	}
	if ( $jd_list ) {
		printf( "  %-48s %2d checks\n", $jd_id, count( $jd_checks ) );
	}
}

require_once __DIR__ . '/release-exception.php';
$jd_exception = jetonomy_release_exception( $jd_root );
if ( $jd_waivable && $jd_exception ) {
	printf( "journey-depth: %d walk-record problem(s) WAIVED for %s by owner exception (%s, %s)\n", count( $jd_waivable ), $jd_exception['version'], $jd_exception['by'], $jd_exception['approved'] );
} else {
	array_push( $jd_problems, ...$jd_waivable );
}

if ( $jd_ready ) {
	echo "no journey '### {$jd_ready[0]}' in the runbook\n";
	exit( 1 );
}

// Rule 3: every escape deepened a journey.
$jd_esc = json_decode( (string) @file_get_contents( 'audit/escapes.json' ), true );
foreach ( (array) ( $jd_esc['escapes'] ?? array() ) as $jd_row ) {
	$jd_card = (string) ( $jd_row['card'] ?? '' );
	if ( '' !== $jd_card && ! isset( $jd_cards[ $jd_card ] ) ) {
		$jd_problems[] = "escape card {$jd_card} has no journey check tagged card:{$jd_card}";
	}
}

if ( $jd_problems ) {
	echo implode( "\n", $jd_problems ), "\n";
	printf( "\njourney-depth: %d problem(s) across %d journeys and %d checks.\n", count( $jd_problems ), count( $jd_parts ), $jd_total );
	exit( 1 );
}
printf( "journey-depth: %d journeys, %d checks, %s\n", count( $jd_parts ), $jd_total, $jd_waivable ? 'structure complete; walk records waived (see above)' : 'every stamp backed by a complete walk record' );
exit( 0 );
