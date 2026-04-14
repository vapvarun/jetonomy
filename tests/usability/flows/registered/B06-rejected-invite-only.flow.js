// @ts-check
/**
 * B06 — Rejected on invite-only space attempt (registered).
 *
 * Finds or identifies an invite-only space via DB query, auto-logs in as
 * bob, visits it, and asserts an "Invite Only" badge or access-denied
 * message is visible.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B06 — Rejected on invite-only attempt', () => {

	let spaceSlug;

	test.beforeAll( () => {
		// Find an invite-only space. The join_policy is stored in the
		// settings JSON column or as a direct column.
		try {
			const result = wp( [ 'eval', `
				global $wpdb;
				$row = $wpdb->get_row(
					"SELECT slug FROM {$wpdb->prefix}jt_spaces WHERE join_policy = 'invite' AND status = 'active' LIMIT 1"
				);
				echo $row ? $row->slug : '';
			` ] );
			spaceSlug = result.trim() || null;
		} catch ( e ) {
			spaceSlug = null;
		}
	} );

	test( 'bob sees invite-only badge on a restricted space', async ( { page } ) => {
		test.skip( ! spaceSlug, 'No invite-only spaces found in the database' );

		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'bob', `/community/s/${ spaceSlug }/` );
		metrics.start();

		// Community container is present (not a 404).
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// An "Invite Only" badge, access denied notice, or request-to-join
		// button should be visible.
		const inviteIndicator = page.locator(
			'.jt-badge:has-text("Invite"), .jt-invite-only, .jt-access-denied, .jt-request-join, :text("invite only"), :text("Invite Only")'
		);
		await expect( inviteIndicator.first() ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
