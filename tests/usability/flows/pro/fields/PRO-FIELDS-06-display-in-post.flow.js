// @ts-check
/**
 * PRO-FIELDS-06 — Display fields in post view.
 *
 * Seeds a post with a custom field value, visits the post as alice,
 * and asserts the field label + value render on the single post page.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-FIELDS-06 — Display fields in post view', () => {

	const spaceId = 1;
	const spaceSlug = 'welcome';
	let fieldId;
	let postId;
	let postSlug;

	test.beforeEach( () => {
		const field = proJourney( [ 'fields', 'create', '--name=Urgency', '--type=text', '--context=post' ] );
		fieldId = field.data?.id;

		const suffix = Date.now();
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=4',
			`--title=Fields Display ${ suffix }`,
			'--content=Check fields display.',
		] );
		postId = post.data?.id || post.id;
		postSlug = post.data?.slug || dbQuery( `SELECT slug FROM wp_jt_posts WHERE id = ${ postId }` )[ 0 ];

		// Set field value.
		if ( fieldId && postId ) {
			dbWrite( `INSERT INTO wp_jt_pro_field_values (field_id, entity_type, entity_id, value) VALUES (${ fieldId }, 'post', ${ postId }, 'Critical')` );
		}
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

	test.fixme( 'custom field value displays on post view', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', `/community/s/${ spaceSlug }/t/${ postSlug }/` );
		metrics.start();

		// Field display area.
		const fieldDisplay = page.locator( '.jt-custom-fields, .jt-post-fields' );
		await expect( fieldDisplay ).toBeVisible( { timeout: 5000 } );

		// Label "Urgency" should appear.
		await expect( fieldDisplay.locator( 'text=Urgency' ) ).toBeVisible();

		// Value "Critical" should appear.
		await expect( fieldDisplay.locator( 'text=Critical' ) ).toBeVisible();

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
