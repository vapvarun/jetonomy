// @ts-check
/**
 * B02 — Edit profile first time (registered).
 *
 * Auto-logs in as bob, visits the profile edit page, and asserts that
 * the profile form renders with editable fields.
 */

const { test, expect } = require( '@playwright/test' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

const SITE = 'http://forums.local';

test.describe( 'B02 — Edit own profile first time', () => {

	test( 'profile edit form renders with fields for bob', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'bob', '/community/u/bob/edit/' );
		metrics.start();

		// Community container is visible (not a 404).
		const container = page.locator( '.jt-app, .jt-container, .jt-two-col' );
		await expect( container.first() ).toBeVisible( { timeout: 8000 } );

		// Profile edit form or fields are present.
		const formFields = page.locator(
			'form input, form textarea, .jt-profile-edit, .jt-edit-profile'
		);
		await expect( formFields.first() ).toBeVisible( { timeout: 5000 } );

		// At least one text input is editable.
		const textInput = page.locator( 'form input[type="text"], form textarea' );
		const count = await textInput.count();
		expect( count ).toBeGreaterThanOrEqual( 1 );

		metrics.assertErrorCount( 0 );
	} );
} );
