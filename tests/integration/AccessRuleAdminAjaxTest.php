<?php
/**
 * wp-admin Access Rules: the row's Grants select and the level picker search.
 *
 * Only a space manager may change what a rule grants or search membership
 * levels; a grant edit keeps the rule and re-derives its roster role, and the
 * "full" grant removed in 2.0.7 is refused.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_Ajax_UnitTestCase;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;
use Jetonomy\DB\Schema;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Space;
use Jetonomy\Permissions\Capabilities;

class AccessRuleAdminAjaxTest extends WP_Ajax_UnitTestCase {

	private int $admin;
	private int $member;
	private int $rule;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$account     = new \WP_User( $this->admin );
		foreach ( Capabilities::all() as $cap ) {
			$account->add_cap( $cap );
		}
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$space        = (int) Space::create(
			array(
				'title'      => 'Rules ' . uniqid(),
				'slug'       => 'rules-' . uniqid(),
				'visibility' => 'private',
			)
		);
		$this->rule   = AccessRule::create(
			array(
				'space_id'   => $space,
				'rule_type'  => 'role',
				'rule_value' => 'subscriber',
				'grants'     => 'read',
			)
		);
		new \Jetonomy\Admin\Ajax\Spaces_Handler();
	}

	public function tear_down(): void {
		$prop = new \ReflectionProperty( Adapter_Registry::class, 'membership' );
		$list = $prop->getValue();
		unset( $list['qa_levels'] );
		$prop->setValue( null, $list );
		parent::tear_down();
	}

	private function ajax( string $action, array $post, int $user ): array {
		wp_set_current_user( $user );
		$_POST = array_merge( array( 'nonce' => wp_create_nonce( 'jetonomy_admin' ) ), $post );
		$this->_last_response = '';
		try {
			$this->_handleAjax( $action );
		} catch ( WPAjaxDieContinueException | WPAjaxDieStopException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- the JSON response is asserted.
		}
		return (array) json_decode( $this->_last_response, true );
	}

	public function test_a_member_cannot_change_what_a_rule_grants(): void {
		$res = $this->ajax( 'jetonomy_update_access_rule', array( 'id' => $this->rule, 'grants' => 'participate' ), $this->member );
		$this->assertFalse( $res['success'] ?? true );
		$this->assertSame( 'read', AccessRule::find( $this->rule )->grants );
	}

	public function test_a_manager_edit_keeps_the_rule_and_rederives_its_role(): void {
		$res  = $this->ajax( 'jetonomy_update_access_rule', array( 'id' => $this->rule, 'grants' => 'participate' ), $this->admin );
		$rule = AccessRule::find( $this->rule );
		$this->assertTrue( $res['success'] );
		$this->assertSame( 'Member', $res['data']['role'] );
		$this->assertSame( 'participate', $rule->grants );
		$this->assertSame( 'member', $rule->space_role );
	}

	public function test_the_removed_full_grant_is_refused(): void {
		$res = $this->ajax( 'jetonomy_update_access_rule', array( 'id' => $this->rule, 'grants' => 'full' ), $this->admin );
		$this->assertFalse( $res['success'] );
		$this->assertSame( 'read', AccessRule::find( $this->rule )->grants );
	}

	public function test_level_search_is_for_managers_and_asks_the_named_adapter(): void {
		$stub = new class() implements Membership_Adapter {
			public function is_active(): bool {
				return true; }
			public function get_user_levels( int $user_id ): array {
				return array(); }
			public function user_has_level( int $user_id, string $level_id ): bool {
				return false; }
			public function get_all_levels(): array {
				return array(); }
			public function get_level_label( string $level_id ): string {
				return $level_id; }
			public function register_hooks(): void {}
			public function search_levels( string $query, int $limit ): array {
				return array( array( 'id' => 'gold', 'label' => 'Gold ' . $query ) ); }
		};
		Adapter_Registry::register_membership( 'qa_levels', $stub );

		$refused = $this->ajax( 'jetonomy_search_membership_levels', array( 'adapter' => 'qa_levels', 'q' => 'go' ), $this->member );
		$this->assertFalse( $refused['success'] ?? true );

		$found = $this->ajax( 'jetonomy_search_membership_levels', array( 'adapter' => 'qa_levels', 'q' => 'go' ), $this->admin );
		$this->assertTrue( $found['success'] );
		$this->assertSame( array( array( 'id' => 'gold', 'label' => 'Gold go' ) ), $found['data'] );
	}
}
