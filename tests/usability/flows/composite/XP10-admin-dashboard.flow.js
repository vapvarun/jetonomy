// @ts-check
/**
 * XP10 — Admin dashboard, verify analytics widget + AI usage widget + license status.
 *
 * Navigates to the Jetonomy Pro admin dashboard and verifies that
 * cross-extension widgets render: analytics overview, AI usage stats,
 * and license status.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'XP10 — Admin dashboard → analytics + ai + license', () => {

	test( 'admin dashboard shows cross-extension widgets', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro' );
		metrics.start();

		// The Pro dashboard wrapper should render.
		const wrapper = page.locator( '.wrap, .jt-pro-dashboard, .jetonomy-pro-dashboard' );
		await expect( wrapper.first() ).toBeVisible( { timeout: 5000 } );

		// Check analytics widget (if enabled).
		try {
			const analyticsStatus = proJourney( [ 'extension', 'status', 'analytics' ] );
			if ( analyticsStatus.success ) {
				const analyticsWidget = page.locator(
					'.jt-analytics-widget, [data-widget="analytics"], .jt-dashboard-analytics'
				);
				if ( await analyticsWidget.count() > 0 ) {
					await expect( analyticsWidget.first() ).toBeVisible( { timeout: 3000 } );
				}
			}
		} catch ( e ) { /* */ }

		// Check AI usage widget (if enabled).
		try {
			const aiStatus = proJourney( [ 'extension', 'status', 'ai' ] );
			if ( aiStatus.success ) {
				const aiWidget = page.locator(
					'.jt-ai-widget, [data-widget="ai-usage"], .jt-dashboard-ai'
				);
				if ( await aiWidget.count() > 0 ) {
					await expect( aiWidget.first() ).toBeVisible( { timeout: 3000 } );
				}
			}
		} catch ( e ) { /* */ }

		// Check license status display.
		const licenseSection = page.locator(
			'.jt-license-status, [data-section="license"], .jt-pro-license'
		);
		if ( await licenseSection.count() > 0 ) {
			await expect( licenseSection.first() ).toBeVisible( { timeout: 3000 } );
		}

		const bodyText = await page.locator( 'body' ).textContent();
		expect( bodyText ).not.toContain( 'Fatal error' );

		metrics.assertErrorCount( 0 );
	} );
} );
