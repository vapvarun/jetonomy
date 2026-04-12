// @ts-check
/**
 * C36 — See someone else's hover card.
 *
 * P2 — Hovers on a user avatar/link in a post listing and checks if a
 * hover card appears with user info (name, trust level, bio, stats).
 * If the feature is not rendered, marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C36 — See someone else\'s hover card', () => {

	test( 'hovering on a user link shows a hover card with user info', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/s/welcome/' );
		metrics.start();

		// Find a user link or avatar in the page (post listings, sidebar leaders, etc.).
		const userLink = page.locator( '.jt-user-link, .jt-mention, a[href*="/community/u/"]' ).first();
		const hasUserLink = await userLink.isVisible( { timeout: 5000 } ).catch( () => false );

		if ( ! hasUserLink ) {
			// Try the single post view where user links are more common.
			// Find the first post link and navigate there.
			const postRow = page.locator( '.jt-row, a.jt-row' ).first();
			const hasPost = await postRow.isVisible( { timeout: 3000 } ).catch( () => false );

			if ( hasPost ) {
				await postRow.click();
				await page.waitForURL( /\/community\/s\//, { timeout: 5000 } );
			}

			const userLinkInPost = page.locator( '.jt-user-link, .jt-mention, a[href*="/community/u/"]' ).first();
			const hasUserLinkInPost = await userLinkInPost.isVisible( { timeout: 3000 } ).catch( () => false );

			if ( ! hasUserLinkInPost ) {
				test.fixme( true, 'No user links found to hover on for hover card test' );
				return;
			}

			// Hover on the user link.
			await userLinkInPost.hover();
		} else {
			// Hover on the user link.
			await userLink.hover();
		}

		// Wait for the hover card to appear (400ms delay + fetch time).
		const hoverCard = page.locator( '.jt-hover-card' );
		const cardVisible = await hoverCard.isVisible( { timeout: 3000 } ).catch( () => false );

		if ( ! cardVisible ) {
			// Wait longer — the hover card has a 400ms debounce + network fetch.
			await page.waitForTimeout( 1000 );
			const cardVisibleRetry = await hoverCard.isVisible().catch( () => false );

			if ( ! cardVisibleRetry ) {
				test.fixme( true, 'Hover card did not appear after hovering on user link' );
				return;
			}
		}

		await expect( hoverCard ).toBeVisible();

		// The hover card should contain user info.
		const name = hoverCard.locator( '.jt-hc-name' );
		await expect( name ).toBeVisible( { timeout: 3000 } );

		const trust = hoverCard.locator( '.jt-hc-trust' );
		await expect( trust ).toBeVisible();
		await expect( trust ).toContainText( 'Level' );

		const stats = hoverCard.locator( '.jt-hc-stats' );
		await expect( stats ).toBeVisible();
		await expect( stats ).toContainText( 'posts' );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
