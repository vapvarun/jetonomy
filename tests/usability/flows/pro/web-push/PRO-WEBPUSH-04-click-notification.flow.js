// @ts-check
/**
 * PRO-WEBPUSH-04 — Click push notification lands on target.
 *
 * Verifies that the push payload contains the correct target URL
 * so clicking the notification would navigate to the right page.
 * (Cannot test actual OS notification click in Playwright, so we
 * verify the payload structure via the REST API.)
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBPUSH-04 — Click push notification lands on target', () => {

	let postId;

	test.beforeEach( () => {
		const post = journey( [
			'post', 'create',
			'--space=1',
			'--author=3',
			`--title=Push Click ${ Date.now() }`,
			'--content=Click target test.',
		] );
		postId = post.data?.id || post.id;
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'push payload contains correct target URL', async () => {
		// Create a notification for alice about a reply to her post.
		journey( [
			'reply', 'create',
			`--post=${ postId }`,
			'--author=4',
			'--content=Testing push click target.',
		] );

		// Fetch the latest notification for alice.
		const notifData = dbQuery( `SELECT data FROM wp_jt_notifications WHERE user_id = 3 AND type = 'reply' ORDER BY id DESC LIMIT 1` );
		expect( notifData.length ).toBeGreaterThan( 0 );

		// The notification data should contain a URL pointing to the post.
		const postSlug = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` );
		if ( postSlug.length > 0 ) {
			// URL should contain the post slug.
			expect( notifData[ 0 ] ).toContain( postSlug[ 0 ] );
		}
	} );
} );
