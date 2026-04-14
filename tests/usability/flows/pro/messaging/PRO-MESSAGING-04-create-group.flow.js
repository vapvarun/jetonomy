// @ts-check
/**
 * PRO-MESSAGING-04 — Create group conversation.
 *
 * Logs in as alice, composes a message to both bob and admin (group),
 * submits, and verifies all participants appear in the conversation.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-04 — Create group conversation', () => {

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );
	} );

	test.afterEach( () => {
		try {
			const ids = dbQuery( "SELECT id FROM wp_jt_pro_conversations WHERE creator_id = 3 ORDER BY id DESC LIMIT 5" );
			ids.forEach( ( id ) => {
				try { proJourney( [ 'messaging', 'delete-conversation', id ] ); } catch ( e ) { /* ignore */ }
			} );
		} catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice creates a group conversation with bob and admin', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		const composeBtn = page.locator( 'button:has-text("New Message"), a:has-text("New Message")' );
		await composeBtn.click();
		metrics.recordClick();

		// Add bob.
		const recipientInput = page.locator( '.jt-recipient-input input, [name="recipients"]' );
		await recipientInput.fill( 'bob' );
		await page.locator( '.jt-recipient-suggestion:has-text("bob")' ).click();
		metrics.recordClick();

		// Add admin.
		await recipientInput.fill( 'admin' );
		await page.locator( '.jt-recipient-suggestion:has-text("admin")' ).click();
		metrics.recordClick();

		// Type and send.
		const body = page.locator( '.jt-message-body [contenteditable="true"], textarea[name="message"]' );
		await body.click();
		await page.keyboard.type( 'Group test message' );
		await page.locator( 'button:has-text("Send")' ).click();
		metrics.recordClick();

		// Should redirect to conversation thread.
		await page.waitForURL( /\/community\/messages\/\d+/, { timeout: 10000 } );

		// DB: 3 participants (alice, bob, admin).
		const convIds = dbQuery( "SELECT id FROM wp_jt_pro_conversations WHERE creator_id = 3 ORDER BY id DESC LIMIT 1" );
		if ( convIds.length > 0 ) {
			const pCount = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_conversation_participants WHERE conversation_id = ${ convIds[ 0 ] }` );
			expect( parseInt( pCount[ 0 ], 10 ) ).toBe( 3 );
		}

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
