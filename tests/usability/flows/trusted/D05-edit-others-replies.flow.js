// @ts-check
/**
 * D05 — Edit others' replies (TL3+).
 *
 * Sets alice to TL3, seeds a post by alice and a reply by bob, then alice
 * visits the post, finds bob's reply, clicks Edit, changes the content,
 * saves, and asserts the updated content appears.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'D05 — Edit others\' replies', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	const aliceId = 3;
	const bobId = 4;
	let postId;
	let postSlug;
	let replyId;

	test.beforeEach( () => {
		// Set alice to TL3 so she can edit others' replies.
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 3 WHERE user_id = ${ aliceId }` );

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			`--author=${ aliceId }`,
			`--title=D05 Reply Edit Test ${ suffix }`,
			'--content=Post for D05 reply edit test.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Create a reply by bob.
		const reply = journey( [
			'reply', 'create',
			`--post=${ postId }`,
			`--author=${ bobId }`,
			`--content=Bob reply for D05 edit test ${ suffix }`,
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
		dbWrite( `UPDATE wp_jt_user_profiles SET trust_level = 1 WHERE user_id = ${ aliceId }` );
	} );

	test( 'alice at TL3 edits bob\'s reply content', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const newContent = `D05 Edited reply ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Locate bob's reply. Replies are in .jt-reply elements.
		const replyEl = page.locator( `.jt-reply[data-reply-id="${ replyId }"], .jt-reply` ).first();
		await expect( replyEl ).toBeVisible( { timeout: 5000 } );

		// Open the more menu on the reply.
		const moreTrigger = replyEl.locator( '.jt-more-trigger' );
		await expect( moreTrigger ).toBeVisible( { timeout: 3000 } );
		await moreTrigger.click();
		metrics.recordClick();

		// Click "Edit".
		const editBtn = page.locator( '.jt-more-dropdown .jt-more-item', { hasText: /Edit/i } ).first();
		await expect( editBtn ).toBeVisible( { timeout: 3000 } );
		await editBtn.click();
		metrics.recordClick();

		// Fill the reply editor with new content.
		const contentEditor = replyEl.locator( 'textarea, [contenteditable="true"], .jt-edit-content' ).first();
		await expect( contentEditor ).toBeVisible( { timeout: 5000 } );
		await contentEditor.fill( newContent );
		metrics.recordClick();

		// Save the edit.
		const saveBtn = replyEl.locator( 'button:has-text("Save"), button:has-text("Update")' ).first();
		await expect( saveBtn ).toBeVisible( { timeout: 3000 } );
		await saveBtn.click();
		metrics.recordClick();

		// Verify the new content appears.
		await expect( replyEl ).toContainText( newContent, { timeout: 10000 } );

		const expectation = loadSpec( 'D05' );
		matchDelivery( expectation, {
			edit_button_visible_for_tl3: true,
			updated_content_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_time_seconds: metrics.getElapsedMs() / 1000,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 6 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 15 } );
		metrics.assertErrorCount( 0 );
	} );
} );
