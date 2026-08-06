<?php
/**
 * PHPUnit extension: reset Jetonomy's per-request static memos after every test.
 *
 * WP_UnitTestCase flushes the object cache between tests, which is why the
 * old persistent caches never leaked across tests. The request-scope static
 * memos introduced by caching-plan WP0.1 (Permission_Engine verdicts,
 * Restriction primitives) live OUTSIDE the object cache, and PHPUnit runs
 * every test in one PHP process while rolling the DB back — InnoDB can then
 * reuse auto-increment ids, so a memoized verdict for user N leaks into the
 * next test's unrelated user N. In production a "request" and a "process"
 * are the same thing; in the test runner they are not, and this hook restores
 * that equivalence: one test = one request = one memo lifetime.
 *
 * @package Jetonomy\Tests
 */

use PHPUnit\Runner\AfterTestHook;

class Jetonomy_Memo_Reset_Extension implements AfterTestHook {

	/**
	 * @param string $test     Test identifier.
	 * @param float  $time     Duration.
	 */
	public function executeAfterTest( string $test, float $time ): void {
		if ( class_exists( '\Jetonomy\Cache', false ) ) {
			\Jetonomy\Cache::reset_memos();
		}
	}
}
