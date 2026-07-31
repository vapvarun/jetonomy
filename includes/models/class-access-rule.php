<?php
/**
 * Access rule model.
 *
 * @package Jetonomy
 */

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
	/**
	 * The roster role a rule's grants can justify.
	 *
	 * A rule stores BOTH what someone may do (`grants`) and what role they are
	 * recorded as when "Sync Members" materialises them (`space_role`). Those
	 * were independent, and the combination was not merely confusing - it was a
	 * privilege escalation. A rule labelled "Read - view posts and replies,
	 * cannot take part" with space_role=admin gave every matched person
	 * `delete_others_posts` and `moderate` the moment an owner pressed Sync
	 * Members, because Permission_Engine's space-moderator bypass reads the
	 * ROSTER role and never looks at the rule's grants.
	 *
	 * Capping here closes that on every site, including the rules already
	 * stored on installs that upgrade - the stored value is left alone, but it
	 * can no longer buy more power than the rule advertises.
	 *
	 * A `membership` rule is capped harder still: never above `member`, whatever
	 * its grants say. A membership rule means "everyone holding this plan", and
	 * nobody should become a space moderator by buying something - moderation is
	 * an appointment, made per person on the Members tab. Role, capability and
	 * trust-level rules are deliberate administrative choices about a known
	 * group, so they keep the full ladder.
	 *
	 * @param string $grants     read | participate | full.
	 * @param string $space_role Requested roster role.
	 * @param string $rule_type  Rule type; `membership` is capped at member.
	 * @return string The requested role, or the highest the rule justifies.
	 */
	public static function cap_space_role( string $grants, string $space_role, string $rule_type = '' ): string {
		$ladder  = array(
			'viewer'    => 1,
			'member'    => 2,
			'moderator' => 3,
			'admin'     => 4,
		);
		$ceiling = array(
			'read'        => 'viewer',
			'participate' => 'member',
			'full'        => 'moderator',
		);

		$max = $ceiling[ $grants ] ?? 'viewer';

		if ( 'membership' === $rule_type && $ladder[ $max ] > $ladder['member'] ) {
			$max = 'member';
		}

		$want = $ladder[ $space_role ] ?? 1;

		return $want > $ladder[ $max ] ? $max : $space_role;
	}

	/**
	 * Does any access rule admit this user to this space?
	 *
	 * The question a GATE asks, as opposed to `is_member()`, which asks whether
	 * someone is on the roster. Before 1.8.1 the two were conflated: private and
	 * hidden spaces demanded roster membership BEFORE access rules were read, so
	 * a rule could only ever upgrade an existing member's grants and could never
	 * admit anyone. A site owner who pointed a space at a membership tier got a
	 * space nobody could enter, and the only way in was the manual "Sync Members"
	 * button materialising roster rows - which then never came off when the
	 * subscription lapsed, because nothing listens for deactivation.
	 *
	 * Resolving the rule at request time instead means access follows the
	 * subscription in both directions with no roster row, no sync step and
	 * nothing to drift out of date.
	 *
	 * @param int $user_id  WP user ID (0 = guest).
	 * @param int $space_id Space ID.
	 * @return bool True when a rule grants this user access to the space.
	 */
	public static function grants_access( int $user_id, int $space_id ): bool {
		if ( $space_id <= 0 ) {
			return false;
		}
		return null !== static::resolve_access( $user_id, $space_id );
	}

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
					$matched  = false;
					foreach ( $adapters as $adapter ) {
						if ( $adapter->is_active() && $adapter->user_has_level( $user_id, $rule->rule_value ) ) {
							$matched = true;
							break;
						}
					}
					if ( ! $matched ) {
						continue 2;
					}
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

	/**
	 * The membership levels this space asks for that this viewer does not hold.
	 *
	 * Powers the gate's "this needs the VIP tier, here is where to get it"
	 * state. Without it a non-subscriber hitting a tier-gated space saw the
	 * generic "this space is private" copy and a Join button that could not
	 * help them — the one thing they needed to know (which plan opens this,
	 * and where to buy it) was the one thing nothing told them.
	 *
	 * Only `membership` rules are considered: a role, capability or
	 * trust-level requirement is not something a visitor can go and purchase,
	 * so surfacing it as a call to action would be misleading.
	 *
	 * @param int $user_id  Viewer ID (0 = guest).
	 * @param int $space_id Space ID.
	 * @return array<int, array{level_id:string, type:string, label:string, url:string}>
	 */
	public static function unmet_membership_requirements( int $user_id, int $space_id ): array {
		if ( $space_id <= 0 ) {
			return array();
		}

		$unmet = array();

		foreach ( static::list_for_space( $space_id ) as $rule ) {
			if ( 'membership' !== $rule->rule_type || '' === (string) $rule->rule_value ) {
				continue;
			}

			$held = false;
			foreach ( \Jetonomy\Adapters\Adapter_Registry::get_all_membership() as $adapter ) {
				if ( $adapter->is_active() && $adapter->user_has_level( $user_id, $rule->rule_value ) ) {
					$held = true;
					break;
				}
			}
			if ( $held ) {
				continue;
			}

			$described = \Jetonomy\Adapters\Adapter_Registry::describe_membership_level( (string) $rule->rule_value );

			$unmet[ (string) $rule->rule_value ] = array(
				'level_id' => (string) $rule->rule_value,
				'type'     => $described['type'],
				'label'    => $described['value'],
				'url'      => \Jetonomy\Adapters\Adapter_Registry::membership_level_url( (string) $rule->rule_value ),
			);
		}

		return array_values( $unmet );
	}
}
