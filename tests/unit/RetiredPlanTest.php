<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;

/**
 * A rule on a plan that no longer exists (a deleted PMPro level "pmpro_999")
 * is flagged for every adapter, not only those with their own level_available():
 * an adapter that can no longer name the level means the level is gone.
 */
class RetiredPlanTest extends WP_UnitTestCase {

	public function test_a_level_the_adapter_cannot_name_is_not_available(): void {
		Adapter_Registry::register_membership(
			'retired_probe',
			new class() implements Membership_Adapter {
				public function is_active(): bool { return true; } // phpcs:ignore
				public function get_user_levels( int $user_id ): array { return array(); } // phpcs:ignore
				public function user_has_level( int $user_id, string $level_id ): bool { return false; } // phpcs:ignore
				public function get_all_levels(): array { return array(); } // phpcs:ignore
				public function get_level_label( string $level_id ): string { return 'rp_1' === $level_id ? 'Gold' : $level_id; } // phpcs:ignore
				public function register_hooks(): void {} // phpcs:ignore
				public function level_prefixes(): array { return array( 'rp_' => 'Level' ); } // phpcs:ignore
			}
		);

		$this->assertTrue( Adapter_Registry::membership_level_available( 'rp_1' ) );
		$this->assertFalse( Adapter_Registry::membership_level_available( 'rp_999' ) );
		$this->assertTrue( Adapter_Registry::membership_level_available( 'nobody_owns_this_1' ), 'No adapter: left alone.' );
	}
}
