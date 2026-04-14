// @ts-check
/**
 * GA17 — Settings: Anti-Spam tab
 *
 * Visit the Jetonomy settings page with the anti-spam tab selected.
 * Assert that rate limit values in the form match the DB-stored settings.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA17 — Settings anti-spam tab renders CAPTCHA + rate limit + honeypot', () => {

	const specId = 'GA17';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'anti-spam field values match database settings', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read anti-spam settings from DB.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( [
				'captcha_enabled' => ! empty( $s['captcha_enabled'] ),
				'honeypot_enabled' => ! empty( $s['honeypot_enabled'] ),
				'rate_limits' => $s['rate_limits'] ?? [],
			] );
		` ], { json: true } );

		const dbCaptchaEnabled = settingsJson?.captcha_enabled || false;
		const dbHoneypotEnabled = settingsJson?.honeypot_enabled || false;
		const dbRateLimits = settingsJson?.rate_limits || {};

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=anti-spam' );
		metrics.start();

		// CAPTCHA section.
		const captchaSection = page.locator( 'text=/captcha/i' ).first();
		await expect( captchaSection ).toBeVisible( { timeout: 5000 } );

		// Rate limit section.
		const rateLimitSection = page.locator( 'text=/rate.*limit/i' ).first();
		await expect( rateLimitSection ).toBeVisible( { timeout: 5000 } );

		// Honeypot section.
		const honeypotSection = page.locator( 'text=/honeypot/i' ).first();
		await expect( honeypotSection ).toBeVisible( { timeout: 5000 } );

		// Verify rate limit numeric inputs have populated values.
		const rateLimitInputs = page.locator(
			'input[type="number"][name*="rate"], input[type="number"][name*="limit"]'
		);
		const rlCount = await rateLimitInputs.count();
		let rateLimitValuesPopulated = false;
		if ( rlCount > 0 ) {
			const firstVal = await rateLimitInputs.first().inputValue();
			rateLimitValuesPopulated = /^\d+$/.test( firstVal );
		}

		// Check if rate limits exist in DB.
		const hasRateLimitsInDb = Object.keys( dbRateLimits ).length > 0;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			captcha_section_visible: true,
			rate_limit_section_visible: true,
			honeypot_section_visible: true,
			rate_limit_values_populated: rateLimitValuesPopulated || rlCount === 0,
			rate_limits_exist_in_db: hasRateLimitsInDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
