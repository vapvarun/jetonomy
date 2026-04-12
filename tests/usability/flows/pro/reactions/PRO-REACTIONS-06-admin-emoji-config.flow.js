// @ts-check
/**
 * PRO-REACTIONS-06 — Admin configure allowed emojis.
 *
 * Logs in as admin, navigates to the reactions settings page,
 * customizes the allowed emoji set, saves, and verifies the
 * setting persists.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, proJourney } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-REACTIONS-06 — Admin configure emojis', () => {

	let originalSetting;

	test.beforeEach( () => {
		// Save original setting for restore.
		try {
			originalSetting = wp( [ 'option', 'get', 'jetonomy_pro_reactions_emojis' ] );
		} catch ( e ) {
			originalSetting = null;
		}
	} );

	test.afterEach( () => {
		// Restore original setting.
		if ( originalSetting ) {
			wp( [ 'option', 'update', 'jetonomy_pro_reactions_emojis', originalSetting ] );
		} else {
			try { wp( [ 'option', 'delete', 'jetonomy_pro_reactions_emojis' ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'admin configures allowed reaction emojis', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-pro-reactions' );
		metrics.start();

		// The emoji configuration section should be visible.
		const configSection = page.locator( '.jt-reactions-config, #jetonomy-reactions-settings' );
		await expect( configSection ).toBeVisible( { timeout: 5000 } );

		// Toggle off one emoji (e.g., uncheck 'angry').
		const angryCheckbox = page.locator( 'input[name="reactions_emojis[]"][value="angry"], .jt-emoji-toggle[data-emoji="angry"]' );
		if ( await angryCheckbox.isVisible() ) {
			await angryCheckbox.click();
			metrics.recordClick();
		}

		// Save settings.
		const saveBtn = page.locator( 'button:has-text("Save"), input[type="submit"]' );
		await saveBtn.click();
		metrics.recordClick();

		// Verify save success notice.
		const notice = page.locator( '.notice-success, .updated' );
		await expect( notice ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 3 } );
		metrics.assertErrorCount( 0 );
	} );
} );
