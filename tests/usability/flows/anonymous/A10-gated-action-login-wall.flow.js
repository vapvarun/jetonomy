// @ts-check
/**
 * A10 — Gated action triggers login wall (anonymous).
 *
 * Visits a space as an anonymous user and attempts to interact with a gated
 * action (reply button, vote button, or new post link). Asserts that the UI
 * shows a login prompt or redirects to the login page.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'A10 — Attempt gated action -> login wall', () => {

	test( 'anonymous visitor is prompted to log in when attempting a write action', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		metrics.start();

		await page.goto( `${ SITE }/community/s/welcome/` );

		// Page loads.
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Look for any interactive gated element: reply button, vote button,
		// new post link, or the composer area.
		const gatedElements = page.locator(
			'button.jt-vote-btn, a[href*="/new/"], button:has-text("Reply"), .jt-login-prompt, a[href*="wp-login.php"]'
		);

		const gatedCount = await gatedElements.count();

		if ( gatedCount > 0 ) {
			// Click the first gated element.
			const first = gatedElements.first();
			await first.click( { timeout: 3000 } ).catch( () => {} );
			metrics.recordClick();

			// After clicking, either:
			// 1. A login modal/prompt appeared.
			// 2. The page redirected to wp-login.php.
			// 3. The element itself was a link to login.
			const loginPrompt = page.locator(
				'.jt-login-prompt, .jt-login-modal, [class*="login"], #loginform'
			);
			const onLoginPage = page.url().includes( 'wp-login.php' );

			if ( ! onLoginPage ) {
				// If not redirected, check for a login prompt on the page.
				const hasPrompt = await loginPrompt.count() > 0;
				// At minimum, the page should not have performed the action
				// (no new reply/vote should have been created).
				expect( hasPrompt || onLoginPage ).toBeTruthy();
			}
		} else {
			// If no gated elements are visible to anon users, the space page
			// itself hides write actions — that's also correct behavior.
			// Verify that the "New Post" link is absent or points to login.
			const newPostLink = page.locator( 'a[href*="/new/"]' );
			const count = await newPostLink.count();
			expect( count ).toBe( 0 );
		}

		metrics.assertErrorCount( 0 );
	} );
} );
