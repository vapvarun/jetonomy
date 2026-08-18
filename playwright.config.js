/**
 * Playwright config, scoped to ONE regression gate.
 *
 * The plugin's previous browser suite was retired (CLAUDE.md) because it
 * drifted and surfaced no product bugs. This config exists only for
 * tests/browser/admin-tables.spec.js - the responsive list-table check, which
 * catches a class of bug no static gate can see.
 *
 * Keep it that way. If this grows projects, fixtures, or a reporters array,
 * the retired suite is growing back.
 *
 * @package Jetonomy
 */

module.exports = {
	testDir: './tests/browser',
	// Local admin screens; no reason for retries to mask a real layout failure.
	retries: 0,
	reporter: [ [ 'list' ] ],
	use: {
		headless: true,
		// A failure here is visual. A screenshot is the fastest way to see it.
		screenshot: 'only-on-failure',
	},
};
