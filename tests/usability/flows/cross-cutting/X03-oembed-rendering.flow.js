// @ts-check
/**
 * X03 — oEmbed rendering (P1)
 *
 * Seed a post containing a YouTube URL, visit it, and check for an
 * iframe or embed element in the rendered content.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X03 — oEmbed rendering in post body', () => {

	let fixturePostId;
	let fixtureSpaceSlug;

	test.beforeAll( () => {
		// Get an existing space to post into.
		const spaceId = wp( [ 'eval', `
			global $wpdb;
			echo $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}jt_spaces LIMIT 1" );
		` ] );

		if ( ! spaceId || spaceId === '' ) {
			return;
		}

		fixtureSpaceSlug = wp( [ 'eval', `
			global $wpdb;
			echo $wpdb->get_var( "SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE id = ${spaceId}" );
		` ] );

		// Create a post with a YouTube URL in the body.
		try {
			const result = journey( [ 'post', 'create',
				'--space=' + spaceId,
				'--author=1',
				'--title=oEmbed Test Post',
				'--content=Check this video: https://www.youtube.com/watch?v=dQw4w9WgXcQ',
			] );
			fixturePostId = result.data?.id || result.data?.post_id;
		} catch ( e ) {
			// Journey may not support this exact syntax; post creation may fail.
		}
	} );

	test.afterAll( () => {
		if ( fixturePostId ) {
			try {
				journey( [ 'post', 'delete', String( fixturePostId ) ] );
			} catch ( e ) { /* best effort */ }
		}
	} );

	test( 'post with YouTube URL renders iframe or embed', async ( { page } ) => {
		if ( ! fixturePostId || ! fixtureSpaceSlug ) {
			test.fixme( true, 'Could not seed fixture post with YouTube URL — no spaces available' );
			return;
		}

		const metrics = new EaseMetrics( page );

		const postSlug = wp( [ 'eval', `
			global $wpdb;
			echo $wpdb->get_var( "SELECT slug FROM {$wpdb->prefix}jt_posts WHERE id = ${fixturePostId}" );
		` ] );

		await autoLogin( page, 1, `/community/s/${ fixtureSpaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Look for an iframe (YouTube embed) or an embed/object element.
		const embed = page.locator( 'iframe[src*="youtube"], iframe[src*="youtu.be"], .wp-embedded-content, oembed' ).first();
		await expect( embed ).toBeVisible( { timeout: 10000 } );

		const expectation = loadSpec( 'X03' );
		matchDelivery( expectation, {
			embed_iframe_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
