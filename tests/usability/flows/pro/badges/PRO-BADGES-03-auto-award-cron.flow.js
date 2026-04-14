// @ts-check
/**
 * PRO-BADGES-03 — Auto-award via cron.
 *
 * Creates a badge with criteria (e.g., 10+ posts), ensures alice
 * meets the criteria, runs the cron evaluator, and asserts the
 * badge is auto-awarded.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-BADGES-03 — Auto-award via cron', () => {

	let badgeId;

	test.beforeEach( () => {
		// Create a badge with auto-award criteria.
		const badge = proJourney( [
			'badges', 'create',
			'--name=Prolific Poster',
			'--tier=gold',
			'--criteria=post_count>=10',
		] );
		badgeId = badge.data?.id;

		// Ensure alice meets the criteria (fake post_count).
		dbWrite( "UPDATE wp_jt_user_profiles SET post_count = 15 WHERE user_id = 3" );
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
		// Reset post_count.
		try { dbWrite( "UPDATE wp_jt_user_profiles SET post_count = 0 WHERE user_id = 3" ); } catch ( e ) { /* ignore */ }
	} );

	test.fixme( 'cron auto-awards badge when criteria met', async () => {
		// Trigger the badge evaluation cron.
		wp( [ 'cron', 'event', 'run', 'jetonomy_pro_badges_evaluate' ] );

		// DB: alice received the badge.
		assertDbRowExists( 'wp_jt_pro_user_badges', `badge_id = ${ badgeId } AND user_id = 3` );
	} );
} );
