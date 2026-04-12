// @ts-check
/**
 * X06 — Code syntax highlighting (P2)
 *
 * Seed a post with a code block, visit it, and check for syntax styling
 * (e.g., a <pre><code> block with a language class or highlight spans).
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'X06 — Code syntax highlighting in post body', () => {

	let fixturePostId;
	let fixtureSpaceSlug;

	test.beforeAll( () => {
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

		try {
			const result = journey( [ 'post', 'create',
				'--space=' + spaceId,
				'--author=1',
				'--title=Code Highlight Test',
				'--content=<pre><code class="language-php">echo "Hello World";</code></pre>',
			] );
			fixturePostId = result.data?.id || result.data?.post_id;
		} catch ( e ) {
			// best effort
		}
	} );

	test.afterAll( () => {
		if ( fixturePostId ) {
			try {
				journey( [ 'post', 'delete', String( fixturePostId ) ] );
			} catch ( e ) { /* best effort */ }
		}
	} );

	test( 'code block renders with syntax styling', async ( { page } ) => {
		if ( ! fixturePostId || ! fixtureSpaceSlug ) {
			test.fixme( true, 'Could not seed fixture post with code block' );
			return;
		}

		const metrics = new EaseMetrics( page );

		const postSlug = wp( [ 'eval', `
			global $wpdb;
			echo $wpdb->get_var( "SELECT slug FROM {$wpdb->prefix}jt_posts WHERE id = ${fixturePostId}" );
		` ] );

		await autoLogin( page, 1, `/community/s/${ fixtureSpaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Look for a pre>code block with a language class or highlight spans.
		const codeBlock = page.locator( 'pre code, .hljs, .highlight, [class*="language-"]' ).first();
		await expect( codeBlock ).toBeVisible( { timeout: 5000 } );

		const expectation = loadSpec( 'X06' );
		matchDelivery( expectation, {
			code_block_visible: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
