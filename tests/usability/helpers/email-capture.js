// @ts-check
const fs = require( 'fs' );
const path = require( 'path' );
const { WP_PATH } = require( './wp-cli' );

/**
 * Layer 2 — Email Capture helper.
 *
 * WordPress sends mail via wp_mail(). Tests must NOT rely on real SMTP
 * delivery. The companion mu-plugin jetonomy-test-mail-capture.php writes
 * every outgoing mail to a JSON-lines file at the path below. This helper
 * reads + clears that file so flow tests can assert on subject/body/to/
 * headers without any actual network call.
 *
 * The mu-plugin only activates when the JETONOMY_TEST_MAIL_CAPTURE constant
 * is defined (set via wp-config.php on test environments), so production
 * sites are never affected.
 */

const CAPTURE_FILE = process.env.JETONOMY_TEST_MAIL_FILE
	|| path.join( WP_PATH, 'wp-content', 'debug-mail.jsonl' );

/**
 * Read every captured mail from disk. Returns an array of
 * { to, subject, message, headers, captured_at }.
 */
function readAllMail() {
	if ( ! fs.existsSync( CAPTURE_FILE ) ) {
		return [];
	}
	const content = fs.readFileSync( CAPTURE_FILE, 'utf8' );
	return content.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => {
			try {
				return JSON.parse( line );
			} catch ( err ) {
				return null;
			}
		} )
		.filter( Boolean );
}

/**
 * Clear the capture file so the next assertion window starts fresh. Call at
 * the start of every flow to avoid cross-test pollution.
 */
function clear() {
	if ( fs.existsSync( CAPTURE_FILE ) ) {
		fs.writeFileSync( CAPTURE_FILE, '' );
	}
}

/**
 * Assert that an email with a matching subject was captured.
 */
function assertMailSent( subjectMatcher, options = {} ) {
	const mails = readAllMail();
	const match = mails.find( ( m ) => {
		if ( typeof subjectMatcher === 'string' ) {
			return m.subject.includes( subjectMatcher );
		}
		return subjectMatcher.test( m.subject );
	} );
	if ( ! match ) {
		const subjects = mails.map( ( m ) => `"${ m.subject }"` ).join( ', ' );
		throw new Error( `EmailCapture: no mail matching ${ subjectMatcher }. Found: [${ subjects }]` );
	}
	if ( options.toContains ) {
		const to = Array.isArray( match.to ) ? match.to.join( ',' ) : match.to;
		if ( ! to.includes( options.toContains ) ) {
			throw new Error( `EmailCapture: recipient "${ to }" does not contain "${ options.toContains }"` );
		}
	}
	if ( options.bodyContains ) {
		if ( ! match.message.includes( options.bodyContains ) ) {
			throw new Error( `EmailCapture: body does not contain "${ options.bodyContains }"` );
		}
	}
	if ( options.bodyDoesNotContain ) {
		if ( match.message.includes( options.bodyDoesNotContain ) ) {
			throw new Error( `EmailCapture: body unexpectedly contains "${ options.bodyDoesNotContain }"` );
		}
	}
	return match;
}

/**
 * Assert that no email has been captured since the last clear().
 */
function assertNoMailSent() {
	const mails = readAllMail();
	if ( mails.length > 0 ) {
		const summary = mails.map( ( m ) => `"${ m.subject }" → ${ m.to }` ).join( ', ' );
		throw new Error( `EmailCapture: expected no mail, found ${ mails.length }: [${ summary }]` );
	}
}

/**
 * Extract the first URL matching a pattern from a captured mail body.
 * Useful for verifying join-request emails point at admin.php, not the
 * frontend members page (the original bug in card 9725048839).
 */
function extractUrl( mail, pattern ) {
	const matches = mail.message.match( /https?:\/\/[^"\s<>]+/g ) || [];
	return matches.find( ( url ) => {
		if ( typeof pattern === 'string' ) {
			return url.includes( pattern );
		}
		return pattern.test( url );
	} );
}

module.exports = {
	readAllMail,
	clear,
	assertMailSent,
	assertNoMailSent,
	extractUrl,
	CAPTURE_FILE,
};
