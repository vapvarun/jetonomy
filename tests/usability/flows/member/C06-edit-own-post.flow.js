// @ts-check
/**
 * C06 — Edit own post.
 *
 * Seeds a post as alice via journey, visits it, clicks the edit button in
 * the more-menu, changes the title, saves, and asserts the updated title
 * appears on the page.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C06 — Edit own post', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const authorId = 3; // alice
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ authorId }`,
			`--title=C06 Original ${ suffix }`,
			'--content=Original body for C06 test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice edits her own post title', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const newTitle = `C06 Edited ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open the more menu.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Edit".
		const editBtn = page.locator( '.jt-more-dropdown .jt-more-item', { hasText: /Edit/i } ).first();
		await expect( editBtn ).toBeVisible( { timeout: 3000 } );
		await editBtn.click();
		metrics.recordClick();

		// The edit mode should show the title input or an inline editor.
		// Wait for an input or contenteditable to appear with the current title.
		const titleEditor = page.locator( 'input[name="title"], #jt-post-title, .jt-edit-title-input' ).first();
		await expect( titleEditor ).toBeVisible( { timeout: 5000 } );
		await titleEditor.fill( newTitle );
		metrics.recordClick();

		// Save the edit.
		const saveBtn = page.locator( 'button:has-text("Save"), button:has-text("Update")' ).first();
		await expect( saveBtn ).toBeVisible( { timeout: 3000 } );
		await saveBtn.click();
		metrics.recordClick();

		// Wait for the page to update and verify the new title.
		await expect( page.locator( 'h1' ) ).toContainText( newTitle, { timeout: 10000 } );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
