// @ts-check
/**
 * GA16 — Settings: Appearance tab
 *
 * Visit the Jetonomy settings page with the appearance tab selected.
 * Assert that accent color and container width fields render.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA16 — Settings appearance tab renders accent color + container width', () => {

	test( 'accent color and container width fields are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=appearance' );
		metrics.start();

		// Accent color field — could be a color input or text input.
		const accentColor = page.locator( [
			'input[type="color"][name*="accent"]',
			'input[name*="accent_color"]',
			'input[id*="accent"]',
		].join( ', ' ) ).first();
		await expect( accentColor ).toBeVisible( { timeout: 5000 } );

		// Container width field.
		const containerWidth = page.locator( [
			'input[name*="container_width"]',
			'input[id*="container_width"]',
			'input[name*="container-width"]',
			'select[name*="container_width"]',
		].join( ', ' ) ).first();
		await expect( containerWidth ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
