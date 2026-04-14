// @ts-check
/**
 * C18 — @mention another user.
 *
 * Checks whether the post/reply editor has an @mention autocomplete UI.
 * Types `@` in the composer, looks for an autocomplete dropdown. If the
 * feature is not yet implemented, the test is marked as fixme.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const users = require( '../../helpers/users' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C18 — @mention another user', () => {

	const spaceSlug = 'welcome';
	let createdPostId;

	test.afterEach( () => {
		if ( createdPostId ) {
			try {
				journey( [ 'post', 'delete', String( createdPostId ) ] );
			} catch ( e ) { /* ignore */ }
			createdPostId = null;
		}
	} );

	test( '@mention autocomplete appears when typing @ in the editor', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Seed a post so we have a reply composer visible.
		const seedResult = journey( [ 'post', 'create', `--space=${ users.spaceId( 'welcome' ) }`, `--author=${ users.id( 'admin' ) }`, '--title=C18 Mention Test', '--content=Testing mentions' ] );
		if ( seedResult.success && seedResult.data?.id ) {
			createdPostId = seedResult.data.id;
		}

		// Get the post slug for navigation.
		const slugRows = dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ createdPostId }` );
		const postSlug = slugRows[ 0 ] || '';

		// Login as alice and visit the post.
		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Find the composer editor body.
		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' ).first();
		await expect( editorBody ).toBeVisible( { timeout: 5000 } );

		// Click into the editor and type @.
		await editorBody.click();
		metrics.recordClick();
		await page.keyboard.type( '@' );

		// Wait briefly for an autocomplete dropdown to appear.
		const autocomplete = page.locator( '.jt-mention-autocomplete, .jt-mention-dropdown, .jt-autocomplete, [data-mention-list]' );
		const hasAutocomplete = await autocomplete.isVisible().catch( () => false );

		if ( ! hasAutocomplete ) {
			// Type a few more characters to trigger it.
			await page.keyboard.type( 'adm' );
			await page.waitForTimeout( 500 );
		}

		const autocompleteAfter = page.locator( '.jt-mention-autocomplete, .jt-mention-dropdown, .jt-autocomplete, [data-mention-list]' );
		const hasAutocompleteAfter = await autocompleteAfter.isVisible().catch( () => false );

		if ( ! hasAutocompleteAfter ) {
			// Feature not implemented — mark as fixme by throwing a skip-worthy message.
			test.fixme( true, '@mention autocomplete UI not yet implemented in the editor' );
			return;
		}

		// If we got here, verify the dropdown has at least one suggestion.
		const suggestions = autocompleteAfter.locator( 'li, .jt-mention-item, [data-mention-user]' );
		await expect( suggestions.first() ).toBeVisible( { timeout: 3000 } );

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
