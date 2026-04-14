// @ts-check
/**
 * PRO-EMAILDIGEST-04 — Click unsubscribe in email.
 *
 * Triggers a digest email, extracts the unsubscribe link, navigates
 * to it in the browser, and asserts alice's digest preference is
 * set to 'none'.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey } = require( '../../../helpers/wp-cli' );
const { clear: clearMail, assertMailSent, extractUrl } = require( '../../../helpers/email-capture' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-EMAILDIGEST-04 — Click unsubscribe in email', () => {

	let postId;

	test.beforeEach( () => {
		clearMail();

		wp( [ 'user', 'meta', 'update', '3', 'jetonomy_digest_frequency', 'daily' ] );

		const post = journey( [
			'post', 'create',
			'--space=1',
			'--author=4',
			`--title=Unsub Digest ${ Date.now() }`,
			'--content=Unsub test.',
		] );
		postId = post.data?.id || post.id;

		// Trigger the digest.
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_email_digest_daily' ] );
	} );

	test.afterEach( () => {
		try { wp( [ 'user', 'meta', 'delete', '3', 'jetonomy_digest_frequency' ] ); } catch ( e ) { /* ignore */ }
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'unsubscribe link in digest email disables digest', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		const mail = assertMailSent( /digest/i );
		const unsubUrl = extractUrl( mail, 'unsubscribe' );
		expect( unsubUrl ).toBeTruthy();

		// Navigate to the unsubscribe URL.
		await autoLogin( page, 'alice', unsubUrl );
		metrics.start();

		// Should show confirmation that digest is disabled.
		const confirm = page.locator( 'text=/unsubscribed|disabled|turned off/i' );
		await expect( confirm ).toBeVisible( { timeout: 5000 } );

		// DB: frequency should be 'none'.
		const freq = wp( [ 'user', 'meta', 'get', '3', 'jetonomy_digest_frequency' ] );
		expect( freq ).toBe( 'none' );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
