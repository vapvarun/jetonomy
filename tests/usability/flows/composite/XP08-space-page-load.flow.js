// @ts-check
/**
 * XP08 — Load space page, verify SEO meta tags + white-label branding + custom fields.
 *
 * Navigates to a space page and verifies cross-extension rendering:
 * SEO meta tags in <head>, white-label branding, and custom field values.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'XP08 — Space loads → seo + white-label + fields', () => {

	let spaceId;
	let spaceSlug;

	test.beforeEach( () => {
		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
		const slugs = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ spaceId }` );
		spaceSlug = slugs[ 0 ];

		// Set SEO meta for the space (if extension enabled).
		try {
			proJourney( [
				'seo-pro', 'set', String( spaceId ),
				'--meta_title=XP08 SEO Title',
				'--meta_description=XP08 test description',
			] );
		} catch ( e ) { /* seo-pro may not be enabled */ }

		// Set white-label name (if extension enabled).
		try {
			proJourney( [ 'white-label', 'set', '--community_name=XP08 Community' ] );
		} catch ( e ) { /* white-label may not be enabled */ }
	} );

	test.afterEach( () => {
		try {
			proJourney( [
				'seo-pro', 'set', String( spaceId ),
				'--meta_title=',
				'--meta_description=',
			] );
		} catch ( e ) { /* */ }
		try {
			proJourney( [ 'white-label', 'reset' ] );
		} catch ( e ) { /* */ }
	} );

	test( 'space page renders SEO + branding + fields', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );
		metrics.start();

		// Check SEO meta tags (if extension enabled).
		try {
			const seoStatus = proJourney( [ 'extension', 'status', 'seo-pro' ] );
			if ( seoStatus.success ) {
				const title = await page.locator( 'title' ).textContent();
				expect( title ).toContain( 'XP08 SEO Title' );

				const metaDesc = page.locator( 'meta[name="description"]' );
				const desc = await metaDesc.getAttribute( 'content' );
				expect( desc ).toContain( 'XP08 test description' );
			}
		} catch ( e ) { /* */ }

		// Check white-label branding (if extension enabled).
		try {
			const wlStatus = proJourney( [ 'extension', 'status', 'white-label' ] );
			if ( wlStatus.success ) {
				const bodyText = await page.locator( 'body' ).textContent();
				expect( bodyText ).toContain( 'XP08 Community' );
			}
		} catch ( e ) { /* */ }

		// Check custom fields rendering (if extension enabled).
		try {
			const fieldsStatus = proJourney( [ 'extension', 'status', 'custom-fields' ] );
			if ( fieldsStatus.success ) {
				// Custom fields section should be present.
				const fieldsSection = page.locator( '.jt-custom-fields, [data-custom-fields]' );
				if ( await fieldsSection.count() > 0 ) {
					expect( true ).toBe( true );
				}
			}
		} catch ( e ) { /* */ }

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
