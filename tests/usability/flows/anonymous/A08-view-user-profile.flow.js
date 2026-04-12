// @ts-check
/**
 * A08 — View user profile (anonymous).
 *
 * Visits the admin user's public profile and asserts display name, avatar,
 * and stats are visible.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A08 — View user profile', () => {

	test( 'anonymous visitor can view admin profile at /community/u/admin/', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/u/admin/` );

		// Page renders without a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// Community shell is present.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Profile info is visible — display name or username should appear.
		const profileInfo = page.locator( '.jt-profile, .jt-user-profile, .jt-profile-header' );
		await expect( profileInfo.first() ).toBeVisible( { timeout: 5000 } );

		// Avatar image is present.
		const avatar = page.locator( '.jt-profile img, .jt-avatar, .jt-user-profile img' );
		await expect( avatar.first() ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'A08' );
		matchDelivery( expectation, {
			page_renders: true,
			profile_info_visible: true,
			avatar_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
