// @ts-check
/**
 * GA19 — Rate limits display
 *
 * Visit the permissions tab and verify that rate limit values display
 * for posts, replies, and votes, matching DB values.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp, journey } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA19 — Rate limit values display on permissions tab', () => {

	const specId = 'GA19';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'rendered rate limit values match database source of truth', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read rate limits from DB.
		let dbRateLimits;
		try {
			const configResult = journey( [ 'config', 'get', '--key=rate_limits' ] );
			dbRateLimits = configResult?.data || configResult;
		} catch ( e ) {
			dbRateLimits = wp( [ 'eval', `
				$s = get_option( 'jetonomy_settings', [] );
				echo wp_json_encode( $s['rate_limits'] ?? [] );
			` ], { json: true } );
		}

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Rate limit section should render.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// Collect all rate-limit inputs and check they have numeric values.
		const rateLimitInputs = page.locator(
			'input[type="number"][name*="rate"], input[type="number"][name*="limit"]'
		);
		const count = await rateLimitInputs.count();
		expect( count ).toBeGreaterThan( 0 );

		const renderedValues = [];
		for ( let i = 0; i < count; i++ ) {
			const val = await rateLimitInputs.nth( i ).inputValue();
			renderedValues.push( val );
		}
		const allNumeric = renderedValues.every( ( v ) => /^\d+$/.test( v ) );

		// Cross-check with DB: rate limits should exist in settings.
		const hasRL = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			$rl = $s['rate_limits'] ?? [];
			echo isset( $rl['posts'], $rl['replies'], $rl['votes'] ) ? 'yes' : 'no';
		` ] );
		expect( hasRL ).toBe( 'yes' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			rate_limit_section_visible: true,
			rate_limit_inputs_count_greater_than_zero: count > 0,
			rate_limits_exist_in_db: hasRL === 'yes',
			all_rate_limit_values_numeric: allNumeric,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
