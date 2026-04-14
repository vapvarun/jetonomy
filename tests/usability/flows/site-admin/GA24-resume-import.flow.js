// @ts-check
/**
 * GA24 — Resume import (P1)
 *
 * If a partially-completed import state exists, assert the resume option shows.
 * Otherwise skip — this test requires a prior interrupted import to be meaningful.
 */

const { test, expect } = require( '@playwright/test' );
const { wp } = require( '../../helpers/wp-cli' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );

test.describe( 'GA24 — Resume import option', () => {

	test( 'resume option shows when import state exists, skip otherwise', async ( { page } ) => {
		// Check if any import state is saved.
		const importState = wp( [ 'eval', `
			$state = get_option( 'jetonomy_import_state', '' );
			echo ! empty( $state ) ? 'exists' : 'empty';
		` ] );

		if ( importState !== 'exists' ) {
			test.skip( true, 'No import state found — resume option not applicable' );
			return;
		}

		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, '/wp-admin/admin.php?page=jetonomy-import' );
		metrics.start();

		// Resume button/link should be visible when import state exists.
		const resumeOption = page.locator( 'text=/resume/i' ).first();
		await expect( resumeOption ).toBeVisible( { timeout: 5000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
