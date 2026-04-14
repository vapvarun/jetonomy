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
			// wp-cli commands often append "Success: ..." or "Warning: ..."
			// after the JSON line. Extract the JSON portion by finding the
			// first { or [ and the matching last } or ].
			const first = stdout.search( /[{\[]/ );
			const lastCurly = stdout.lastIndexOf( '}' );
			const lastBracket = stdout.lastIndexOf( ']' );
			const last = Math.max( lastCurly, lastBracket );
			if ( first >= 0 && last > first ) {
				try {
					return JSON.parse( stdout.substring( first, last + 1 ) );
				} catch ( innerErr ) {
					// Fall through to the original error.
				}
			}
			throw new Error( `wp-cli returned non-JSON output for: wp ${ allArgs.join( ' ' ) }\n${ stdout.substring( 0, 500 ) }` );
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
 * Execute a SELECT via `wp eval` using $wpdb, which knows the correct
 * MySQL socket on Local by Flywheel (unlike `wp db query` which calls the
 * raw mysql CLI binary and defaults to /tmp/mysql.sock).
 *
 * @param {string} sql
 * @returns {Array<string>}
 */
function dbQuery( sql ) {
	const escaped = sql.replace( /'/g, "\\'" );
	const code = `global $wpdb; $rows = $wpdb->get_col( '${escaped}' ); echo implode( "\\n", $rows );`;
	const output = wp( [ 'eval', code ] );
	return output.split( '\n' ).filter( Boolean );
}

/**
 * Execute a write statement (INSERT/UPDATE/DELETE) via `wp eval` + $wpdb.
 *
 * @param {string} sql
 * @returns {string} stdout from wp eval
 */
function dbWrite( sql ) {
	const escaped = sql.replace( /'/g, "\\'" );
	const code = `global $wpdb; $wpdb->query( '${escaped}' ); echo $wpdb->rows_affected;`;
	return wp( [ 'eval', code ] );
}

/**
 * Cached user login → WP user ID lookup.
 * Demo-seeded users (alice, bob, carol, david, eve, admin) don't have stable
 * IDs — they depend on when demo-seed ran. Flows call getUserId('alice')
 * instead of hardcoding 3.
 */
const _userCache = {};
function getUserId( login ) {
	if ( _userCache[ login ] !== undefined ) {
		return _userCache[ login ];
	}
	try {
		const out = wp( [ 'user', 'get', String( login ), '--field=ID' ] );
		_userCache[ login ] = parseInt( out, 10 );
	} catch ( e ) {
		_userCache[ login ] = 0;
	}
	return _userCache[ login ];
}

/**
 * Cached space slug → space ID lookup.
 * Demo-seeded spaces don't have stable IDs either.
 */
const _spaceCache = {};
function getSpaceId( slug ) {
	if ( _spaceCache[ slug ] !== undefined ) {
		return _spaceCache[ slug ];
	}
	try {
		const rows = dbQuery( `SELECT id FROM wp_jt_spaces WHERE slug = '${ slug.replace( /'/g, "\\'" ) }' LIMIT 1` );
		_spaceCache[ slug ] = rows.length > 0 ? parseInt( rows[ 0 ], 10 ) : 0;
	} catch ( e ) {
		_spaceCache[ slug ] = 0;
	}
	return _spaceCache[ slug ];
}

module.exports = { wp, journey, proJourney, dbQuery, dbWrite, WP_PATH, getUserId, getSpaceId };
