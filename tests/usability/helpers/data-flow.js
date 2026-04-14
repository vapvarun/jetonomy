// @ts-check
const { expect } = require( '@playwright/test' );
const { wp, journey, proJourney, dbQuery, dbWrite } = require( './wp-cli' );

/**
 * Layer 2 — Data Flow assertion helpers.
 *
 * Usability flows follow state through multiple hops: click → REST → DB →
 * notification → email → user inbox. Each helper here asserts state at one
 * hop so a broken link surfaces with a precise error message, not a generic
 * "test failed".
 *
 * All assertions throw on mismatch so Playwright marks the test as failed
 * and captures a screenshot + trace.
 */

/**
 * Assert a DB row has the expected column value.
 *
 * @param {string} table - e.g. 'wp_jt_notifications'
 * @param {number} id - Row primary key
 * @param {string} column - Column name to check
 * @param {string | number} expected - Expected value (string compare)
 */
function assertDbColumn( table, id, column, expected ) {
	const rows = dbQuery( `SELECT ${ column } FROM ${ table } WHERE id = ${ id }` );
	if ( rows.length === 0 ) {
		throw new Error( `DataFlow: no row in ${ table } with id=${ id }` );
	}
	const actual = rows[ 0 ];
	if ( String( actual ) !== String( expected ) ) {
		throw new Error(
			`DataFlow: ${ table }.${ column } expected "${ expected }", got "${ actual }" for id=${ id }`
		);
	}
}

/**
 * Assert a DB row exists matching the given WHERE fragment.
 *
 * @param {string} table
 * @param {string} whereFragment - e.g. "user_id=1 AND space_id=15"
 */
function assertDbRowExists( table, whereFragment ) {
	const rows = dbQuery( `SELECT COUNT(*) FROM ${ table } WHERE ${ whereFragment }` );
	const count = parseInt( rows[ 0 ], 10 );
	if ( count === 0 ) {
		throw new Error( `DataFlow: no row in ${ table } matching ${ whereFragment }` );
	}
}

/**
 * Assert a DB row does NOT exist.
 */
function assertDbRowAbsent( table, whereFragment ) {
	const rows = dbQuery( `SELECT COUNT(*) FROM ${ table } WHERE ${ whereFragment }` );
	const count = parseInt( rows[ 0 ], 10 );
	if ( count > 0 ) {
		throw new Error( `DataFlow: found ${ count } row(s) in ${ table } matching ${ whereFragment } (expected 0)` );
	}
}

/**
 * Poll the DB until a condition is met or timeout. Useful when a REST call
 * runs asynchronously (via Action Scheduler, Queue, or cron fallback).
 *
 * @param {() => boolean} predicate
 * @param {object} [options]
 * @param {number} [options.timeoutMs=5000]
 * @param {number} [options.intervalMs=200]
 */
async function waitForState( predicate, options = {} ) {
	const { timeoutMs = 5000, intervalMs = 200 } = options;
	const deadline = Date.now() + timeoutMs;
	while ( Date.now() < deadline ) {
		if ( predicate() ) {
			return true;
		}
		await new Promise( ( r ) => setTimeout( r, intervalMs ) );
	}
	throw new Error( `DataFlow: waitForState timed out after ${ timeoutMs }ms` );
}

/**
 * Verify that a free-plugin journey read returns the expected value at a
 * dotted path. The journey envelope is { success, data, errors, logs,
 * duration_ms } — this helper asserts on data.<path>.
 *
 * @param {Array<string>} commandArgs - e.g. [ 'post', 'get', '42' ]
 * @param {string} expectedPath - dotted path inside .data
 * @param {any} expectedValue - stringified for comparison
 */
function assertJourney( commandArgs, expectedPath, expectedValue ) {
	const result = journey( commandArgs );
	if ( ! result.success ) {
		throw new Error( `DataFlow: journey ${ JSON.stringify( commandArgs ) } failed: ${ JSON.stringify( result.errors ) }` );
	}
	const actual = getByPath( result.data, expectedPath );
	if ( String( actual ) !== String( expectedValue ) ) {
		throw new Error( `DataFlow: journey ${ JSON.stringify( commandArgs ) } data.${ expectedPath } expected "${ expectedValue }", got "${ actual }"` );
	}
}

/**
 * Same for Pro journeys.
 *
 * @param {Array<string>} commandArgs
 * @param {string} expectedPath
 * @param {any} expectedValue
 */
function assertProJourney( commandArgs, expectedPath, expectedValue ) {
	const result = proJourney( commandArgs );
	if ( ! result.success ) {
		throw new Error( `DataFlow: pro journey ${ JSON.stringify( commandArgs ) } failed: ${ JSON.stringify( result.errors ) }` );
	}
	const actual = getByPath( result.data, expectedPath );
	if ( String( actual ) !== String( expectedValue ) ) {
		throw new Error( `DataFlow: pro journey ${ JSON.stringify( commandArgs ) } data.${ expectedPath } expected "${ expectedValue }", got "${ actual }"` );
	}
}

/**
 * Resolve a dotted path on an object.
 * @param {object} obj
 * @param {string} path - e.g. 'data.items.0.id'
 */
function getByPath( obj, path ) {
	return path.split( '.' ).reduce( ( acc, key ) => ( acc == null ? acc : acc[ key ] ), obj );
}

module.exports = {
	assertDbColumn,
	assertDbRowExists,
	assertDbRowAbsent,
	waitForState,
	assertJourney,
	assertProJourney,
	getByPath,
};
