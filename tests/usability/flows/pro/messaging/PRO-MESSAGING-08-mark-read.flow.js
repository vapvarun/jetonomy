// @ts-check
/**
 * PRO-MESSAGING-08 — Mark conversation read.
 *
 * Seeds an unread conversation, opens it as alice, and verifies the
 * unread indicator disappears and the DB read timestamp is updated.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-08 — Mark conversation read', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Read Test',
		] );
		conversationId = conv.data?.id;

		// Bob sends a message so alice has unread.
		if ( conversationId ) {
			proJourney( [
				'messaging', 'send-message',
				`--conversation=${ conversationId }`,
				'--sender=4',
				'--body=Unread message for alice',
			] );
		}
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'opening a conversation marks it as read', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Navigate to inbox first — should show unread indicator.
		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		const unreadRow = page.locator( '.jt-conversation-row.unread, .jt-conversation-row[data-unread="true"]' ).first();
		await expect( unreadRow ).toBeVisible( { timeout: 5000 } );

		// Click into the conversation.
		await unreadRow.click();
		metrics.recordClick();

		await page.waitForURL( /\/community\/messages\/\d+/, { timeout: 10000 } );

		// Go back to inbox — the row should no longer be unread.
		await page.goto( '/community/messages/' );
		const readRow = page.locator( `.jt-conversation-row[data-id="${ conversationId }"]` ).first();
		await expect( readRow ).not.toHaveClass( /unread/, { timeout: 5000 } );

		// DB: participant read_at should be set.
		const readAt = dbQuery(
			`SELECT read_at FROM wp_jt_pro_conversation_participants WHERE conversation_id = ${ conversationId } AND user_id = 3`
		);
		expect( readAt.length ).toBeGreaterThan( 0 );
		expect( readAt[ 0 ] ).not.toBe( '' );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
