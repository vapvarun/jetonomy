// @ts-check
/**
 * C21 — Open in-app notifications.
 *
 * Logs in as admin (who has existing notifications), clicks the bell
 * icon, and asserts the notification dropdown opens with items.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C21 — Open in-app notifications', () => {

	let seededNotifIds = [];

	test.beforeEach( () => {
		// Seed notifications for admin (user 1) if none exist.
		const unreadCount = parseInt(
			dbQuery( 'SELECT COUNT(*) FROM wp_jt_notifications WHERE user_id = 1 AND is_read = 0' )[ 0 ],
			10
		);
		if ( unreadCount === 0 ) {
			const scenarioRun = journey( [ 'scenario', 'run', 'notification-delivery-sweep' ] );
			if ( scenarioRun.success ) {
				seededNotifIds = scenarioRun.fixtures?.notification_ids || [];
			}
		}
	} );

	test.afterEach( () => {
		if ( seededNotifIds.length > 0 ) {
			const idList = seededNotifIds.map( ( id ) => parseInt( id, 10 ) ).join( ',' );
			try {
				dbQuery( `DELETE FROM wp_jt_notifications WHERE id IN (${ idList })` );
			} catch ( e ) { /* ignore */ }
			seededNotifIds = [];
		}
	} );

	test( 'admin clicks bell icon and notification dropdown opens with items', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Login as admin and visit community page.
		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// The notification bell should be visible.
		const bellButton = page.locator( '.jt-notif-dropdown-wrap button' ).first();
		await expect( bellButton ).toBeVisible( { timeout: 5000 } );

		// Badge should show unread count.
		const badge = page.locator( '.jt-community-nav-badge' ).first();
		const hasBadge = await badge.isVisible().catch( () => false );

		// Click the bell to open the dropdown.
		await bellButton.click();
		metrics.recordClick();

		// The notification panel should become visible (hidden attribute removed).
		const panel = page.locator( '.jt-notif-panel' );
		await expect( panel ).toBeVisible( { timeout: 5000 } );

		// Wait for notification items to load (they load via REST API).
		const items = panel.locator( '.jt-notif-panel-item' );
		await expect( items.first() ).toBeVisible( { timeout: 5000 } );

		// There should be at least one notification item.
		const itemCount = await items.count();
		expect( itemCount ).toBeGreaterThan( 0 );

		// The "View all notifications" link should be present.
		const viewAllLink = panel.locator( '.jt-notif-panel-footer' );
		await expect( viewAllLink ).toBeVisible();
		await expect( viewAllLink ).toHaveAttribute( 'href', /\/notifications\// );

		// The "Mark all read" button should be present.
		const markAllBtn = panel.locator( '.jt-notif-mark-read' );
		await expect( markAllBtn ).toBeVisible();

		// Ease metrics.
		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
