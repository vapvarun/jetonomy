// @ts-check
/**
 * PRO-EMAILDIGEST-02 — Daily cron fires digest.
 *
 * Sets alice to daily digest, seeds activity, triggers the cron event,
 * and asserts a digest email is captured via email-capture helper.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbWrite } = require( '../../../helpers/wp-cli' );
const { clear: clearMail, assertMailSent } = require( '../../../helpers/email-capture' );

test.describe( 'PRO-EMAILDIGEST-02 — Daily cron fires digest', () => {

	test.beforeEach( () => {
		clearMail();

		// Set alice to daily digest.
		wp( [ 'user', 'meta', 'update', '3', 'jetonomy_digest_frequency', 'daily' ] );

		// Seed some activity so the digest has content.
		const post = journey( [
			'post', 'create',
			'--space=1',
			'--author=4',
			`--title=Digest Post ${ Date.now() }`,
			'--content=New activity for digest.',
		] );
		// Store for cleanup if needed.
		this._postId = post.data?.id || post.id;
	} );

	test.afterEach( () => {
		try { wp( [ 'user', 'meta', 'delete', '3', 'jetonomy_digest_frequency' ] ); } catch ( e ) { /* ignore */ }
		if ( this._postId ) {
			try { journey( [ 'post', 'delete', String( this._postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'daily cron sends digest email to alice', async () => {
		// Trigger the daily digest cron.
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_email_digest_daily' ] );

		// Assert email was sent.
		const mail = assertMailSent( /digest|daily/i, {
			toContains: 'alice',
		} );

		// Email body should contain post content reference.
		expect( mail.message ).toContain( 'Digest' );
	} );
} );
