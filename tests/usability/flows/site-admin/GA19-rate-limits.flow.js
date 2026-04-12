// @ts-check
/**
 * GA19 — Rate limits display
 *
 * Visit the permissions tab and verify that rate limit values display
 * for posts, replies, and votes.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA19 — Rate limit values display on permissions tab', () => {

	test( 'rate limit values for posts/replies/votes are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=permissions' );
		metrics.start();

		// Rate limit section should render.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// At least one rate-limit input should have a numeric value.
		const rateLimitInputs = page.locator( 'input[type="number"][name*="rate"], input[type="number"][name*="limit"]' );
		const count = await rateLimitInputs.count();
		expect( count ).toBeGreaterThan( 0 );

		// Cross-check with DB: rate limits should exist in settings.
		const hasRL = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			$rl = $s['rate_limits'] ?? [];
			echo isset( $rl['posts'], $rl['replies'], $rl['votes'] ) ? 'yes' : 'no';
		` ] );
		expect( hasRL ).toBe( 'yes' );

		metrics.assertErrorCount( 0 );
	} );
} );
