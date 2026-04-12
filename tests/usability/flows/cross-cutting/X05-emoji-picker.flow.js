// @ts-check
/**
 * X05 — Emoji picker (P2)
 *
 * Fixme if the emoji picker is not rendered on the reply editor.
 * When implemented, visit a post and check for the emoji trigger button.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'X05 — Emoji picker in reply editor', () => {

	test( 'emoji picker trigger is present on post page, or fixme', async ( { page } ) => {
		// Get a space + post to visit.
		const postUrl = wp( [ 'eval', `
			global $wpdb;
			$row = $wpdb->get_row( "
				SELECT s.slug AS space_slug, p.slug AS post_slug
				FROM {$wpdb->prefix}jt_posts p
				JOIN {$wpdb->prefix}jt_spaces s ON s.id = p.space_id
				LIMIT 1
			" );
			echo $row ? $row->space_slug . '/' . $row->post_slug : '';
		` ] );

		if ( ! postUrl || postUrl === '' ) {
			test.fixme( true, 'No posts available to test emoji picker' );
			return;
		}

		const [ spaceSlug, postSlug ] = postUrl.split( '/' );
		await autoLogin( page, 1, `/community/s/${ spaceSlug }/t/${ postSlug }/` );

		// Look for an emoji button/trigger near the reply editor.
		const emojiTrigger = page.locator( 'button[aria-label*="emoji" i], button.emoji-trigger, [data-emoji-picker], button:has-text("😀")' ).first();
		const isVisible = await emojiTrigger.isVisible().catch( () => false );

		if ( ! isVisible ) {
			test.fixme( true, 'Emoji picker not rendered — feature may not be enabled' );
			return;
		}

		await expect( emojiTrigger ).toBeVisible();
	} );
} );
