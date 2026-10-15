<?php
/**
 * New regression tests must fail on the code they were written against.
 *
 * A test added with a fix proves the fix only if it fails without it. Until
 * now that was a manual step (revert the source, run, restore) done some of
 * the time; a guard that passes on the old code looks like protection and is
 * none. bin/check-guards-can-fail.php proves qa-actions guards with one
 * hand-written mutation each; this proves PHPUnit tests against the whole
 * previous codebase, with no registry to keep up to date.
 *
 * What it does:
 *   1. Lists test methods added since <base> (by name, per test file).
 *   2. Checks <base> out into a temporary git worktree, copies the current
 *      tests/ and phpunit.xml.dist over it, and runs only those new methods.
 *   3. Every one must fail or error there. One that passes is reported.
 *
 * Opt out, with intent, for tests that pin behaviour that already worked
 * (coverage, not a regression guard): put `@covers-existing` in the class or
 * method docblock.
 *
 * Usage (needs the WordPress test library, like PHPUnit):
 *   php bin/check-new-tests-fail-on-base.php [<base-ref>]
 * Default base: the merge-base with origin/main.
 *
 * @package Jetonomy
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

$jt_root = dirname( __DIR__ );
chdir( $jt_root );

$jt_run = static function ( string $cmd ): array {
	exec( $cmd . ' 2>&1', $out, $code );
	return array( $code, implode( "\n", $out ) );
};

[ , $jt_base ] = $jt_run( 'git rev-parse --verify ' . escapeshellarg( ( $argv[1] ?? '' ) ?: trim( shell_exec( 'git merge-base HEAD origin/main' ) ?? '' ) ) );
$jt_base       = trim( $jt_base );
if ( ! preg_match( '/^[0-9a-f]{40}$/', $jt_base ) ) {
	fwrite( STDERR, "new-tests-fail-on-base: cannot resolve the base ref\n" );
	exit( 2 );
}

/**
 * Test method names in a PHP source, with their docblocks.
 *
 * @return array<string,string> method => docblock ('' when none)
 */
$jt_methods = static function ( string $src ): array {
	preg_match_all( '#(/\*\*(?:(?!\*/).)*\*/\s*)?public function (test_\w+)\s*\(#s', $src, $m, PREG_SET_ORDER );
	$out = array();
	foreach ( $m as $x ) {
		$out[ $x[2] ] = $x[1];
	}
	return $out;
};

// Against the working tree, untracked files included: local CI runs before
// the commit, which is when a test that proves nothing should be caught.
[ , $jt_changed ]   = $jt_run( 'git diff --name-only --diff-filter=AM ' . $jt_base . ' -- tests/' );
[ , $jt_untracked ] = $jt_run( 'git ls-files --others --exclude-standard -- tests/' );
$jt_changed        .= "\n" . $jt_untracked;
$jt_targets       = array(); // file => [ method, ... ]
foreach ( array_filter( explode( "\n", $jt_changed ) ) as $jt_file ) {
	if ( ! str_ends_with( $jt_file, 'Test.php' ) || ! is_file( $jt_file ) ) {
		continue;
	}
	$jt_now = (string) file_get_contents( $jt_file );
	// A class-level @covers-existing exempts the whole file.
	if ( preg_match( '#/\*\*((?:(?!\*/).)*)\*/\s*(?:final\s+)?class\s#s', $jt_now, $jt_cls ) && str_contains( $jt_cls[1], '@covers-existing' ) ) {
		continue;
	}
	[ $jt_had, $jt_old ] = $jt_run( 'git show ' . $jt_base . ':' . escapeshellarg( $jt_file ) );
	$jt_before           = 0 === $jt_had ? $jt_methods( $jt_old ) : array();
	foreach ( $jt_methods( $jt_now ) as $jt_name => $jt_doc ) {
		if ( ! isset( $jt_before[ $jt_name ] ) && ! str_contains( $jt_doc, '@covers-existing' ) ) {
			$jt_targets[ $jt_file ][] = $jt_name;
		}
	}
}

if ( ! $jt_targets ) {
	echo "new-tests-fail-on-base: no new regression tests since {$jt_base}\n";
	exit( 0 );
}

$jt_tree = sys_get_temp_dir() . '/jt-base-' . substr( $jt_base, 0, 8 ) . '-' . getmypid();
[ $jt_code, $jt_out ] = $jt_run( 'git worktree add --detach ' . escapeshellarg( $jt_tree ) . ' ' . $jt_base );
if ( 0 !== $jt_code ) {
	fwrite( STDERR, "new-tests-fail-on-base: could not create a worktree at {$jt_base}\n{$jt_out}\n" );
	exit( 2 );
}

