// @ts-check
/**
 * C04 — Create a feed post.
 *
 * Looks for a feed-type space. If none exists, creates one via journey.
 * Logs in as alice, fills the new-post form, submits, and asserts the
 * status post is created.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C04 — Create a feed post', () => {

	let feedSpaceId;
	let feedSpaceSlug;
	let createdSpaceId;
	let createdPostId;
	const authorId = users.id( 'alice' );

	test.beforeEach( () => {
		const rows = dbQuery( "SELECT id FROM wp_jt_spaces WHERE type = 'feed' AND status = 'active' LIMIT 1" );
		if ( rows.length > 0 ) {
			feedSpaceId = parseInt( rows[ 0 ], 10 );
			feedSpaceSlug = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ feedSpaceId }` )[ 0 ];
		} else {
			const suffix = Date.now();
			const catRows = dbQuery( 'SELECT id FROM wp_jt_categories LIMIT 1' );
			const catId = catRows.length > 0 ? parseInt( catRows[ 0 ], 10 ) : 1;
			const space = journey( [
				'space', 'create',
				`--title=Feed Test ${ suffix }`,
				`--slug=feed-test-${ suffix }`,
				`--category=${ catId }`,
				'--type=feed',
				'--visibility=public',
				'--join-policy=open',
			] );
			feedSpaceId = space.data?.id || space.id;
			feedSpaceSlug = space.data?.slug || `feed-test-${ suffix }`;
			createdSpaceId = feedSpaceId;
		}
	} );

	test.afterEach( () => {
		if ( createdPostId ) {
			try { journey( [ 'post', 'delete', String( createdPostId ) ] ); } catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
		if ( createdSpaceId ) {
			try { journey( [ 'space', 'delete', String( createdSpaceId ) ] ); } catch ( e ) { /* ignore */ }
			createdSpaceId = null;
		}
	} );

	test.fixme( 'alice creates a feed (status) post', async ( { page } ) => {
		// FIXME: feed is not an allowed space type in Space_Journey::ALLOWED_TYPES
		// (only forum, qa, ideas, chat). Demo-seed does not create a feed space, so
		// the flow cannot provision one. Revisit once feed-type spaces ship.
		const metrics = new EaseMetrics( page );
		const title = `C04 Status ${ Date.now() }`;
		const body = 'Just sharing a quick status update.';

		await autoLogin( page, 'alice', `/community/s/${ feedSpaceSlug }/new/` );
		metrics.start();

		// Heading should say "New Status".
		await expect( page.locator( '.jt-post-create-title' ) ).toContainText( /Status/i );

		const titleInput = page.locator( '#jt-post-title' );
		await expect( titleInput ).toBeVisible( { timeout: 5000 } );
		await titleInput.fill( title );
		metrics.recordClick();

		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' ).first();
		await editorBody.click();
		await page.keyboard.type( body );
		metrics.recordClick();

		const submitBtn = page.locator( 'button.jt-publish-mode__submit' );
		await submitBtn.click();
		metrics.recordClick();

		await page.waitForURL( /\/community\/s\/.*\/t\//, { timeout: 10000 } );
		await expect( page.locator( '.jt-post-head h1' ) ).toContainText( title );

		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title = '${ title.replace( /'/g, "\\'" ) }' LIMIT 1` );
		if ( ids.length > 0 ) {
			createdPostId = parseInt( ids[ 0 ], 10 );
		}

		assertDbRowExists( 'wp_jt_posts', `title = '${ title.replace( /'/g, "\\'" ) }' AND author_id = ${ authorId }` );

		const expectation = loadSpec( 'C04' );
		matchDelivery( expectation, {
			status_created_in_db: true,
			navigated_to_status_url: true,
			form_heading_says_status: true,
			title_visible_on_page: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks_to_publish: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 5 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
