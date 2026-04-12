// @ts-check
/**
 * GA12 — Settings general tab.
 *
 * Visits the settings page general tab, asserts form fields render,
 * and verifies the rendered field values match the database values.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA12 — Edit general settings tab', () => {

	const specId = 'GA12';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'general settings field values match database', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read settings from DB via wp eval.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( $s );
		` ], { json: true } );

		const dbBaseSlug = settingsJson?.base_slug || 'community';
		const dbPostsPerPage = String( settingsJson?.posts_per_page || '20' );

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

		// Assert base_slug field renders and its value matches DB.
		const baseSlug = page.locator(
			'input[name*="base_slug"], input[id*="base_slug"], input[name="jetonomy_settings[base_slug]"]'
		);
		await expect( baseSlug.first() ).toBeVisible( { timeout: 5000 } );
		const renderedSlug = await baseSlug.first().inputValue();
		const slugMatchesDb = renderedSlug === dbBaseSlug;

		// Assert posts_per_page field renders and its value matches DB.
		const postsPerPage = page.locator(
			'input[name*="posts_per_page"], input[id*="posts_per_page"], input[name="jetonomy_settings[posts_per_page]"]'
		);
		await expect( postsPerPage.first() ).toBeVisible( { timeout: 5000 } );
		const renderedPpp = await postsPerPage.first().inputValue();
		const pppMatchesDb = renderedPpp === dbPostsPerPage;

		// Hard assertion: values must match.
		expect( renderedSlug ).toBe( dbBaseSlug );
		expect( renderedPpp ).toBe( dbPostsPerPage );

		// Assert no PHP fatal.
		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			general_tab_visible: true,
			base_slug_field_visible: true,
			posts_per_page_field_visible: true,
			no_php_fatal: ! bodyText.includes( 'Fatal error' ),
			base_slug_matches_db: slugMatchesDb,
			posts_per_page_matches_db: pppMatchesDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
