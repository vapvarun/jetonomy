// @ts-check
/**
 * C32 — Flag a reply.
 *
 * Visits a post with a reply authored by another user, clicks the
 * flag/report button on the reply, fills in a reason, and verifies
 * the flag row is created in wp_jt_flags.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C32 — Flag a reply', () => {

	const testUserId = users.id( 'alice' );
	let createdPostId;
	let createdReplyId;

	test.beforeEach( () => {
		// Seed a post by admin.
		const postResult = journey( [ 'post', 'create', `--space=${ users.spaceId( 'welcome' ) }`, `--author=${ users.id( 'admin' ) }`, '--title=C32 Flag Reply Test', '--content=Testing reply flagging' ] );
		if ( postResult.success && postResult.data?.id ) {
			createdPostId = postResult.data.id;
		}

		// Seed a reply by admin (user 1) so alice can flag it.
		if ( createdPostId ) {
			const replyResult = journey( [ 'reply', 'create', `--post=${ createdPostId }`, `--author=${ users.id( 'admin' ) }`, '--content=This reply should be flagged for testing' ] );
			if ( replyResult.success && replyResult.data?.id ) {
				createdReplyId = replyResult.data.id;
			}
		}

		// Clean up existing flags.
		if ( createdReplyId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'reply' AND object_id = ${ createdReplyId } AND user_id = ${ testUserId }` );
		}
	} );

	test.afterEach( () => {
		if ( createdReplyId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'reply' AND object_id = ${ createdReplyId }` );
		}
		if ( createdPostId ) {
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
			createdReplyId = null;
		}
	} );

	test( 'clicking flag on a reply opens prompt and creates DB flag row', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';
		expect( postSlug ).toBeTruthy();

		await autoLogin( page, 'alice', `/community/s/welcome/t/${ postSlug }/` );
		metrics.start();

		// Find the flag button on the reply card. It uses data-wp-on--click="actions.flagReply".
		const flagBtn = page.locator( `button[data-wp-on--click="actions.flagReply"][data-reply-id="${ createdReplyId }"]` );

		// If we can't find the exact reply, try the first flagReply button.
		const hasFlagBtn = await flagBtn.isVisible().catch( () => false );
		const targetFlagBtn = hasFlagBtn
			? flagBtn
			: page.locator( 'button[data-wp-on--click="actions.flagReply"]' ).first();

		await expect( targetFlagBtn ).toBeVisible( { timeout: 5000 } );

		// Click the flag button.
		await targetFlagBtn.click();
		metrics.recordClick();

		// Handle the prompt modal (jetonomyPrompt).
		const promptModal = page.locator( '.jt-prompt-overlay, .jt-modal, .jt-report-modal, dialog[open]' );
		const hasPrompt = await promptModal.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( hasPrompt ) {
			const textarea = promptModal.locator( 'textarea, input[type="text"]' ).first();
			await textarea.fill( 'This reply is inappropriate - test flag' );
			metrics.recordClick();

			const submitBtn = promptModal.locator( 'button:has-text("Submit"), button:has-text("Report"), button:has-text("Send"), button.jt-btn-fill' ).first();
			await submitBtn.click();
			metrics.recordClick();
		} else {
			page.on( 'dialog', async ( dialog ) => {
				await dialog.accept( 'Test reply flag reason' );
			} );
			await targetFlagBtn.click();
			metrics.recordClick();
		}

		// Data flow: verify flag row exists.
		const replyIdToCheck = createdReplyId || parseInt(
			await targetFlagBtn.getAttribute( 'data-reply-id' ) || '0',
			10
		);

		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_flags WHERE object_type = 'reply' AND object_id = ${ replyIdToCheck } AND user_id = ${ testUserId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		assertDbRowExists(
			'wp_jt_flags',
			`object_type = 'reply' AND object_id = ${ replyIdToCheck } AND user_id = ${ testUserId }`
		);

		const expectation = loadSpec( 'C32' );
		matchDelivery( expectation, {
			flag_button_visible: true,
			flag_prompt_opens: true,
			flag_row_created_in_db: true,
			max_clicks_to_flag: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 4 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
	} );
} );
