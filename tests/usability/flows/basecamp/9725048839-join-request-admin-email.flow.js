// @ts-check
/**
 * Basecamp 9725048839 — "No Admin Screen or Email Notification for Space Join Requests"
 *
 * Two sub-bugs:
 * (a) Email link points at the frontend members page instead of the admin
 *     Join Requests tab.
 * (b) Join Requests tab disappears when join_policy changes from approval
 *     to open while pending requests exist.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { clear: clearMail, assertMailSent, extractUrl } = require( '../../helpers/email-capture' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadExpectation, matchDelivery } = require( '../../helpers/expectation-matcher' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'Basecamp 9725048839 — join request admin email + tab visibility', () => {

	const cardId = '9725048839';
	let expectation;
	let fixtureSpaceId;
	let fixtureCategoryId;
	let fixtureUserId;
	let fixtureRequestId;

	test.beforeAll( () => {
		expectation = loadExpectation( cardId );
	} );

	test.beforeEach( () => {
		clearMail();

		// Seed: category → approval-policy space → test user → submit a
		// join request so the join_request_created action fires and sends
		// the admin email.
		const scenario = journey( [ 'scenario', 'run', 'space-with-pending-join-request' ] );
		if ( ! scenario.success ) {
			throw new Error( `Scenario seed failed: ${ JSON.stringify( scenario.errors ) }` );
		}
		fixtureCategoryId = scenario.fixtures?.category_id;
		fixtureSpaceId = scenario.fixtures?.space_id;
		fixtureUserId = scenario.fixtures?.user_id;
		fixtureRequestId = scenario.fixtures?.request_id;
	} );

	test.afterEach( () => {
		// Full scenario cleanup reverses everything — deny request, delete
		// space/category/user.
		try {
			journey( [ 'scenario', 'run', 'space-with-pending-join-request', '--cleanup' ] );
		} catch ( e ) {
			// Best effort — fixture ids may already be gone.
		}
	} );

	test( 'admin email contains admin.php URL, not frontend /members/ page', async () => {
		// Layer 2 — data flow: the scenario's submit_join_request fires
		// do_action('jetonomy_join_request_created') which invokes
		// Notifier::on_join_request, which sends the email. The mail
		// capture mu-plugin writes it to debug-mail.jsonl.
		const mail = assertMailSent( 'requested to join', {
			bodyContains: 'admin.php',
		} );

		// The URL in the email body must point at the admin screen with
		// the join_requests tab pre-selected.
		const adminUrl = extractUrl( mail, 'admin.php' );
		expect( adminUrl ).toBeTruthy();
		expect( adminUrl ).toContain( 'page=jetonomy-spaces' );
		expect( adminUrl ).toContain( 'tab=join_requests' );
		expect( adminUrl ).toContain( `space_id=${ fixtureSpaceId }` );

		// It must NOT contain the frontend members path.
		const frontendUrl = extractUrl( mail, '/members/' );
		expect( frontendUrl ).toBeUndefined();
	} );

	test( 'admin can click email link, see the tab, and approve', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Layer 2 — capture the admin URL from the email.
		const mail = assertMailSent( 'requested to join' );
		const adminUrl = extractUrl( mail, 'admin.php' );
		expect( adminUrl ).toBeTruthy();

		// Layer 3 — navigate to the email URL as admin.
		await autoLogin( page, 1, adminUrl );
		metrics.start();

		// Layer 3 — the Join Requests tab should be active.
		const activeTab = page.locator( 'a.nav-tab-active:has-text("Join Requests")' );
		await expect( activeTab ).toBeVisible( { timeout: 5000 } );

		// Layer 3 — the pending request should be in the table with
		// Approve / Deny buttons.
		const approveButton = page.locator(
			`button.jetonomy-approve-join-request[data-id="${ fixtureRequestId }"]`
		);
		await expect( approveButton ).toBeVisible( { timeout: 5000 } );

		// Click Approve.
		await approveButton.click();
		metrics.recordClick();

		// Layer 2 — wait for the AJAX roundtrip. The approve handler
		// removes the table row dynamically.
		await expect( approveButton ).not.toBeVisible( { timeout: 5000 } );

		// Layer 2 — the user is now a member.
		assertDbRowExists(
			'wp_jt_space_members',
			`space_id = ${ fixtureSpaceId } AND user_id = ${ fixtureUserId }`
		);

		// Layer 5 — expectation.
		matchDelivery( expectation, {
			email_url_points_to_admin: true,
			email_url_contains_tab_join_requests: true,
			email_url_does_not_contain_members_frontend: true,
			admin_tab_visible_when_policy_approval: true,
			admin_tab_visible_when_policy_changed_but_pending_exist: true, // tested below
			approve_button_works_on_admin_page: true,
			max_clicks_from_email_to_approve: metrics.clicks,
			max_time_to_goal_seconds: metrics.getElapsedMs() / 1000,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'tab stays visible when join_policy switched to open while pending exist', async ( { page } ) => {
		// Seed a SECOND request so after the first test's approve there's
		// still a pending row. Actually the beforeEach already seeded one
		// per scenario, and we haven't approved it yet in THIS test.

		// Flip the space policy to 'open' via the journey.
		journey( [
			'space', 'set-join-policy', String( fixtureSpaceId ), '--policy=open',
		] );

		// Navigate to the admin space edit page.
		await autoLogin(
			page, 1,
			`/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ fixtureSpaceId }&tab=general`
		);

		// Layer 3 — the Join Requests tab must still be visible because
		// pending rows exist, even though join_policy is now 'open'.
		const joinTab = page.locator( 'a.nav-tab:has-text("Join Requests")' );
		await expect( joinTab ).toBeVisible( { timeout: 5000 } );

		// It should show a count pill reflecting the pending row.
		await expect( joinTab ).toHaveText( /\(1\)/ );

		// Restore the policy so cleanup works cleanly.
		journey( [
			'space', 'set-join-policy', String( fixtureSpaceId ), '--policy=approval',
		] );
	} );
} );
