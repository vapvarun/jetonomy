<?php
namespace Jetonomy\Tests\Unit\Adapters;

use WP_UnitTestCase;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;

/**
 * Coverage for the "where do I buy this?" resolver.
 *
 * A space gated on a membership level shows the visitor which plan opens it.
 * Naming the plan is only half the answer - the other half is a way to go and
 * get it, which is what `Adapter_Registry::membership_level_url()` supplies.
 *
 * Two properties are load-bearing and pinned here:
 *
 *   1. `get_level_url()` is OPTIONAL. It is deliberately absent from the
 *      `Membership_Adapter` signature list, because adding it would fatal every
 *      third-party adapter already implementing the interface. The resolver
 *      therefore probes with `method_exists()`, and an adapter without it must
 *      resolve to '' rather than erroring.
 *   2. '' is a correct answer, not a failure. Some levels are granted rather
 *      than sold - a WP role, a WP Fusion tag, a SureMembers group - and the
 *      gate states the requirement with no button. A button to the wrong
 *      checkout is worse than no button.
 *
 * Stubs rather than real integrations, on purpose: the contract is "any
 * registered adapter is asked, whoever it is", which stubs prove for the
 * adapters this install does not have installed.
 */
class AdapterRegistryLevelUrlTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_membership_upgrade_url' );
		parent::tear_down();
	}

	/**
	 * An adapter that knows where its levels are sold.
	 */
	private function selling_adapter( string $prefix, string $url, bool $active = true ): Membership_Adapter {
		return new class( $prefix, $url, $active ) implements Membership_Adapter {
			public function __construct(
				private string $prefix,
				private string $url,
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
				return array(
					array(
						'id'    => $this->prefix . '1',
						'label' => 'Stub Level',
					),
				);
			}

			public function get_level_label( string $level_id ): string {
				return 'Stub Level';
			}

			public function get_level_url( string $level_id ): string {
				return $this->url;
			}

			public function register_hooks(): void {}
		};
	}

	/**
	 * An adapter written before `get_level_url()` existed, or one whose level
	 * simply is not purchasable. Must not fatal the gate.
	 */
	private function granting_adapter( string $prefix ): Membership_Adapter {
		return new class( $prefix ) implements Membership_Adapter {
			public function __construct( private string $prefix ) {}

			public function is_active(): bool {
				return true;
			}

			public function get_user_levels( int $user_id ): array {
				return array();
			}

			public function user_has_level( int $user_id, string $level_id ): bool {
				return false;
			}

			public function get_all_levels(): array {
				return array(
					array(
						'id'    => $this->prefix . '1',
						'label' => 'Granted Level',
					),
				);
			}

			public function get_level_label( string $level_id ): string {
				return 'Granted Level';
			}

			public function register_hooks(): void {}
		};
	}

	public function test_returns_the_url_the_owning_adapter_reports(): void {
		Adapter_Registry::register_membership(
			'jt-url-selling',
			$this->selling_adapter( 'jturlsell_', 'https://example.test/plans/vip/' )
		);

		$this->assertSame(
			'https://example.test/plans/vip/',
			Adapter_Registry::membership_level_url( 'jturlsell_1' )
		);
	}

	public function test_an_adapter_without_the_optional_method_resolves_to_empty(): void {
		Adapter_Registry::register_membership( 'jt-url-granting', $this->granting_adapter( 'jturlgrant_' ) );

		$this->assertSame(
			'',
			Adapter_Registry::membership_level_url( 'jturlgrant_1' ),
			'a granted level has nowhere to buy it, and that must not be an error'
		);
	}

	public function test_an_unknown_level_resolves_to_empty(): void {
		$this->assertSame( '', Adapter_Registry::membership_level_url( 'nobody_owns_this_9' ) );
	}

	public function test_an_empty_level_resolves_to_empty(): void {
		$this->assertSame( '', Adapter_Registry::membership_level_url( '' ) );
	}

	public function test_a_site_can_redirect_the_button_through_the_filter(): void {
		Adapter_Registry::register_membership(
			'jt-url-filtered',
			$this->selling_adapter( 'jturlfilt_', 'https://example.test/plans/vip/' )
		);

		add_filter(
			'jetonomy_membership_upgrade_url',
			static fn( $url, $level_id ) => 'https://example.test/custom-funnel/?level=' . rawurlencode( $level_id ),
			10,
			2
		);

		$this->assertSame(
			'https://example.test/custom-funnel/?level=jturlfilt_1',
			Adapter_Registry::membership_level_url( 'jturlfilt_1' ),
			'owners route the button through their own funnel with this filter'
		);
	}

	/**
	 * The filter also has to be able to SUPPLY a URL where the adapter has
	 * none - that is the escape hatch for the granted-level adapters, and the
	 * reason those are documented as intentional '' returns rather than gaps.
	 */
	public function test_the_filter_can_supply_a_url_an_adapter_lacks(): void {
		Adapter_Registry::register_membership( 'jt-url-supplied', $this->granting_adapter( 'jturlsupp_' ) );

		add_filter(
			'jetonomy_membership_upgrade_url',
			static fn( $url ) => '' === $url ? 'https://example.test/join/' : $url
		);

		$this->assertSame( 'https://example.test/join/', Adapter_Registry::membership_level_url( 'jturlsupp_1' ) );
	}

	public function test_a_hostile_url_is_not_returned_raw(): void {
		Adapter_Registry::register_membership(
			'jt-url-hostile',
			$this->selling_adapter( 'jturlhost_', 'javascript:alert(1)' )
		);

		$this->assertStringNotContainsString(
			'javascript:',
			Adapter_Registry::membership_level_url( 'jturlhost_1' ),
			'the resolved URL goes straight into an href, so it is escaped at the source'
		);
	}
}
