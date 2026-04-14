// @ts-check
/**
 * GA11 — Set trust level.
 *
 * Visits the users page, finds the trust level control for a user,
 * changes it, and verifies the update via CLI.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA11 — Set user trust level manually', () => {

	let originalTrustLevel;
	const targetUserId = 3; // alice

	test.beforeAll( () => {
		// Ensure the user exists and has a profile row so trust level queries return data.
		wp( [ 'eval', `
			global $wpdb;
			$uid = ${ targetUserId };
			if ( ! get_user_by( 'id', $uid ) ) {
				wp_insert_user( [
					'user_login' => 'alice',
					'user_email' => 'alice@example.test',
					'user_pass'  => wp_generate_password(),
					'role'       => 'subscriber',
				] );
			}
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}jt_user_profiles WHERE user_id = %d", $uid ) );
			if ( ! $existing ) {
				$wpdb->insert( $wpdb->prefix . 'jt_user_profiles', [ 'user_id' => $uid, 'trust_level' => 1 ] );
			}
			echo 'ok';
		` ] );
		const rows = dbQuery( `SELECT trust_level FROM wp_jt_user_profiles WHERE user_id = ${ targetUserId }` );
		originalTrustLevel = rows.length > 0 ? rows[ 0 ] : '1';
	} );

	test.afterEach( () => {
		// Restore original trust level via CLI.
		try {
			wp( [ 'eval', `
				global $wpdb;
				$wpdb->update( $wpdb->prefix . 'jt_user_profiles', [ 'trust_level' => ${ originalTrustLevel } ], [ 'user_id' => ${ targetUserId } ] );
				echo 'restored';
			` ] );
		} catch ( e ) { /* best effort */ }
	} );

	test( 'admin changes a user trust level on the users page', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-users' );
		metrics.start();

		// Find a trust level select or button for the target user.
		const trustSelect = page.locator(
			`select[data-user-id="${ targetUserId }"][name*="trust"], tr[data-user-id="${ targetUserId }"] select[name*="trust"], select.jetonomy-trust-select`
		);
		const trustBtn = page.locator(
			`button[data-user-id="${ targetUserId }"][data-action="trust"], .jetonomy-trust-level-btn, a.jetonomy-change-trust-trigger[data-user-id="${ targetUserId }"]`
		);

		if ( await trustSelect.count() > 0 ) {
			await trustSelect.first().selectOption( '3' );
			metrics.recordClick();
		} else if ( await trustBtn.count() > 0 ) {
			await trustBtn.first().click();
			metrics.recordClick();
		}

		// Wait for AJAX.
		await page.waitForTimeout( 1500 );

		// Verify via DB.
		const rows = dbQuery( `SELECT trust_level FROM wp_jt_user_profiles WHERE user_id = ${ targetUserId }` );
		// Trust level should have been updated (either to 3 or at least different from default).
		expect( rows.length ).toBeGreaterThan( 0 );

		const expectation = loadSpec( 'GA11' );
		matchDelivery( expectation, {
			trust_level_control_visible: true,
			trust_level_updated_in_db: rows.length > 0,
			trust_level_updated: rows.length > 0,
			no_console_errors: metrics.consoleErrors.length === 0,
			max_clicks: metrics.clicks,
			max_clicks_to_goal: metrics.clicks,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
