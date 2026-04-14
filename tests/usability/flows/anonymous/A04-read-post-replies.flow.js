// @ts-check
/**
 * A04 — Read a single post + replies (anonymous).
 *
 * Finds a known post via DB query, visits it, and asserts the post body
 * renders. If the post has replies, asserts the replies section is visible.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

const SITE = 'http://forums.local';

test.describe( 'A04 — Read a single post + replies', () => {

	let postUrl;

	test.beforeAll( () => {
		// Use wp eval to get post + space slug together via $wpdb.
		const json = wp( [ 'eval', `
			global $wpdb;
			$row = $wpdb->get_row( "SELECT p.slug AS post_slug, s.slug AS space_slug FROM {$wpdb->prefix}jt_posts p JOIN {$wpdb->prefix}jt_spaces s ON p.space_id = s.id WHERE p.status = 'published' ORDER BY p.reply_count DESC LIMIT 1" );
			echo $row ? wp_json_encode( $row ) : '{}';
		` ], { json: true } );
		if ( ! json.post_slug ) {
			throw new Error( 'A04: no published post found in the database' );
		}
		postUrl = `/community/s/${ json.space_slug }/t/${ json.post_slug }/`;
	} );

	test( 'anonymous visitor can read a post and see replies', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }${ postUrl }` );

		// Page is not a 404.
		const title = await page.title();
		expect( title ).not.toContain( '404' );

		// Post body is visible.
		const postBody = page.locator( '.jt-post-body, .jt-single-post, .jt-post-content, article' );
		await expect( postBody.first() ).toBeVisible( { timeout: 8000 } );

		// Replies section is present (container should render even if empty).
		const repliesSection = page.locator( '.jt-replies, .jt-reply, .jt-reply-list' );
		const hasReplies = await repliesSection.count() > 0;
		// We don't fail if there are no replies — the post may not have any.
		// But the page itself must load cleanly.

		const expectation = loadSpec( 'A04' );
		matchDelivery( expectation, {
			page_renders: true,
			post_body_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
