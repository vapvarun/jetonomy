// @ts-check

/**
 * Helper — Log in to the WordPress test site as a specific user via query
 * parameter rather than typing into the login form.
 *
 * The auto-login mu-plugin (wp-content/mu-plugins/dev-auto-login.php) reads
 * ?autologin=<login-or-id> and sets an auth cookie. This helper encapsulates
 * the convention so flow tests never hard-code the URL parameter.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string | number} userIdOrLogin - '1', 'admin', 'alice', etc.
 * @param {string} [landingPath='/community/'] - Where to land after login.
 */
async function autoLogin( page, userIdOrLogin, landingPath = '/community/' ) {
	const separator = landingPath.includes( '?' ) ? '&' : '?';
	const urlWithAutoLogin = `${ landingPath }${ separator }autologin=${ userIdOrLogin }`;
	await page.goto( urlWithAutoLogin );
	// Wait for the redirect to strip the autologin param so we land on the
	// canonical URL. This also guarantees the cookie is set.
	await page.waitForURL( ( url ) => ! url.searchParams.has( 'autologin' ), {
		timeout: 5000,
	} );
}

/**
 * Log out by visiting wp-login.php?action=logout.
 *
 * @param {import('@playwright/test').Page} page
 */
async function logout( page ) {
	await page.goto( '/wp-login.php?action=logout' );
	// WP will show a "you are attempting to log out" confirmation — click the link.
	const confirm = page.locator( 'a:has-text("log out")' );
	if ( await confirm.count() > 0 ) {
		await confirm.first().click();
	}
}

module.exports = { autoLogin, logout };
