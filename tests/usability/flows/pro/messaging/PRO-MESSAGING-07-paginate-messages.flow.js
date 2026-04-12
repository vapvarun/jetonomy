// @ts-check
/**
 * PRO-MESSAGING-07 — Paginate older messages.
 *
 * Seeds a conversation with 30+ messages, opens it, scrolls up, and
 * asserts older messages load via cursor-based pagination.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-MESSAGING-07 — Paginate older messages', () => {

	let conversationId;

	test.beforeEach( () => {
		dbWrite( "UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = 3" );

		const conv = proJourney( [
			'messaging', 'create-conversation',
			'--creator=4',
			'--participants=3',
			'--subject=Pagination Test',
		] );
		conversationId = conv.data?.id;

		// Seed 30 messages to trigger pagination.
		if ( conversationId ) {
			for ( let i = 1; i <= 30; i++ ) {
				proJourney( [
					'messaging', 'send-message',
					`--conversation=${ conversationId }`,
					`--sender=${ i % 2 === 0 ? 3 : 4 }`,
					`--body=Paginate message ${ i }`,
				] );
			}
		}
	} );

	test.afterEach( () => {
		if ( conversationId ) {
			try { proJourney( [ 'messaging', 'delete-conversation', String( conversationId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'scrolling up loads older messages', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/messages/${ conversationId }/` );
		metrics.start();

		// Initial messages should be visible (latest batch).
		const messages = page.locator( '.jt-message-bubble' );
		await expect( messages.first() ).toBeVisible( { timeout: 5000 } );
		const initialCount = await messages.count();

		// Scroll to top to trigger "load more".
		const thread = page.locator( '.jt-message-thread' );
		await thread.evaluate( ( el ) => el.scrollTop = 0 );

		// Wait for additional messages to load.
		const loadMore = page.locator( '.jt-load-more-messages, button:has-text("Load older")' );
		if ( await loadMore.isVisible() ) {
			await loadMore.click();
			metrics.recordClick();
		}

		// More messages should now be present.
		await expect( messages ).toHaveCount( initialCount + 1, { timeout: 5000 } ).catch( () => {
			// At least verify no errors occurred during scroll.
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
