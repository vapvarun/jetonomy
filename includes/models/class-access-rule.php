<?php
namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

class AccessRule extends Model {

	protected static function table_name(): string {
		return 'access_rules';
	}

	/**
	 * Create a new access rule.
	 *
	 * Automatically sets created_at if absent.
	 *
	 * @param array $data Column data (space_id, rule_type, rule_value, grants, priority, etc.).
	 * @return int Inserted row ID.
	 */
	public static function create( array $data ): int {
		$data = array_merge(
			[
				'created_at' => now(),
			],
			$data
		);

		return static::insert( $data );
	}

	/**
	 * List all access rules for a space, ordered by priority descending.
	 *
	 * @param int $space_id
	 * @return object[]
	 */
	public static function list_for_space( int $space_id ): array {
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE space_id = %d ORDER BY priority DESC',
				$space_id
			)
		) ?: [];
	}

	/**
	 * Evaluate access rules for a user in a space.
	 *
	 * Iterates rules in priority order (highest first) and returns the first
	 * rule whose conditions match the user, as an array containing the rule's
	 * decoded grants and the space_role (if set).
	 *
	 * Rule types evaluated:
	 *   - 'everyone'    - always matches
	 *   - 'logged_in'   - matches if $user_id > 0
	 *   - 'role'        - matches if the WP user has the given WP role
	 *   - 'capability'  - matches if user_can( $user_id, $rule_value )
	 *   - 'trust_level' - matches if the user's trust_level >= (int) $rule_value
	 *   - 'membership'  - matches if any active membership adapter confirms the user has the level
	 *
	 * @param int $user_id WP user ID (0 = guest).
	 * @param int $space_id
	 * @return array|null Matched rule's resolved data, or null if no rule matched.
	 */
	public static function resolve_access( int $user_id, int $space_id ): ?array {
		$rules = static::list_for_space( $space_id );

		if ( empty( $rules ) ) {
			return null;
		}

		$wp_user = $user_id > 0 ? get_userdata( $user_id ) : null;

		foreach ( $rules as $rule ) {
			$matched = false;

			switch ( $rule->rule_type ) {
				case 'everyone':
					$matched = true;
					break;

				case 'logged_in':
					$matched = $user_id > 0;
					break;

				case 'role':
					if ( $wp_user && in_array( $rule->rule_value, (array) $wp_user->roles, true ) ) {
						$matched = true;
					}
					break;

				case 'capability':
					if ( $user_id > 0 && user_can( $user_id, $rule->rule_value ) ) {
						$matched = true;
					}
					break;

				case 'trust_level':
					if ( $user_id > 0 ) {
						$profile = UserProfile::find_by_user( $user_id );
						if ( $profile && isset( $profile->trust_level ) && (int) $profile->trust_level >= (int) $rule->rule_value ) {
							$matched = true;
						}
					}
					break;

				case 'membership':
					$adapters = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
					$matched = false;
					foreach ( $adapters as $adapter ) {
						if ( $adapter->is_active() && $adapter->user_has_level( $user_id, $rule->rule_value ) ) {
							$matched = true;
							break;
						}
					}
					if ( ! $matched ) continue 2;
					break;
			}

			if ( $matched ) {
				return [
					'grants'     => $rule->grants,     // 'read', 'participate', or 'full'
					'space_role' => $rule->space_role,
					'rule_id'    => (int) $rule->id,
					'rule_type'  => $rule->rule_type,
				];
			}
		}

		return null;
	}
}
