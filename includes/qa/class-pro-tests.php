<?php
/**
 * Phase 3: Pro Extension REST Tests — delegator.
 *
 * The actual Pro extension tests live in the Pro plugin
 * (`jetonomy-pro/includes/qa/class-rest-tests.php`, class
 * `\Jetonomy_Pro\QA\REST_Tests`) so Pro's manifest-driven coverage gate
 * (`bin/qa-coverage-check.php`) can credit them and Pro's stub generator
 * (`bin/qa-stub-gen.php`) has a file to append to (Basecamp 10161768864).
 * They used to live here, but a free-repo test file can never satisfy the
 * Pro repo's coverage gate — the checker only greps the plugin's own tree.
 *
 * This class keeps the `wp jetonomy qa-actions` Phase 3 contract stable:
 * the CLI still instantiates \Jetonomy\QA\Pro_Tests, and run() forwards to
 * the Pro-side class when Pro is active (Pro loads it in CLI context).
 *
 * @package Jetonomy\QA
 * @since   1.0.0
 */

namespace Jetonomy\QA;

defined( 'ABSPATH' ) || exit;

class Pro_Tests {

	/**
	 * Run all Phase-3 Pro extension tests via the Pro-side test class.
	 *
	 * Returns immediately with empty counts if Jetonomy Pro is not loaded
	 * (or is an older Pro build without the QA module).
	 *
	 * @return array{ pass: int, fail: int }
	 */
	public function run(): array {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			\WP_CLI::log( '  Pro not active — skipping' );
			return [ 'pass' => 0, 'fail' => 0 ];
		}

		if ( ! class_exists( '\Jetonomy_Pro\QA\REST_Tests' ) ) {
			\WP_CLI::log( '  Pro active but its QA module is missing (pre-1.9.1 Pro build?) — skipping' );
			return [ 'pass' => 0, 'fail' => 0 ];
		}

		return ( new \Jetonomy_Pro\QA\REST_Tests() )->run();
	}
}
