<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Space;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;

/**
 * A space whose only plan was deleted offers no way in: the gate says it is
 * closed, shows no button and never prints the raw plan id, even when the
 * plan's plugin builds a checkout link from the id alone (PMPro, RCP).
 * Basecamp 10454716513, QA retest 2026-10-30.
 *
 * The view declares a function, so each render runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class RetiredPlanGateTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		Adapter_Registry::register_membership(
			'retired_probe',
			new class() implements Membership_Adapter {
				public function is_active(): bool { return true; } // phpcs:ignore
				public function get_user_levels( int $user_id ): array { return array(); } // phpcs:ignore
				public function user_has_level( int $user_id, string $level_id ): bool { return false; } // phpcs:ignore
				public function get_all_levels(): array { return array(); } // phpcs:ignore
				public function get_level_label( string $level_id ): string { return 'rp_1' === $level_id ? 'Gold' : $level_id; } // phpcs:ignore
				public function register_hooks(): void {} // phpcs:ignore
				public function level_prefixes(): array { return array( 'rp_' => '' ); } // phpcs:ignore
				// Like PMPro: a checkout link is built from the id, existing level or not.
				public function get_level_url( string $level_id ): string { return 'https://example.org/checkout/?level=' . substr( $level_id, 3 ); } // phpcs:ignore
			}
		);
	}

	private function gate_for( string $level_id ): string {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$space = Space::find( (int) Space::create( array( 'title' => 'Retired Plan Room', 'slug' => 'rpr-' . uniqid(), 'visibility' => 'private' ), $admin ) );
		AccessRule::create( array( 'space_id' => (int) $space->id, 'rule_type' => 'membership', 'rule_value' => $level_id, 'grants' => 'participate' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		\Jetonomy\Cache::flush();

		$data = array( 'slug' => $space->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/space.php';
		return (string) ob_get_clean();
	}

	public function test_a_deleted_plan_offers_no_button_and_no_raw_id(): void {
		$html = $this->gate_for( 'rp_999' );

		$this->assertStringContainsString( 'is not open to join right now.', $html );
		$this->assertStringNotContainsString( 'rp_999', $html );
		$this->assertStringNotContainsString( 'checkout/?level=999', $html );
	}

	public function test_a_live_plan_still_offers_its_button(): void {
		$html = $this->gate_for( 'rp_1' );

		$this->assertStringContainsString( 'Get Gold', $html );
		$this->assertStringContainsString( 'checkout/?level=1', $html );
	}
}