$jt_problems = array();
try {
	// Today's tests and runner config over yesterday's code; dependencies shared.
	$jt_run( 'rm -rf ' . escapeshellarg( $jt_tree . '/tests' ) );
	$jt_run( 'cp -R ' . escapeshellarg( $jt_root . '/tests' ) . ' ' . escapeshellarg( $jt_tree . '/tests' ) );
	copy( $jt_root . '/phpunit.xml.dist', $jt_tree . '/phpunit.xml.dist' );
	// vendor/ is partly tracked (the bundled EDD SDK), so the old checkout has
	// the directory but none of the dev tools. Link in whatever it lacks.
	if ( ! is_dir( $jt_tree . '/vendor' ) ) {
		mkdir( $jt_tree . '/vendor' );
	}
	foreach ( scandir( $jt_root . '/vendor' ) as $jt_entry ) {
		if ( '.' !== $jt_entry[0] && ! file_exists( $jt_tree . '/vendor/' . $jt_entry ) ) {
			symlink( $jt_root . '/vendor/' . $jt_entry, $jt_tree . '/vendor/' . $jt_entry );
		}
	}
	[ $jt_ok, $jt_ver ] = $jt_run( 'cd ' . escapeshellarg( $jt_tree ) . ' && php vendor/bin/phpunit --version' );
	if ( 0 !== $jt_ok ) {
		throw new \RuntimeException( "PHPUnit does not start in the base checkout:\n{$jt_ver}" );
	}

	$jt_total = 0;
	foreach ( $jt_targets as $jt_file => $jt_names ) {
		$jt_total += count( $jt_names );
		$jt_junit  = $jt_tree . '/junit-' . md5( $jt_file ) . '.xml';
		[ , $jt_output ] = $jt_run(
			'cd ' . escapeshellarg( $jt_tree ) . ' && JETONOMY_TEST_SKIP_PRO=1 php vendor/bin/phpunit -c phpunit.xml.dist'
			. ' --log-junit ' . escapeshellarg( $jt_junit )
			. ' --filter ' . escapeshellarg( '/::(' . implode( '|', $jt_names ) . ')( |$)/' )
			. ' ' . escapeshellarg( $jt_file )
		);
		$jt_xml = is_file( $jt_junit ) ? simplexml_load_file( $jt_junit ) : false;
		$jt_ran = array();
		if ( $jt_xml ) {
			foreach ( $jt_xml->xpath( '//testcase' ) as $jt_case ) {
				$jt_ran[ (string) $jt_case['name'] ] = isset( $jt_case->failure ) || isset( $jt_case->error );
			}
		}
		// "Did not run" is never "failed": only a recorded failure, or PHP
		// dying while loading the test against the old code, counts. Anything
		// else means this harness is broken, and a broken harness that reports
		// green is the very thing this script exists to prevent.
		$jt_fatal = ! $jt_ran && preg_match( '/PHP Fatal error|Fatal error:|Uncaught Error/', $jt_output );
		foreach ( $jt_names as $jt_name ) {
			if ( ! array_key_exists( $jt_name, $jt_ran ) ) {
				if ( ! $jt_fatal ) {
					throw new \RuntimeException( "{$jt_file}::{$jt_name} did not run on the base checkout, so nothing was proven:\n" . substr( $jt_output, -1500 ) );
				}
				echo "  fails on base (fatal while loading): {$jt_file}::{$jt_name}\n";
				continue;
			}
			if ( $jt_ran[ $jt_name ] ) {
				echo "  fails on base (assertion or error): {$jt_file}::{$jt_name}\n";
			} else {
				$jt_problems[] = "{$jt_file}::{$jt_name} passes on {$jt_base}, so it proves nothing about this change (fix the assertion, or mark @covers-existing if it pins behaviour that already worked)";
			}
		}
	}
} catch ( \RuntimeException $e ) {
	fwrite( STDERR, 'new-tests-fail-on-base: ' . $e->getMessage() . "\n" );
	$jt_run( 'git worktree remove --force ' . escapeshellarg( $jt_tree ) );
	exit( 2 );
} finally {
	if ( is_dir( $jt_tree ) ) {
		$jt_run( 'git worktree remove --force ' . escapeshellarg( $jt_tree ) );
	}
}

if ( $jt_problems ) {
	echo implode( "\n", $jt_problems ), "\n";
	printf( "\nnew-tests-fail-on-base: %d of %d new test(s) pass on the old code.\n", count( $jt_problems ), $jt_total );
	exit( 1 );
}
printf( "new-tests-fail-on-base: all %d new test(s) fail on %s, as a regression test should.\n", $jt_total, substr( $jt_base, 0, 8 ) );
exit( 0 );
