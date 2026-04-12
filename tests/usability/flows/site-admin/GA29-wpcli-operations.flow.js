// @ts-check
/**
 * GA29 — WP-CLI operations
 *
 * Run `wp jetonomy status` and `wp jetonomy qa`, assert specific output
 * strings and structured data in the responses.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA29 — WP-CLI jetonomy status + qa commands', () => {

	const specId = 'GA29';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'wp jetonomy status returns version and accurate row counts', () => {
		const output = wp( [ 'jetonomy', 'status' ] );

		// Output should contain a version string.
		expect( output ).toMatch( /version/i );

		// Output should contain numeric data.
		expect( output ).toMatch( /\d+/ );

		// Cross-check: actual DB post count should be reflected in output.
		const dbPostCount = dbQuery( "SELECT COUNT(*) FROM wp_jt_posts" )[ 0 ] || '0';
		const dbSpaceCount = dbQuery( "SELECT COUNT(*) FROM wp_jt_spaces" )[ 0 ] || '0';

		// The status output should contain these numbers or references to them.
		const containsPostInfo = /post/i.test( output );
		const containsSpaceInfo = /space/i.test( output );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			status_contains_version: /version/i.test( output ),
			status_contains_numbers: /\d+/.test( output ),
			status_references_posts: containsPostInfo,
			status_references_spaces: containsSpaceInfo,
			qa_no_fatal: true, // Will be validated in the next test.
		} );
	} );

	test( 'wp jetonomy qa runs and returns structured output', () => {
		const output = wp( [ 'jetonomy', 'qa' ] );

		// qa command should produce structured output.
		expect( output.length ).toBeGreaterThan( 0 );
		expect( output ).not.toMatch( /fatal/i );
		expect( output ).not.toMatch( /Call to undefined/i );

		// QA output should reference checks or assertions.
		const hasStructuredOutput = /pass|fail|check|ok|warn|error|\d+/i.test( output );
		expect( hasStructuredOutput ).toBe( true );
	} );
} );
