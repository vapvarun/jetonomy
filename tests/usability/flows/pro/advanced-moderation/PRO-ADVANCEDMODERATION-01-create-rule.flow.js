// @ts-check
/**
 * PRO-ADVANCEDMODERATION-01 — Admin create moderation rule.
 *
 * Uses proJourney to create a word-filter moderation rule via CLI,
 * then verifies it exists in the wp_jt_pro_mod_rules table.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-ADVANCEDMODERATION-01 — Admin create moderation rule', () => {

	let ruleId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try {
				dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` );
			} catch ( e ) { /* cleanup best effort */ }
		}
	} );

	test( 'create a word-filter rule via CLI and verify DB row', () => {
		const result = proJourney( [
			'advanced-moderation', 'create',
			'--type=word_filter',
			'--pattern=spam-keyword',
			'--action=hold',
			'--enabled=1',
		] );

		expect( result.success ).toBe( true );
		ruleId = result.data?.id;
		expect( ruleId ).toBeTruthy();

		// Verify row in DB.
		assertDbRowExists( 'wp_jt_pro_mod_rules', `id = ${ ruleId }` );

		// Verify the rule can be read back.
		const readback = proJourney( [ 'advanced-moderation', 'get', String( ruleId ) ] );
		expect( readback.success ).toBe( true );
		expect( readback.data?.type ).toBe( 'word_filter' );
		expect( readback.data?.action ).toBe( 'hold' );
	} );
} );
