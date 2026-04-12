// @ts-check
/**
 * PRO-BADGES-04 — View own badges on profile.
 *
 * Awards alice a badge, logs in as alice, visits her profile,
 * and asserts the badges section shows the awarded badge.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../../helpers/ease-metrics' );
const { autoLogin } = require( '../../../helpers/auto-login' );

test.describe( 'PRO-BADGES-04 — View own badges on profile', () => {

	let badgeId;

	test.beforeEach( () => {
		const badge = proJourney( [ 'badges', 'create', '--name=Helpful Member', '--tier=bronze' ] );
		badgeId = badge.data?.id;

		// Award to alice.
		if ( badgeId ) {
			proJourney( [ 'badges', 'award', `--badge=${ badgeId }`, '--user=3' ] );
		}
	} );

	test.afterEach( () => {
		if ( badgeId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_user_badges WHERE badge_id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
			try { dbWrite( `DELETE FROM wp_jt_pro_badges WHERE id = ${ badgeId }` ); } catch ( e ) { /* ignore */ }
		}
	} );

	test.fixme( 'alice sees her badges on profile', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, 'alice', '/community/u/alice/' );
		metrics.start();

		// Badges section should be visible.
		const badgesSection = page.locator( '.jt-profile-badges, .jt-user-badges' );
		await expect( badgesSection ).toBeVisible( { timeout: 5000 } );

		// The awarded badge should appear.
		const badge = badgesSection.locator( '.jt-badge-item, .jt-badge' ).filter( { hasText: 'Helpful Member' } );
		await expect( badge ).toBeVisible( { timeout: 5000 } );

		metrics.assertClickCount( { lessThanOrEqual: 0 } );
		metrics.assertTimeToGoal( { lessThanSeconds: 5 } );
		metrics.assertErrorCount( 0 );
	} );
} );
