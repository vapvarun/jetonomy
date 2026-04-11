// @ts-check
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

/**
 * Thin wrapper around `wp` CLI for usability tests.
 *
 * Every usability flow stages fixtures and verifies state via the CLI module
 * shipped in C0-C27. This file exists so flow files never build their own
 * child_process calls and so the WP path is centralized.
 *
 * Uses execFileSync with argv arrays instead of a shell string so arguments
 * are never re-parsed and command injection is impossible regardless of
 * fixture content.
 */

const WP_PATH = process.env.JETONOMY_TEST_WP_PATH
	|| path.resolve( __dirname, '../../../../../..' );

/**
 * Run a wp-cli command and return stdout (trimmed).
 *
 * @param {Array<string>} args - argv passed to `wp`, e.g.
 *   [ 'jetonomy', 'post', 'create', '--space=15', '--author=3',
 *     '--title=Hello', '--content=Body' ]
 *   Every array element is passed as one argument — no shell parsing.
 * @param {object} [options]
 * @param {boolean} [options.json] - Parse stdout as JSON before returning.
 * @returns {string | object}
 */
function wp( args, options = {} ) {
	const allArgs = [ '--path=' + WP_PATH, ...args ];
	let stdout;
	try {
		stdout = execFileSync( 'wp', allArgs, {
			encoding: 'utf8',
			stdio: [ 'ignore', 'pipe', 'pipe' ],
		} ).trim();
	} catch ( err ) {
		const message = err.stderr ? err.stderr.toString() : err.message;
		throw new Error( `wp-cli failed: wp ${ allArgs.join( ' ' ) }\n${ message }` );
	}
	if ( options.json ) {
		try {
			return JSON.parse( stdout );
		} catch ( parseErr ) {
			throw new Error( `wp-cli returned non-JSON output for: wp ${ allArgs.join( ' ' ) }\n${ stdout }` );
		}
	}
	return stdout;
}

/**
 * Run a free-plugin journey command and return the parsed JSON envelope.
 *
 * @param {Array<string>} commandArgs - e.g. [ 'post', 'get', '42' ]
 * @returns {object} The journey result { success, data, errors, logs, duration_ms }.
 */
function journey( commandArgs ) {
	return wp( [ 'jetonomy', ...commandArgs, '--format=json' ], { json: true } );
}

/**
 * Run a Pro journey command and return the parsed JSON envelope.
 *
 * @param {Array<string>} commandArgs
 * @returns {object}
 */
function proJourney( commandArgs ) {
	return wp( [ 'jetonomy-pro', ...commandArgs, '--format=json' ], { json: true } );
}

/**
 * Execute a SELECT via `wp db query` and return stdout lines as an array.
 * Every argument is passed as a single argv entry so SQL strings with
 * spaces, quotes, and special characters round-trip safely.
 *
 * @param {string} sql
 * @returns {Array<string>}
 */
function dbQuery( sql ) {
	const output = wp( [ 'db', 'query', sql, '--skip-column-names' ] );
	return output.split( '\n' ).filter( Boolean );
}

module.exports = { wp, journey, proJourney, dbQuery, WP_PATH };
