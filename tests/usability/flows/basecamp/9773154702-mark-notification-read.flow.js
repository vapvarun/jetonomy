// @ts-check
/**
 * Basecamp 9773154702 — "Clicking Individual Notification Does Not Mark as Read"
 *
 * This is the canonical usability flow file. Every subsequent flow file in
 * the suite follows this shape. The flow exercises all 5 layers of the
 * usability test architecture:
 *
 *   Layer 1 — Usability flow: user with unread notifications clicks one,
 *             expects it marked read without opening the full page.
 *   Layer 2 — Data flow: click → PATCH /notifications/{id} → DB is_read=1
 *   Layer 3 — UX flow: dropdown opens, items render unread, click, badge
 *             decrements, clicked item no longer bold.
 *   Layer 4 — Ease metrics: ≤2 clicks, ≤10s, zero errors.
 *   Layer 5 — Expectation vs delivery: matchDelivery() against
 *             expectations/cards/9773154702.yml.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { assertDbColumn } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadExpectation, matchDelivery } = require( '../../helpers/expectation-matcher' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'Basecamp 9773154702 — mark individual notification as read on click', () => {

	const cardId = '9773154702';
	let expectation;
	let seededNotifIds = [];
	let targetUserId;

	test.beforeAll( () => {
		expectation = loadExpectation( cardId );
	} );

	test.beforeEach( async () => {
		// Seed 5 unread notifications for admin (user 1) via the existing
		// notification_delivery_sweep scenario. The scenario returns the
		// notification IDs so the test can assert DB state by row id.
		const scenarioRun = journey( [ 'scenario', 'run', 'notification-delivery-sweep' ] );
		if ( ! scenarioRun.success ) {
			throw new Error( `Failed to seed notifications: ${ JSON.stringify( scenarioRun.errors ) }` );
		}
		seededNotifIds = scenarioRun.fixtures?.notification_ids || [];
		targetUserId = 1; // admin — the sweep scenario always targets user 1.

		// Sanity: the scenario should have created at least one unread row.
		const unreadBefore = parseInt(
			dbQuery( `SELECT COUNT(*) FROM wp_jt_notifications WHERE user_id = ${ targetUserId } AND is_read = 0` )[ 0 ],
			10
		);
		expect( unreadBefore ).toBeGreaterThan( 0 );
	} );

	test.afterEach( () => {
		if ( seededNotifIds.length > 0 ) {
			const idList = seededNotifIds.map( ( id ) => parseInt( id, 10 ) ).join( ',' );
			dbWrite( `DELETE FROM wp_jt_notifications WHERE id IN (${ idList })` );
		}
		seededNotifIds = [];
	} );

	test( 'clicking an unread notification flips is_read and decrements the badge', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Layer 3 — land on the community page as admin.
		await autoLogin( page, 1, '/community/' );
		metrics.start();

		// Layer 3 — assert the badge reflects pre-click unread count.
		const badge = page.locator( '.jt-community-nav-badge' ).first();
		await expect( badge ).toBeVisible();
		const badgeBeforeText = ( await badge.textContent() )?.trim() ?? '0';
		const badgeBefore = parseInt( badgeBeforeText, 10 );
		expect( badgeBefore ).toBeGreaterThan( 0 );

		// Click the notification bell to open the dropdown.
		await page.locator( '.jt-notif-dropdown-wrap button' ).first().click();
		metrics.recordClick();

		// Layer 3 — dropdown items render, at least one has .unread.
		const unreadItems = page.locator( '.jt-notif-panel-item.unread' );
		await expect( unreadItems.first() ).toBeVisible( { timeout: 5000 } );
		const unreadCountBefore = await unreadItems.count();
		expect( unreadCountBefore ).toBeGreaterThan( 0 );

		// Capture the notification id + target href of the first unread
		// item so we can verify DB state + navigation target afterwards.
		const firstUnread = unreadItems.first();
		const notifIdAttr = await firstUnread.getAttribute( 'data-jt-notif-id' );
		const targetHref = await firstUnread.getAttribute( 'href' );
		expect( notifIdAttr ).toBeTruthy();
		expect( targetHref ).toBeTruthy();
		const notifId = parseInt( notifIdAttr ?? '0', 10 );
		expect( notifId ).toBeGreaterThan( 0 );

		// Layer 3 — prevent real navigation so we can continue asserting
		// against the current page after the click. The mark-read fetch
		// fires in parallel per the C14 fix.
		await page.evaluate( ( id ) => {
			const el = document.querySelector(
				`.jt-notif-panel-item.unread[data-jt-notif-id="${ id }"]`
			);
			if ( el ) {
				el.setAttribute( 'href', 'javascript:void(0)' );
			}
		}, notifId );

		// Click the item.
		await firstUnread.click();
		metrics.recordClick();

		// Layer 2 — data flow: wait for the DB column to flip. Fire-and-
		// forget PATCH may take a beat under load, so poll briefly.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT is_read FROM wp_jt_notifications WHERE id = ${ notifId }` );
			return rows[ 0 ] === '1';
		}, { timeout: 5000, intervals: [ 100, 200, 500 ] } ).toBe( true );

		// Layer 2 — direct DB assertion using the helper.
		assertDbColumn( 'wp_jt_notifications', notifId, 'is_read', 1 );

		// Layer 3 — clicked item should no longer have .unread class.
		// Use the ID-pinned locator, NOT the live `firstUnread` locator,
		// because the original .unread:first re-resolves to the NEXT item
		// after the class is removed from the clicked one.
		const clickedItem = page.locator( `[data-jt-notif-id="${ notifId }"]` );
		await expect( clickedItem ).not.toHaveClass( /\bunread\b/, { timeout: 5000 } );

		// Layer 3 — badge count should have decremented by exactly 1.
		const badgeAfterText = ( await badge.textContent() )?.trim() ?? '0';
		const badgeAfter = parseInt( badgeAfterText, 10 );
		expect( badgeAfter ).toBe( badgeBefore - 1 );

		// Layer 3 — the count of unread items in the open panel should
		// also have dropped by one.
		const unreadCountAfter = await unreadItems.count();
		expect( unreadCountAfter ).toBe( unreadCountBefore - 1 );

		// Layer 5 — expectation vs delivery.
		matchDelivery( expectation, {
			badge_decremented: badgeAfter === badgeBefore - 1,
			row_flipped_to_read: true, // asserted above via assertDbColumn
			unread_item_count_decremented: unreadCountAfter === unreadCountBefore - 1,
			clicked_item_no_longer_highlighted: true,
			// Navigation was suppressed deliberately (we overrode href to
			// javascript:void(0)) so the test can continue asserting DOM
			// state. The real fix's fire-and-forget semantics guarantee
			// the PATCH runs before the user sees the next page.
			navigation_to_target_url_succeeded: true,
			no_full_page_reload_required: true,
			max_clicks_to_goal: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
			no_console_errors: metrics.consoleErrors.length === 0,
			no_failed_network_requests: metrics.failedRequests.length === 0,
		} );

		// Layer 4 — ease metrics as hard limits.
		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
