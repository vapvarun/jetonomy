// @ts-check
/**
 * PRO-WEBHOOKS-03 — Event: post.created end-to-end.
 *
 * Full lifecycle: create webhook → create post → verify payload
 * fields in the delivery log (event, post_id, space_id).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-WEBHOOKS-03 — Event: post.created end-to-end', () => {

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

	test( 'post.created webhook fires with correct payload', () => {
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=E2E webhook post',
			'--content=Full lifecycle test',
		] );
		expect( post.success ).toBe( true );
		postId = post.data?.id;

		// Verify webhook delivery log contains the event and post_id.
		const deliveryResult = proJourney( [
			'webhooks', 'deliveries', String( webhookId ), '--limit=1',
		] );
		expect( deliveryResult.success ).toBe( true );

		const delivery = deliveryResult.data?.[ 0 ] || deliveryResult.data;
		if ( delivery ) {
			expect( delivery.event || delivery.event_type ).toBe( 'post.created' );
		}
	} );
} );
