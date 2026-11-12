<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Blocks;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * 10498439475: an Everyone / Logged In rule that gives nobody anything on a
 * Public space says so when saved, instead of looking like a read-only gate.
 * 10497555913: the Login card's Register links to the site's own sign-up page
 * when one is configured, and keeps the inline form otherwise.
 */
class NoopRuleAndRegisterLinkTest extends WP_UnitTestCase {

	private function space( string $visibility, string $join ): int {
		$cat = Category::create( array( 'name' => 'NR', 'slug' => 'nr-' . uniqid() ) );
		return Space::create( array( 'title' => 'NR', 'slug' => 'nr-' . uniqid(), 'category_id' => $cat, 'visibility' => $visibility, 'join_policy' => $join ) );
	}

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_logged_in_read_rule_on_public_open_space_says_it_changes_nothing(): void {
		$space = $this->space( 'public', 'open' );
		$id    = AccessRule::create( array( 'space_id' => $space, 'rule_type' => 'logged_in', 'rule_value' => '1', 'grants' => 'read' ) );

		$this->assertStringContainsString( 'changes nothing', AccessRule::added_message( $space, false, 'logged_in', 'read' ) );
		$this->assertStringContainsString( 'Who Can Post', AccessRule::updated_message( $id ) );
	}

	public function test_rules_that_do_something_keep_the_plain_message(): void {
		$approval = $this->space( 'public', 'approval' );
		$private  = $this->space( 'private', 'approval' );

		// Participate on a non-open public space lets non-members post.
		$this->assertSame( 'Access rule added.', AccessRule::added_message( $approval, false, 'logged_in', 'participate' ) );
		// Any rule on a private space admits people who were shut out.
		$this->assertSame( 'Access rule added.', AccessRule::added_message( $private, false, 'logged_in', 'read' ) );
		// Restrictive types are handled by enforce_gate_on_public_space().
		$this->assertFalse( AccessRule::is_noop_on_space( Space::find( $approval ), 'role', 'read' ) );
	}

	public function test_register_links_to_custom_registration_page(): void {
		update_option( 'users_can_register', 1 );
		$join = static fn() => home_url( '/join/' );
		add_filter( 'register_url', $join );
		$html = Blocks::render_login( array( 'context' => 'sidebar' ) );
		remove_filter( 'register_url', $join );

		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/join/' ) ) . '"', $html );
		$this->assertStringNotContainsString( 'data-jt-panel="register"', $html, 'no inline sign-up form beside the owner\'s page' );
		$this->assertStringContainsString( 'data-jt-panel="login"', $html, 'login stays inline' );
	}

	/**
	 * Default sites keep the inline form the fix must not take away.
	 *
	 * @covers-existing
	 */
	public function test_default_registration_keeps_inline_form(): void {
		update_option( 'users_can_register', 1 );
		$html = Blocks::render_login( array( 'context' => 'sidebar' ) );

		$this->assertStringContainsString( 'data-jt-panel="register"', $html );
		$this->assertStringContainsString( 'role="tablist"', $html );
	}
}
