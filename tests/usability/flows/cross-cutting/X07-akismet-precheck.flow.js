// @ts-check
/**
 * X07 — Akismet pre-check (P1)
 *
 * Fixme if Akismet is not active. If active, verify that Jetonomy's spam
 * filter hook is registered.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );

test.describe( 'X07 — Akismet pre-publish check', () => {

	test( 'Akismet integration hook registered, or fixme if not active', () => {
		const akismetActive = wp( [ 'eval', `
			echo is_plugin_active( 'akismet/akismet.php' ) ? 'yes' : 'no';
		` ] );

		if ( akismetActive !== 'yes' ) {
			test.fixme( true, 'Akismet is not active — cannot test spam pre-check integration' );
			return;
		}

		// Verify Jetonomy hooks into the spam check pipeline.
		const hasSpamHook = wp( [ 'eval', `
			echo has_filter( 'jetonomy_pre_publish_check' ) || has_filter( 'jetonomy_spam_check' ) ? 'yes' : 'no';
		` ] );
		expect( hasSpamHook ).toBe( 'yes' );
	} );
} );
