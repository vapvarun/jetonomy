// @ts-check
/**
 * C01 — Create a forum post.
 *
 * Logs in as alice, navigates to the new-post form in the Welcome space,
 * fills in title + content, submits, and verifies the post appears at the
 * new URL.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C01 — Create a forum post', () => {

	const spaceId = 1; // Welcome & Introductions — open, forum type.
	const spaceSlug = 'welcome';
	const authorId = 3; // alice
	let createdPostId;

	test.afterEach( () => {
		if ( createdPostId ) {
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'alice creates a forum post via the new-post form', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `C01 Test Post ${ Date.now() }`;
		const body = 'This is the body of the C01 test post.';

		// Login as alice and land on the new-post form.
		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Fill the title field.
		const titleInput = page.locator( '#jt-post-title' );
		await expect( titleInput ).toBeVisible( { timeout: 5000 } );
		await titleInput.fill( title );
		metrics.recordClick();

		// Fill the content editor (contenteditable div).
		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' ).first();
		await expect( editorBody ).toBeVisible();
		await editorBody.click();
		await page.keyboard.type( body );
		metrics.recordClick();

		// Submit the form.
		const submitBtn = page.locator( 'button.jt-publish-mode__submit' );
		await expect( submitBtn ).toBeVisible();
		await submitBtn.click();
		metrics.recordClick();

		// Wait for navigation to the new post URL.
		await page.waitForURL( /\/community\/s\/welcome\/t\//, { timeout: 10000 } );

		// Assert post title is visible on the single-post page.
		await expect( page.locator( 'h1' ) ).toContainText( title );

		// Extract post ID from DB for cleanup.
		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title = '${ title.replace( /'/g, "\\'" ) }' LIMIT 1` );
		if ( ids.length > 0 ) {
			createdPostId = parseInt( ids[ 0 ], 10 );
		}

		// Data flow: confirm DB row exists.
		assertDbRowExists( 'wp_jt_posts', `title = '${ title.replace( /'/g, "\\'" ) }' AND author_id = ${ authorId } AND space_id = ${ spaceId }` );

		// Layer 5 — expectation vs delivery.
		const expectation = loadSpec( 'C01' );
		matchDelivery( expectation, {
			post_created_in_db: true,
			navigated_to_post_url: true,
			title_visible_on_page: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_publish: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		// Ease metrics.
		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
