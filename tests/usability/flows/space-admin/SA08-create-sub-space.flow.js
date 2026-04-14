// @ts-check
/**
 * SA08 — Create a sub-space (P1).
 *
 * Creates a space with a parent_id via the journey CLI, then verifies the
 * parent-child relationship is correct in the DB.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../helpers/data-flow' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'SA08 — Create sub-space', () => {

	let parentSpaceId;
	let childSpaceId;

	test.beforeAll( () => {
		const rows = dbQuery( 'SELECT id FROM wp_jt_spaces ORDER BY id ASC LIMIT 1' );
		parentSpaceId = rows.length > 0 ? parseInt( rows[ 0 ], 10 ) : 1;
	} );

	test.afterEach( () => {
		if ( childSpaceId ) {
			try {
				journey( [ 'space', 'delete', String( childSpaceId ) ] );
			} catch ( e ) { /* best effort */ }
			childSpaceId = null;
		}
	} );

	test.fixme( 'create a sub-space with parent_id via journey CLI', () => {
		// FIXME: wp jetonomy space create does not yet accept --parent_id.
		const title = `SA08 Sub-Space ${ Date.now() }`;

		const result = journey( [
			'space', 'create',
			`--title=${ title }`,
			'--type=forum',
			`--parent_id=${ parentSpaceId }`,
			'--category_id=1',
		] );

		expect( result.success ).toBe( true );
		childSpaceId = result.data?.id ?? result.data?.space_id;
		expect( childSpaceId ).toBeTruthy();

		// Assert parent-child relationship in DB.
		const rows = dbQuery(
			`SELECT parent_id FROM wp_jt_spaces WHERE id = ${ childSpaceId }`
		);
		expect( rows[ 0 ] ).toBe( String( parentSpaceId ) );

		// Assert row exists.
		assertDbRowExists( 'wp_jt_spaces', `id = ${ childSpaceId } AND parent_id = ${ parentSpaceId }` );

		const expectation = loadSpec( 'SA08' );
		matchDelivery( expectation, {
			sub_space_created: true,
			parent_child_relationship_correct: rows[ 0 ] === String( parentSpaceId ),
		} );
	} );
} );
