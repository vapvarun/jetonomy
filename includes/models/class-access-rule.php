<?php
/**
 * Access rule model.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;
use function Jetonomy\table;

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

		$id = static::insert( $data );

		// A rule written mid-request must be visible to the next
		// list_for_space() in the same process — the Pro course provisioner
		// creates a rule and the integration reads the list in one flow
		// (caching plan WP2.3).
		self::reset_memo();

		return $id;
	}

	/**
	 * Delete a rule and clear the per-request rule-list memo.
	 *
	 * @param int $id Rule row ID.
	 * @return bool|\WP_Error
	 */
	/**
	 * Make a restrictive rule actually bite, by privatising a public space.
	 *
	 * A public space is always readable, and Permission_Engine stops non-members
	 * on private/hidden spaces BEFORE rules are consulted. So a membership /
	 * role / capability / trust-level rule attached to a PUBLIC space silently
	 * does nothing - the owner configures a gate, sees no error, and the content
	 * stays open. That is the "configured but content still accessible" report
	 * (Basecamp 10000074550).
	 *
	 * Rather than fail the save, flip the space to Private so the rule means what
	 * the owner clearly intended, and let the caller tell them it happened.
	 *
	 * Extracted to the model in 1.9.4 because the REST path added that release
	 * would otherwise have reimplemented - or, more likely, omitted - it, and
	 * recreated the exact bug the guard exists to prevent. One implementation,
	 * two callers.
	 *
	 * @param int    $space_id  Space the rule was attached to.
	 * @param string $rule_type Rule type just created.
	 * @return bool True when the space was switched to Private.
	 */
	public static function enforce_gate_on_public_space( int $space_id, string $rule_type ): bool {
		$restrictive = array( 'membership', 'role', 'capability', 'trust_level' );
		if ( ! in_array( $rule_type, $restrictive, true ) ) {
			return false;
		}

		$space = \Jetonomy\Models\Space::find( $space_id );
		if ( ! $space || 'public' !== $space->visibility ) {
			return false;
		}

		return (bool) \Jetonomy\Models\Space::update( $space_id, array( 'visibility' => 'private' ) );
	}

	public static function delete( int $id ): bool|\WP_Error {
		$result = parent::delete( $id );
		self::reset_memo();
		return $result;
	}

	/**
	 * Per-request memo of rule lists per space.
	 *
	 * A space page resolves the rules ~5x (grants_access,
	 * unmet_membership_requirements, concealed_from_viewer — each from
	 * several call sites), and every pass re-fans-out to the membership
	 * adapters. Only the DB list is memoized; resolve_access() itself stays
	 * live so third-party entitlements granted mid-request (a checkout
	 * completing) are never frozen (caching plan WP2.3).
	 *
	 * @var array<int, object[]>
	 */
	private static array $memo = [];

	/**
	 * Whether the memo reset is registered with Cache::flush() (plan U1).
	 *
	 * @var bool
	 */
	private static bool $memo_registered = false;

	/**
	 * Empty the rule-list memo. Called from create()/delete() and
	 * Cache::flush().
	 */
	public static function reset_memo(): void {
		self::$memo = [];
	}

	/**
	 * List all access rules for a space, ordered by priority descending.
	 *
	 * Memoized per request — see $memo.
	 *
	 * @param int $space_id
	 * @return object[]
	 */
	public static function list_for_space( int $space_id ): array {
		if ( ! self::$memo_registered ) {
			self::$memo_registered = true;
			\Jetonomy\Cache::register_memo_reset(
				static function (): void {
					self::$memo = [];
				}
			);
		}

		if ( ! array_key_exists( $space_id, self::$memo ) ) {
			self::$memo[ $space_id ] = static::db()->get_results(
				static::db()->prepare(
					'SELECT * FROM ' . static::table() . ' WHERE space_id = %d ORDER BY priority DESC',
					$space_id
				)
			) ?: [];
		}

		return self::$memo[ $space_id ];
	}

	/**
	 * Space IDs gated by one membership level.
	 *
	 * The inverse of list_for_space(): given "lrn_course_42", which spaces does
	 * holding it open? Every consumer that wants to link FROM the product TO the
	 * community needs this - a course page asking "is there a discussion for
	 * me?", a space page asking "which course is this attached to?", the
	 * auto-create path asking "does one already exist?".
	 *
	 * It also replaces thirteen hand-written copies of this query living inside
	 * the adapters, which is a plain violation of the models-only rule and drifts
	 * the moment one of them learns something the others do not.
	 *
	 * Reads `(rule_type, rule_value)`, which is indexed - the lookup runs on
	 * course and account pageviews, so an unindexed scan here is a scan on every
	 * request a logged-in student makes.
	 *
	 * @param string $rule_value Level identifier, e.g. 'lrn_course_42'.
	 * @param string $rule_type  Rule type. Defaults to the membership adapters' type.
	 * @return int[] Space IDs, ascending, unique.
	 */
	public static function spaces_for_level( string $rule_value, string $rule_type = 'membership' ): array {
		if ( '' === $rule_value ) {
			return [];
		}

		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT DISTINCT space_id FROM ' . static::table() . ' WHERE rule_type = %s AND rule_value = %s ORDER BY space_id ASC',
				$rule_type,
				$rule_value
			)
		);

		return array_map( 'intval', $ids ?: [] );
	}

	/**
	 * Spaces a user belongs to that some product's entitlement unlocked.
	 *
	 * Powers the "your discussions" list. Deliberately NOT "every space the user
	 * is in" - that is the community's own My Spaces, and duplicating it adds
	 * nothing. What earns a place in the product's account area is the set the
	 * user could not have found on their own: a hidden space has no directory
	 * entry, no search result and no URL they were ever shown, so without a list
	 * like this their only route back is a link somebody sent them once.
	 *
	 * Matches by level PREFIX so one call covers everything a single product
	 * grants - courses, plans, teams, departments - rather than one query per
	 * entitlement type. Membership is required, so a rule the user does not
	 * satisfy contributes nothing.
	 *
	 * @param int    $user_id   Member.
	 * @param string $prefix    Level prefix, e.g. 'lrn_'. Must be non-empty.
	 * @param string $rule_type Rule type. Defaults to the membership adapters' type.
	 * @return object[] Space rows, each with the `rule_value` that granted it.
	 */
	public static function member_spaces_for_level_prefix( int $user_id, string $prefix, string $rule_type = 'membership' ): array {
		if ( $user_id <= 0 || '' === $prefix ) {
			return [];
		}

		$spaces  = table( 'spaces' );
		$members = table( 'space_members' );

		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT s.*, r.rule_value
				 FROM ' . $members . ' m
				 INNER JOIN ' . $spaces . ' s ON s.id = m.space_id
				 INNER JOIN ' . static::table() . ' r ON r.space_id = s.id
				 WHERE m.user_id = %d
				   AND r.rule_type = %s
				   AND r.rule_value LIKE %s
				   AND s.status = %s
				 GROUP BY s.id
				 ORDER BY s.title ASC',
				$user_id,
				$rule_type,
				static::db()->esc_like( $prefix ) . '%',
				'active'
			)
		);

		return $rows ?: [];
	}

	/**
	 * Every ACTIVE space gated on a rule whose level starts with $prefix,
	 * regardless of roster membership — the access-based companion to
	 * {@see member_spaces_for_level_prefix()}.
	 *
	 * Access is derived from the rule at read time (a learner enrolled before
	 * the space existed has no roster row but IS admitted), so a caller that
	 * wants "the course spaces this viewer can reach" must enumerate by the rule
	 * and test grants_access() per row — not filter by the roster, which drops
	 * the pre-enrolled. Returns s.* plus r.rule_value (the level).
	 *
	 * @param string $prefix    Level-value prefix, e.g. 'lrn_course_'.
	 * @param string $rule_type Rule type (default 'membership').
	 * @return object[] Space rows, each carrying rule_value.
	 */
	public static function spaces_with_level_prefix( string $prefix, string $rule_type = 'membership' ): array {
		if ( '' === $prefix ) {
			return [];
		}

		$spaces = table( 'spaces' );

		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT s.*, r.rule_value
				 FROM ' . static::table() . ' r
				 INNER JOIN ' . $spaces . ' s ON s.id = r.space_id
				 WHERE r.rule_type = %s
				   AND r.rule_value LIKE %s
				   AND s.status = %s
				 GROUP BY s.id
				 ORDER BY s.title ASC',
				$rule_type,
				static::db()->esc_like( $prefix ) . '%',
				'active'
			)
		);

		return $rows ?: [];
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
	 * someone is on the roster. Before 1.9.0 the two were conflated: private and
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
