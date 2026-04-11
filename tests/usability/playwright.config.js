// @ts-check
const { defineConfig, devices } = require( '@playwright/test' );

/**
 * Playwright config for Jetonomy usability tests.
 *
 * Tests hit a live WordPress install at JETONOMY_TEST_BASE_URL. Locally that
 * defaults to the Local by Flywheel site at http://forums.local. In CI, spin
 * up a container with both jetonomy + jetonomy-pro active and point the env
 * var at it.
 *
 * Auto-login is handled via the ?autologin=<login> query parameter served
 * by `wp-content/mu-plugins/dev-auto-login.php` — tests never type into the
 * WP login form. See global flag JETONOMY_TEST_SKIP_PRO for running the free
 * plugin in isolation (skips any flow tagged with @pro).
 */
module.exports = defineConfig( {
	testDir: './flows',
	testMatch: [ '**/*.flow.js', '**/*.spec.js' ],
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false, // Tests share a DB; run serially to avoid fixture collisions.
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: [
		[ 'list' ],
		[ 'html', { outputFolder: 'reports', open: 'never' } ],
		[ 'json', { outputFile: 'reports/results.json' } ],
	],
	use: {
		baseURL: process.env.JETONOMY_TEST_BASE_URL || 'http://forums.local',
		trace: 'retain-on-failure',
		video: 'retain-on-failure',
		screenshot: 'only-on-failure',
		actionTimeout: 10_000,
	},
	projects: [
		{
			name: 'chromium-desktop',
			use: {
				...devices[ 'Desktop Chrome' ],
				viewport: { width: 1280, height: 900 },
			},
		},
		{
			name: 'chromium-mobile',
			use: {
				...devices[ 'iPhone 13' ],
			},
		},
	],
} );
