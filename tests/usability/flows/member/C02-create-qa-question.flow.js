// @ts-check
/**
 * C02 — Create a Q&A question.
 *
 * Creates a Q&A space via journey if needed, logs in as alice, fills the
 * new-post form, submits, and asserts the question is created.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C02 — Create a Q&A question', () => {

	let qaSpaceId;
	let qaSpaceSlug;
	let createdSpaceId;
	let createdPostId;
	const authorId = 3; // alice

	test.beforeEach( () => {
		// Look for an existing Q&A space.
		const rows = dbQuery( "SELECT id, slug FROM wp_jt_spaces WHERE type = 'qa' AND status = 'active' LIMIT 1" );
		if ( rows.length >= 2 ) {
			qaSpaceId = parseInt( rows[ 0 ], 10 );
			qaSpaceSlug = dbQuery( `SELECT slug FROM wp_jt_spaces WHERE id = ${ qaSpaceId }` )[ 0 ];
		} else {
			// Create a Q&A space.
			const suffix = Date.now();
			const catRows = dbQuery( 'SELECT id FROM wp_jt_categories LIMIT 1' );
			const catId = catRows.length > 0 ? parseInt( catRows[ 0 ], 10 ) : 1;
			const space = journey( [
				'space', 'create',
				`--title=QA Test Space ${ suffix }`,
				`--slug=qa-test-${ suffix }`,
				`--category=${ catId }`,
				'--type=qa',
				'--visibility=public',
				'--join-policy=open',
			] );
			qaSpaceId = space.data?.id || space.id;
			qaSpaceSlug = space.data?.slug || `qa-test-${ suffix }`;
			createdSpaceId = qaSpaceId;
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

	test( 'alice creates a Q&A question', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `C02 QA Question ${ Date.now() }`;
		const body = 'How does this Q&A feature work?';

		await autoLogin( page, 'alice', `/community/s/${ qaSpaceSlug }/new/` );
		metrics.start();

		// The form heading should say "Ask a Question".
		await expect( page.locator( '.jt-post-create-title' ) ).toContainText( /Question/i );

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
		await expect( page.locator( 'h1' ) ).toContainText( title );

		// Grab ID for cleanup.
		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title = '${ title.replace( /'/g, "\\'" ) }' LIMIT 1` );
		if ( ids.length > 0 ) {
			createdPostId = parseInt( ids[ 0 ], 10 );
		}

		assertDbRowExists( 'wp_jt_posts', `title = '${ title.replace( /'/g, "\\'" ) }' AND author_id = ${ authorId }` );

		const expectation = loadSpec( 'C02' );
		matchDelivery( expectation, {
			question_created_in_db: true,
			navigated_to_question_url: true,
			form_heading_says_question: true,
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
