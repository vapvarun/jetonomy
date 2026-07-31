<?php
namespace Jetonomy\Tests\Unit\API;

use WP_UnitTestCase;
use Jetonomy\Integrations\App_Connect;

/**
 * The app/config `auth` block — how the mobile app discovers its sign-in door.
 *
 * Wbcom App Auth standard (docs/standards/app-auth.md): one bridge per site,
 * BuddyNext's when BN is active, and the app contains ZERO of that logic — it
 * reads this block and goes where it is pointed. These tests pin the contract
 * shape (byte-compatible with BuddyNext's, so one app client serves every
 * product) and the seams. The BN-active branch is not mocked here — BN is not
 * loaded in this suite — it is covered by the filter-override test plus live
 * verification on a combined site (rollout plan, phase J1).
 */
class AppConfigAuthBlockTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'jetonomy_app_connect_bridge' );
		remove_all_filters( 'jetonomy_app_connect_schemes' );
		parent::tear_down();
	}

	/**
	 * The exact standard shape, standalone. social_providers is present and
	 * empty — Jetonomy has no social system; shape parity with BN is what
	 * lets one app client parse every product's config.
	 */
	public function test_auth_block_shape_standalone(): void {
		$request  = new \WP_REST_Request( 'GET', '/jetonomy/v1/app/config' );
		$response = rest_do_request( $request );
		$auth     = $response->get_data()['auth'] ?? null;

		$this->assertIsArray( $auth, 'the auth block must be present' );
		$this->assertSame(
			array( 'social_providers', 'twofactor', 'register', 'app_passwords_available', 'connect_url', 'connect_schemes' ),
			array_keys( $auth ),
			'key order and set are the cross-product contract'
		);
		$this->assertSame( array(), $auth['social_providers'] );
		$this->assertFalse( $auth['twofactor'] );
		$this->assertIsBool( $auth['register'] );
		$this->assertIsBool( $auth['app_passwords_available'] );
		$this->assertContains( 'jetonomyapp', $auth['connect_schemes'] );
		// J3 shipped the standalone bridge: a BN-less site advertises its own
		// router-resolved connect-app door, honouring a renamed base slug.
		$this->assertSame( home_url( '/community/connect-app/' ), $auth['connect_url'] );

		update_option( 'jetonomy_settings', array( 'base_slug' => 'forums' ) );
		$renamed = rest_do_request( new \WP_REST_Request( 'GET', '/jetonomy/v1/app/config' ) )->get_data()['auth'];
		delete_option( 'jetonomy_settings' );
		$this->assertSame( home_url( '/forums/connect-app/' ), $renamed['connect_url'], 'the bridge URL must follow the base slug' );
	}

	/**
	 * The one-door rule, exercised through the bridge filter (the same seam
	 * the BuddyNext-active branch feeds): whatever the bridge resolver says
	 * is what the app is told, verbatim.
	 */
	public function test_config_advertises_the_resolved_bridge(): void {
		add_filter(
			'jetonomy_app_connect_bridge',
			static fn() => array(
				'owner'           => 'buddynext',
				'connect_url'     => 'https://site.test/login/connect-app/',
				'connect_schemes' => array( 'buddynextapp', 'jetonomyapp' ),
			)
		);

		$auth = rest_do_request( new \WP_REST_Request( 'GET', '/jetonomy/v1/app/config' ) )->get_data()['auth'];

		$this->assertSame( 'https://site.test/login/connect-app/', $auth['connect_url'] );
		$this->assertSame( array( 'buddynextapp', 'jetonomyapp' ), $auth['connect_schemes'] );
	}

	/**
	 * Jetonomy's own sibling seam mirrors the one BN exposes for us: a
	 * sibling app registers its scheme with one filter line.
	 */
	public function test_sibling_apps_join_via_the_schemes_filter(): void {
		add_filter(
			'jetonomy_app_connect_schemes',
			static function ( array $schemes ): array {
				$schemes[] = 'learnomyapp';
				return $schemes;
			}
		);

		$this->assertSame( array( 'jetonomyapp', 'learnomyapp' ), App_Connect::schemes() );
	}

	/**
	 * Jetonomy registers its scheme into BUDDYNEXT'S allowlist at boot, so a
	 * Jetonomy-app connection through BN's bridge may deliver to
	 * jetonomyapp://. BN is not loaded here; the filter contract is what is
	 * pinned (live-verified on the combined dev site: BN's schemes() returns
	 * [buddynextapp, jetonomyapp] and its mint emits a jetonomyapp:// link).
	 */
	public function test_jetonomy_joins_buddynexts_allowlist(): void {
		$this->assertSame(
			array( 'buddynextapp', 'jetonomyapp' ),
			apply_filters( 'buddynext_app_connect_schemes', array( 'buddynextapp' ) )
		);
	}
}
