// @ts-check
/**
 * C05 — Save post as draft.
 *
 * Uses the publish-mode dropdown on the new-post form to select "Save as
 * draft", fills the form, submits, and asserts the post is saved with
 * status=draft in the DB.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C05 — Save post as draft', () => {

	const spaceSlug = 'welcome';
	const spaceId = 1;
	const authorId = 3; // alice
	let createdPostId;

	test.afterEach( () => {
		if ( createdPostId ) {
			try { journey( [ 'post', 'delete', String( createdPostId ) ] ); } catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( 'alice saves a post as draft via the publish menu', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `C05 Draft Post ${ Date.now() }`;
		const body = 'Draft content that should not be published yet.';

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Fill title and content.
		const titleInput = page.locator( '#jt-post-title' );
		await expect( titleInput ).toBeVisible( { timeout: 5000 } );
		await titleInput.fill( title );
		metrics.recordClick();

		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' ).first();
		await editorBody.click();
		await page.keyboard.type( body );
		metrics.recordClick();

		// Open the publish-mode dropdown.
		const toggleBtn = page.locator( 'button.jt-publish-mode__toggle' );
		await expect( toggleBtn ).toBeVisible();
		await toggleBtn.click();
		metrics.recordClick();

		// Select "Save as draft".
		const draftOption = page.locator( '.jt-publish-mode__option', { hasText: /draft/i } );
		await expect( draftOption ).toBeVisible( { timeout: 3000 } );
		await draftOption.click();
		metrics.recordClick();

		// Submit the form (button text may have changed to reflect draft).
		const submitBtn = page.locator( 'button.jt-publish-mode__submit' );
		await submitBtn.click();
		metrics.recordClick();

		// Wait for navigation or confirmation. Draft posts may redirect to
		// the single-post page (visible to author) or the space.
		await page.waitForURL( /\/community\//, { timeout: 10000 } );

		// Verify DB has the post with draft status.
		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title = '${ title.replace( /'/g, "\\'" ) }' AND status = 'draft' LIMIT 1` );
		expect( ids.length ).toBeGreaterThan( 0 );
		createdPostId = parseInt( ids[ 0 ], 10 );

		assertDbRowExists( 'wp_jt_posts', `id = ${ createdPostId } AND status = 'draft' AND author_id = ${ authorId }` );

		const expectation = loadSpec( 'C05' );
		matchDelivery( expectation, {
			draft_saved_in_db: true,
			db_status_is_draft: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_save_draft: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 7 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
