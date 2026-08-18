/**
 * Admin list tables must collapse cleanly on a phone.
 *
 * ONE assertion, deliberately: at 390px, no cell that is still laid out may
 * have zero width.
 *
 * Why this and not a static check. WordPress hides small-screen columns with
 * `td.column-primary ~ td` - a sibling combinator that can only reach columns
 * AFTER the primary cell. A column before the primary stays display:table-cell,
 * table-layout:fixed squeezes it to 0, and its text spills one letter per line.
 * That is how the Revisions screen shipped rendering "T Y P E" down the page
 * (Basecamp 10212987797).
 *
 * A grep cannot see it: the markup comes from WP_List_Table::display(), and the
 * width is decided by core CSS at layout time. Asserting "the primary column is
 * first" would catch that one cause, but the same symptom has several - a stray
 * width, a nowrap, a column added after the primary override. This tests the
 * outcome instead of a proxy for it.
 *
 * Scope note: the previous browser-level suite was retired (see CLAUDE.md)
 * because it drifted and found no product bugs. This is intentionally NOT a
 * suite - one file, one assertion, screens as data, no fixtures or page
 * objects. If it ever grows a helper directory, it has started repeating that
 * mistake.
 *
 * Run: npm run test:admin-tables
 * Requires a running site and the dev auto-login mu-plugin. Skips rather than
 * fails when either is missing, so it can never become a flaky blocker.
 */

const { test, expect } = require( '@playwright/test' );

/** Admin screens that render a list table. */
const SCREENS = [
	'jetonomy-categories',
	'jetonomy-tags',
	'jetonomy-spaces',
	'jetonomy-content',
	'jetonomy-moderation',
	'jetonomy-activity',
	'jetonomy-revisions',
	'jetonomy-users',
];

const BASE = process.env.JETONOMY_BASE_URL || 'http://forums.local';

test.describe( 'admin list tables at 390px', () => {
	test.use( { viewport: { width: 390, height: 844 } } );

	test.beforeAll( async ( { request } ) => {
		const res = await request.get( BASE ).catch( () => null );
		test.skip( ! res || ! res.ok(), `site not reachable at ${ BASE }` );
	} );

	for ( const slug of SCREENS ) {
		test( `${ slug } has no zero-width visible cells`, async ( { page } ) => {
			await page.goto( `${ BASE }/wp-admin/admin.php?page=${ slug }&autologin=1` );

			// Auto-login is a dev mu-plugin. If it is absent we land on wp-login
			// and would otherwise report a false pass on an empty page.
			test.skip(
				page.url().includes( 'wp-login.php' ),
				'not logged in - dev auto-login mu-plugin missing'
			);

			const table = page.locator( 'table.wp-list-table' ).first();
			test.skip( ( await table.count() ) === 0, 'screen renders no list table' );

			const offenders = await page.evaluate( () => {
				const out = [];
				document
					.querySelectorAll( 'table.wp-list-table tbody tr' )
					.forEach( ( row ) => {
						row.querySelectorAll( 'th, td' ).forEach( ( cell ) => {
							// A cell core has hidden is fine. A cell still in the
							// layout with no width is the bug: its content has
							// nowhere to go and wraps one character per line.
							if ( getComputedStyle( cell ).display === 'none' ) {
								return;
							}
							if ( cell.getBoundingClientRect().width > 0 ) {
								return;
							}
							out.push( {
								cls: cell.className || '(no class)',
								text: ( cell.textContent || '' ).trim().slice( 0, 40 ),
							} );
						} );
					} );
				// Same offending column repeats per row; report it once.
				return [ ...new Map( out.map( ( o ) => [ o.cls, o ] ) ).values() ];
			} );

			expect(
				offenders,
				`laid-out cells with zero width on ${ slug }. A column before the ` +
					'primary cannot be hidden by core\'s `td.column-primary ~ td` rule - ' +
					'put the primary column first in get_columns().'
			).toEqual( [] );

			// The page itself must not scroll sideways either; a table that
			// overflows its container is the other half of the same problem.
			const overflows = await page.evaluate(
				() =>
					document.documentElement.scrollWidth >
					document.documentElement.clientWidth
			);
			expect( overflows, `${ slug } scrolls horizontally at 390px` ).toBe( false );
		} );
	}
} );
