// @ts-check
/**
 * C11 — Edit own reply.
 *
 * Seeds a post + reply as alice, visits the post, opens the reply
 * more-menu, clicks Edit, modifies the content, saves, and asserts the
 * updated content is visible.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C11 — Edit own reply', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const authorId = 3; // alice
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4', // bob owns the post
			`--title=C11 Post ${ suffix }`,
			'--content=Post for reply edit test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ authorId }`,
			`--content=Original reply by alice ${ suffix }`,
		] );
		replyId = reply.data?.id || reply.id;
	} );

	test.afterEach( () => {
		if ( replyId ) {
			try { journey( [ 'reply', 'delete', String( replyId ) ] ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test( 'alice edits her own reply', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const editedText = `C11 Edited Reply ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the reply authored by alice and open its more-menu.
		const replyCard = page.locator( '.jt-reply' ).first();
		await expect( replyCard ).toBeVisible( { timeout: 5000 } );

		const moreTrigger = replyCard.locator( '.jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible();
		await moreTrigger.click();
		metrics.recordClick();

		// Click Edit.
		const editBtn = replyCard.locator( '.jt-more-item', { hasText: /Edit/i } );
		await expect( editBtn ).toBeVisible( { timeout: 3000 } );
		await editBtn.click();
		metrics.recordClick();

		// An inline editor should appear. Replace content.
		const editBody = replyCard.locator( '.jt-editor-body[contenteditable="true"], textarea' ).first();
		await expect( editBody ).toBeVisible( { timeout: 5000 } );
		await editBody.click();
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.type( editedText );
		metrics.recordClick();

		// Save.
		const saveBtn = replyCard.locator( 'button:has-text("Save"), button:has-text("Update")' ).first();
		await expect( saveBtn ).toBeVisible( { timeout: 3000 } );
		await saveBtn.click();
		metrics.recordClick();

		// Assert the edited text appears.
		await expect( page.locator( '.jt-reply-body', { hasText: editedText } ) ).toBeVisible( { timeout: 10000 } );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
