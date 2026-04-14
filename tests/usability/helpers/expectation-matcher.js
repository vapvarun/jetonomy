// @ts-check
const fs = require( 'fs' );
const path = require( 'path' );
const yaml = require( 'js-yaml' );

/**
 * Layer 5 — Expectation vs Delivery matcher.
 *
 * Loads a YAML file from tests/usability/expectations/cards/<id>.yml and
 * compares what the flow test actually delivered against what the user
 * originally stated as their expectation (usually sourced from a Basecamp
 * card).
 *
 * YAML schema:
 *
 *   card_id: "9773154702"
 *   title: "Clicking Individual Notification Does Not Mark as Read"
 *   user_story: |
 *     As a user with unread notifications, when I click one in the
 *     dropdown, I expect it to be marked read immediately and the badge
 *     count to decrement...
 *   expectations:
 *     badge_decremented: true
 *     row_flipped_to_read: true
 *     navigation_succeeded: true
 *     max_clicks_to_goal: 2
 *     max_time_to_goal_seconds: 10
 *     no_errors: true
 *
 * A flow test calls:
 *
 *   const expectation = loadExpectation( '9773154702' );
 *   matchDelivery( expectation, { badge_decremented: true, row_flipped_to_read: true, ... } );
 *
 * Any mismatch throws with a report that reads like a product requirement
 * gap, not a test stack trace.
 */

const EXPECTATIONS_DIR = path.resolve( __dirname, '../expectations/cards' );
const SPECS_DIR = path.resolve( __dirname, '../expectations/specs' );

/**
 * Load an expectation YAML by card ID.
 */
function loadExpectation( cardId ) {
	const file = path.join( EXPECTATIONS_DIR, `${ cardId }.yml` );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `ExpectationMatcher: no expectation file at ${ file }` );
	}
	const content = fs.readFileSync( file, 'utf8' );
	const parsed = yaml.load( content );
	if ( ! parsed || typeof parsed !== 'object' ) {
		throw new Error( `ExpectationMatcher: failed to parse ${ file }` );
	}
	return parsed;
}

/**
 * Load a spec expectation YAML by flow ID (e.g. 'GA02', 'C24', 'M01').
 */
function loadSpec( flowId ) {
	const file = path.join( SPECS_DIR, `${ flowId }.yml` );
	if ( ! fs.existsSync( file ) ) {
		throw new Error( `ExpectationMatcher: no spec file at ${ file }` );
	}
	const content = fs.readFileSync( file, 'utf8' );
	const parsed = yaml.load( content );
	if ( ! parsed || typeof parsed !== 'object' ) {
		throw new Error( `ExpectationMatcher: failed to parse ${ file }` );
	}
	return parsed;
}

/**
 * Compare delivered results against a loaded expectation and throw with a
 * human-readable diff on mismatch.
 */
function matchDelivery( expectation, delivered ) {
	const stated = expectation.expectations || {};
	const mismatches = [];

	// Reaching this call implies the flow ran to completion without throwing,
	// so expectations that assert "flow_completes_without_error" are implicitly
	// delivered. Individual flow files don't have to pass the key explicitly.
	const effectiveDelivered = { flow_completes_without_error: true, ...delivered };

	for ( const [ key, expectedValue ] of Object.entries( stated ) ) {
		const actualValue = effectiveDelivered[ key ];

		// Handle "max_X" keys as upper bounds rather than equality.
		if ( key.startsWith( 'max_' ) ) {
			if ( typeof actualValue !== 'number' ) {
				mismatches.push( `  ${ key }: expected ≤ ${ expectedValue }, but delivered no value` );
				continue;
			}
			if ( actualValue > expectedValue ) {
				mismatches.push( `  ${ key }: expected ≤ ${ expectedValue }, delivered ${ actualValue }` );
			}
			continue;
		}
		if ( key.startsWith( 'min_' ) ) {
			if ( typeof actualValue !== 'number' ) {
				mismatches.push( `  ${ key }: expected ≥ ${ expectedValue }, but delivered no value` );
				continue;
			}
			if ( actualValue < expectedValue ) {
				mismatches.push( `  ${ key }: expected ≥ ${ expectedValue }, delivered ${ actualValue }` );
			}
			continue;
		}

		// Default: equality.
		if ( JSON.stringify( actualValue ) !== JSON.stringify( expectedValue ) ) {
			mismatches.push(
				`  ${ key }: expected ${ JSON.stringify( expectedValue ) }, delivered ${ JSON.stringify( actualValue ) }`
			);
		}
	}

	if ( mismatches.length > 0 ) {
		const cardRef = expectation.card_id
			? `Basecamp ${ expectation.card_id }`
			: ( expectation.flow_id ? `Flow ${ expectation.flow_id }` : '(no id)' );
		const title = expectation.title || '(untitled)';
		throw new Error(
			`ExpectationMatcher: user expectation not met for ${ cardRef }: "${ title }"\n\n`
			+ `Mismatches:\n${ mismatches.join( '\n' ) }\n\n`
			+ `User story:\n${ expectation.user_story || '(no story provided)' }`
		);
	}
}

module.exports = { loadExpectation, loadSpec, matchDelivery, EXPECTATIONS_DIR, SPECS_DIR };
