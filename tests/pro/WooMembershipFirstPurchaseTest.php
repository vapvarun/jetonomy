<?php
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy_Pro\Adapters\WooCommerce_Adapter;

/**
 * WooCommerce Memberships never fires status_changed for a brand-new
 * membership, so a first purchase must be picked up from
 * wc_memberships_user_membership_saved. Basecamp 10462612523.
 *
 * Memberships is not installed here; its lookup function is stubbed, so each
 * test runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class WooMembershipFirstPurchaseTest extends WP_UnitTestCase {

	private function stub( string $status ): void {
		$GLOBALS['jt_test_wc_membership'] = new class( $status ) {
			public function __construct( private string $status ) {}
			public function get_user_id(): int { return 42; } // phpcs:ignore
			public function get_plan_id(): int { return 7; } // phpcs:ignore
			public function get_status(): string { return $this->status; } // phpcs:ignore
		};
		if ( ! function_exists( 'wc_memberships_get_user_membership' ) ) {
			eval( 'function wc_memberships_get_user_membership( $id ) { return $GLOBALS["jt_test_wc_membership"]; }' ); // phpcs:ignore
		}
	}

	private function save( bool $is_update ): array {
		$fired = array();
		add_action( 'jetonomy_membership_activated', function ( $user_id, $level_id ) use ( &$fired ) { $fired[] = array( 'on', $user_id, $level_id ); }, 10, 2 );
		add_action( 'jetonomy_membership_deactivated', function ( $user_id, $level_id ) use ( &$fired ) { $fired[] = array( 'off', $user_id, $level_id ); }, 10, 2 );
		( new WooCommerce_Adapter() )->on_membership_saved( null, array( 'user_id' => 42, 'user_membership_id' => 99, 'is_update' => $is_update ) );
		return $fired;
	}

	public function test_a_new_active_membership_activates(): void {
		$this->stub( 'active' );
		$this->assertSame( array( array( 'on', 42, 'wc_membership_7' ) ), $this->save( false ) );
	}

	public function test_an_update_is_left_to_status_changed(): void {
		$this->stub( 'active' );
		$this->assertSame( array(), $this->save( true ) );
	}

	public function test_a_new_membership_without_access_fires_nothing(): void {
		$this->stub( 'auto-draft' );
		$this->assertSame( array(), $this->save( false ) );
	}
}
