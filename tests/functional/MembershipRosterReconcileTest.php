<?php
/**
 * The recurring roster reconcile backstop, asserted.
 *
 * on_deactivated() only removes a tier row when the adapter FIRES
 * jetonomy_membership_deactivated. Several adapters (BuddyNext Pro) hold access
 * until expires_at and never fire it, so a lapsed plan's tier row would linger
 * forever. Membership_Roster_Sync::reconcile() (cron: jetonomy_reconcile_rosters)
 * is the backstop: it drops tier rows nothing still grants, and must never touch
 * a manual/invite/rule row.
 *
 * @package Jetonomy\Tests\Functional
 */

namespace Jetonomy\Tests\Functional;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\DB\Schema;
use Jetonomy\Membership_Roster_Sync;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;

class MembershipRosterReconcileTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * A tier row nothing grants is dropped; a manual row is never touched.
	 */
	public function test_reconcile_drops_lapsed_tier_rows_but_keeps_manual(): void {
		$space = Space::create(
			array(
				'title'       => 'Reconcile',
				'slug'        => 'reconcile-' . uniqid(),
				'category_id' => 0,
				'visibility'  => 'private',
			)
		);

		// A membership rule for a level no adapter grants — so grants_access()
		// resolves false and the tier row has nothing keeping it on the roster.
		AccessRule::create(
			array(
				'space_id'   => $space,
				'rule_type'  => 'membership',
				'rule_value' => 'pmpro_nonexistent_level',
				'grants'     => 'participate',
				'space_role' => 'member',
			)
		);

		$lapsed = self::factory()->user->create();
		$manual = self::factory()->user->create();
		SpaceMember::add( $space, $lapsed, 'member', 'tier' );
		SpaceMember::add( $space, $manual, 'member', 'manual' );

		Membership_Roster_Sync::reconcile();

		$this->assertFalse(
			SpaceMember::is_member( $space, $lapsed ),
			'A tier row that nothing still grants must be removed by reconcile.'
		);
		$this->assertTrue(
			SpaceMember::is_member( $space, $manual ),
			'A manual row must never be removed by reconcile — only tier rows.'
		);
	}

	/**
	 * The jetonomy_reconcile_rosters cron runs the reconcile backstop.
	 */
	public function test_reconcile_cron_hook_is_wired(): void {
		new Cron();

		$this->assertNotFalse(
			has_action( 'jetonomy_reconcile_rosters', array( Membership_Roster_Sync::class, 'reconcile' ) ),
			'The jetonomy_reconcile_rosters cron hook must invoke Membership_Roster_Sync::reconcile().'
		);
	}
}
