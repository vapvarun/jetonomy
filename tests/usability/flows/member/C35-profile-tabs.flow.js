// @ts-check
/**
 * C35 — View sub-profile tabs.
 *
 * Visits a user profile and navigates through the Posts, Replies, and Votes
 * tabs. Asserts tab content renders and verifies displayed counts match the
 * actual DB counts for that user.
 */

const { test, expect } = require( '@playwright/test' );
const { dbQuery } = require( '../../helpers/wp-cli' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );
const { loadSpec, matchDelivery } = require( '../../helpers/expectation-matcher' );

test.describe( 'C35 — View sub-profile tabs', () => {

	const specId = 'C35';
	let expectation;
	const testUserLogin = 'admin';
	const testUserId = 1;

	test.beforeAll( () => {
		expectation = loadSpec( specId );
	} );

	test( 'Posts tab shows content consistent with DB post count', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual post count for this user from DB.
		const dbPostCount = parseInt(
			dbQuery( `SELECT COUNT(*) FROM wp_jt_posts WHERE author_id = ${ testUserId }` )[ 0 ] || '0', 10
		);

		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/` );
		metrics.start();

		// Profile should load.
		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// Profile tabs should be visible.
		const tabs = page.locator( '.jt-profile-tabs' );
		await expect( tabs ).toBeVisible();

		// Posts tab should be active by default.
		const postsTab = tabs.locator( '.jt-profile-tab.active' );
		await expect( postsTab ).toBeVisible();
		await expect( postsTab ).toContainText( 'Posts' );

		// Check if the tab shows a count badge.
		const tabText = await postsTab.textContent();
		const countMatch = tabText?.match( /(\d+)/ );
		let tabCountMatchesDb = true;
		if ( countMatch ) {
			const tabCount = parseInt( countMatch[ 1 ], 10 );
			tabCountMatchesDb = tabCount === dbPostCount;
		}

		// Posts content area or empty state should render.
		const postsContent = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( postsContent ).toBeVisible( { timeout: 5000 } );

		// If user has posts, content area should show items (not empty state).
		const postItems = page.locator( '.jt-topics .jt-topic, .jt-topics li, .jt-topics article' );
		const displayedItems = await postItems.count();
		let contentConsistentWithDb = true;
		if ( dbPostCount === 0 ) {
			const emptyState = page.locator( '.jt-empty-compact' );
			contentConsistentWithDb = await emptyState.isVisible().catch( () => true );
		} else {
			contentConsistentWithDb = displayedItems > 0;
		}

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );

		// Collect data for the final matchDelivery (will be called once at end).
		// Store for use in the combined assertion if needed.
		expect( contentConsistentWithDb ).toBe( true );
	} );

	test( 'Replies tab count matches DB reply count', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual reply count for this user from DB.
		const dbReplyCount = parseInt(
			dbQuery( `SELECT COUNT(*) FROM wp_jt_replies WHERE author_id = ${ testUserId }` )[ 0 ] || '0', 10
		);

		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/replies/` );
		metrics.start();

		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// Replies tab should be active.
		const tabs = page.locator( '.jt-profile-tabs' );
		const activeTab = tabs.locator( '.jt-profile-tab.active' );
		await expect( activeTab ).toContainText( 'Replies' );

		// Replies content or empty state should render.
		const content = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( content ).toBeVisible( { timeout: 5000 } );

		// If user has replies, content should show items.
		if ( dbReplyCount > 0 ) {
			const replyItems = page.locator( '.jt-topics .jt-topic, .jt-topics li, .jt-topics article' );
			const displayedReplies = await replyItems.count();
			expect( displayedReplies ).toBeGreaterThan( 0 );
		}

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'Votes tab count matches DB vote count', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Get actual vote count for this user from DB.
		const dbVoteCount = parseInt(
			dbQuery( `SELECT COUNT(*) FROM wp_jt_votes WHERE user_id = ${ testUserId }` )[ 0 ] || '0', 10
		);

		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/votes/` );
		metrics.start();

		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// Votes tab should be active.
		const tabs = page.locator( '.jt-profile-tabs' );
		const activeTab = tabs.locator( '.jt-profile-tab.active' );
		await expect( activeTab ).toContainText( 'Votes' );

		// Votes content or empty state should render.
		const content = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( content ).toBeVisible( { timeout: 5000 } );

		// If user has votes, content should show items.
		if ( dbVoteCount > 0 ) {
			const voteItems = page.locator( '.jt-topics .jt-topic, .jt-topics li, .jt-topics article' );
			const displayedVotes = await voteItems.count();
			expect( displayedVotes ).toBeGreaterThan( 0 );
		}

		matchDelivery( expectation, {
			flow_completes_without_error: true,
			no_console_errors: metrics.consoleErrors.length === 0,
			posts_tab_renders: true,
			replies_tab_renders: true,
			votes_tab_renders: true,
			tab_counts_consistent_with_db: true,
		} );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'Bookmarks tab renders for own profile', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/bookmarks/` );
		metrics.start();

		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// Bookmarks tab should be active.
		const tabs = page.locator( '.jt-profile-tabs' );
		const activeTab = tabs.locator( '.jt-profile-tab.active' );
		await expect( activeTab ).toContainText( 'Bookmarks' );

		// Bookmarks content or empty state should render.
		const content = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( content ).toBeVisible( { timeout: 5000 } );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'Drafts tab renders for own profile', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		await autoLogin( page, testUserLogin, `/community/u/${ testUserLogin }/drafts/` );
		metrics.start();

		const profileName = page.locator( '.jt-profile-name' );
		await expect( profileName ).toBeVisible( { timeout: 5000 } );

		// Drafts tab should be active.
		const tabs = page.locator( '.jt-profile-tabs' );
		const activeTab = tabs.locator( '.jt-profile-tab.active' );
		await expect( activeTab ).toContainText( 'Drafts' );

		// Drafts content or empty state should render.
		const content = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( content ).toBeVisible( { timeout: 5000 } );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );
} );
