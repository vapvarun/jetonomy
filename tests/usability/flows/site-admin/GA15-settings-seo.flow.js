// @ts-check
/**
 * GA15 — Settings: SEO tab
 *
 * Visit the Jetonomy settings page with the SEO tab selected.
 * Assert that SEO options render (meta title pattern, noindex toggles, etc.).
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA15 — Settings SEO tab renders SEO options', () => {

	test( 'SEO configuration fields are visible', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings&tab=seo' );
		metrics.start();

		// SEO tab should be active — look for the tab or heading.
		const seoHeading = page.locator( 'text=/seo/i' ).first();
		await expect( seoHeading ).toBeVisible( { timeout: 5000 } );

		// At least one SEO-related input or toggle should be present.
		const seoField = page.locator( [
			'input[name*="seo"]',
			'select[name*="seo"]',
			'input[name*="noindex"]',
			'input[name*="meta_title"]',
			'input[name*="canonical"]',
		].join( ', ' ) ).first();
		await expect( seoField ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
