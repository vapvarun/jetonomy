// @ts-check
/**
 * Basecamp 9763494148 — "Settings defaults not persisted"
 *
 * No browser needed for this flow — it's a pure CLI + DB verification.
 * Confirms that deactivating → clearing settings → reactivating seeds
 * trust_thresholds and rate_limits via the canonical defaults, and that
 * wp jetonomy qa reports Settings 6/6 OK.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );
const { loadExpectation, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'Basecamp 9763494148 — settings defaults seeded on activation', () => {

	const cardId = '9763494148';
	let expectation;
	let originalSettings;

	test.beforeAll( () => {
		expectation = loadExpectation( cardId );
	} );

	test.beforeEach( () => {
		// Snapshot the current settings so we can restore them after the
		// destructive deactivate/activate cycle.
		originalSettings = wp( [ 'option', 'get', 'jetonomy_settings', '--format=json' ], { json: true } );
	} );

	test.afterEach( () => {
		// Restore original settings and ensure the plugin is active.
		wp( [ 'option', 'update', 'jetonomy_settings', JSON.stringify( originalSettings ), '--format=json' ] );
		try {
			wp( [ 'plugin', 'activate', 'jetonomy' ] );
		} catch ( e ) { /* already active */ }
	} );

	test( 'deactivate → clear keys → reactivate → keys seeded', () => {
		// Layer 2 — clear the two keys from the stored settings.
		wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			unset( $s['trust_thresholds'], $s['rate_limits'] );
			update_option( 'jetonomy_settings', $s );
			echo 'cleared';
		` ] );

		// Verify they're actually gone.
		const cleared = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo empty( $s['trust_thresholds'] ) && empty( $s['rate_limits'] ) ? 'yes' : 'no';
		` ] );
		expect( cleared ).toBe( 'yes' );

		// Deactivate + reactivate to trigger Jetonomy::activate().
		wp( [ 'plugin', 'deactivate', 'jetonomy' ] );
		wp( [ 'plugin', 'activate', 'jetonomy' ] );

		// Layer 2 — verify both keys are now present with correct shapes.
		const seeded = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			$tt = $s['trust_thresholds'] ?? null;
			$rl = $s['rate_limits'] ?? null;
			echo wp_json_encode( [
				'trust_thresholds_present' => is_array( $tt ) && isset( $tt[1], $tt[2], $tt[3] ),
				'rate_limits_present'      => is_array( $rl ) && isset( $rl['posts'], $rl['replies'], $rl['votes'] ),
				'tt_1_posts'               => $tt[1]['posts'] ?? null,
				'rl_posts'                 => $rl['posts'] ?? null,
			] );
		` ], { json: true } );

		expect( seeded.trust_thresholds_present ).toBe( true );
		expect( seeded.rate_limits_present ).toBe( true );
		expect( seeded.tt_1_posts ).toBe( 5 ); // canonical default
		expect( seeded.rl_posts ).toBe( 3 );   // canonical default

		// Layer 2 — run wp jetonomy qa and grep for Settings pass.
		const qa = wp( [ 'jetonomy', 'qa' ] );
		expect( qa ).toContain( 'Settings' );
		expect( qa ).toMatch( /Settings\s+6\/6\s+OK/ );

		// Layer 5 — expectation.
		matchDelivery( expectation, {
			trust_thresholds_seeded_on_activation: seeded.trust_thresholds_present,
			rate_limits_seeded_on_activation: seeded.rate_limits_present,
			qa_settings_passes: /6\/6\s+OK/.test( qa ),
			existing_values_not_overwritten_on_reactivation: true, // tested separately below
		} );
	} );

	test( 'reactivation does not overwrite admin-customized values', () => {
		// Set a custom trust threshold that differs from the default.
		wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			$s['trust_thresholds'][1]['posts'] = 99;
			update_option( 'jetonomy_settings', $s );
			echo 'custom';
		` ] );

		// Deactivate + reactivate.
		wp( [ 'plugin', 'deactivate', 'jetonomy' ] );
		wp( [ 'plugin', 'activate', 'jetonomy' ] );

		// The customized value must survive the reactivation because
		// activate() guards each seed block with empty().
		const after = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo $s['trust_thresholds'][1]['posts'] ?? 'missing';
		` ] );
		expect( after ).toBe( '99' );
	} );
} );
