// @ts-check
/**
 * PRO-FIELDS-02 — Fill custom field on new post.
 *
 * Seeds a custom field for posts, logs in as alice, creates a new post,
 * fills the custom field, submits, and asserts the value is stored.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-02 — Fill custom field on new post', () => {

	const spaceSlug = 'welcome';
	let fieldId;
	let postId;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Priority Level', '--type=text', '--context=post' ] );
		fieldId = field.data?.id;
	} );

	test.afterEach( () => {
		if ( fieldId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_field_values WHERE field_id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_fields WHERE id = ${ fieldId }` ); } catch ( e ) { /* ignore */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice fills a custom field when creating a post', async ( { page } ) => {
		const metrics = new EaseMetrics( page );
		const title = `Field Post ${ Date.now() }`;

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/new/` );
		metrics.start();

		// Fill post title.
		const titleInput = page.locator( '[name="title"], .jt-post-title input' );
		await expect( titleInput ).toBeVisible( { timeout: 5000 } );
		await titleInput.fill( title );

		// Fill post content.
		const editorBody = page.locator( '.jt-editor-body[contenteditable="true"]' );
		await editorBody.click();
		await page.keyboard.type( 'Post with custom field.' );

		// Fill the custom field.
		const customField = page.locator( `input[name="custom_field_${ fieldId }"], input[data-field-id="${ fieldId }"]` );
		await expect( customField ).toBeVisible( { timeout: 5000 } );
		await customField.fill( 'High' );

		// Submit.
		const submitBtn = page.locator( 'button:has-text("Publish"), button:has-text("Create Post")' );
		await submitBtn.click();
		metrics.recordClick();

		await page.waitForURL( /\/community\/s\/[^/]+\/t\/[^/]+/, { timeout: 10000 } );

		// Grab post ID.
		const ids = dbQuery( `SELECT id FROM wp_jt_posts WHERE title LIKE '%${ title.slice( 0, 10 ) }%' ORDER BY id DESC LIMIT 1` );
		if ( ids.length > 0 ) {
			postId = parseInt( ids[ 0 ], 10 );
		}

		// DB: field value stored.
		if ( postId && fieldId ) {
			assertDbRowExists( 'wp_jt_pro_field_values', `field_id = ${ fieldId } AND entity_type = 'post' AND entity_id = ${ postId }` );
		}

		metrics.assertClickCount( { lessThanOrEqual: 2 } );
		metrics.assertErrorCount( 0 );
	} );
} );
