// @ts-check
/**
 * GA27 — Plugin deactivation
 *
 * Deactivate jetonomy via WP-CLI, assert no fatal errors, then reactivate.
 * Verify that all custom tables still exist and key options are preserved
 * after the deactivate+reactivate cycle.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, dbQuery } = require( '../../helpers/wp-cli' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'GA27 — Plugin deactivation cycle', () => {

	const specId = 'GA27';
	let expectation;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test.afterEach( () => {
		// Always ensure the plugin is reactivated after the test.
		try {
			wp( [ 'plugin', 'activate', 'jetonomy' ] );
		} catch ( e ) { /* already active */ }
	} );

	test( 'deactivate + reactivate preserves tables and options', () => {
		// Record key tables before deactivation.
		const tablesBefore = dbQuery(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_jt_%'"
		);
		const tableCountBefore = tablesBefore.length;
		expect( tableCountBefore ).toBeGreaterThan( 0 );

		// Record the jetonomy_settings option before deactivation.
		const settingsBefore = dbQuery(
			"SELECT option_value FROM wp_options WHERE option_name = 'jetonomy_settings'"
		);
		expect( settingsBefore.length ).toBe( 1 );

		// Deactivate.
		const deactivateOutput = wp( [ 'plugin', 'deactivate', 'jetonomy' ] );
		expect( deactivateOutput ).toContain( 'Success' );

		// Verify it is inactive.
		const status = wp( [ 'plugin', 'status', 'jetonomy' ] );
		expect( status ).toMatch( /inactive/i );

		// Verify tables still exist while plugin is inactive.
		const tablesWhileInactive = dbQuery(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_jt_%'"
		);
		expect( tablesWhileInactive.length ).toBe( tableCountBefore );

		// Verify options are preserved while plugin is inactive.
		const settingsWhileInactive = dbQuery(
			"SELECT option_value FROM wp_options WHERE option_name = 'jetonomy_settings'"
		);
		expect( settingsWhileInactive.length ).toBe( 1 );
		expect( settingsWhileInactive[ 0 ] ).toBe( settingsBefore[ 0 ] );

		// Reactivate.
		const activateOutput = wp( [ 'plugin', 'activate', 'jetonomy' ] );
		expect( activateOutput ).toContain( 'Success' );

		// Verify it is active again.
		const statusAfter = wp( [ 'plugin', 'status', 'jetonomy' ] );
		expect( statusAfter ).toMatch( /active/i );

		// Verify tables still exist after reactivation.
		const tablesAfter = dbQuery(
			"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'wp_jt_%'"
		);
		expect( tablesAfter.length ).toBe( tableCountBefore );

		// Verify settings option preserved after reactivation.
		const settingsAfter = dbQuery(
			"SELECT option_value FROM wp_options WHERE option_name = 'jetonomy_settings'"
		);
		expect( settingsAfter[ 0 ] ).toBe( settingsBefore[ 0 ] );

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			deactivate_success: true,
			no_fatal_on_deactivation: true,
			reactivate_success: true,
			tables_preserved_during_deactivation: tablesWhileInactive.length === tableCountBefore,
			options_preserved_during_deactivation: settingsWhileInactive[ 0 ] === settingsBefore[ 0 ],
			tables_preserved_after_reactivation: tablesAfter.length === tableCountBefore,
			options_preserved_after_reactivation: settingsAfter[ 0 ] === settingsBefore[ 0 ],
		} );
	} );
} );
