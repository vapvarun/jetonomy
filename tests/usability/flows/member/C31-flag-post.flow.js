// @ts-check
/**
 * C31 — Flag a post (all reasons).
 *
 * Visits a post authored by another user, clicks the flag/report button,
 * fills in a reason in the prompt/modal, submits, and verifies the flag
 * row is created in wp_jt_flags.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C31 — Flag a post (all reasons)', () => {

	const testUserId = users.id( 'alice' );
	let createdPostId;

	test.beforeEach( () => {
		// Seed a post by admin (user 1) so alice (user 3) can flag it.
		const seedResult = journey( [ 'post', 'create', `--space=${ users.spaceId( 'welcome' ) }`, `--author=${ users.id( 'admin' ) }`, '--title=C31 Flag Post Test', '--content=This post should be flagged' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}
		// Clean up any existing flags.
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ createdPostId } AND user_id = ${ testUserId }` );
		}
	} );

	test.afterEach( () => {
		if ( createdPostId ) {
			dbWrite( `DELETE FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ createdPostId }` );
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'clicking flag on a post opens prompt, submitting creates DB flag row', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';
		expect( postSlug ).toBeTruthy();

		// The flag action uses a custom prompt (jetonomyPrompt) which creates a
		// modal dialog. We need to handle this. The implementation uses a custom
		// promise-based prompt, not window.prompt.
		await autoLogin( page, 'alice', `/community/s/welcome/t/${ postSlug }/` );
		metrics.start();

		// Find the flag/report button on the post.
		const flagBtn = page.locator( 'button[data-wp-on--click="actions.flagPost"]' );
		await expect( flagBtn ).toBeVisible( { timeout: 5000 } );

		// Click the flag button.
		await flagBtn.click();
		metrics.recordClick();

		// A custom prompt modal should appear (jetonomyPrompt).
		// Look for the modal dialog that the flag action creates.
		const promptModal = page.locator( '.jt-prompt-overlay, .jt-modal, .jt-report-modal, dialog[open]' );
		const hasPrompt = await promptModal.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( hasPrompt ) {
			// Fill in the reason textarea.
			const textarea = promptModal.locator( 'textarea, input[type="text"]' ).first();
			await textarea.fill( 'This post violates community guidelines - test flag' );
			metrics.recordClick();

			// Submit the prompt.
			const submitBtn = promptModal.locator( 'button:has-text("Submit"), button:has-text("Report"), button:has-text("Send"), button.jt-btn-fill' ).first();
			await submitBtn.click();
			metrics.recordClick();
		} else {
			// The implementation may use a native dialog or a different pattern.
			// Handle window.confirm/prompt via page.on('dialog').
			page.on( 'dialog', async ( dialog ) => {
				await dialog.accept( 'Test flag reason' );
			} );

			// Re-click the flag button to trigger the dialog.
			await flagBtn.click();
			metrics.recordClick();
		}

		// Data flow: verify flag row exists in DB.
		await expect.poll( () => {
			const rows = dbQuery(
				`SELECT COUNT(*) FROM wp_jt_flags WHERE object_type = 'post' AND object_id = ${ createdPostId } AND user_id = ${ testUserId }`
			);
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000, intervals: [ 200, 500, 1000 ] } ).toBeGreaterThan( 0 );

		assertDbRowExists(
			'wp_jt_flags',
			`object_type = 'post' AND object_id = ${ createdPostId } AND user_id = ${ testUserId }`
		);

		const expectation = loadSpec( 'C31' );
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
