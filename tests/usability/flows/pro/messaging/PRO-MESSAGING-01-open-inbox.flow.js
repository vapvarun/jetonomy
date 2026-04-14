// @ts-check
/**
 * PRO-MESSAGING-01 — Open messages inbox.
 *
 * Logs in as alice, navigates to the messages page, and asserts the inbox
 * container renders with a conversation list.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-01 — Open messages inbox', () => {

	let conversationId;

	test.beforeEach( () => {
		// Ensure alice has trust_level >= 1 to access messaging.
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		// Seed a conversation so inbox is not empty.
		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4', // bob
			'--participants=3', // alice
			'--subject=Inbox Test',
		] );
		conversationId = conv.data?.id;
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice opens the inbox and sees conversations', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		// The inbox container should be visible.
		const inbox = page.locator( '.jt-messages-inbox' );
		await expect( inbox ).toBeVisible( { timeout: 5000 } );

		// At least one conversation row should appear.
		const rows = page.locator( '.jt-conversation-row' );
		await expect( rows.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
