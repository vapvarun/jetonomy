// @ts-check
/**
 * X04 — Image upload (P1)
 *
 * Fixme — requires actual file upload interaction which is difficult to
 * automate reliably in a stub. Marked fixme until a proper file upload
 * helper is implemented.
 */

const { test } = require( '@playwright/test' );

test.describe( 'X04 — Image upload in editor', () => {

	test.fixme( true, 'Requires file upload interaction — needs dedicated upload helper' );

	test( 'image upload renders preview in editor', async ( { page } ) => {
		// Implementation requires:
		// 1. Navigate to post creation form
		// 2. Use Playwright fileChooser to attach an image
		// 3. Assert preview thumbnail renders
		// 4. Submit and verify image appears in published post
	} );
} );
