<?php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\Permissions\Capabilities;

/**
 * Coverage for the editable role -> capability mapping (Basecamp 9725751235).
 *
 * The mapping used to be a hard-coded ROLE_MAP written add-only onto roles,
 * and the settings card literally said "This mapping is fixed." Now the
 * jetonomy_role_caps option overrides per-role sets, register() SYNCS (adds
 * and revokes), administrator is pinned to every cap, and the first sync
 * snapshots any live drift so upgrades never change effective permissions.
 */
class RoleCapsMappingTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( Capabilities::ROLE_CAPS_OPTION );
		Capabilities::register(); // restore defaults for subsequent tests
		delete_option( Capabilities::ROLE_CAPS_OPTION );
		parent::tear_down();
	}

	public function test_defaults_apply_without_overrides(): void {
		delete_option( Capabilities::ROLE_CAPS_OPTION );
		Capabilities::register();

		$this->assertTrue( get_role( 'editor' )->has_cap( 'jetonomy_moderate' ) );
		$this->assertTrue( get_role( 'subscriber' )->has_cap( 'jetonomy_flag' ) );
		$this->assertFalse( get_role( 'subscriber' )->has_cap( 'jetonomy_moderate' ) );
	}

	public function test_override_grants_and_unticking_revokes(): void {
		Capabilities::register(); // ensure option seeded + defaults live

		// Grant moderate to author; strip editor down to nothing.
		update_option(
			Capabilities::ROLE_CAPS_OPTION,
			array(
				'author' => array( 'jetonomy_read', 'jetonomy_moderate' ),
				'editor' => array(),
			),
			false
		);
		Capabilities::register();

		$this->assertTrue( get_role( 'author' )->has_cap( 'jetonomy_moderate' ), 'override must grant' );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'jetonomy_moderate' ), 'unticked cap must be revoked' );
		$this->assertFalse( get_role( 'editor' )->has_cap( 'jetonomy_pin_posts' ), 'empty set means none' );
		// A real user picks the change up.
		$uid = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->assertTrue( user_can( $uid, 'jetonomy_moderate' ) );
	}

	public function test_administrator_is_pinned_to_every_cap(): void {
		update_option( Capabilities::ROLE_CAPS_OPTION, array( 'administrator' => array() ), false );
		Capabilities::register();

		foreach ( Capabilities::all() as $cap ) {
			$this->assertTrue( get_role( 'administrator' )->has_cap( $cap ), "admin lost {$cap}" );
		}
	}

	public function test_first_sync_snapshots_hand_granted_custom_role(): void {
		// A site hand-granted moderate to a custom role before this feature
		// existed. The first sync must PRESERVE that, not strip it.
		remove_role( 'jt_test_custom' );
		add_role( 'jt_test_custom', 'JT Custom', array( 'read' => true ) );
		get_role( 'jt_test_custom' )->add_cap( 'jetonomy_moderate' );
		delete_option( Capabilities::ROLE_CAPS_OPTION );

		Capabilities::register();

		$this->assertTrue( get_role( 'jt_test_custom' )->has_cap( 'jetonomy_moderate' ), 'seed must preserve live drift' );
		$saved = get_option( Capabilities::ROLE_CAPS_OPTION );
		$this->assertContains( 'jetonomy_moderate', $saved['jt_test_custom'] ?? array(), 'drift must be captured in the option' );
		remove_role( 'jt_test_custom' );
	}

	public function test_effective_map_reflects_overrides_for_ui(): void {
		update_option( Capabilities::ROLE_CAPS_OPTION, array( 'subscriber' => array( 'jetonomy_read' ) ), false );

		$map = Capabilities::effective_map();

		$this->assertSame( array( 'jetonomy_read' ), $map['subscriber'] );
		$this->assertSame( Capabilities::all(), $map['administrator'] );
	}

	public function test_labels_cover_every_capability(): void {
		$labels = Capabilities::labels();
		foreach ( Capabilities::all() as $cap ) {
			$this->assertArrayHasKey( $cap, $labels, "no label for {$cap}" );
		}
	}
}
