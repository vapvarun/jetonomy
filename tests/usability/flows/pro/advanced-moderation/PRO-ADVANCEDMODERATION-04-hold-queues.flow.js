// @ts-check
/**
 * PRO-ADVANCEDMODERATION-04 — Hold action queues for moderation.
 *
 * Seeds a hold rule, creates content that triggers it, verifies
 * the post appears in the moderation queue.
 */

const { test, expect } = require( '@playwright/test' );
const { proJourney, journey, dbQuery, dbWrite } = require( '../../../helpers/wp-cli' );
const { assertDbRowExists } = require( '../../../helpers/data-flow' );

test.describe( 'PRO-ADVANCEDMODERATION-04 — Hold action queues for moderation', () => {

	let ruleId;
	let spaceId;
	let postId;

	test.beforeEach( () => {
		const status = proJourney( [ 'extension', 'status', 'advanced-moderation' ] );
		if ( ! status.success ) {
			proJourney( [ 'extension', 'enable', 'advanced-moderation' ] );
		}

		const rule = proJourney( [
			'advanced-moderation', 'create',
			'--type=word_filter',
			'--pattern=hold-me-word',
			'--action=hold',
			'--enabled=1',
		] );
		ruleId = rule.data?.id;

		const spaces = dbQuery( 'SELECT id FROM wp_jt_spaces LIMIT 1' );
		spaceId = spaces[ 0 ];
	} );

	test.afterEach( () => {
		if ( ruleId ) {
			try { dbWrite( `DELETE FROM wp_jt_pro_mod_rules WHERE id = ${ ruleId }` ); } catch ( e ) { /* */ }
		}
		if ( postId ) {
			try { journey( [ 'post', 'delete', String( postId ) ] ); } catch ( e ) { /* */ }
		}
	} );

	test( 'held post appears in moderation queue', () => {
		const post = journey( [
			'post', 'create',
			`--space=${ spaceId }`,
			'--author=1',
			'--title=Hold me test hold-me-word',
			'--content=Content with hold-me-word for queue test',
		] );
		postId = post.data?.id;

		// Post should be held.
		const statuses = dbQuery(
			`SELECT status FROM wp_jt_posts WHERE id = ${ postId }`
		);
		expect( [ 'held', 'pending_review', 'pending' ] ).toContain( statuses[ 0 ] );
	} );
} );
