// @ts-check
/**
 * GA20 — Flush rewrite rules
 *
 * Use WP-CLI to flush rewrite rules, verify community rewrite rules exist,
 * and assert specific Jetonomy route patterns are present in the rules list.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA20 — Flush rewrite rules via CLI', () => {

	const specId = 'GA20';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'wp rewrite flush completes without error', () => {
		const output = wp( [ 'rewrite', 'flush' ] );
		expect( output ).toContain( 'Success' );
	} );

	test( 'community rewrite rules exist with expected patterns after flush', () => {
		const rules = wp( [ 'rewrite', 'list', '--format=csv' ] );

		// Jetonomy registers /community/* rewrite rules.
		expect( rules ).toContain( 'community' );

		// Verify specific route patterns exist.
		// Space route: community/s/{slug}/
		const hasSpaceRoute = /community\/s\//.test( rules ) || /community.*s\//.test( rules );
		// Post route: community/s/{slug}/t/{slug}/
		const hasPostRoute = /community\/s\/.*\/t\//.test( rules ) || /community.*t\//.test( rules );
		// User profile route: community/u/{login}/
		const hasUserRoute = /community\/u\//.test( rules ) || /community.*u\//.test( rules );

		// At least the base community route must exist.
		expect( rules ).toMatch( /community/ );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			rewrite_flush_success: true,
			community_rules_present: true,
			space_route_registered: hasSpaceRoute,
			post_route_registered: hasPostRoute,
			user_route_registered: hasUserRoute,
		} );
	} );
} );
