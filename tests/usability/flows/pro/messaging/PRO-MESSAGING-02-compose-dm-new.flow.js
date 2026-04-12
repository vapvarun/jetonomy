// @ts-check
/**
 * PRO-MESSAGING-02 — Compose a new DM.
 *
 * Logs in as alice, navigates to the messages page, clicks compose,
 * picks a recipient (bob), types a subject + message, and submits.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-02 — Compose new DM', () => {

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );
	} );

	test.afterEach( () => {
		// Cleanup conversations alice created.
		try {
			const ids = dbQuery( "SELECT id FROM wp_jt_pro_conversations WHERE creator_id = 3 ORDER BY id DESC LIMIT 5" );
			ids.forEach( ( id ) => {
				try { proJourney( [ 'messaging', 'delete-conversation', id ] ); } catch ( e ) { /* ignore */ }
			} );
		} catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'alice composes and sends a new DM to bob', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const subject = `DM Test ${ Date.now() }`;

		await autoLogin( page, 'alice', '/community/messages/' );
		metrics.start();

		// Click compose button.
		const composeBtn = page.locator( 'button:has-text("New Message"), a:has-text("New Message")' );
		await expect( composeBtn ).toBeVisible( { timeout: 5000 } );
		await composeBtn.click();
		metrics.recordClick();

		// Fill in recipient.
		const recipientInput = page.locator( '.jt-recipient-input input, [name="recipients"]' );
		await expect( recipientInput ).toBeVisible( { timeout: 5000 } );
		await recipientInput.fill( 'bob' );
		// Select from dropdown.
		const suggestion = page.locator( '.jt-recipient-suggestion:has-text("bob")' );
		await expect( suggestion ).toBeVisible( { timeout: 5000 } );
		await suggestion.click();
		metrics.recordClick();

		// Fill subject.
		const subjectInput = page.locator( '[name="subject"], .jt-message-subject input' );
		await subjectInput.fill( subject );

		// Fill message body.
		const body = page.locator( '.jt-message-body [contenteditable="true"], textarea[name="message"]' );
		await expect( body ).toBeVisible();
		await body.click();
		await page.keyboard.type( 'Hello from the test suite!' );

		// Submit.
		const sendBtn = page.locator( 'button:has-text("Send")' );
		await sendBtn.click();
		metrics.recordClick();

		// Should land on the new conversation thread.
		await page.waitForURL( /\/community\/messages\/\d+/, { timeout: 10000 } );

		// DB: conversation row exists.
		assertDbRowExists( 'wp_jt_pro_conversations', "creator_id = 3" );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
