// @ts-check
/**
 * C03 — Create an idea post.
 *
 * Looks for an ideas-type space. If none exists, the test is marked fixme.
 * Otherwise, logs in as alice, fills the new-post form, submits, and
 * asserts the idea post is created.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, getUserId, getSpaceId } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C03 — Create an idea post', () => {

	let ideasSpaceId;
	let ideasSpaceSlug;
	let createdSpaceId;
	let createdPostId;
	const authorId = users.id( 'alice' );

	test.beforeEach( () => {
		// Look for an existing ideas space.
		const rows = dbQuery( "SELECT id FROM wp_jt_spaces WHERE type = 'ideas' AND status = 'active' LIMIT 1" );
		if ( rows.length > 0 ) {
			ideasSpaceId = parseInt( rows[ 0 ], 10 );
			ideasSpaceSlug = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ ideasSpaceId }` )[ 0 ];
		} else {
			// Create an ideas space for the test.
			const suffix = Date.now();
			const catRows = dbQuery( 'SELECT id FROM wp_jt_categories LIMIT 1' );
			const catId = catRows.length > 0 ? parseInt( catRows[ 0 ], 10 ) : 1;
			const space = journey( [
				'space', 'create',
				`--title=Ideas Test ${ suffix }`,
				`--slug=ideas-test-${ suffix }`,
				`--category=${ catId }`,
				'--type=ideas',
				'--visibility=public',
				'--join-policy=open',
			] );
			ideasSpaceId = space.data?.id || space.id;
			ideasSpaceSlug = space.data?.slug || `ideas-test-${ suffix }`;
			createdSpaceId = ideasSpaceId;
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

	test( 'alice creates an idea post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `C03 Idea ${ Date.now() }`;
		const body = 'Here is my idea for improvement.';

		await autoLogin( page, 'alice', `/community/s/${ ideasSpaceSlug }/new/` );
		metrics.start();

		// Heading should say "Share an Idea".
		await expect( page.locator( '.jt-post-create-title' ) ).toContainText( /Idea/i );

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

		const expectation = loadSpec( 'C03' );
		matchDelivery( expectation, {
			idea_created_in_db: true,
			navigated_to_idea_url: true,
			form_heading_says_idea: true,
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
