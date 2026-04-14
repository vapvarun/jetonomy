// @ts-check
/**
 * GA28 — Plugin uninstall (P1)
 *
 * Destructive operation — skip actual uninstall. Instead, verify that
 * uninstall.php exists so the plugin has a clean removal path.
 */

const { test, expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );
const { WP_PATH } = require( '../../helpers/wp-cli' );

test.describe( 'GA28 — Plugin uninstall file exists', () => {

	test( 'uninstall.php exists in the jetonomy plugin directory', () => {
		const uninstallFile = path.join( WP_PATH, 'wp-content', 'plugins', 'jetonomy', 'uninstall.php' );
		expect( fs.existsSync( uninstallFile ) ).toBe( true );
	} );

	test( 'actual uninstall is destructive — fixme', () => {
		test.fixme( true, 'Actual uninstall is destructive — only file existence is verified' );
	} );
} );
