// @ts-check
/**
 * PRO-AI-02 — User submits post, spam verdict runs.
 *
 * Creates a post with known-spam content and verifies the AI spam
 * detector evaluated it (conditional on provider being configured).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-02 — User submits post → spam verdict', () => {

	let spaceId;
	let postId;
	let providerConfigured = false;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}

		// Check if any AI provider is configured.
		try {
			const config = proJourney( [ 'ai', 'provider-status' ] );
			providerConfigured = config.success && config.data?.configured === true;
		} catch ( e ) {
			providerConfigured = false;
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'spam detection evaluates new post', () => {
		if ( ! providerConfigured ) {
			test.skip( true, 'No AI provider configured — skipping spam detection test' );
			return;
		}

		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Buy cheap watches online FREE',
			'--content=Click here now for FREE offer limited time discount watches',
		] );
		postId = post.data?.id;

		// Check the AI evaluation log or post meta for spam verdict.
		const verdict = proJourney( [
			'ai', 'spam-check', `--post_id=${ postId }`,
		] );
		expect( verdict.success ).toBe( true );
		expect( verdict.data?.verdict ).toBeTruthy();
	} );
} );
