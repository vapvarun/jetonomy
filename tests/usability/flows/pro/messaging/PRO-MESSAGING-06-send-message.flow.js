// @ts-check
/**
 * PRO-MESSAGING-06 — Send message in conversation.
 *
 * Opens an existing conversation, types a new message, clicks send,
 * and asserts the message appears in the thread and is persisted to DB.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite, dbQuery } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-06 — Send message in conversation', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Send Test',
		] );
		conversationId = conv.data?.id;
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice sends a message in an existing conversation', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const msgText = `Test msg ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/messages/${ conversationId }/` );
		metrics.start();

		// Type message into the composer.
		const composer = page.locator( '.jt-message-composer [contenteditable="true"], .jt-message-composer textarea' );
		await expect( composer ).toBeVisible( { timeout: 5000 } );
		await composer.click();
		await page.keyboard.type( msgText );

		// Click send.
		const sendBtn = page.locator( '.jt-message-composer button:has-text("Send")' );
		await expect( sendBtn ).toBeVisible();
		await sendBtn.click();
		metrics.recordClick();

		// Message should appear in the thread.
		const newMsg = page.locator( '.jt-message-bubble', { hasText: msgText } );
		await expect( newMsg ).toBeVisible( { timeout: 10000 } );

		// DB: message row exists.
		assertDbRowExists( 'wp_jt_pro_messages', `conversation_id = ${ conversationId } AND sender_id = 3` );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
