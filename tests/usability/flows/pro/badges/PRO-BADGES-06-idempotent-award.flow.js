// @ts-check
/**
 * PRO-BADGES-06 — Idempotent re-award non-repeatable.
 *
 * Awards a non-repeatable badge to alice twice and asserts only one
 * row exists in wp_jt_pro_user_badges (no duplicates).
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-BADGES-06 — Idempotent re-award non-repeatable', () => {

	let badgeId;

	test.beforeEach( () => {
		const badge = proJourney( [ 'badges', 'create', '--name=Pioneer', '--tier=silver', '--repeatable=false' ] );
		badgeId = badge.data?.id;
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'awarding the same non-repeatable badge twice creates only one row', async () => {
		// Award once.
		proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );

		// Award again — should be idempotent.
		try {
			proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );
		} catch ( e ) {
			// May throw — that is acceptable behavior too.
		}

		// DB: exactly 1 row.
		const count = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId } AND user_id = 3` );
		expect( parseInt( count[ 0 ], 10 ) ).toBe( 1 );
	} );
} );
