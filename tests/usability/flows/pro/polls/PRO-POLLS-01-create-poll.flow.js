// @ts-check
/**
 * PRO-POLLS-01 — Create poll on new post.
 *
 * Logs in as alice, navigates to new post form, toggles the poll panel,
 * adds options, submits, and asserts the poll is stored in wp_jt_pro_polls.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-POLLS-01 — Create poll on new post', () => {

	const spaceSlug = 'welcome';
	let postId;

	test.afterEach( () => {
		if ( postId ) {
			// Clean up poll and post.
			try { dbWrite( `DELETE FROM wp_jt_pro_poll_options WHERE poll_id IN (SELECT id FROM wp_jt_pro_polls WHERE post_id = ${ postId })` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_polls WHERE post_id = ${ postId }` ); } catch ( e ) { /* ignore */ }
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice creates a post with an inline poll', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `Poll Post ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Fill the post title.
		const titleInput = page.locator( '[name="title"], .jt-post-title input' );
		await expect( titleInput ).toBeVisible( { timeout: 5000 } );
		await titleInput.fill( title );

		// Fill post content.
		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' );
		await editorBody.click();
		await page.keyboard.type( 'Which option do you prefer?' );

		// Toggle the poll panel.
		const pollToggle = page.locator( 'button:has-text("Add Poll"), button[data-action="toggle-poll"]' );
		await expect( pollToggle ).toBeVisible( { timeout: 5000 } );
		await pollToggle.click();
		metrics.recordClick();

		// Add poll options.
		const optionInputs = page.locator( '.jt-poll-option-input, input[name="poll_option[]"]' );
		await optionInputs.nth( 0 ).fill( 'Option Alpha' );
		await optionInputs.nth( 1 ).fill( 'Option Beta' );

		// Submit the post.
		const submitBtn = page.locator( 'button:has-text("Publish"), button:has-text("Create Post")' );
		await submitBtn.click();
		metrics.recordClick();

		// Wait for navigation to the new post.
		await page.waitForURL( /\/community\/s\/[^/]+\/t\/[^/]+/, { timeout: 10000 } );

		// Grab post ID for cleanup.
		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title LIKE '%${ title.slice( 0, 10 ) }%' ORDER BY id DESC LIMIT 1` );
		if ( ids.length > 0 ) {
			postId = parseInt( ids[ 0 ], 10 );
		}

		// DB: poll row exists.
		if ( postId ) {
			assertDbRowExists( 'wp_jt_pro_polls', `post_id = ${ postId }` );
			// At least 2 options.
			const optCount = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_poll_options WHERE poll_id IN (SELECT id FROM wp_jt_pro_polls WHERE post_id = ${ postId })` );
			expect( parseInt( optCount[ 0 ], 10 ) ).toBeGreaterThanOrEqual( 2 );
		}

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
