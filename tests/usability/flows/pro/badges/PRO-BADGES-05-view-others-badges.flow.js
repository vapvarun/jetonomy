// @ts-check
/**
 * PRO-BADGES-05 — View another user's badges.
 *
 * Awards bob a badge, logs in as alice, visits bob's profile,
 * and asserts alice can see bob's badge collection.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-BADGES-05 — View another user badges', () => {

	let badgeId;

	test.beforeEach( () => {
		const badge = proJourney( [ 'badges', 'create', '--name=Top Contributor', '--tier=gold' ] );
		badgeId = badge.data?.id;

		// Award to bob.
		if ( badgeId ) {
			proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=4' ] );
		}
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice views bob profile and sees his badges', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/u/bob/' );
		metrics.start();

		const badgesSection = page.locator( '.jt-profile-badges, .jt-user-badges' );
		await expect( badgesSection ).toBeVisible( { timeout: 5000 } );

		const badge = badgesSection.locator( '.jt-badge-item, .jt-badge' ).filter( { hasText: 'Top Contributor' } );
		await expect( badge ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertErrorCount( 0 );
	} );
} );
