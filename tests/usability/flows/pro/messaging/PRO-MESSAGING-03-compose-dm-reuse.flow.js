// @ts-check
/**
 * PRO-MESSAGING-03 — Compose DM reuse existing thread.
 *
 * When alice already has a conversation with bob and starts a new DM to bob,
 * the system should reuse the existing thread instead of creating a duplicate.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-03 — Compose DM reuse thread', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		// Seed an existing conversation between alice (3) and bob (4).
		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=3',
			'--participants=4',
			'--subject=Existing Thread',
		] );
		conversationId = conv.data?.id;
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'composing DM to bob reuses existing thread', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		// Click compose.
		const composeBtn = page.locator( 'button:has-text("New Message"), a:has-text("New Message")' );
		await composeBtn.click();
		metrics.recordClick();

		// Pick bob as recipient.
		const recipientInput = page.locator( '.jt-recipient-input input, [name="recipients"]' );
		await recipientInput.fill( 'bob' );
		const suggestion = page.locator( '.jt-recipient-suggestion:has-text("bob")' );
		await expect( suggestion ).toBeVisible( { timeout: 5000 } );
		await suggestion.click();
		metrics.recordClick();

		// Type message and send.
		const body = page.locator( '.jt-message-body [contenteditable="true"], textarea[name="message"]' );
		await body.click();
		await page.keyboard.type( 'Reuse test' );
		const sendBtn = page.locator( 'button:has-text("Send")' );
		await sendBtn.click();
		metrics.recordClick();

		// Should redirect to the EXISTING conversation, not a new one.
		await page.waitForURL( new RegExp( `/community/messages/${ conversationId }` ), { timeout: 10000 } );

		// Verify no duplicate conversations were created.
		const count = dbQuery( "SELECT COUNT(*) FROM wp_jt_pro_conversations WHERE creator_id = 3" );
		expect( parseInt( count[ 0 ], 10 ) ).toBe( 1 );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertErrorCount( 0 );
	} );
} );
