// @ts-check
/**
 * PRO-WEBHOOKS-02 — Event fires, webhook dispatches.
 *
 * Creates a webhook for post.created, then creates a post. Verifies
 * the webhook delivery log records the dispatch attempt.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-02 — Event fires → webhook dispatches', () => {

	let webhookId;
	let postId;
	let spaceId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'webhooks' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'webhooks' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		// Create a webhook subscription.
		const wh = proJourney( [
			'webhooks', 'create',
			'--url=https://httpbin.org/post',
			'--event=post.created',
			'--enabled=1',
		] );
		webhookId = wh.data?.id;
	} );

	test.afterEach( () => {
		if ( webhookId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }` ); } catch ( e ) { /* */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'creating a post triggers webhook dispatch', () => {
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Webhook dispatch test',
			'--content=Testing webhook event dispatch',
		] );
		expect( post.success ).toBe( true );
		postId = post.data?.id;

		// Check webhook delivery log for a dispatch attempt.
		// The pre_http_request filter in test env intercepts, so we check
		// the webhook's last_triggered or delivery log.
		const delivered = dbQuery(
			`SELECT last_triggered_at FROM wp_jt_pro_webhooks WHERE id = ${ webhookId }`
		);
		// last_triggered_at should be set (non-null) if dispatch ran.
		expect( delivered[ 0 ] ).toBeTruthy();
	} );
} );
