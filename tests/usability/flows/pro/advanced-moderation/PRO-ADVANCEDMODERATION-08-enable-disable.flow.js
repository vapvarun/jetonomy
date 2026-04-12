// @ts-check
/**
 * PRO-ADVANCEDMODERATION-08 — Enable/disable rule.
 *
 * Creates a rule, disables it via CLI, verifies it no longer fires,
 * then re-enables it and verifies it fires again.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );

test.describe( 'PRO-ADVANCEDMODERATION-08 — Enable/disable rule', () => {

	let ruleId;
	let spaceId;
	let postIds = [];

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];

		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--type=word_filter',
			'--pattern=toggle-test-word',
			'--action=hold',
			'--enabled=1',
		] );
		ruleId = rule.data?.id;
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` ); } catch ( e ) { /* */ }
		}
		for ( const pid of postIds ) {
			try { journey( [ 'post', 'delete', String( pid ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'disabled rule does not fire, re-enabled rule fires', () => {
		// Disable the rule.
		proJourney( [ 'advanced-moderation', 'disable', String( ruleId ) ] );

		// Post with the trigger word — should be published (rule disabled).
		const p1 = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Disabled toggle-test-word',
			'--content=toggle-test-word body',
		] );
		postIds.push( p1.data?.id );
		const s1 = dbQuery( `SELECT status FROM wp_jt_posts WHERE id = ${ p1.data.id }` );
		expect( s1[ 0 ] ).toBe( 'published' );

		// Re-enable the rule.
		proJourney( [ 'advanced-moderation', 'enable', String( ruleId ) ] );

		// Post again — should now be held.
		const p2 = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Enabled toggle-test-word again',
			'--content=toggle-test-word body again',
		] );
		postIds.push( p2.data?.id );
		const s2 = dbQuery( `SELECT status FROM wp_jt_posts WHERE id = ${ p2.data.id }` );
		expect( [ 'held', 'pending_review', 'pending' ] ).toContain( s2[ 0 ] );
	} );
} );
