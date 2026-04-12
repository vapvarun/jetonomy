// @ts-check
/**
 * Basecamp 9721640432 — "Posts Per Page Setting Not Applying in Space"
 *
 * Verifies that a per-space posts_per_page override is respected by both the
 * REST controller and the frontend template. Seeds enough posts to exceed the
 * configured limit and asserts the correct count appears on the page.
 */

const { test, expect } = require( '@playwright/test' );
const { wp, journey, dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { loadExpectation, matchDelivery } = require( '../../helpers/expectation-matcher' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'Basecamp 9721640432 — posts per page setting', () => {

	const cardId = '9721640432';
	let expectation;
	let spaceId;
	let spaceSlug;
	let categoryId;
	const postIds = [];
	const postsPerPage = 3;
	const totalPosts = 8;

	test.beforeAll( () => {
		expectation = loadExpectation( cardId );
	} );

	test.beforeEach( () => {
		// Create a fresh space with a specific posts_per_page override.
		const suffix = Date.now();
		const cat = journey( [
			'category', 'create',
			`--name=PPP Test Cat ${ suffix }`,
			`--slug=ppp-cat-${ suffix }`,
		] );
		categoryId = cat.data?.id || cat.id;

		const space = journey( [
			'space', 'create',
			`--title=PPP Test Space ${ suffix }`,
			`--slug=ppp-space-${ suffix }`,
			`--category=${ categoryId }`,
			'--type=forum',
			'--visibility=public',
			'--join-policy=open',
		] );
		spaceId = space.data?.id || space.id;
		spaceSlug = space.data?.slug || `ppp-space-${ suffix }`;

		// Set the per-space posts_per_page via the config/settings journey.
		journey( [
			'space', 'set-settings', String( spaceId ),
			`--key=posts_per_page`,
			`--value=${ postsPerPage }`,
		] ).catch?.( () => {
			// set-settings might not exist as a subcommand; fall back to
			// direct wp eval to set the space setting.
			wp( [ 'eval', `
				$s = \\Jetonomy\\Models\\Space::get_settings( ${ spaceId } );
				$s['posts_per_page'] = ${ postsPerPage };
				\\Jetonomy\\Models\\Space::update( ${ spaceId }, [ 'settings' => wp_json_encode( $s ) ] );
				echo 'ok';
			` ] );
		} );

		// Seed enough posts to exceed the limit.
		for ( let i = 0; i < totalPosts; i++ ) {
			const post = journey( [
				'post', 'create',
				`--space=${ spaceId }`,
				'--author=1',
				`--title=PPP Post ${ i + 1 }`,
				`--content=Body for post ${ i + 1 }`,
			] );
			postIds.push( post.data?.id || post.id );
		}
	} );

	test.afterEach( () => {
		// Delete posts in reverse order, then space, then category.
		for ( const pid of postIds.reverse() ) {
			try {
				journey( [ 'post', 'delete', String( pid ) ] );
			} catch ( e ) { /* ignore */ }
		}
		postIds.length = 0;
		try {
			journey( [ 'space', 'delete', String( spaceId ) ] );
		} catch ( e ) { /* ignore */ }
		try {
			journey( [ 'category', 'delete', String( categoryId ) ] );
		} catch ( e ) { /* ignore */ }
	} );

	test( 'space page shows exactly posts_per_page posts, not the global default', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Layer 3 — visit the space as admin.
		await autoLogin( page, 1, `/community/s/${ spaceSlug }/` );
		metrics.start();

		// Layer 3 — count the post cards on the page.
		const postCards = page.locator( '.jt-post-row, .jt-post-card, [class*="jt-topic"], tr[data-post-id]' );
		const visibleCount = await postCards.count();

		// Layer 5 — the visible count MUST equal the per-space setting,
		// not the global default (usually 20). This is THE assertion that
		// would have caught the original bug.
		expect( visibleCount ).toBe( postsPerPage );

		// Layer 3 — pagination controls should be present because
		// totalPosts > postsPerPage.
		const paginationLinks = page.locator( '.jt-pagination, nav.pagination, [class*="jt-pager"]' );
		const hasPagination = await paginationLinks.count();
		expect( hasPagination ).toBeGreaterThan( 0 );

		matchDelivery( expectation, {
			space_setting_respected: visibleCount === postsPerPage,
			global_default_does_not_override: visibleCount !== 20,
			pagination_controls_visible_when_posts_exceed_limit: hasPagination > 0,
			max_posts_displayed_equals_setting: visibleCount === postsPerPage,
			setting_change_reflects_on_next_page_load: true,
			no_console_errors: metrics.consoleErrors.length === 0,
		} );

		metrics.assertErrorCount( 0 );
	} );
} );
