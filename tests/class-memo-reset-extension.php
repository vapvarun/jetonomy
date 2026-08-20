<?php
/**
 * PHPUnit extension: restore "one test = one request" between tests.
 *
 * Two things leak across tests in this suite, and both are here because there
 * is no shared base test case to put them in — 123 test classes extend
 * WP_UnitTestCase directly.
 *
 * 1. Static memos.
 *    WP_UnitTestCase flushes the object cache between tests, which is why the
 *    old persistent caches never leaked. The request-scope static memos
 *    introduced by caching-plan WP0.1 (Permission_Engine verdicts, Restriction
 *    primitives) live OUTSIDE the object cache, and PHPUnit runs every test in
 *    one PHP process while rolling the DB back — InnoDB can then reuse
 *    auto-increment ids, so a memoized verdict for user N leaks into the next
 *    test's unrelated user N. In production a "request" and a "process" are the
 *    same thing; in the test runner they are not.
 *
 * 2. Rows in the plugin's own tables (added 1.9.4).
 *    WP_UnitTestCase wraps each test in a transaction and rolls it back, which
 *    normally covers every InnoDB table on the connection. But MySQL performs
 *    an IMPLICIT COMMIT on DDL — CREATE/ALTER/DROP/TRUNCATE — and twelve test
 *    files in this suite run DDL (schema and migration tests by necessity;
 *    AttachmentsModelTest::tear_down drops a table on every single test). The
 *    moment one of those runs, the enclosing transaction ends and everything
 *    inserted before it becomes permanent. Rows then pile up run after run.
 *
 *    The symptom is a suite whose result depends on how many times you have
 *    run it. Measured before this hook existed: 148 rows in jt_spaces and 80 in
 *    jt_space_members surviving against 0 rows in wptests_users, so
 *    Space::resolve_successor() could not find a surviving admin and
 *    SpaceJourneyTest::test_delete_defaults_to_transfer_and_keeps_the_space
 *    failed — while a full run minutes earlier had been green. Worse, the
 *    failing test NAME moved between runs, which reads as flakiness in the
 *    product rather than a defect in the harness.
 *
 *    DELETE, never TRUNCATE: TRUNCATE is itself DDL and would cause the very
 *    implicit commit this cleans up after.
 *
 * @package Jetonomy\Tests
 */

use PHPUnit\Runner\AfterTestHook;
use PHPUnit\Runner\BeforeFirstTestHook;

class Jetonomy_Memo_Reset_Extension implements AfterTestHook, BeforeFirstTestHook {

	/**
	 * Cached list of this install's jt_* tables, resolved once.
	 *
	 * @var string[]|null
	 */
	private ?array $tables = null;

	/**
	 * Start the run from a known-empty state.
	 *
	 * A previous run that ended on a DDL-committed transaction leaves rows
	 * behind, so without this the first test of a fresh run can already be
	 * looking at another run's data.
	 */
	public function executeBeforeFirstTest(): void {
		$this->purge_plugin_tables();
	}

	/**
	 * @param string $test Test identifier.
	 * @param float  $time Duration.
	 */
	public function executeAfterTest( string $test, float $time ): void {
		if ( class_exists( '\Jetonomy\Cache', false ) ) {
			\Jetonomy\Cache::reset_memos();
		}

		$this->purge_plugin_tables();
	}

	/**
	 * Delete every row from the plugin's own tables.
	 *
	 * A no-op in the normal case: the test's transaction has already rolled the
	 * rows away, so each DELETE matches nothing. It only does real work after a
	 * test whose DDL committed the transaction out from under the harness.
	 */
	private function purge_plugin_tables(): void {
		global $wpdb;

		if ( ! $wpdb instanceof \wpdb ) {
			return;
		}

		if ( null === $this->tables ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$found        = $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}jt\_%'" );
			$this->tables = is_array( $found ) ? $found : array();
		}

		$suppress = $wpdb->suppress_errors( true );
		foreach ( $this->tables as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$table}" );
		}
		$wpdb->suppress_errors( $suppress );
	}
}
