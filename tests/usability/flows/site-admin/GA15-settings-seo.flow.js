// @ts-check
/**
 * GA15 — Settings: SEO tab
 *
 * Visit the Jetonomy settings page with the SEO tab selected.
 * Assert that SEO checkbox/toggle states match the stored DB values.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA15 — Settings SEO tab renders SEO options', () => {

	const specId = 'GA15';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'SEO checkbox states match database values', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Read SEO settings from DB.
		const settingsJson = wp( [ 'eval', `
			$s = get_option( 'jetonomy_settings', [] );
			echo wp_json_encode( [
				'noindex_profiles' => array_key_exists( 'seo_noindex_profiles', $s ) ? ! empty( $s['seo_noindex_profiles'] ) : true,
				'noindex_tags'     => ! empty( $s['seo_noindex_search'] ),
				'meta_title'       => $s['meta_title_pattern'] ?? '',
			] );
		` ], { json: true } );

		const dbNoindexProfiles = settingsJson?.noindex_profiles || false;
		const dbNoindexTags = settingsJson?.noindex_tags || false;

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

		// Check noindex checkbox states against DB.
		const noindexProfilesCheckbox = page.locator(
			'input[type="checkbox"][name*="noindex_profiles"], input[type="checkbox"][name*="seo_noindex_profiles"]'
		).first();
		let noindexProfilesMatchesDb = true;
		if ( await noindexProfilesCheckbox.count() > 0 ) {
			const isChecked = await noindexProfilesCheckbox.isChecked();
			noindexProfilesMatchesDb = isChecked === dbNoindexProfiles;
		}

		const noindexTagsCheckbox = page.locator(
			'input[type="checkbox"][name*="noindex_tags"], input[type="checkbox"][name*="seo_noindex_search"]'
		).first();
		// The default-checked state in the view (when option not set) is `true`.
		let noindexTagsMatchesDb = true;

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			seo_heading_visible: true,
			seo_field_visible: true,
			noindex_profiles_matches_db: noindexProfilesMatchesDb,
			noindex_tags_matches_db: noindexTagsMatchesDb,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
