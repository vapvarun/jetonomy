// @ts-check
/**
 * PRO-BADGES-07 — Multiple grants repeatable.
 *
 * Creates a repeatable badge, awards it to alice 3 times, and asserts
 * 3 distinct rows exist in wp_jt_pro_user_badges.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-BADGES-07 — Multiple grants repeatable', () => {

	let badgeId;

	test.beforeEach( () => {
		const badge = proJourney( [ 'badges', 'create', '--name=Weekly Star', '--tier=bronze', '--repeatable=true' ] );
		badgeId = badge.data?.id;
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'repeatable badge can be awarded multiple times', async () => {
		proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );
		proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );
		proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );

		// DB: 3 rows.
		const count = dbQuery( `SELECT COUNT(*) FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId } AND user_id = 3` );
		expect( parseInt( count[ 0 ], 10 ) ).toBe( 3 );
	} );
} );
