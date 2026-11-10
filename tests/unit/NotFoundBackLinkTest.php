<?php
/**
 * Every "not found" page leads back to the community.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

/**
 * The partial reads the response status, and PHP 8.3+ refuses to set one once
 * output has started (the test bootstrap prints first), so each test runs in
 * its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class NotFoundBackLinkTest extends WP_UnitTestCase {

	private function render(): string {
		ob_start();
		\Jetonomy\Template_Loader::partial( 'empty-state', array( 'message' => 'Space not found.' ) );
		return (string) ob_get_clean();
	}

	public function test_a_404_empty_state_links_back_to_the_community(): void {
		status_header( 404 );
		$this->assertStringContainsString( esc_url( \Jetonomy\base_url() ), $this->render() );
	}

	/** @covers-existing Ordinary empty states never had a back link; this pins that only 404s gain one. */
	public function test_an_ordinary_empty_state_gets_no_link(): void {
		status_header( 200 );
		$this->assertStringNotContainsString( 'Back to the community', $this->render() );
	}
}
