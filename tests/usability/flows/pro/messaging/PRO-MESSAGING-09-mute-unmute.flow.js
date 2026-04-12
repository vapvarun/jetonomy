// @ts-check
/**
 * PRO-MESSAGING-09 — Mute/unmute conversation.
 *
 * Opens a conversation, clicks the mute toggle, asserts the muted state
 * persists, then unmutes and verifies the toggle reverts.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-09 — Mute/unmute conversation', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Mute Test',
		] );
		conversationId = conv.data?.id;
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice mutes then unmutes a conversation', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/messages/${ conversationId }/` );
		metrics.start();

		// Click the mute/options button.
		const muteBtn = page.locator( 'button:has-text("Mute"), button[data-action="mute"]' );
		await expect( muteBtn ).toBeVisible( { timeout: 5000 } );
		await muteBtn.click();
		metrics.recordClick();

		// Verify muted state in DB.
		const muted = dbQuery(
			`SELECT is_muted FROM wp_jt_pro_conversation_participants WHERE conversation_id = ${ conversationId } AND user_id = 3`
		);
		expect( muted[ 0 ] ).toBe( '1' );

		// Unmute.
		const unmuteBtn = page.locator( 'button:has-text("Unmute"), button[data-action="unmute"]' );
		await expect( unmuteBtn ).toBeVisible( { timeout: 5000 } );
		await unmuteBtn.click();
		metrics.recordClick();

		// Verify unmuted in DB.
		const unmuted = dbQuery(
			`SELECT is_muted FROM wp_jt_pro_conversation_participants WHERE conversation_id = ${ conversationId } AND user_id = 3`
		);
		expect( unmuted[ 0 ] ).toBe( '0' );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
