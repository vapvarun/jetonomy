// @ts-check
/**
 * D03 — Create tags inline (P2).
 *
 * If the tag creation UI exists in the post editor for TL3+ users, tests
 * that alice can type a new tag name and have it created inline. If the UI
 * is not yet implemented, the test is marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'D03 — Create new tags inline', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const aliceId = 3;
	let postId;
	let postSlug;

	test.beforeEach( () => {
		// Set alice to TL3.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 3 WHERE user_id = ${ aliceId }` );

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ aliceId }`,
			`--title=D03 Tag Test ${ suffix }`,
			'--content=Post for inline tag creation test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
		// Clean up any test tags we created.
		dbWrite( `DELETE FROM wp_jt_tags WHERE name LIKE 'd03-test-tag-%'` );
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test.fixme( 'alice at TL3 creates a new tag inline while editing a post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const tagName = `d03-test-tag-${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Open edit mode on the post.
		const moreTrigger = page.locator( '.jt-post-foot .jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 5000 } );
		await moreTrigger.click();
		metrics.recordClick();

		const editBtn = page.locator( '.jt-more-dropdown .jt-more-item', { hasText: /Edit/i } ).first();
		await expect( editBtn ).toBeVisible( { timeout: 3000 } );
		await editBtn.click();
		metrics.recordClick();

		// Look for a tag input field in the editor.
		const tagInput = page.locator( '.jt-tag-input, input[name="tags"], .jt-edit-tags input' ).first();
		await expect( tagInput ).toBeVisible( { timeout: 5000 } );
		await tagInput.fill( tagName );
		metrics.recordClick();

		// Submit the tag (press Enter or click an add button).
		await tagInput.press( 'Enter' );
		metrics.recordClick();

		// Save the edit.
		const saveBtn = page.locator( 'button:has-text("Save"), button:has-text("Update")' ).first();
		await expect( saveBtn ).toBeVisible( { timeout: 3000 } );
		await saveBtn.click();
		metrics.recordClick();

		// Verify tag was created in DB.
		await expect.poll( () => {
			const rows = dbQuery( `SELECT COUNT(*) FROM wp_jt_tags WHERE name = '${ tagName }'` );
			return parseInt( rows[ 0 ], 10 );
		}, { timeout: 8000 } ).toBeGreaterThan( 0 );

		metrics.assertClickCount( { lessThanOrEqual: 7 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 20 } );
		metrics.assertErrorCount( 0 );
	} );
} );
