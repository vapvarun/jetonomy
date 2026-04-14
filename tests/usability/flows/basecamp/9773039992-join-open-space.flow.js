// @ts-check
/**
 * Basecamp 9773039992 — "Non-Members in Open Spaces See 'Follow' Instead of 'Join Space'"
 *
 * Verifies that a logged-in non-member visiting an open-policy space sees
 * "Join Space" (not "Follow"), can click it to become a member, and the
 * page updates to reflect membership.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists, assertDbRowAbsent } = require( '../../helpers/data-flow' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadExpectation, matchDelivery } = require( '../../helpers/expectation-matcher' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'Basecamp 9773039992 — Join Space button on open spaces', () => {

	const cardId = '9773039992';
	let expectation;
	const spaceId = 15; // "First Forum" — open policy, known fixture.
	const testUserLogin = 'bob';
	const testUserId = 4;

	test.beforeAll( () => {
		expectation = loadExpectation( cardId );
	} );

	test.beforeEach( () => {
		// Ensure the test user is NOT a member of the target space before
		// each run so the flow starts from a clean "non-member" state.
		try {
			wp( [ 'jetonomy', 'member', 'leave', `--space=${ spaceId }`, `--by=${ testUserId }` ] );
		} catch ( e ) {
			// Ignore — user may already not be a member.
		}
		assertDbRowAbsent( 'wp_jt_space_members', `space_id = ${ spaceId } AND user_id = ${ testUserId }` );
	} );

	test.afterEach( () => {
		// Clean up membership so the next run is re-runnable.
		try {
			wp( [ 'jetonomy', 'member', 'leave', `--space=${ spaceId }`, `--by=${ testUserId }` ] );
		} catch ( e ) {
			// Ignore.
		}
	} );

	test( 'non-member sees Join Space button, clicks it, becomes a member', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Layer 3 — log in as the test user (bob, non-member of space 15).
		await autoLogin( page, testUserLogin, `/community/s/first-forum/` );
		metrics.start();

		// Layer 3 — "Join Space" button MUST be visible.
		const joinButton = page.locator( 'button.jt-join-btn' );
		await expect( joinButton ).toBeVisible( { timeout: 5000 } );
		await expect( joinButton ).toHaveText( /Join Space/i );

		// Layer 3 — "Follow" button must NOT be visible as the primary
		// action for non-members (it's fine for members, but we're not
		// a member yet).
		const followButton = page.locator( 'button:has-text("Follow"):not(.jt-join-btn)' );
		const followVisible = await followButton.isVisible().catch( () => false );

		// Click Join Space.
		await joinButton.click();
		metrics.recordClick();

		// Layer 2 — wait for the page to reload (composer.js reloads on
		// successful join). The URL should still be the space page.
		await page.waitForURL( /\/community\/s\/first-forum\// , { timeout: 10000 } );

		// Layer 2 — data flow: membership row must exist.
		assertDbRowExists(
			'wp_jt_space_members',
			`space_id = ${ spaceId } AND user_id = ${ testUserId } AND role = 'member'`
		);

		// Layer 3 — after reload, the user should see "Following" (the
		// subscription toggle) instead of "Join Space", confirming the
		// transition from non-member to member.
		const memberState = page.locator( 'button:has-text("Follow"), button:has-text("Following")' );
		await expect( memberState.first() ).toBeVisible( { timeout: 5000 } );

		// Layer 3 — "Join Space" should be gone now.
		await expect( joinButton ).not.toBeVisible();

		// Layer 5 — expectation vs delivery.
		matchDelivery( expectation, {
			join_space_button_visible: true,
			follow_button_hidden: ! followVisible,
			clicking_join_creates_membership: true,
			page_reloads_with_member_state: true,
			max_clicks_to_join: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
			no_console_errors: metrics.consoleErrors.length === 0,
			no_failed_network_requests: metrics.failedRequests.length === 0,
		} );

		// Layer 4 — ease metrics.
		metrics.assertClickCount( { lessThanOrEqual: 1 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'button works at 390px mobile viewport', async ( { page } ) => {
		await page.setViewportSize( { width: 390, height: 844 } );
		await autoLogin( page, testUserLogin, `/community/s/first-forum/` );

		const joinButton = page.locator( 'button.jt-join-btn' );
		await expect( joinButton ).toBeVisible( { timeout: 5000 } );
		await expect( joinButton ).toHaveText( /Join Space/i );
	} );
} );
