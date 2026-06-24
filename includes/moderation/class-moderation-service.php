<?php
/**
 * Moderation service.
 *
 * Business-logic kernel that every moderation surface (templates, REST
 * controllers, wp-admin AJAX, CLI) calls into. Centralising here keeps
 * permission checks and data access honest and consistent.
 *
 * Controllers stay thin: parse request → call service → shape response.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Moderation;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;
use Jetonomy\Trust\Reputation;

class Moderation_Service {

	/**
	 * List pending flags the user is allowed to see.
	 *
	 * Three call shapes:
	 *   (user, null)     → admin dashboard: full global list (requires cap)
	 *   (user, space_id) → per-space queue: flags in that space only
	 *   (user, null) + no-cap → scoped-aggregate: flags across their spaces
	 *
	 * A user with no viewing permission gets an empty list — callers should
	 * have already gated with Moderation_Permissions::can_view_* before
	 * reaching here. The empty fallback is defence in depth.
	 *
	 * @param int      $user_id
	 * @param int|null $space_id
	 * @param int      $limit    Max rows. 0 = unbounded (back-compat).
	 * @param int      $offset   Row offset for pagination.
	 * @return object[]
	 */
	public static function list_pending_flags( int $user_id, ?int $space_id = null, int $limit = 0, int $offset = 0 ): array {
		if ( ! $user_id ) {
			return [];
		}

		if ( null !== $space_id ) {
			if ( ! Moderation_Permissions::can_view_space_queue( $user_id, $space_id ) ) {
				return [];
			}
			return Flag::list_pending_in_space( $space_id, $limit, $offset );
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			return Flag::list_pending( $limit, $offset );
		}

		$space_ids = SpaceMember::moderated_space_ids( $user_id );
		if ( empty( $space_ids ) ) {
			return [];
		}
		return Flag::list_pending_in_spaces( $space_ids, $limit, $offset );
	}

	/**
	 * Pending-flag count visible to this user — paired with
	 * list_pending_flags() for pagination totals + badges. Same scoping
	 * rules as the list method: admin dashboard cap → global; specific
	 * space → that space; otherwise → moderated_space_ids.
	 *
	 * @param int      $user_id
	 * @param int|null $space_id Limit count to this space, or null for the
	 *                           caller's full visible scope.
	 * @return int
	 */
	public static function count_pending_flags( int $user_id, ?int $space_id = null ): int {
		if ( ! $user_id ) {
			return 0;
		}

		if ( null !== $space_id ) {
			if ( ! Moderation_Permissions::can_view_space_queue( $user_id, $space_id ) ) {
				return 0;
			}
			return Flag::count_pending_in_space( $space_id );
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			return Flag::count_pending();
		}

		$space_ids = SpaceMember::moderated_space_ids( $user_id );
		if ( empty( $space_ids ) ) {
			return 0;
		}
		return Flag::count_pending_in_spaces( $space_ids );
	}

	/**
	 * Count pending flags per space for every space the user may moderate.
	 *
	 * Used by the admin cross-space dashboard.
	 *
	 * Restored 2026-06-11: briefly removed in the dead-code sweep because no
	 * production caller exists, but the behavior is fully specified by
	 * ModerationServiceDashboardTest (5 cases: admin/mod/guest scoping) — a
	 * tested contract is not dead code. Wire-or-remove decision tracked for
	 * the team; the intended consumer is a moderation dashboard widget.
	 *
	 * @param int $user_id
	 * @return array<int, array{space_id:int, title:string, slug:string, pending:int}>
	 */
	public static function dashboard_summary( int $user_id ): array {
		$flags = self::list_pending_flags( $user_id, null );
		if ( empty( $flags ) ) {
			return [];
		}

		$by_space = [];
		foreach ( $flags as $flag ) {
			$space_id = Flag_Scope::space_id( $flag );
			if ( null === $space_id ) {
				continue;
			}
			$by_space[ $space_id ] = ( $by_space[ $space_id ] ?? 0 ) + 1;
		}

		$summary = [];
		foreach ( $by_space as $space_id => $count ) {
			$space = Space::find( $space_id );
			if ( ! $space ) {
				continue;
			}
			$summary[] = [
				'space_id' => (int) $space_id,
				'title'    => (string) $space->title,
				'slug'     => (string) $space->slug,
				'pending'  => (int) $count,
			];
		}

		usort(
			$summary,
			static function ( $a, $b ) {
				return $b['pending'] <=> $a['pending'];
			}
		);

		return $summary;
	}

	/**
	 * Resolve a flag (valid / dismissed) with permission check.
	 *
	 * @param int    $user_id
	 * @param int    $flag_id
	 * @param string $status 'valid' or 'dismissed'
	 * @return true|WP_Error
	 */
	public static function resolve_flag( int $user_id, int $flag_id, string $status ) {
		if ( ! in_array( $status, [ 'valid', 'dismissed' ], true ) ) {
			return new WP_Error(
				'jetonomy_invalid_status',
				__( 'Invalid flag resolution.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		$flag = Flag::find( $flag_id );
		if ( ! $flag ) {
			return new WP_Error(
				'jetonomy_not_found',
				__( 'Flag not found.', 'jetonomy' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! Moderation_Permissions::can_act_on_flag( $user_id, $flag ) ) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot resolve this flag.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		// End-user contract: the moderation queue's "Remove" button maps to
		// data-resolution=valid and carries a trash icon. A moderator who
		// clicks it expects the offending post or reply to come down at the
		// same time the flag clears, and any other pending flags on the
		// same object to clear with it. Resolving the flag without trashing
		// the content (the prior behaviour) leaves bad content in front of
		// readers and bait for a second moderator to re-action minutes
		// later. Gate the trash permission upfront so we never leave the
		// flag in 'valid' with content still live.
		$type      = (string) $flag->object_type;
		$object_id = (int) $flag->object_id;
		if ( 'valid' === $status
			&& ! Moderation_Permissions::can_act_on_object( $user_id, $type, $object_id )
		) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot remove this content.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		$ok = Flag::resolve( $flag_id, $user_id, $status );
		if ( ! $ok ) {
			return new WP_Error(
				'jetonomy_resolve_failed',
				__( 'Failed to resolve flag.', 'jetonomy' ),
				[ 'status' => 500 ]
			);
		}

		if ( 'valid' === $status ) {
			$trash = self::set_object_status( $user_id, $type, $object_id, 'trash' );
			if ( is_wp_error( $trash ) ) {
				// Already-deleted content makes the report moot, not failed.
				if ( 'jetonomy_not_found' !== $trash->get_error_code() ) {
					return $trash;
				}
				// set_object_status() bailed (content already gone) before its
				// flag-sync ran, so clear the remaining pending siblings here for
				// this edge case. On the normal path apply_object_status() has
				// already resolved them — no double work.
				Flag::resolve_siblings_for( $type, $object_id, $user_id, 'valid', $flag_id );
			}

			// Reward the original reporter when their flag is confirmed valid.
			// Skip self-flags (reporter == author) — that's a moderation
			// no-op and shouldn't pad the actor's reputation.
			$reporter_id = isset( $flag->reporter_id ) ? (int) $flag->reporter_id : 0;
			if ( $reporter_id > 0 ) {
				$author_id = 0;
				if ( 'post' === $type ) {
					$obj = Post::find( $object_id );
					if ( $obj ) {
						$author_id = (int) $obj->author_id;
					}
				} elseif ( 'reply' === $type ) {
					$obj = Reply::find( $object_id );
					if ( $obj ) {
						$author_id = (int) $obj->author_id;
					}
				}
				if ( $reporter_id !== $author_id ) {
					Reputation::award( $reporter_id, 'flag_validated' );
				}
			}
		}

		do_action( 'jetonomy_flag_resolved', $flag_id, $status, $user_id );

		/**
		 * Fires after a flag is resolved with the full flag object plus context.
		 *
		 * @since 1.4.1
		 * @param object         $flag    Flag object (post-resolution row).
		 * @param array{status:string,user_id:int} $context Context.
		 */
		$flag = Flag::find( (int) $flag_id );
		if ( $flag ) {
			do_action(
				'jetonomy_after_resolve_flag',
				$flag,
				array(
					'status'  => $status,
					'user_id' => $user_id,
				)
			);
		}

		return true;
	}

	/**
	 * Change status of a post or reply (approve / spam / hold / trash) with permission.
	 *
	 * Mirrors the existing controller::set_status behaviour and adds the
	 * spam reputation penalty when applicable.
	 *
	 * The single owner of the status-change contract: REST, admin AJAX, CLI,
	 * and automated reviewers all route through here so counters, reputation,
	 * and the jetonomy_content_moderated hook stay consistent.
	 *
	 * SECURITY: $user_id 0 (anonymous) is always rejected here. Callers pass
	 * get_current_user_id() directly, so a system bypass keyed on 0 would turn
	 * any future permission_callback mistake into anonymous moderation.
	 * Automated actors (cron / AI review) use system_set_object_status().
	 *
	 * @param int    $user_id Acting user ID (must be > 0).
	 * @param string $type   'post' or 'reply'
	 * @param int    $id     Object row ID.
	 * @param string $action 'approve' | 'spam' | 'hold' | 'trash'
	 * @return true|WP_Error
	 */
	public static function set_object_status( int $user_id, string $type, int $id, string $action ) {
		if ( $user_id <= 0 || ! Moderation_Permissions::can_act_on_object( $user_id, $type, $id ) ) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot moderate this content.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		return self::apply_object_status( $user_id, $type, $id, $action );
	}

	/**
	 * Status change by a TRUSTED internal caller that has already authorized the
	 * request — cron / automated moderation (Pro AI batch reviewer) or an admin
	 * AJAX handler that ran its own capability check. Skips the per-user
	 * `can_act_on_object` check (the caller owns authorization) but still runs
	 * the full choke-point side-effects: status write, pending-flag resolution,
	 * reputation, and the `jetonomy_content_moderated` action.
	 *
	 * Never wire this directly to a public REST/AJAX route without a prior
	 * capability + nonce check in the caller.
	 *
	 * @param string $type     'post' or 'reply'
	 * @param int    $id       Object row ID.
	 * @param string $action   'approve' | 'spam' | 'hold' | 'trash'
	 * @param int    $actor_id Acting user ID to record (0 = system/cron). The
	 *                         caller passes the authenticated user for audit and
	 *                         self-action suppression (e.g. don't notify yourself).
	 * @return true|WP_Error
	 */
	public static function system_set_object_status( string $type, int $id, string $action, int $actor_id = 0 ) {
		return self::apply_object_status( $actor_id, $type, $id, $action );
	}

	/**
	 * Canonical moderation-action → content-status map. The single source of
	 * truth for how a moderation verb translates to a stored status, shared by
	 * apply_object_status() and any caller that needs to report the resulting
	 * status (e.g. the moderate ability's output). Returns '' for an unknown
	 * action so callers can treat that as a validation failure.
	 *
	 * @param string $action 'approve' | 'spam' | 'hold' | 'trash'
	 * @return string Stored status ('publish'|'spam'|'pending'|'trash'), or '' if invalid.
	 */
	public static function status_for_action( string $action ): string {
		$map = [
			'approve' => 'publish',
			'spam'    => 'spam',
			'hold'    => 'pending',
			'trash'   => 'trash',
		];
		return $map[ $action ] ?? '';
	}

	/**
	 * Shared status-change implementation. Permission decisions live in the
	 * two public entry points above.
	 *
	 * @param int    $user_id Acting user ID (0 = system actor).
	 * @param string $type   'post' or 'reply'
	 * @param int    $id     Object row ID.
	 * @param string $action 'approve' | 'spam' | 'hold' | 'trash'
	 * @return true|WP_Error
	 */
	private static function apply_object_status( int $user_id, string $type, int $id, string $action ) {
		if ( ! in_array( $type, [ 'post', 'reply' ], true ) ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid object type.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		$new_status = self::status_for_action( $action );
		if ( '' === $new_status ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid action.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'post' === $type ) {
			$row = Post::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ), [ 'status' => 404 ] );
			}
			Post::update( $id, [ 'status' => $new_status ] );
		} else {
			$row = Reply::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', __( 'Reply not found.', 'jetonomy' ), [ 'status' => 404 ] );
			}
			Reply::update( $id, [ 'status' => $new_status ] );
		}

		// Single place every moderation path resolves pending flags. REST,
		// admin AJAX (single + bulk), abilities, space-mod, and resolve_flag all
		// funnel through here, so directly actioning content can no longer leave
		// orphan 'pending' flags in the queue pointing at already-removed content
		// (Basecamp flag/moderation sync gap). resolve_siblings_for() is a single
		// bulk UPDATE, so this stays one extra query per object.
		$flag_resolution = [
			'approve' => 'dismissed', // Content found acceptable — reports dismissed.
			'spam'    => 'valid',     // Reports confirmed.
			'trash'   => 'valid',     // Reports confirmed.
			// 'hold' intentionally absent: still under review, leave flags pending.
		];
		$flag_status = $flag_resolution[ $action ] ?? '';
		/**
		 * Filter the status applied to an object's pending flags when it is
		 * moderated directly (outside the flag-resolution flow). Return '' to
		 * leave the flags pending (e.g. a custom "needs second review" policy).
		 *
		 * @param string $flag_status Default resolution for this action ('valid', 'dismissed', or '').
		 * @param string $action      Moderation action: approve|spam|trash|hold.
		 * @param string $type        'post' or 'reply'.
		 * @param int    $id          Object row ID.
		 * @param int    $user_id     Acting user ID (0 = system actor).
		 */
		$flag_status = (string) apply_filters( 'jetonomy_moderation_flag_resolution', $flag_status, $action, $type, $id, $user_id );
		if ( '' !== $flag_status ) {
			Flag::resolve_siblings_for( $type, $id, $user_id, $flag_status, 0 );
		}

		if ( 'spam' === $action && ! empty( $row->author_id ) ) {
			Reputation::award( (int) $row->author_id, 'post_removed' );
		}

		do_action( 'jetonomy_content_moderated', $action, $type, $id, $user_id );

		return true;
	}
}
