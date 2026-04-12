// @ts-check
/**
 * C23 — Mark all notifications read.
 *
 * Logs in as admin, opens the notification bell, clicks "Mark all read",
 * and asserts the badge is removed and all items lose the `.unread` class.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C23 — Mark all notifications read', () => {

	let seededNotifIds = [];

	test.beforeEach( () => {
		// Seed unread notifications for admin (user 1).
		const scenarioRun = journey( [ 'scenario', 'run', 'notification-delivery-sweep' ] );
		if ( scenarioRun.success ) {
			seededNotifIds = scenarioRun.fixtures?.notification_ids || [];
		}
		// Sanity: at least one unread exists.
		const count = parseInt(
			dbQuery( 'SELECT COUNT(*) FROM wp_jt_notifications WHERE user_id = 1 AND is_read = 0' )[ 0 ],
			10
		);
		expect( count ).toBeGreaterThan( 0 );
	} );

	test.afterEach( () => {
		if ( seededNotifIds.length > 0 ) {
			const idList = seededNotifIds.map( ( id ) => parseInt( id, 10 ) ).join( ',' );
			dbWrite( `DELETE FROM wp_jt_notifications WHERE id IN (${ idList })` );
			seededNotifIds = [];
		}
	} );

	test( 'clicking Mark all read removes badge and unread highlights', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// Badge should be visible with a count.
		const badge = page.locator( '.jt-community-nav-badge' ).first();
		await expect( badge ).toBeVisible( { timeout: 5000 } );
		const badgeText = ( await badge.textContent() )?.trim() ?? '0';
		expect( parseInt( badgeText, 10 ) ).toBeGreaterThan( 0 );

		// Open the notification dropdown.
		const bellButton = page.locator( '.jt-notif-dropdown-wrap button' ).first();
		await bellButton.click();
		metrics.recordClick();

		// Wait for items to load.
		const panel = page.locator( '.jt-notif-panel' );
		await expect( panel ).toBeVisible( { timeout: 5000 } );
		const unreadItems = panel.locator( '.jt-notif-panel-item.unread' );
		await expect( unreadItems.first() ).toBeVisible( { timeout: 5000 } );
		const unreadCountBefore = await unreadItems.count();
		expect( unreadCountBefore ).toBeGreaterThan( 0 );

		// Click "Mark all read".
		const markAllBtn = page.locator( '.jt-notif-mark-read' );
		await expect( markAllBtn ).toBeVisible();
		await markAllBtn.click();
		metrics.recordClick();

		// Badge should be removed (or hidden).
		await expect( badge ).not.toBeVisible( { timeout: 5000 } );

		// All items should lose the .unread class.
		const remainingUnread = panel.locator( '.jt-notif-panel-item.unread' );
		await expect.poll( () => remainingUnread.count(), { timeout: 5000 } ).toBe( 0 );

		// Data flow: verify all notifications for admin are marked read in DB.
		await expect.poll( () => {
			const rows = dbQuery( 'SELECT COUNT(*) FROM wp_jt_notifications WHERE user_id = 1 AND is_read = 0' );
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBe( 0 );

		// Ease metrics.
		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
