// @ts-check
/**
 * SA09 — Configure roadmap (P2).
 *
 * If an ideas-type space with roadmap exists, visit its admin page and
 * verify the roadmap tab renders. Otherwise mark as FIXME.
 */

const { test, expect } = require( '@playwright/test' );
const { journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'SA09 — Configure roadmap columns', () => {

	let ideasSpaceId;

	test.beforeAll( () => {
		// Find an ideas-type space.
		const rows = dbQuery( "SELECT id FROM wp_jt_spaces WHERE type = 'ideas' LIMIT 1" );
		ideasSpaceId = rows.length > 0 ? parseInt( rows[ 0 ], 10 ) : null;
	} );

	test( 'roadmap tab renders on ideas space edit page', async ( { page } ) => {
		if ( ! ideasSpaceId ) {
			// FIXME: No ideas-type space exists to test roadmap configuration.
			test.skip( true, 'No ideas-type space found — create one to test roadmap config' );
			return;
		}

		const metrics = new EaseMetrics( page );

		await autoLogin( page, 1, `/wp-admin/admin.php?page=jetonomy-spaces&action=edit&space_id=${ ideasSpaceId }&tab=roadmap` );
		metrics.start();

		// Assert roadmap tab or section renders.
		const roadmapSection = page.locator(
			'a.nav-tab:has-text("Roadmap"), [data-tab="roadmap"], .jetonomy-roadmap-config, h2:has-text("Roadmap")'
		);
		await expect( roadmapSection.first() ).toBeVisible( { timeout: 5000 } );

		// Assert column configuration UI is present.
		const columnUi = page.locator(
			'.jetonomy-roadmap-columns, .roadmap-column, input[name*="column"], table'
		);
		await expect( columnUi.first() ).toBeVisible( { timeout: 3000 } );

		metrics.assertErrorCount( 0 );
	} );
} );
