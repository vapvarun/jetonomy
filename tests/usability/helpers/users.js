// @ts-check
/**
 * Thin wrapper around wp-cli helpers for user/space ID resolution.
 * Demo-seeded users and spaces don't have stable IDs — always resolve by login/slug.
 */
const { getUserId, getSpaceId } = require( './wp-cli' );

module.exports = {
	id: getUserId,
	spaceId: getSpaceId,
};
