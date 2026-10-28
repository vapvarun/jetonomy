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

	/**
	 * Rule types that gate who gets in, so they only bite on a non-public space.
	 */
	public const RESTRICTIVE_TYPES = array( 'membership', 'role', 'capability', 'trust_level' );

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
			array(
				'created_at' => now(),
			),
			$data
		);
		// The roster role is derived from the grant, never chosen, for every
		// writer (REST, wp-admin, Pro provisioners): read is Viewer, participate
		// and full are Member.
		$data['grants']     = self::grants_for_type( (string) ( $data['grants'] ?? 'read' ), (string) ( $data['rule_type'] ?? '' ) );
		$data['space_role'] = self::role_for_grants( $data['grants'] );

		$id = static::insert( $data );

		// A rule written mid-request must be visible to the next
		// list_for_space() in the same process — the Pro course provisioner
		// creates a rule and the integration reads the list in one flow
		self::reset_memo();

		return $id;
	}

	/**
	 * Make a restrictive rule actually bite, by privatising a public space.
	 *
	 * A public space is always readable, and Permission_Engine stops non-members
	 * on private/hidden spaces BEFORE rules are consulted. So a membership /
	 * role / capability / trust-level rule attached to a PUBLIC space silently
	 * does nothing - the owner configures a gate, sees no error, and the content
	 * stays open. That is the "configured but content still accessible" report
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
		if ( ! in_array( $rule_type, self::RESTRICTIVE_TYPES, true ) ) {
			return false;
		}

		$space = \Jetonomy\Models\Space::find( $space_id );
		if ( ! $space || 'public' !== $space->visibility ) {
			return false;
		}

		return (bool) \Jetonomy\Models\Space::update( $space_id, array( 'visibility' => 'private' ) );
	}

	/**
	 * The notice both rule-create paths (wp-admin AJAX, REST) show. When the
	 * space was switched to Private it also names the join policy that now
	 * applies, since Private is never open to join.
	 *
	 * @param int  $space_id     Space the rule was attached to.
	 * @param bool $made_private What enforce_gate_on_public_space() returned.
	 * @return string
	 */
	public static function added_message( int $space_id, bool $made_private ): string {
		$space = $made_private ? Space::find( $space_id ) : null;
		if ( ! $space ) {
			return __( 'Access rule added.', 'jetonomy' );
		}
		return sprintf(
			/* translators: 1: space label, lowercase (e.g. "space"). 2: join policy label (e.g. "Requires Approval"). */
			__( 'Access rule added. This %1$s was switched to Private so the rule can restrict access, and its join policy is now %2$s. A rule cannot gate a public %1$s.', 'jetonomy' ),
			\Jetonomy\space_label( false, true ),
			\Jetonomy\space_join_policy_label( Space::effective_join_policy( $space ) )
		);
	}

	/**
	 * Whether "Full" means anything for this rule type. Full adds editing,
	 * closing and pinning only for people whose WordPress role already allows
	 * moderation, which only a role or capability rule can single out; for any
	 * other rule an ordinary member gets exactly Participate.
	 *
	 * @param string $rule_type Rule type.
	 */
	public static function offers_full( string $rule_type ): bool {
		return in_array( $rule_type, array( 'role', 'capability' ), true );
	}

	/**
	 * The grant stored for a rule type: Full on a type that cannot offer it is
	 * saved as Participate, which is what it would do anyway.
	 *
	 * @param string $grants    read | participate | full.
	 * @param string $rule_type Rule type.
	 */
	public static function grants_for_type( string $grants, string $rule_type ): string {
		return 'full' === $grants && ! self::offers_full( $rule_type ) ? 'participate' : $grants;
	}

	/**
	 * The roster role a grant records: Viewer for read, Member for participate and full.
	 *
	 * @param string $grants read | participate | full.
	 */
	public static function role_for_grants( string $grants ): string {
		return self::cap_space_role( $grants, 'admin' );
	}

	/**
	 * Change what an existing rule grants, keeping its type and value.
	 *
	 * Editing in place keeps the rule's members on the roster; delete and
	 * re-add would sweep them off and make the owner press Sync again. A tier
	 * member's role needs no rewrite: SpaceMember caps it by the live grant.
	 *
	 * @param int    $id     Rule row ID.
	 * @param string $grants read | participate | full.
	 * @return bool|\WP_Error True on success.
	 */
	public static function update_grants( int $id, string $grants ): bool|\WP_Error {
		$rule = static::find( $id );
		if ( ! $rule ) {
			return new \WP_Error( 'jetonomy_rule_not_found', __( 'Access rule not found.', 'jetonomy' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $grants, array( 'read', 'participate', 'full' ), true ) ) {
			return new \WP_Error( 'jetonomy_invalid_grants', __( 'Invalid grants value.', 'jetonomy' ), array( 'status' => 400 ) );
		}
		$grants = self::grants_for_type( $grants, (string) $rule->rule_type );
		$saved  = parent::update(
			$id,
			array(
				'grants'     => $grants,
				'space_role' => self::role_for_grants( $grants ),
			)
		);
		self::reset_memo();
		return $saved ? true : new \WP_Error( 'jetonomy_rule_update_failed', __( 'Failed to update access rule.', 'jetonomy' ), array( 'status' => 500 ) );
	}

	/**
	 * Delete a rule and clear the per-request rule-list memo.
	 *
	 * @param int $id Rule row ID.
	 * @return bool|\WP_Error
	 */
	public static function delete( int $id ): bool|\WP_Error {
		$rule   = static::find( $id );
		$result = parent::delete( $id );
		self::reset_memo();
		if ( $rule && true === $result ) {
			\Jetonomy\Membership_Roster_Sync::queue_space_sweep( (int) $rule->space_id );
		}
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
	 * completing) are never frozen.
	 *
	 * @var array<int, object[]>
	 */
	private static array $memo = array();

	/**
	 * Whether the memo reset is registered with Cache::flush().
	 *
	 * @var bool
	 */
	private static bool $memo_registered = false;

	/**
	 * Empty the rule-list memo. Called from create()/delete() and
	 * Cache::flush().
	 */
	public static function reset_memo(): void {
		self::$memo = array();
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
					self::$memo = array();
				}
			);
		}

		if ( ! array_key_exists( $space_id, self::$memo ) ) {
			self::$memo[ $space_id ] = static::db()->get_results(
				static::db()->prepare(
					'SELECT * FROM ' . static::table() . ' WHERE space_id = %d ORDER BY priority DESC, id ASC',
					$space_id
				)
			) ?: array();
		}

		return self::$memo[ $space_id ];
	}

	/**
	 * Why a rule's value cannot work for its type, or null. A rule with a
	 * missing value must not save: a trust_level rule with no level read as
	 * "trust >= 0" and admitted every logged-in member to a private space.
	 *
	 * @param string $rule_type  Rule type.
	 * @param string $rule_value Submitted value (sanitized).
	 */
	public static function value_error( string $rule_type, string $rule_value ): ?\WP_Error {
		$ok = match ( $rule_type ) {
			'trust_level'               => ctype_digit( $rule_value ) && (int) $rule_value >= 1 && (int) $rule_value <= 5,
			'role'                      => '' !== $rule_value && wp_roles()->is_role( $rule_value ),
			'capability', 'membership'  => '' !== $rule_value,
			default                     => true,
		};
		if ( $ok ) {
			return null;
		}
		$message = match ( $rule_type ) {
			'trust_level' => __( 'Choose a trust level from 1 to 5.', 'jetonomy' ),
			'role'        => __( 'Choose a role that exists on this site.', 'jetonomy' ),
			default       => __( 'Enter a value for this rule.', 'jetonomy' ),
		};
		return new \WP_Error( 'jetonomy_invalid_rule_value', $message, array( 'status' => 400 ) );
	}

	/**
	 * Full rule rows for a level, not just the space ids.
	 *
	 * Callers that only need to point at the spaces should use
	 * spaces_for_level(); that answers "which spaces" and is right for them.
	 * only need to point at them. The roster sync also needs `grants` and
	 * `space_role` to work out what role to write, and fetching the ids then
	 * re-reading each space's rules to find them again would be the per-row
	 * lookup this model exists to avoid.
	 *
	 * Reads the same indexed `(rule_type, rule_value)` key.
	 *
	 * @param string $rule_value Level identifier, e.g. 'pmpro_3'.
	 * @param string $rule_type  Rule type. Defaults to the membership adapters' type.
	 * @return object[] Rule rows, ascending by space id.
	 */
	public static function rules_for_level( string $rule_value, string $rule_type = 'membership' ): array {
		if ( '' === $rule_value ) {
			return array();
		}

		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE rule_type = %s AND rule_value = %s ORDER BY space_id ASC',
				$rule_type,
				$rule_value
			)
		) ?: array();
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
			return array();
		}

		$ids = static::db()->get_col(
			static::db()->prepare(
				'SELECT DISTINCT space_id FROM ' . static::table() . ' WHERE rule_type = %s AND rule_value = %s ORDER BY space_id ASC',
				$rule_type,
				$rule_value
			)
		);

		return array_map( 'intval', $ids ?: array() );
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
			return array();
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

		return $rows ?: array();
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
			return array();
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

		return $rows ?: array();
	}

	/**
	 * The roster role a rule's grants can justify.
	 *
	 * A rule stores BOTH what someone may do (`grants`) and what role they are
	 * recorded as when "Sync Members" materialises them (`space_role`). Those
	 * were independent, which allowed a privilege escalation: a rule labelled "Read -
	 * view posts and replies, cannot take part" with space_role=admin would give every
	 * matched person `delete_others_posts` and `moderate` when an owner presses Sync
	 * Members, because Permission_Engine's space-moderator bypass reads the ROSTER role
	 * and never looks at the rule's grants.
	 *
	 * Capping here covers every site, including rules already stored on installs that
	 * upgrade - the stored value is left alone, but it cannot buy more power than the
	 * rule advertises.
	 *
	 * No rule ever records anyone above `member`, whatever its type or grants
	 * (owner decision 2026-10-17): Full is full participation, never moderation.
	 * Moderators and admins are appointed per person on the Members tab.
	 *
	 * @param string $grants     read | participate | full.
	 * @param string $space_role Requested roster role.
	 * @param string $rule_type  Rule type. Unused since every type shares one
	 *                           ladder; kept because adapters pass it.
	 * @return string The requested role, or the highest the rule justifies.
	 */
	public static function cap_space_role( string $grants, string $space_role, string $rule_type = '' ): string {
		unset( $rule_type );
		$ladder  = array(
			'viewer'    => 1,
			'member'    => 2,
			'moderator' => 3,
			'admin'     => 4,
		);
		$ceiling = array(
			'read'        => 'viewer',
			'participate' => 'member',
			'full'        => 'member',
		);

		$max  = $ceiling[ $grants ] ?? 'viewer';
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

	/**
	 * Spaces a member is admitted to by a rule alone (no roster row).
	 *
	 * Space::content_visibility_sql() models the roster only, so a list built
	 * on it hides the follows and bookmarks of a member who got in through a
	 * Role, Capability, Trust Level, Logged In or membership rule. Spaces with
	 * rules are few, so asking the engine about each stays cheap, and it keeps
	 * bans and category concealment in force.
	 *
	 * @param int $user_id WP user ID (0 = guest).
	 * @return int[] Space IDs.
	 */
	public static function admitted_space_ids( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = static::db()->get_col( 'SELECT DISTINCT space_id FROM ' . static::table() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table name, no input.
		return array_values( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'read', $id ) ) );
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

		// Among the matching rules with the highest priority, the most
		// permissive grant wins; equal grants fall to the oldest rule.
		$rank = array(
			'read'        => 1,
			'participate' => 2,
			'full'        => 3,
		);
		$best = null;

		foreach ( $rules as $rule ) {
			if ( $best && (int) $rule->priority < (int) $best->priority ) {
				break;
			}

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

			if ( $matched && ( ! $best || ( $rank[ $rule->grants ] ?? 0 ) > ( $rank[ $best->grants ] ?? 0 ) ) ) {
				$best = $rule;
			}
		}

		if ( ! $best ) {
			return null;
		}

		return array(
			'grants'     => $best->grants,     // 'read', 'participate', or 'full'
			'space_role' => $best->space_role,
			'rule_id'    => (int) $best->id,
			'rule_type'  => $best->rule_type,
		);
	}

	/**
	 * Why this rule would duplicate one the space already has, or null.
	 *
	 * Two rules on the same type and value cannot both mean something: one
	 * always outranks the other. Change the existing rule's access instead.
	 *
	 * @param int    $space_id   Space.
	 * @param string $rule_type  Rule type.
	 * @param string $rule_value Rule value (sanitized).
	 */
	public static function duplicate_error( int $space_id, string $rule_type, string $rule_value ): ?\WP_Error {
		foreach ( static::list_for_space( $space_id ) as $rule ) {
			if ( $rule->rule_type === $rule_type && (string) $rule->rule_value === $rule_value ) {
				return new \WP_Error(
					'jetonomy_duplicate_rule',
					/* translators: %s: the singular space label, lowercase (e.g. space, group). */
					sprintf( __( 'This %s already has a rule for that. Change what the existing rule grants instead.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					array( 'status' => 409 )
				);
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
	 * @return array<int, array{level_id:string, name:string, type:string, kind:string, label:string, url:string}>
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

			$unmet[ (string) $rule->rule_value ] = self::describe_requirement( (string) $rule->rule_value );
		}

		return array_values( $unmet );
	}

	/**
	 * The membership level that lets this viewer in, when a membership rule is
	 * what admits them, so the space can say "You have access through Course X".
	 *
	 * @param int $user_id  Viewer ID.
	 * @param int $space_id Space ID.
	 * @return array{level_id:string, name:string, type:string, kind:string, label:string, url:string}|null
	 */
	public static function admitting_membership( int $user_id, int $space_id ): ?array {
		$access = $user_id > 0 ? self::resolve_access( $user_id, $space_id ) : null;
		if ( ! $access || 'membership' !== $access['rule_type'] ) {
			return null;
		}
		$rule = static::find( $access['rule_id'] );
		return $rule && '' !== (string) $rule->rule_value ? self::describe_requirement( (string) $rule->rule_value ) : null;
	}

	/**
	 * One membership level as the gate and the space header show it.
	 *
	 * @param string $level_id Stored rule_value.
	 * @return array{level_id:string, name:string, type:string, kind:string, label:string, url:string}
	 */
	private static function describe_requirement( string $level_id ): array {
		$described = \Jetonomy\Adapters\Adapter_Registry::describe_membership_level( $level_id );
		$purchase  = \Jetonomy\Adapters\Adapter_Registry::membership_level_is_purchase( $level_id );
		return array(
			'level_id'  => $level_id,
			'name'      => $described['value'],
			'type'      => $described['type'],
			'kind'      => $described['kind'],
			// "Gold (Level)" is noise; "(Course)" / "(Group)" tells two ways in apart.
			'label'     => '' !== $described['kind'] && ! $purchase
				/* translators: 1: membership level name, 2: what kind of level it is (e.g. Course, Group). */
				? sprintf( __( '%1$s (%2$s)', 'jetonomy' ), $described['value'], $described['kind'] )
				: $described['value'],
			'url'       => \Jetonomy\Adapters\Adapter_Registry::membership_level_url( $level_id ),
			'purchase'  => $purchase,
			'available' => \Jetonomy\Adapters\Adapter_Registry::membership_level_available( $level_id ),
		);
	}
}
