<?php
namespace Jetonomy\Tests\Unit\Adapters;

use WP_UnitTestCase;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;

/**
 * Coverage for the Access Rules label resolver.
 *
 * The screen used to map a stored rule_value to its adapter through a
 * hardcoded prefix table living in the view. Every adapter that shipped after
 * that table was written went unlabelled — WP Fusion and SureMembers both did,
 * so a paying customer saw raw `wpfusion_102` where a tag name belonged
 * (Basecamp 10126146658). Resolution now asks the registry which adapter owns
 * an id, so a new adapter is labelled the moment it registers.
 *
 * These tests use a stub adapter rather than a real integration on purpose:
 * the contract under test is "any registered adapter gets resolved", and a
 * stub proves that for adapters this install does not have installed.
 */
class AdapterRegistryLevelLabelTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_membership_adapter_labels' );
		parent::tear_down();
	}

	/**
	 * A stand-in for any tag/level-based membership adapter.
	 */
	private function stub_adapter( string $prefix, array $levels, bool $active = true ): Membership_Adapter {
		return new class( $prefix, $levels, $active ) implements Membership_Adapter {
			public function __construct(
				private string $prefix,
				private array $levels,
				private bool $active
			) {}

			public function is_active(): bool {
				return $this->active;
			}

			public function get_user_levels( int $user_id ): array {
				return array();
			}

			public function user_has_level( int $user_id, string $level_id ): bool {
				return false;
			}

			public function get_all_levels(): array {
				$out = array();
				foreach ( $this->levels as $id => $label ) {
					$out[] = array(
						'id'    => $this->prefix . $id,
						'label' => $label . ' (stub)',
					);
				}
				return $out;
			}

			public function get_level_label( string $level_id ): string {
				$key = str_replace( $this->prefix, '', $level_id );
				return $this->levels[ $key ] ?? $level_id;
			}

			public function register_hooks(): void {}
		};
	}

	public function test_resolves_label_for_an_adapter_registered_after_the_view_was_written(): void {
		Adapter_Registry::register_membership(
			'suremembers',
			$this->stub_adapter( 'suremembers_', array( '5' => 'Chapter Leaders Access' ) )
		);

		$out = Adapter_Registry::describe_membership_level( 'suremembers_5' );

		$this->assertSame( 'Chapter Leaders Access', $out['value'] );
		$this->assertSame( 'SureMembers', $out['type'] );
	}

	public function test_resolves_the_customer_reported_wpfusion_tag(): void {
		Adapter_Registry::register_membership(
			'wpfusion',
			$this->stub_adapter( 'wpfusion_', array( '102' => 'WP Sync: Is Chapter Leader' ) )
		);

		$out = Adapter_Registry::describe_membership_level( 'wpfusion_102' );

		$this->assertSame( 'WP Sync: Is Chapter Leader', $out['value'] );
		$this->assertSame( 'WP Fusion Tag', $out['type'] );
		$this->assertStringNotContainsString( 'wpfusion_', $out['value'] );
	}

	public function test_falls_back_to_the_raw_id_when_the_owning_plugin_is_deactivated(): void {
		Adapter_Registry::register_membership(
			'suremembers',
			$this->stub_adapter( 'suremembers_', array( '5' => 'Chapter Leaders Access' ), false )
		);

		$out = Adapter_Registry::describe_membership_level( 'suremembers_5' );

		// Rules outlive their plugin; showing the stored value beats showing nothing.
		$this->assertSame( 'suremembers_5', $out['value'] );
		$this->assertSame( 'Membership', $out['type'] );
	}

	public function test_unknown_id_falls_back_instead_of_erroring(): void {
		$out = Adapter_Registry::describe_membership_level( 'totally_unknown_9' );

		$this->assertSame( 'totally_unknown_9', $out['value'] );
		$this->assertSame( 'Membership', $out['type'] );
	}

	public function test_empty_value_is_handled(): void {
		$out = Adapter_Registry::describe_membership_level( '' );

		$this->assertSame( '', $out['value'] );
		$this->assertSame( 'Membership', $out['type'] );
	}

	public function test_a_third_party_adapter_can_name_itself_via_filter(): void {
		Adapter_Registry::register_membership(
			'acme',
			$this->stub_adapter( 'acme_', array( '1' => 'Gold Tier' ) )
		);

		add_filter(
			'jetonomy_membership_adapter_labels',
			static function ( $labels ) {
				$labels['acme'] = 'Acme Plan';
				return $labels;
			}
		);

		$out = Adapter_Registry::describe_membership_level( 'acme_1' );

		$this->assertSame( 'Acme Plan', $out['type'] );
		$this->assertSame( 'Gold Tier', $out['value'] );
	}

	public function test_membership_rules_never_resolve_to_a_wp_role(): void {
		// wp-roles ids are bare slugs and it is excluded from the membership
		// picker; a membership rule resolving to "Administrator" would be wrong.
		$owners = Adapter_Registry::membership_level_owners();

		$this->assertNotContains( 'wp-roles', $owners, 'wp-roles must not own membership level ids.' );
	}
}
