<?php
/**
 * Keep the space roster in step with paid memberships.
 *
 * Every membership adapter already fires `jetonomy_membership_activated` and
 * `jetonomy_membership_deactivated` — BN Pro, PMPro, MemberPress, Woo, RCP,
 * LearnDash, Tutor, Sensei, MasterStudy, Learnomy, tags. Until 1.9.4 nothing
 * listened to either: measured at runtime, zero add_action on both hooks
 * across free and Pro.
 *
 * What this is NOT
 * ----------------
 * It is not an access fix. Permission_Engine has resolved membership rules
 * live since 1.9.0, so a subscriber gets in the moment their plan is active
 * and loses access the moment it lapses, with no roster row involved. That
 * behaviour is unchanged and this class must never become load-bearing for it.
 *
 * What it fixes is that a paying member was not ON the roster: absent from the
 * space Members list, missing from member_count, and invisible to role-based
 * settings that read SpaceMember::get_role() — so `who_can_post: members`
 * excluded the very people paying for that access.
 *
 * Why it could not ship before
 * ----------------------------
 * jt_space_members had no provenance, so a deactivation handler could not tell
 * a tier-granted row from someone who joined legitimately, and would
 * eventually evict the wrong people. Migration 1.9.4.2 adds `source`, and this
 * class only ever deletes rows that say 'tier'.
 *
 * The overlap case
 * ----------------
 * A member can hold two plans that grant the same space. Deleting every 'tier'
 * row for the lapsed level would throw them out of a space their OTHER, still
 * active plan pays for. So deactivation does not delete on the strength of the
 * event alone: it re-asks AccessRule::grants_access() per space and removes
 * the row only when nothing else still grants it. The event says "something
 * changed, re-check"; the rules say what is true.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\AccessRule;
use Jetonomy\Models\SpaceMember;

class Membership_Roster_Sync {

	/**
	 * Provenance written by this class, and the only value it will delete.
	 */
	private const SOURCE = 'tier';

	public static function init(): void {
		add_action( 'jetonomy_membership_activated', array( __CLASS__, 'on_activated' ), 10, 3 );
		add_action( 'jetonomy_membership_deactivated', array( __CLASS__, 'on_deactivated' ), 10, 3 );
	}

	/**
	 * Put the member on the roster of every space this level grants.
	 *
	 * @param int    $user_id  Member.
	 * @param string $level_id Level identifier the adapter reported.
	 * @param string $source   Adapter id, e.g. 'pmpro'. Unused; part of the hook contract.
	 */
	public static function on_activated( $user_id, $level_id, $source = '' ): void {
		unset( $source );

		$user_id  = (int) $user_id;
		$level_id = (string) $level_id;
		if ( $user_id <= 0 || '' === $level_id ) {
			return;
		}

		/*
		 * Activation TRUSTS the event and does not re-ask the adapter whether
		 * the member holds the level.
		 *
		 * That asymmetry is deliberate. Adapters fire this hook from inside
		 * their own status-change handler, and there is no guarantee across
		 * eleven third-party plugins that their membership row is already
		 * queryable at that moment. A re-check that answers "not a member yet"
		 * would silently drop the member off the roster with nothing logged -
		 * the failure that is hardest to notice and hardest to explain.
		 *
		 * The two directions have different worst cases, and that is what
		 * decides the rule:
		 *   activation over-adds  -> a name on a roster it should not be on.
		 *                            Cosmetic; the live gate still decides
		 *                            actual access, so nothing is unlocked.
		 *   deactivation over-removes -> somebody loses a space they joined
		 *                            themselves. Data loss.
		 * So: trust the event when adding, verify against the rules when
		 * removing. Neither mistake can ever cost a member their place.
		 */
		foreach ( AccessRule::rules_for_level( $level_id ) as $rule ) {
			$role = AccessRule::cap_space_role(
				(string) ( $rule->grants ?? 'read' ),
				(string) ( $rule->space_role ?? 'member' ),
				'membership'
			);

			// add() preserves the provenance of an existing row, so a member
			// who joined manually keeps 'manual' and stays put when the plan
			// ends. Only genuinely new rows are stamped 'tier'.
			SpaceMember::add( (int) $rule->space_id, $user_id, $role, self::SOURCE );
		}
	}

	/**
	 * Take the member off the rosters this level was paying for — and only
	 * those.
	 *
	 * @param int    $user_id  Member.
	 * @param string $level_id Level identifier the adapter reported.
	 * @param string $source   Adapter id. Unused; part of the hook contract.
	 */
	public static function on_deactivated( $user_id, $level_id, $source = '' ): void {
		unset( $source );

		$user_id  = (int) $user_id;
		$level_id = (string) $level_id;
		if ( $user_id <= 0 || '' === $level_id ) {
			return;
		}

		// The rule memo is per-request and was populated while the membership
		// still counted; without this the re-check below answers from the
		// state we are reacting to.
		AccessRule::reset_memo();

		foreach ( AccessRule::spaces_for_level( $level_id ) as $space_id ) {
			if ( self::SOURCE !== self::row_source( $space_id, $user_id ) ) {
				// Not ours to remove: they joined manually, by invite, or the
				// row predates provenance and is backfilled 'manual'.
				continue;
			}

			// Another live plan may grant the same space.
			if ( AccessRule::grants_access( $user_id, $space_id ) ) {
				continue;
			}

			SpaceMember::remove( $space_id, $user_id );
		}
	}

	/**
	 * Recurring backstop: drop 'tier' roster rows whose grant has lapsed.
	 *
	 * The on_deactivated() listener handles adapters that FIRE
	 * jetonomy_membership_deactivated on cancel. But several adapters - BuddyNext
	 * Pro among them - hold access
	 * until expires_at and never fire that hook, so a lapsed plan would otherwise
	 * leave its tier row on the roster forever. This sweep re-asks
	 * AccessRule::grants_access() for every tier row and removes the ones nothing
	 * still grants.
	 *
	 * Safe by construction: it only ever inspects 'tier' rows, so a manual /
	 * invite / rule member is never touched; and it never grants access (the live
	 * Permission_Engine already does that) - it only trims stale roster rows.
	 * Keyset-paged on the (space_id, user_id) primary key so a large roster is
	 * never loaded at once and the walk is stable under concurrent deletes.
	 *
	 * Scheduled hourly-ish by Cron (jetonomy_reconcile_rosters).
	 *
	 * ponytail: full reconcile per run, paged. If a site ever has millions of
	 * tier rows, carry a cross-run cursor like the trust evaluator does.
	 */
	public static function reconcile(): void {
		global $wpdb;

		// Fresh request (cron), but be explicit: decisions must read current rules.
		AccessRule::reset_memo();

		$table      = \Jetonomy\table( 'space_members' );
		$per_page   = 500;
		$last_space = 0;
		$last_user  = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows    = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT space_id, user_id FROM {$table}
					 WHERE source = 'tier'
					   AND ( space_id > %d OR ( space_id = %d AND user_id > %d ) )
					 ORDER BY space_id ASC, user_id ASC
					 LIMIT %d",
					$last_space,
					$last_space,
					$last_user,
					$per_page
				)
			);
			$fetched = count( $rows );

			foreach ( $rows as $row ) {
				$space_id   = (int) $row->space_id;
				$user_id    = (int) $row->user_id;
				$last_space = $space_id;
				$last_user  = $user_id;

				if ( ! AccessRule::grants_access( $user_id, $space_id ) ) {
					SpaceMember::remove( $space_id, $user_id );
				}
			}
		} while ( $fetched === $per_page );
	}

	/**
	 * Provenance of one roster row, or null when there is no row.
	 *
	 * @param int $space_id Space.
	 * @param int $user_id  Member.
	 */
	private static function row_source( int $space_id, int $user_id ): ?string {
		global $wpdb;

		$table = \Jetonomy\table( 'space_members' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$source = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT source FROM {$table} WHERE space_id = %d AND user_id = %d",
				$space_id,
				$user_id
			)
		);

		return null === $source ? null : (string) $source;
	}
}
