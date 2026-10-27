<?php
/**
 * Every "not found" page leads back to the community.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

defined( 'ABSPATH' ) || exit;

class NotFoundBackLinkTest extends WP_UnitTestCase {

	private function render(): string {
		ob_start();
		\Jetonomy\Template_Loader::partial( 'empty-state', array( 'message' => 'Space not found.' ) );
		return (string) ob_get_clean();
	}

	public function tear_down(): void {
		http_response_code( 200 );
		parent::tear_down();
	}

	public function test_a_404_empty_state_links_back_to_the_community(): void {
		http_response_code( 404 );
		$this->assertStringContainsString( esc_url( \Jetonomy\base_url() ), $this->render() );
	}

	public function test_an_ordinary_empty_state_gets_no_link(): void {
		http_response_code( 200 );
		$this->assertStringNotContainsString( 'Back to the community', $this->render() );
	}
}
