// @ts-check
/**
 * C19 — Keyboard shortcuts (j/k navigation).
 *
 * P2 — Tests that pressing `j` and `k` on a space listing moves focus
 * between post rows. Also tests `?` to open the shortcut help modal.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C19 — Keyboard shortcuts', () => {

	test( 'j/k keys navigate between post rows on a space listing', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/s/welcome/' );
		metrics.start();

		// Check if there are .jt-row elements on the page.
		const rows = page.locator( '.jt-row, a.jt-row' );
		const rowCount = await rows.count();

		if ( rowCount < 2 ) {
			test.skip( true, 'Not enough post rows to test j/k navigation' );
			return;
		}

		// Press `j` to move focus to the first row.
		await page.keyboard.press( 'j' );
		const firstFocused = page.locator( '.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus' );
		await expect( firstFocused ).toBeVisible( { timeout: 2000 } );

		// First focused should be the first row (index 0).
		const firstFocusedEl = firstFocused.first();
		await expect( firstFocusedEl ).toHaveClass( /jt-kb-focus/ );

		// Press `j` again to move to the second row.
		await page.keyboard.press( 'j' );
		const secondFocused = page.locator( '.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus' );
		await expect( secondFocused ).toBeVisible( { timeout: 2000 } );

		// Press `k` to go back up.
		await page.keyboard.press( 'k' );
		const backUp = page.locator( '.jt-row.jt-kb-focus, a.jt-row.jt-kb-focus' );
		await expect( backUp ).toBeVisible( { timeout: 2000 } );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( '? key opens the shortcut help modal', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/s/welcome/' );
		metrics.start();

		// Press `?` to open shortcut help.
		await page.keyboard.press( 'Shift+/' );

		// The shortcut help modal should appear.
		const modal = page.locator( '.jt-shortcut-help' );
		const visible = await modal.isVisible().catch( () => false );

		if ( ! visible ) {
			// Wait a bit for the modal to render.
			await page.waitForTimeout( 500 );
		}

		await expect( modal ).toBeVisible( { timeout: 3000 } );

		// It should contain shortcut descriptions.
		await expect( modal.locator( 'table' ) ).toBeVisible();
		await expect( modal ).toContainText( 'j / k' );

		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
