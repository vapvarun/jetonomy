// @ts-check
/**
 * PRO-AI-09 — Thread summaries feature.
 *
 * Enables the thread summaries feature and verifies a summary can
 * be generated for a post with replies. Skips if no provider configured.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-AI-09 — Thread summaries feature', () => {

	let providerConfigured = false;
	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'ai' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'ai' ] );
		}

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

	test.fixme( 'generate thread summary for a post', () => {
		if ( ! providerConfigured ) {
			test.skip( true, 'No AI provider configured — skipping summaries test' );
			return;
		}

		// Enable the feature.
		proJourney( [ 'ai', 'feature', 'thread_summaries', '--enable' ] );

		// Create a post with some replies to summarize.
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Thread summary test',
			'--content=What is the best approach for testing AI features?',
		] );
		postId = post.data?.id;

		journey( [
			'reply', 'create',
			`--post_id=${ postId }`,
			'--author=1',
			'--content=You should use mock providers for unit tests.',
		] );

		// Generate summary.
		const summary = proJourney( [
			'ai', 'summarize-thread', `--post_id=${ postId }`,
		] );
		expect( summary.success ).toBe( true );
		expect( summary.data?.summary ).toBeTruthy();
	} );
} );
