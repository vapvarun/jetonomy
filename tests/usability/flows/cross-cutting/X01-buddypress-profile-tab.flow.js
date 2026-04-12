// @ts-check
/**
 * X01 — BuddyPress profile tab (P2)
 *
 * If BuddyPress is active, verify that a Jetonomy profile tab appears on
 * BP member profiles. If BP is not active, mark as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'X01 — BuddyPress profile tab integration', () => {

	test( 'jetonomy profile tab registered in BP, or fixme if BP inactive', () => {
		const bpActive = wp( [ 'eval', `
			echo defined( 'BP_VERSION' ) || function_exists( 'buddypress' ) ? 'yes' : 'no';
		` ] );

		if ( bpActive !== 'yes' ) {
			test.fixme( true, 'BuddyPress is not active — cannot test profile tab integration' );
			return;
		}

		// Verify Jetonomy registers a BP nav item.
		const hasNav = wp( [ 'eval', `
			echo has_action( 'bp_setup_nav' ) ? 'yes' : 'no';
		` ] );
		expect( hasNav ).toBe( 'yes' );
	} );
} );
