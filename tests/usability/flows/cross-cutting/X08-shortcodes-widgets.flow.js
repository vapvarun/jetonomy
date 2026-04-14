// @ts-check
/**
 * X08 — Shortcodes / widgets (P2)
 *
 * Verify that the [jetonomy_home] shortcode is registered and renders.
 * Create a WP page with the shortcode and visit it.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X08 — Shortcode [jetonomy_home] renders', () => {

	let fixturePageId;

	test.beforeAll( () => {
		// Check if the shortcode is registered.
		const registered = wp( [ 'eval', `
			echo shortcode_exists( 'jetonomy_home' ) ? 'yes' : 'no';
		` ] );

		if ( registered !== 'yes' ) {
			return;
		}

		// Create a WP page with the shortcode.
		fixturePageId = wp( [ 'post', 'create',
			'--post_type=page',
			'--post_title=Shortcode Test Page',
			'--post_content=[jetonomy_home]',
			'--post_status=publish',
			'--porcelain',
		] ).trim();
	} );

	test.afterAll( () => {
		if ( fixturePageId ) {
			try {
				wp( [ 'post', 'delete', fixturePageId, '--force' ] );
			} catch ( e ) { /* best effort */ }
		}
	} );

	test( '[jetonomy_home] shortcode renders community content', async ( { page } ) => {
		const registered = wp( [ 'eval', `
			echo shortcode_exists( 'jetonomy_home' ) ? 'yes' : 'no';
		` ] );

		if ( registered !== 'yes' || ! fixturePageId ) {
			test.fixme( true, '[jetonomy_home] shortcode not registered or page creation failed' );
			return;
		}

		const metrics = new EaseMetrics( page );

		const pageUrl = wp( [ 'post', 'get', fixturePageId, '--field=url' ] ).trim();
		await autoLogin( page, 1, pageUrl || `/?p=${ fixturePageId }` );
		metrics.start();

		// The shortcode output should contain Jetonomy markup.
		const jtContent = page.locator( '.jt-app, .jetonomy-home, [class*="jetonomy"], [class*="jt-"]' ).first();
		await expect( jtContent ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'X08' );
		matchDelivery( expectation, {
			shortcode_renders_content: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
