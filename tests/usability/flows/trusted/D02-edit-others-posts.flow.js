// @ts-check
/**
 * D02 — Edit other users' posts (TL3+).
 *
 * Sets alice to TL3, seeds a post by bob (user 4), then alice visits that
 * post, clicks Edit from the more-menu, changes the title, saves, and
 * asserts the updated title appears.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'D02 — Edit other users\' posts (TL3+)', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const aliceId = 3;
	const bobId = 4;
	let postId;
	let postSlug;

	test.beforeEach( () => {
		// Set alice to TL3 so she can edit others' posts.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 3 WHERE user_id = ${ aliceId }` );

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ bobId }`,
			`--title=D02 Bob Post ${ suffix }`,
			'--content=Post authored by bob for D02 edit test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
		// Reset alice back to TL1.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test( 'alice at TL3 edits bob\'s post title', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const newTitle = `D02 Edited ${ Date.now() }`;

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

		// The edit mode should show the title input.
		const titleEditor = page.locator( 'input[name="title"], #jt-post-title, .jt-edit-title-input' ).first();
		await expect( titleEditor ).toBeVisible( { timeout: 5000 } );
		await titleEditor.fill( newTitle );
		metrics.recordClick();

		// Save the edit.
		const saveBtn = page.locator( 'button:has-text("Save"), button:has-text("Update")' ).first();
		await expect( saveBtn ).toBeVisible( { timeout: 3000 } );
		await saveBtn.click();
		metrics.recordClick();

		// Verify the new title appears.
		await expect( page.locator( 'h1' ) ).toContainText( newTitle, { timeout: 10000 } );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
