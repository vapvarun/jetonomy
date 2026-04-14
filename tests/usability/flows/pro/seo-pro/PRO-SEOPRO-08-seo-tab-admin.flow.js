// @ts-check
/**
 * PRO-SEOPRO-08 — Admin SEO tab on space edit.
 *
 * Navigates to a space edit page in admin and verifies the SEO tab
 * is present with meta title and description fields.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-SEOPRO-08 — Admin SEO tab on space edit', () => {

	let spaceId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'seo-pro' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'seo-pro' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test( 'SEO tab renders with meta fields', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin(
			page, 1,
			`/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ spaceId }&tab=seo`
		);
		metrics.start();

		// SEO tab should be active or present.
		const seoTab = page.locator(
			'a.nav-tab:has-text("SEO"), a.nav-tab-active:has-text("SEO"), [data-tab="seo"]'
		);
		await expect( seoTab.first() ).toBeVisible( { timeout: 5000 } );

		// Meta title input.
		const metaTitle = page.locator(
			'input[name*="meta_title"], input[id*="meta_title"]'
		);
		await expect( metaTitle.first() ).toBeVisible( { timeout: 5000 } );

		// Meta description input.
		const metaDesc = page.locator(
			'textarea[name*="meta_description"], input[name*="meta_description"]'
		);
		await expect( metaDesc.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
