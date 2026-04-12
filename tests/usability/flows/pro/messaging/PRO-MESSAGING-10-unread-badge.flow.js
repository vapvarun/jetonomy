// @ts-check
/**
 * PRO-MESSAGING-10 — Unread badge count in header.
 *
 * Seeds unread messages for alice, navigates to any community page, and
 * asserts the header messages icon shows the correct unread badge count.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-10 — Unread badge count in header', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Badge Test',
		] );
		conversationId = conv.data?.id;

		// Bob sends a message — alice has 1 unread.
		if ( conversationId ) {
			proJourney( [
				'messaging', 'send-message',
				`--conversation=${ conversationId }`,
				'--sender=4',
				'--body=You have mail!',
			] );
		}
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'header shows unread messages badge', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/' );
		metrics.start();

		// The messages icon badge in the header should show count >= 1.
		const badge = page.locator( '.jt-header-messages-badge, .jt-messages-icon .badge' );
		await expect( badge ).toBeVisible( { timeout: 5000 } );
		const text = await badge.textContent();
		expect( parseInt( text?.trim() || '0', 10 ) ).toBeGreaterThanOrEqual( 1 );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
