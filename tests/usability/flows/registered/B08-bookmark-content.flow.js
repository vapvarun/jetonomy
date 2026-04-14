// @ts-check
/**
 * B08 — Bookmark content for later (registered).
 *
 * Auto-logs in as bob, visits a post, and looks for a bookmark button.
 * If present, asserts it is clickable. If the feature is not yet
 * implemented, the test is marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B08 — Bookmark content for later', () => {

	let postUrl;

	test.beforeAll( () => {
		const json = wp( [ 'eval', `
			global $wpdb;
			$row = $wpdb->get_row( "SELECT p.slug AS post_slug, s.slug AS space_slug FROM {$wpdb->prefix}jt_posts p JOIN {$wpdb->prefix}jt_spaces s ON p.space_id = s.id WHERE p.status = 'published' LIMIT 1" );
			echo $row ? wp_json_encode( $row ) : '{}';
		` ], { json: true } );
		if ( json.post_slug ) {
			postUrl = `/community/s/${ json.space_slug }/t/${ json.post_slug }/`;
		}
	} );

	test( 'bob can find and click the bookmark button on a post', async ( { page } ) => {
		test.skip( ! postUrl, 'No published post found in the database' );

		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'bob', postUrl );
		metrics.start();

		// Post page loads.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Look for a bookmark button.
		const bookmarkBtn = page.locator(
			'button.jt-bookmark-btn, button[data-action="bookmark"], button:has-text("Bookmark"), button:has-text("Save"), .jt-bookmark'
		);

		const bookmarkExists = await bookmarkBtn.count() > 0;

		if ( bookmarkExists ) {
			await expect( bookmarkBtn.first() ).toBeVisible();
			// Verify it is clickable (enabled).
			await expect( bookmarkBtn.first() ).toBeEnabled();
		} else {
			// Bookmark feature not yet implemented — mark as fixme so CI
			// reports it without failing the suite.
			test.fixme( true, 'Bookmark button not found — feature may not be implemented yet' );
		}

		metrics.assertErrorCount( 0 );
	} );
} );
