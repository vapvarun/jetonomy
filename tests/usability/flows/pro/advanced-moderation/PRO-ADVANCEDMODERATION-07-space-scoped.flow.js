// @ts-check
/**
 * PRO-ADVANCEDMODERATION-07 — Space-scoped rule fires only in target.
 *
 * Seeds a rule scoped to a specific space. Posts in that space trigger
 * the rule; posts in another space do not.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-ADVANCEDMODERATION-07 — Space-scoped rule fires only in target', () => {

	let ruleId;
	let targetSpaceId;
	let otherSpaceId;
	let postInTarget;
	let postInOther;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		// Get two spaces.
		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 2' );
		targetSpaceId = spaces[ 0 ];
		otherSpaceId = spaces[ 1 ] || spaces[ 0 ];

		// Create a space-scoped hold rule.
		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--name=TestRule',
			'--type=keyword',
			'--pattern=scoped-word',
			'--action=hold',
			
			`--space_id=${ targetSpaceId }`,
		] );
		ruleId = rule.data?.id;
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` ); } catch ( e ) { /* */ }
		}
		if ( postInTarget ) {
			try { journey( [ 'post', 'delete', String( postInTarget ) ] ); } catch ( e ) { /* */ }
		}
		if ( postInOther ) {
			try { journey( [ 'post', 'delete', String( postInOther ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'rule fires in target space but not in other space', () => {
		// Post in the target space — should be held.
		const p1 = journey( [
			'post', 'create',
			`--space=${ targetSpaceId }`,
			'--author=1',
			'--title=Target scoped-word test',
			'--content=Has scoped-word',
		] );
		postInTarget = p1.data?.id;

		const s1 = dbQuery( `SELECT status FROM wp_jt_posts WHERE id = ${ postInTarget }` );
		expect( [ 'held', 'pending_review', 'pending' ] ).toContain( s1[ 0 ] );

		// Post in the other space — should be published (rule does not apply).
		if ( otherSpaceId !== targetSpaceId ) {
			const p2 = journey( [
				'post', 'create',
				`--space=${ otherSpaceId }`,
				'--author=1',
				'--title=Other space scoped-word test',
				'--content=Has scoped-word',
			] );
			postInOther = p2.data?.id;

			const s2 = dbQuery( `SELECT status FROM wp_jt_posts WHERE id = ${ postInOther }` );
			expect( s2[ 0 ] ).toBe( 'published' );
		}
	} );
} );
