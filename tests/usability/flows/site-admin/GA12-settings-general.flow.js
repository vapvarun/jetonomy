// @ts-check
/**
 * GA12 — Settings general tab.
 *
 * Visits the settings page general tab and asserts form fields render
 * (base_slug, posts_per_page, etc).
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'GA12 — Edit general settings tab', () => {

	test( 'general settings tab renders with expected fields', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-settings' );
		metrics.start();

		// Assert page renders.
		const wrapper = page.locator( '.wrap, .jetonomy-settings' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Assert the general tab is active or present.
		const generalTab = page.locator(
			'a.nav-tab:has-text("General"), a.nav-tab-active:has-text("General"), [data-tab="general"]'
		);
		await expect( generalTab.first() ).toBeVisible( { timeout: 5000 } );

		// Assert base_slug field renders.
		const baseSlug = page.locator(
			'input[name*="base_slug"], input[id*="base_slug"], input[name="jetonomy_settings[base_slug]"]'
		);
		await expect( baseSlug.first() ).toBeVisible( { timeout: 5000 } );

		// Assert posts_per_page field renders.
		const postsPerPage = page.locator(
			'input[name*="posts_per_page"], input[id*="posts_per_page"], input[name="jetonomy_settings[posts_per_page]"]'
		);
		await expect( postsPerPage.first() ).toBeVisible( { timeout: 5000 } );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
