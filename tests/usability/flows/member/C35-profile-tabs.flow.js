// @ts-check
/**
 * C35 — View sub-profile tabs.
 *
 * Visits a user profile and navigates through the Posts, Replies,
 * and Votes tabs, asserting each tab content renders correctly.
 */

const { test, expect } = require( '@playwright/test' );
const { EaseMetrics } = require( '../../helpers/ease-metrics' );
const { autoLogin } = require( '../../helpers/auto-login' );

test.describe( 'C35 — View sub-profile tabs', () => {

	const testUserLogin = 'admin';

	test( 'Posts tab renders on default profile view', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

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

		// Posts content area or empty state should render.
		const postsContent = page.locator( '.jt-topics, .jt-empty-compact' );
		await expect( postsContent ).toBeVisible( { timeout: 5000 } );

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'Replies tab renders tab content', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

		// Navigate directly to the replies tab.
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

		metrics.assertTimeToGoal( { lessThanSeconds: 10 } );
		metrics.assertErrorCount( 0 );
	} );

	test( 'Votes tab renders tab content', async ( { page } ) => {
		const metrics = new EaseMetrics( page );

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
