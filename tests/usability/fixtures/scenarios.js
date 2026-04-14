// @ts-check
const { wp } = require( '../helpers/wp-cli' );

/**
 * Fixture seeders — thin wrappers around `wp jetonomy scenario run X`.
 *
 * The C9 scenario runner shipped in the CLI module provides 5 bundled
 * scenarios that create realistic fixtures (category + space + users +
 * content) and return their IDs. Usability tests call these wrappers
 * instead of rolling their own fixture SQL so the DB state always matches
 * what a real user would see.
 *
 * Each wrapper runs the scenario, parses the fixtures from the output, and
 * returns an object the test can use to assert against later. All scenarios
 * ship with a cleanup path that tests should call in test.afterEach().
 */

/**
 * Run a scenario by slug and return the parsed fixtures map.
 *
 * @param {string} slug
 * @returns {object}
 */
function runScenario( slug ) {
	const output = wp(
		[ 'jetonomy', 'scenario', 'run', slug, '--format=json' ],
		{ json: true }
	);
	if ( ! output.success ) {
		throw new Error( `Scenario "${ slug }" failed: ${ JSON.stringify( output.errors ) }` );
	}
	return output.fixtures || {};
}

/**
 * Tear down a scenario's fixtures. The scenario runner accepts --cleanup.
 *
 * @param {string} slug
 * @param {object} _fixtures - reserved for forward compatibility; the
 *        scenario's own cleanup() already knows which ids to reverse.
 */
function cleanupScenario( slug, _fixtures ) {
	wp( [ 'jetonomy', 'scenario', 'run', slug, '--cleanup' ] );
}

const scenarios = {
	spaceWithPendingJoinRequest() {
		return runScenario( 'space-with-pending-join-request' );
	},
	postWithFlagsForModeration() {
		return runScenario( 'post-with-flags-for-moderation' );
	},
	multiUserVotingThread() {
		return runScenario( 'multi-user-voting-thread' );
	},
	fullMembershipApprovalFlow() {
		return runScenario( 'full-membership-approval-flow' );
	},
	notificationDeliverySweep() {
		return runScenario( 'notification-delivery-sweep' );
	},
};

module.exports = { runScenario, cleanupScenario, scenarios };
