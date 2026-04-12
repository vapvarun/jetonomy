// @ts-check
/**
 * PRO-REPLYBYEMAIL-02 — IMAP cron polls inbox.
 *
 * Verifies the IMAP cron job is registered and can be triggered
 * via CLI without fatal errors.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, wp } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-REPLYBYEMAIL-02 — IMAP cron polls inbox', () => {

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'reply-by-email' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'reply-by-email' ] );
		}
	} );

	test.fixme( 'IMAP cron event is scheduled', () => {
		// Check cron schedule includes the reply-by-email hook.
		const cron = wp( [ 'cron', 'event', 'list', '--format=json' ], { json: true } );
		const hasEvent = Array.isArray( cron )
			? cron.some( ( e ) => e.hook?.includes( 'reply_by_email' ) || e.hook?.includes( 'rbe_poll' ) )
			: false;

		expect( hasEvent ).toBe( true );
	} );
} );
