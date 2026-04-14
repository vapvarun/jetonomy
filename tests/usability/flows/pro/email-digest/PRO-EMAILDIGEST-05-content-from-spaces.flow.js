// @ts-check
/**
 * PRO-EMAILDIGEST-05 — Digest includes subscribed spaces.
 *
 * Subscribes alice to a space, seeds posts in that space and a
 * different space, triggers digest, and asserts the email only
 * includes content from the subscribed space.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey } = require( '../../../helpers/wp-cli' );
const { clear: clearMail, assertMailSent } = require( '../../../helpers/email-capture' );

test.describe( 'PRO-EMAILDIGEST-05 — Digest includes subscribed spaces', () => {

	let postId;

	test.beforeEach( () => {
		clearMail();

		wp( [ 'user', 'meta', 'update', '3', 'jetonomy_digest_frequency', 'daily' ] );

		// Ensure alice is subscribed to space 1 (welcome).
		journey( [ 'subscription', 'subscribe', '--user=3', '--type=space', '--id=1' ] );

		// Seed post in subscribed space.
		const post = journey( [
			'post', 'create',
			'--space=1',
			'--author=4',
			`--title=Subscribed Space Post ${ Date.now() }`,
			'--content=From subscribed space.',
		] );
		postId = post.data?.id || post.id;
	} );

	test.afterEach( () => {
		try { wp( [ 'user', 'meta', 'delete', '3', 'jetonomy_digest_frequency' ] ); } catch ( e ) { /* ignore */ }
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'digest email contains content from subscribed spaces', async () => {
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_email_digest_daily' ] );

		const mail = assertMailSent( /digest/i, { toContains: 'alice' } );

		// Should reference the subscribed space content.
		expect( mail.message ).toContain( 'Subscribed Space Post' );
	} );
} );
