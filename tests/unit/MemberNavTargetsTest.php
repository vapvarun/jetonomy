<?php
/**
 * Where the member navigation leads.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

defined( 'ABSPATH' ) || exit;

class MemberNavTargetsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function tear_down(): void {
		delete_option( 'jetonomy_settings' );
		parent::tear_down();
	}

	private function render_header(): string {
		$data = array( 'route' => 'home' );
		ob_start();
		include JETONOMY_DIR . 'templates/partials/header.php';
		return (string) ob_get_clean();
	}

	/** @covers-existing The Moderation link opens the cross-space queue (/mod/), never one space's queue. */
	public function test_moderation_link_targets_the_cross_space_queue(): void {
		wp_set_current_user( $this->factory()->user->create( array( 'role' => 'administrator' ) ) );
		$html = $this->render_header();
		$this->assertStringContainsString( 'href="' . esc_url( \Jetonomy\route_url( 'moderation' ) ) . '"', $html );
		$this->assertStringNotContainsString( '/mod/" class="jt-nav-dup', $html, 'Moderation has no tab, so it must not be hidden as a duplicate.' );
	}

	/** @covers-existing The member bar is Home, Spaces, New, Alerts, Me; Me opens the sheet that carries Moderation. */
	public function test_tab_bar_uses_page_names(): void {
		wp_set_current_user( $this->factory()->user->create() );
		$html = $this->render_header();
		$tabs = substr( $html, (int) strpos( $html, 'jt-mobile-tabs' ) );
		$this->assertStringContainsString( '>Alerts<', $tabs );
		$this->assertStringContainsString( '>Me<', $tabs );
		$this->assertStringContainsString( 'data-jt-menu="jt-mobile-tab-menu"', $tabs );
	}

	/** New outside a space: no space to post in = a sheet that says so; one space = its composer; two or more = a sheet listing them. */
	public function test_new_tab_start_a_topic_sheet(): void {
		$uid = $this->factory()->user->create();
		wp_set_current_user( $uid );
		$html = $this->render_header();
		$this->assertStringContainsString( 'id="jt-new-sheet"', $html );
		$this->assertStringContainsString( 'data-jt-menu="jt-new-sheet"', $html );
		$this->assertStringNotContainsString( '/new/" role="menuitem"', $html, 'No space, so no composer rows.' );

		$one = \Jetonomy\Models\Space::create( array( 'title' => 'QA One', 'slug' => 'qa-one-' . $uid, 'type' => 'forum' ) );
		\Jetonomy\Models\SpaceMember::add( (int) $one, $uid );
		$html = $this->render_header();
		$this->assertStringNotContainsString( 'id="jt-new-sheet"', $html, 'One space goes straight to its composer.' );
		$this->assertStringContainsString( 'data-jt-new-fallback="' . esc_url( \Jetonomy\route_url( 'new-post', 'qa-one-' . $uid ) ) . '"', $html );

		$two = \Jetonomy\Models\Space::create( array( 'title' => 'QA Two', 'slug' => 'qa-two-' . $uid, 'type' => 'forum' ) );
		\Jetonomy\Models\SpaceMember::add( (int) $two, $uid );
		$html = $this->render_header();
		$this->assertStringContainsString( 'id="jt-new-sheet"', $html );
		$this->assertStringContainsString( esc_url( \Jetonomy\route_url( 'new-post', 'qa-two-' . $uid ) ), $html );
	}

	/** @covers-existing Topic pages paginate replies: 30 per page by default, and the owner's setting is honoured. */
	public function test_reply_pagination_default_and_setting(): void {
		delete_option( 'jetonomy_settings' );
		$this->assertSame( 30, \Jetonomy\replies_per_page() );
		update_option( 'jetonomy_settings', array( 'replies_per_page' => 15 ) );
		$this->assertSame( 15, \Jetonomy\replies_per_page() );
	}
}
