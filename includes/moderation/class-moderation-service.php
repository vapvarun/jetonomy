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
use Jetonomy\Models\ActivityLog;
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
	 * One row per reported item (its newest report): several members reporting
	 * the same reply is one decision, so the queue shows it once, and
	 * flag_context() carries the report count and reasons.
	 *
	 * @param int      $user_id
	 * @param int|null $space_id
	 * @param int      $limit    Max rows. 0 = unbounded (back-compat).
	 * @param int      $offset   Row offset for pagination.
	 * @return object[]
	 */
	public static function list_pending_flags( int $user_id, ?int $space_id = null, int $limit = 0, int $offset = 0 ): array {
		if ( ! $user_id ) {
			return array();
		}

		if ( null !== $space_id ) {
			if ( ! Moderation_Permissions::can_view_space_queue( $user_id, $space_id ) ) {
				return array();
			}
			return Flag::list_pending_in_space( $space_id, $limit, $offset, true );
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			return Flag::list_pending( $limit, $offset, Moderation_Permissions::unreadable_space_ids( $user_id ), true );
		}

		$space_ids = SpaceMember::moderated_space_ids( $user_id );
		if ( empty( $space_ids ) ) {
			return array();
		}
		return Flag::list_pending_in_spaces( $space_ids, $limit, $offset, true );
	}

	/**
	 * Pending-flag count visible to this user — paired with
	 * list_pending_flags() for pagination totals + badges. Same scoping
	 * rules as the list method: admin dashboard cap → global; specific
	 * space → that space; otherwise → moderated_space_ids. Counts reported
	 * items, the cards the queue shows.
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
			return Flag::count_pending_in_space( $space_id, true );
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			return Flag::count_pending( Moderation_Permissions::unreadable_space_ids( $user_id ), true );
		}

		$space_ids = SpaceMember::moderated_space_ids( $user_id );
		if ( empty( $space_ids ) ) {
			return 0;
		}
		return Flag::count_pending_in_spaces( $space_ids, true );
	}

	/**
	 * Resolve the space scope for the pending-APPROVAL queries below.
	 *
	 * Mirrors the three call shapes of list_pending_flags(), with one
	 * deliberate difference in how "denied" is expressed: it returns an EMPTY
	 * array, never null. The model layer reads null as "no filter, every
	 * space", so returning null on a denial would hand the whole site's held
	 * content to someone with no rights at all. Denied and
	 * no-spaces-in-scope are the same answer here - an empty scope, which
	 * matches nothing.
	 *
	 * @param int      $user_id
	 * @param int|null $space_id
	 * @return array|null Null = unscoped (administrators); int[] = these spaces only;
	 *                    array( 'skip_spaces' => int[] ) = every space but these (site moderators).
	 */
	private static function approval_scope( int $user_id, ?int $space_id ): ?array {
		if ( ! $user_id ) {
			return array();
		}

		if ( null !== $space_id ) {
			return Moderation_Permissions::can_view_space_queue( $user_id, $space_id )
				? array( $space_id )
				: array();
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			// Site moderators: every space they can read (null = all, for admins).
			return Moderation_Permissions::staff_scope( $user_id );
		}

		return SpaceMember::moderated_space_ids( $user_id );
	}

	/**
	 * List content held by a space's require_approval setting.
	 *
	 * This is the queue's SECOND source, and it is a different mechanism from
	 * flags: a flag is a member REPORTING published content, while an
	 * approval-hold is the space itself refusing to publish in the first
	 * place (Moderation_Service::screen_new_content() writes
	 * status = 'pending' and creates no flag row). Because nothing lands in
	 * jt_flags, the flag-only queue showed "no pending flags" while held
	 * posts piled up invisibly - reachable only from wp-admin, which is
	 * exactly what a frontend-first community cannot ask a space moderator
	 * to use.
	 *
	 * Posts and replies are listed separately rather than merged. Merging
	 * would need a UNION across two tables to paginate honestly, and the
	 * admin screen already presents them as two tabs - so two lists keeps
	 * one query per model, one index per query, and the same mental model on
	 * both surfaces.
	 *
	 * @param int      $user_id
	 * @param string   $kind     'post' or 'reply'.
	 * @param int|null $space_id Limit to one space, or null for the caller's scope.
	 * @param int      $limit
	 * @param int      $offset
	 * @return object[]
	 */
	public static function list_pending_approvals( int $user_id, string $kind, ?int $space_id = null, int $limit = 20, int $offset = 0 ): array {
		$scope = self::approval_scope( $user_id, $space_id );

		return 'reply' === $kind
			? Reply::list_by_status( array( 'pending' ), $limit, $offset, $scope )
			: Post::list_by_status( array( 'pending' ), $limit, $offset, $scope );
	}

	/**
	 * Count held content visible to this user - paired with
	 * list_pending_approvals() for pagination totals and tab badges.
	 *
	 * @param int      $user_id
	 * @param string   $kind     'post' or 'reply'.
	 * @param int|null $space_id Limit to one space, or null for the caller's scope.
	 * @return int
	 */
	public static function count_pending_approvals( int $user_id, string $kind, ?int $space_id = null ): int {
		$scope = self::approval_scope( $user_id, $space_id );

		return 'reply' === $kind
			? Reply::count_by_status( array( 'pending' ), $scope )
			: Post::count_by_status( array( 'pending' ), $scope );
	}

	/**
	 * List trashed content this user may moderate - the queue's Trash tab.
	 *
	 * Deleting a topic or reply is a soft delete, and without this the only
	 * way back (or forward, to a permanent delete) was a wp-admin row action
	 * a space moderator cannot reach. Same scope rules as the approvals list.
	 *
	 * @param int      $user_id
	 * @param string   $kind     'post' or 'reply'.
	 * @param int|null $space_id Limit to one space, or null for the caller's scope.
	 * @param int      $limit
	 * @param int      $offset
	 * @return object[]
	 */
	public static function list_trashed( int $user_id, string $kind, ?int $space_id = null, int $limit = 20, int $offset = 0 ): array {
		$scope = self::approval_scope( $user_id, $space_id );

		return 'reply' === $kind
			? Reply::list_by_status( array( 'trash' ), $limit, $offset, $scope )
			: Post::list_by_status( array( 'trash' ), $limit, $offset, $scope );
	}

	/**
	 * Count trashed content visible to this user - paired with list_trashed().
	 *
	 * @param int      $user_id
	 * @param string   $kind     'post' or 'reply'.
	 * @param int|null $space_id Limit to one space, or null for the caller's scope.
	 * @return int
	 */
	public static function count_trashed( int $user_id, string $kind, ?int $space_id = null ): int {
		$scope = self::approval_scope( $user_id, $space_id );

		return 'reply' === $kind
			? Reply::count_by_status( array( 'trash' ), $scope )
			: Post::count_by_status( array( 'trash' ), $scope );
	}

	/**
	 * Resolve everything a page of flag rows needs to render, in a fixed
	 * number of queries.
	 *
	 * Both moderation surfaces use this instead of resolving per row (four queries a row,
	 * ~100 on a full page of 25, plus a permalink join and get_userdata() in
	 * partials/moderation/flag-card.php), a cost that stays invisible until someone opens
	 * a busy queue.
	 *
	 * This resolves the whole page in four queries plus one user query,
	 * whatever the row count: replies, then every parent post together with
	 * the directly-flagged posts, then their spaces, then the reporter cache.
	 * Ordering matters - a reply's space is only knowable once its parent post
	 * is loaded, which is why replies are fetched before posts.
	 *
	 * @param object[] $flags Flag rows.
	 * @param string   $base  Non-empty to include permalinks (built by
	 *                        \Jetonomy\route_url(); callers pass base_url()).
	 * @return array<string,object{object:object,topic:?object,space:?object,permalink:string,reports:array,removed:bool}>
	 *         Keyed "{object_type}:{object_id}". Flags whose object or space
	 *         has been deleted are simply absent - callers skip them. A report
	 *         on a member carries the WP_User as object and no topic or space.
	 *         `reports` is every pending report on the object (count and
	 *         reasons); `removed` is true once the content is trashed or spam.
	 */
	public static function flag_context( array $flags, string $base = '' ): array {
		if ( empty( $flags ) ) {
			return array();
		}

		$post_ids  = array();
		$reply_ids = array();
		$user_ids  = array();
		foreach ( $flags as $flag ) {
			$user_ids[] = (int) ( $flag->reporter_id ?? 0 );
			if ( 'reply' === ( $flag->object_type ?? '' ) ) {
				$reply_ids[] = (int) $flag->object_id;
			} elseif ( 'post' === ( $flag->object_type ?? '' ) ) {
				$post_ids[] = (int) $flag->object_id;
			} elseif ( 'user' === ( $flag->object_type ?? '' ) ) {
				$user_ids[] = (int) $flag->object_id;
			}
		}
		$summary = Flag::pending_summary( $flags );

		$replies = Reply::list_by_ids( $reply_ids );
		foreach ( $replies as $reply ) {
			$post_ids[] = (int) $reply->post_id;
		}

		$posts     = Post::list_by_ids( $post_ids );
		$space_ids = array();
		foreach ( $posts as $post ) {
			$space_ids[] = (int) $post->space_id;
		}

		$spaces = array();
		foreach ( Space::list_by_ids( array_values( array_unique( array_filter( $space_ids ) ) ) ) as $space ) {
			$spaces[ (int) $space->id ] = $space;
		}

		$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
		if ( $user_ids ) {
			// Primes WP's user cache so each row's get_userdata() is served
			// from memory instead of issuing its own query.
			get_users(
				array(
					'include'     => $user_ids,
					'fields'      => 'all_with_meta',
					'number'      => count( $user_ids ),
					'count_total' => false,
				)
			);
		}

		$context = array();
		foreach ( $flags as $flag ) {
			$type    = (string) ( $flag->object_type ?? '' );
			$id      = (int) ( $flag->object_id ?? 0 );
			$reports = $summary[ $type . ':' . $id ] ?? array(
				'count'   => 1,
				'reasons' => array( (string) $flag->reason => 1 ),
			);

			if ( 'user' === $type ) {
				$member = get_userdata( $id );
				if ( $member ) {
					$context[ $type . ':' . $id ] = (object) array(
						'object'    => $member,
						'topic'     => null,
						'space'     => null,
						'permalink' => '' !== $base ? \Jetonomy\get_profile_url( $id ) : '',
						'reports'   => $reports,
						'removed'   => false,
					);
				}
				continue;
			}

			if ( 'reply' === $type ) {
				$object = $replies[ $id ] ?? null;
				$parent = $object ? ( $posts[ (int) $object->post_id ] ?? null ) : null;
			} elseif ( 'post' === $type ) {
				$object = $posts[ $id ] ?? null;
				$parent = $object;
			} else {
				continue;
			}

			if ( ! $object || ! $parent ) {
				continue;
			}

			$space = $spaces[ (int) $parent->space_id ] ?? null;
			if ( ! $space ) {
				continue;
			}

			$context[ $type . ':' . $id ] = (object) array(
				'object'    => $object,
				'topic'     => $parent,
				'space'     => $space,
				'permalink' => '' !== $base
					? \Jetonomy\route_url( 'post', $space->slug, $parent->slug )
					: '',
				'reports'   => $reports,
				// Gone already (its author deleted it, or a moderator acted): only Dismiss is left to do.
				'removed'   => in_array( (string) $object->status, array( 'trash', 'spam' ), true )
					|| in_array( (string) $parent->status, array( 'trash', 'spam' ), true ),
			);
		}

		return $context;
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
			return array();
		}

		$by_space = array();
		foreach ( $flags as $flag ) {
			$space_id = Flag_Scope::space_id( $flag );
			if ( null === $space_id ) {
				continue;
			}
			$by_space[ $space_id ] = ( $by_space[ $space_id ] ?? 0 ) + 1;
		}

		$summary = array();
		foreach ( $by_space as $space_id => $count ) {
			$space = Space::find( $space_id );
			if ( ! $space ) {
				continue;
			}
			$summary[] = array(
				'space_id' => (int) $space_id,
				'title'    => (string) $space->title,
				'slug'     => (string) $space->slug,
				'pending'  => (int) $count,
			);
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
	 * Every pending report on the same item is resolved with it: the queue shows
	 * one card per item, so one decision answers all of its reporters (each gets
	 * the "your report was reviewed" notice and, when upheld, the reward). A
	 * report that is no longer pending answers 409 and changes nothing, so a
	 * second click or a stale card cannot notify the reporters again.
	 *
	 * @param int    $user_id
	 * @param int    $flag_id
	 * @param string $status 'valid' or 'dismissed'
	 * @return true|WP_Error
	 */
	public static function resolve_flag( int $user_id, int $flag_id, string $status ) {
		if ( ! in_array( $status, array( 'valid', 'dismissed' ), true ) ) {
			return new WP_Error(
				'jetonomy_invalid_status',
				__( 'Invalid flag resolution.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		$flag = Flag::find( $flag_id );
		// A report the caller may not act on answers exactly like a missing one,
		// so staff who cannot read its space do not learn it exists.
		if ( ! $flag || ! Moderation_Permissions::can_act_on_flag( $user_id, $flag ) ) {
			return new WP_Error(
				'jetonomy_not_found',
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				sprintf( __( '%s not found.', 'jetonomy' ), __( 'Report', 'jetonomy' ) ),
				array( 'status' => 404 )
			);
		}

		$restricted = Moderation_Permissions::restriction_error( $user_id, Flag_Scope::space_id( $flag ) );
		if ( $restricted ) {
			return $restricted;
		}

		if ( 'pending' !== $flag->status ) {
			return self::flag_handled_error();
		}

		// End-user contract: the moderation queue's "Remove" button maps to
		// data-resolution=valid and carries a trash icon. A moderator who
		// clicks it expects the offending post or reply to come down at the
		// same time the flag clears. Gate the trash permission upfront so we
		// never leave the flag in 'valid' with content still live. A report on
		// a member removes nothing: upholding it records the decision, and any
		// ban goes through the ban flow.
		$type       = (string) $flag->object_type;
		$object_id  = (int) $flag->object_id;
		$is_content = in_array( $type, array( 'post', 'reply' ), true );
		if ( 'valid' === $status && $is_content
			&& ! Moderation_Permissions::can_act_on_object( $user_id, $type, $object_id )
		) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot remove this content.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! Flag::resolve( $flag_id, $user_id, $status ) ) {
			$fresh = Flag::find( $flag_id );
			if ( $fresh && 'pending' !== $fresh->status ) {
				return self::flag_handled_error(); // Another moderator got there first.
			}
			return new WP_Error(
				'jetonomy_resolve_failed',
				__( 'Failed to resolve flag.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}
		$resolved = array( $flag );
		foreach ( Flag::pending_for_object( $type, $object_id ) as $sibling ) {
			if ( Flag::resolve( (int) $sibling->id, $user_id, $status ) ) {
				$resolved[] = $sibling;
			}
		}

		if ( 'valid' === $status && $is_content ) {
			$object    = 'post' === $type ? Post::find( $object_id ) : Reply::find( $object_id );
			$author_id = $object ? (int) $object->author_id : 0;

			$trash = self::set_object_status( $user_id, $type, $object_id, 'trash' );
			// Content already deleted or already removed makes the removal moot, not failed.
			if ( is_wp_error( $trash ) && ! in_array( $trash->get_error_code(), array( 'jetonomy_not_found', 'jetonomy_already_handled' ), true ) ) {
				return $trash;
			}

			// Reward each reporter whose report was upheld. A self-report
			// (reporter == author) pads nobody's reputation.
			foreach ( $resolved as $row ) {
				$reporter_id = (int) ( $row->reporter_id ?? 0 );
				if ( $reporter_id > 0 && $reporter_id !== $author_id ) {
					Reputation::award( $reporter_id, 'flag_validated' );
				}
			}
		}

		foreach ( $resolved as $row ) {
			/**
			 * Fires after a flag is resolved.
			 *
			 * @since 1.3.8
			 *
			 * @param int    $flag_id Flag ID.
			 * @param string $status  Resolution status.
			 * @param int    $user_id Resolving user ID.
			 */
			do_action( 'jetonomy_flag_resolved', (int) $row->id, $status, $user_id );

			/**
			 * Fires after a flag is resolved with the full flag object plus context.
			 *
			 * @since 1.4.1
			 * @param object         $flag    Flag object (post-resolution row).
			 * @param array{status:string,user_id:int} $context Context.
			 */
			$fresh = Flag::find( (int) $row->id );
			if ( $fresh ) {
				do_action(
					'jetonomy_after_resolve_flag',
					$fresh,
					array(
						'status'  => $status,
						'user_id' => $user_id,
					)
				);
			}
		}

		return true;
	}

	/**
	 * 409 for a report that is no longer pending.
	 */
	private static function flag_handled_error(): WP_Error {
		return new WP_Error(
			'jetonomy_already_handled',
			__( 'This report was already handled by a moderator.', 'jetonomy' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Whether this member may report this post, reply or member: no account
	 * restriction, a live target they can read, not their own, not already
	 * reported by them. Shared by POST /flags and WP-CLI.
	 *
	 * @param int    $reporter_id Reporting member.
	 * @param string $object_type 'post', 'reply' or 'user'.
	 * @param int    $object_id   Target ID.
	 * @return true|WP_Error
	 */
	public static function check_flag( int $reporter_id, string $object_type, int $object_id ) {
		$restricted = \Jetonomy\Permissions\Permission_Engine::restriction_error( $reporter_id, 'flag' );
		if ( $restricted ) {
			return $restricted;
		}

		// The target must exist, and members cannot report their own content /
		// themselves. Both were client-side-only before, so a raw API call
		// could 201 a flag against a nonexistent id or self-report to farm the
		// reporter-reputation path.
		$target_author = null;
		// Content must also be live and readable by the reporter. A topic in a
		// private space you cannot open, or a trashed reply, answers exactly like a
		// missing one: otherwise any member could flag (and dock reputation from)
		// content they cannot see, and 404-vs-201 would confirm private ids exist.
		if ( 'post' === $object_type ) {
			$target        = Post::find( $object_id );
			$readable      = $target && 'publish' === $target->status
				&& \Jetonomy\Permissions\Permission_Engine::can_read_post( $reporter_id, $target );
			$target_author = $readable ? (int) $target->author_id : null;
		} elseif ( 'reply' === $object_type ) {
			$target        = Reply::find( $object_id );
			$parent        = $target ? Post::find( (int) $target->post_id ) : null;
			$readable      = $target && $parent && 'publish' === $target->status && 'publish' === $parent->status
				&& \Jetonomy\Permissions\Permission_Engine::can_read_post( $reporter_id, $parent )
				&& \Jetonomy\Permissions\Permission_Engine::can_read_reply( $reporter_id, $target, $parent );
			$target_author = $readable ? (int) $target->author_id : null;
		} elseif ( 'user' === $object_type ) {
			$target        = get_userdata( $object_id );
			$target_author = $target ? (int) $target->ID : null;
		}
		if ( null === $target_author ) {
			return new WP_Error(
				'jetonomy_flag_target_missing',
				__( 'The reported content no longer exists.', 'jetonomy' ),
				array( 'status' => 404 )
			);
		}
		if ( $target_author === $reporter_id ) {
			return new WP_Error(
				'jetonomy_flag_self',
				__( 'You cannot report your own content.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		// Prevent duplicate flags by the same user on the same object.
		$existing = Flag::find_by_reporter_and_object( $reporter_id, $object_type, $object_id );
		if ( $existing ) {
			return new WP_Error(
				'jetonomy_already_flagged',
				__( 'You have already reported this content.', 'jetonomy' ),
				array( 'status' => 409 )
			);
		}

		return true;
	}

	/**
	 * File a report: the one create path for REST, the abilities API and WP-CLI.
	 *
	 * Runs check_flag(), validates the reason and the note length, stores the
	 * row, docks the reported author's reputation and fires the create hooks,
	 * so every entry point behaves the same.
	 *
	 * @param int    $reporter_id Reporting member.
	 * @param string $object_type 'post', 'reply' or 'user'.
	 * @param int    $object_id   Target ID.
	 * @param string $reason      One of Flag::REASONS.
	 * @param string $description Optional note, up to Flag::DESCRIPTION_MAX characters.
	 * @param array  $context     Extra context for jetonomy_after_create_flag (e.g. the REST request).
	 * @return int|WP_Error New flag ID.
	 */
	public static function create_flag( int $reporter_id, string $object_type, int $object_id, string $reason, string $description = '', array $context = array() ) {
		if ( ! in_array( $reason, Flag::REASONS, true ) ) {
			return new WP_Error(
				'jetonomy_validation',
				/* translators: %s: comma-separated list of report reasons. */
				sprintf( __( 'Choose a reason: %s.', 'jetonomy' ), implode( ', ', Flag::REASONS ) ),
				array( 'status' => 400 )
			);
		}
		if ( mb_strlen( $description ) > Flag::DESCRIPTION_MAX ) {
			return new WP_Error(
				'jetonomy_validation',
				/* translators: %s: maximum number of characters. */
				sprintf( __( 'Report details can be up to %s characters.', 'jetonomy' ), number_format_i18n( Flag::DESCRIPTION_MAX ) ),
				array( 'status' => 400 )
			);
		}

		$gate = self::check_flag( $reporter_id, $object_type, $object_id );
		if ( is_wp_error( $gate ) ) {
			return $gate;
		}

		$flag_id = Flag::create(
			array(
				'reporter_id' => $reporter_id,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'reason'      => $reason,
				'description' => $description,
			)
		);
		if ( ! $flag_id ) {
			return new WP_Error(
				'jetonomy_flag_failed',
				__( 'Failed to create flag.', 'jetonomy' ),
				array( 'status' => 500 )
			);
		}

		/**
		 * Fires after a content flag is created.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $flag_id     Flag ID.
		 * @param string $object_type Flagged object type: 'post', 'reply' or 'user'.
		 */
		do_action( 'jetonomy_flag_created', $flag_id, $object_type );

		// A report docks the reported author (post/reply only; a reported member
		// goes through the ban flow). check_flag() already refused self-reports.
		$target = 'post' === $object_type ? Post::find( $object_id ) : ( 'reply' === $object_type ? Reply::find( $object_id ) : null );
		if ( $target && (int) $target->author_id > 0 ) {
			Reputation::award(
				(int) $target->author_id,
				'post_reported',
				array(
					'object_type' => $object_type,
					'object_id'   => $object_id,
				)
			);
		}

		/**
		 * Fires after a flag is created with the full flag object plus context.
		 *
		 * @since 1.4.1
		 * @param object $flag    Flag object (id, reporter_id, object_type, object_id, reason, description).
		 * @param array  $context Context: user_id, and request on the REST path.
		 */
		$flag = Flag::find( $flag_id );
		if ( $flag ) {
			do_action( 'jetonomy_after_create_flag', $flag, array_merge( array( 'user_id' => $reporter_id ), $context ) );
		}

		return $flag_id;
	}

	/**
	 * The status new member content is stored with: the one hold decision
	 * every create/publish path runs (REST create, draft publish-now, the
	 * scheduled publish, abilities, reply-by-email). Content rules
	 * (jetonomy_check_content) first, then the space's require_approval unless
	 * the author is space staff. Held content is stored `pending` and is
	 * announced only when a moderator approves it.
	 *
	 * @param string $type      'post' or 'reply' (picks the block message).
	 * @param array  $data      Content as stored: title/content, and status ('draft' skips screening).
	 * @param int    $space_id  Space ID.
	 * @param int    $author_id Content author.
	 * @return array{status: string, flag: bool}|WP_Error WP_Error when a rule blocks it.
	 */
	public static function screen_new_content( string $type, array $data, int $space_id, int $author_id ) {
		$status = (string) ( $data['status'] ?? 'publish' );
		if ( 'draft' === $status ) {
			return array(
				'status' => 'draft',
				'flag'   => false,
			); // Screened when it is published.
		}

		/**
		 * Check content against moderation rules before it is stored or published.
		 *
		 * @param string|null $action    null if no action, or 'flag', 'hold', 'block', 'spam'.
		 * @param array       $data      Content data with 'title' (posts) and 'content' keys.
		 * @param int         $space_id  Space ID.
		 * @param int         $author_id Author user ID.
		 */
		$action = apply_filters( 'jetonomy_check_content', null, $data, $space_id, $author_id );
		if ( 'block' === $action ) {
			return new WP_Error(
				'jetonomy_validation',
				'post' === $type ? sprintf( /* translators: %s: the singular Topic label, lowercase. */ __( 'Your %s was blocked by our content policy.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) : sprintf( /* translators: %s: the singular Reply label, lowercase. */ __( 'Your %s was blocked by our content policy.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ) ),
				array( 'status' => 400 )
			);
		}
		if ( 'hold' === $action ) {
			$status = 'pending';
		} elseif ( 'spam' === $action ) {
			$status = 'spam';
		}

		if ( 'publish' === $status
			&& ! empty( Space::get_settings( $space_id )['require_approval'] )
			&& ! \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $author_id, $space_id ) ) {
			$status = 'pending';
		}

		return array(
			'status' => $status,
			'flag'   => 'flag' === $action,
		);
	}

	/**
	 * File the system flag a content rule asked for (the 'flag' outcome of
	 * jetonomy_check_content). The content stays live; the flag puts it in the
	 * moderation queue with reporter_id 0. Flag::create() bumps flag_count.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Object row ID.
	 */
	public static function auto_flag( string $type, int $id ): void {
		$flag_id = Flag::create(
			array(
				'reporter_id' => 0,
				'object_type' => $type,
				'object_id'   => $id,
				'reason'      => 'other',
				'description' => __( 'Flagged automatically by a moderation rule.', 'jetonomy' ),
			)
		);
		if ( $flag_id ) {
			do_action( 'jetonomy_flag_created', (int) $flag_id, $type );
		}
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
	 * @param int    $user_id  Acting user ID (must be > 0).
	 * @param string $type     'post' or 'reply'
	 * @param int    $id       Object row ID.
	 * @param string $action   'approve' | 'spam' | 'hold' | 'trash'
	 * @param string $expected Status the caller saw (a queue card sends it). When the
	 *                         row has moved on since, the action is refused with 409.
	 * @return true|WP_Error
	 */
	public static function set_object_status( int $user_id, string $type, int $id, string $action, string $expected = '' ) {
		if ( $user_id <= 0 || ! Moderation_Permissions::can_act_on_object( $user_id, $type, $id ) ) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot moderate this content.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		return self::apply_object_status( $user_id, $type, $id, $action, $expected );
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
		$map = array(
			'approve' => 'publish',
			'spam'    => 'spam',
			'hold'    => 'pending',
			'trash'   => 'trash',
		);
		return $map[ $action ] ?? '';
	}

	/**
	 * Why this moderation action may not run on this row, or null.
	 *
	 * A silenced or banned moderator is refused with the reason the member
	 * surfaces give. An item that already has the target status, or no longer
	 * has the one the caller saw, was already handled.
	 *
	 * @param int    $user_id    Acting user (0 = system actor, never restricted).
	 * @param string $type       'post' or 'reply'.
	 * @param object $row        Row as stored now.
	 * @param string $new_status Status the action writes.
	 * @param string $expected   Status the caller saw, or ''.
	 * @param int    $space_id   Space the item is in.
	 */
	private static function moderation_refusal( int $user_id, string $type, object $row, string $new_status, string $expected, int $space_id ): ?WP_Error {
		$restricted = $user_id > 0 ? Moderation_Permissions::restriction_error( $user_id, $space_id ) : null;
		if ( $restricted ) {
			return $restricted;
		}
		$status = (string) ( $row->status ?? '' );
		if ( $status === $new_status || ( '' !== $expected && $status !== $expected ) ) {
			return new WP_Error(
				'jetonomy_already_handled',
				sprintf(
					/* translators: %s: singular topic or reply label, lowercase. */
					__( 'This %s was already handled by a moderator. Reload to see where it is now.', 'jetonomy' ),
					\Jetonomy\jetonomy_label( 'reply' === $type ? 'reply' : 'topic', false, true )
				),
				array( 'status' => 409 )
			);
		}
		return null;
	}

	/**
	 * Shared status-change implementation. Permission decisions live in the
	 * two public entry points above.
	 *
	 * Refuses with 409 jetonomy_already_handled, changing and sending nothing,
	 * when the item already has the status the action would give it (a second
	 * click, or two moderators on the same card) or no longer has the status
	 * the caller expected (a stale Reject on an item another moderator approved).
	 *
	 * @param int    $user_id  Acting user ID (0 = system actor).
	 * @param string $type     'post' or 'reply'
	 * @param int    $id       Object row ID.
	 * @param string $action   'approve' | 'spam' | 'hold' | 'trash'
	 * @param string $expected Status the caller saw, or '' to skip that check.
	 * @return true|WP_Error
	 */
	private static function apply_object_status( int $user_id, string $type, int $id, string $action, string $expected = '' ) {
		if ( ! in_array( $type, array( 'post', 'reply' ), true ) ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid object type.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}
		$new_status = self::status_for_action( $action );
		if ( '' === $new_status ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid action.', 'jetonomy' ),
				array( 'status' => 400 )
			);
		}

		if ( 'post' === $type ) {
			$row = Post::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ), array( 'status' => 404 ) );
			}
			$refused = self::moderation_refusal( $user_id, $type, $row, $new_status, $expected, (int) $row->space_id );
			if ( $refused ) {
				return $refused;
			}
			// Approving a SCHEDULED draft is a publish-now, not a status poke.
			// Writing status=publish directly left published_at in the future,
			// so the post went live early, kept rendering a "Scheduled" badge,
			// pinned itself to the top of the "new" sort, and never fired
			// jetonomy_after_create_post - no notifications, no activity, no
			// BuddyPress broadcast. publish_draft() clears published_at and
			// fires the create hook, which is what the REST publish-now route
			// already did correctly.
			if ( 'publish' === $new_status
				&& 'draft' === ( $row->status ?? '' )
				&& ! empty( $row->published_at ) ) {
				Post::publish_draft( $id, false );
			} else {
				Post::update( $id, array( 'status' => $new_status ) );
			}
		} else {
			$row = Reply::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', sprintf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ) ), array( 'status' => 404 ) );
			}
			$refused = self::moderation_refusal( $user_id, $type, $row, $new_status, $expected, (int) ( Post::find( (int) $row->post_id )->space_id ?? 0 ) );
			if ( $refused ) {
				return $refused;
			}
			Reply::update( $id, array( 'status' => $new_status ) );
		}

		// Announce on approval only content that never went live (no created_* activity row yet).
		if ( 'publish' === $new_status && in_array( $row->status ?? '', array( 'pending', 'spam' ), true )
			&& ! ActivityLog::exists_for( (int) $row->author_id, 'created_' . $type, $type, $id ) ) {
			if ( 'post' === $type ) {
				do_action( 'jetonomy_after_create_post', $id, (int) $row->space_id, null );
			} else {
				do_action( 'jetonomy_after_create_reply', $id, (int) $row->post_id, null );
			}
		}

		// Single place every moderation path resolves pending flags. REST,
		// admin AJAX (single + bulk), abilities, space-mod, and resolve_flag all
		// funnel through here, so directly actioning content cannot leave orphan 'pending'
		// flags in the queue pointing at already-removed content. resolve_siblings_for() is a
		// single bulk UPDATE, so this stays one extra query per object.
		$flag_resolution = array(
			'approve' => 'dismissed', // Content found acceptable — reports dismissed.
			'spam'    => 'valid',     // Reports confirmed.
			'trash'   => 'valid',     // Reports confirmed.
			// 'hold' intentionally absent: still under review, leave flags pending.
		);
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

		// A moderator removing content (spam or trash) costs the author the
		// "Post removed by moderator" points; approving it back restores them.
		// $row is the pre-action row, so a repeat action on the same status
		// changes nothing.
		$was_removed = in_array( (string) ( $row->status ?? '' ), array( 'spam', 'trash' ), true );
		// Ledger-guarded: content the author trashed themselves (no penalty) or
		// removed before 2.0.2 gives nothing back when approved.
		if ( ! empty( $row->author_id ) && 'approve' === $action && $was_removed ) {
			Reputation::revoke_awarded(
				(int) $row->author_id,
				'post_removed',
				array(
					'object_type' => $type,
					'object_id'   => $id,
				)
			);
		}
		// A moderator removing their OWN content is a self-delete: no penalty.
		if ( in_array( $action, array( 'spam', 'trash' ), true ) && ! $was_removed && ! empty( $row->author_id ) && (int) $row->author_id !== $user_id ) {
			Reputation::award_once(
				(int) $row->author_id,
				'post_removed',
				array(
					'object_type' => $type,
					'object_id'   => $id,
				)
			);
		}

		/**
		 * Fires after a moderation action is applied to a post or reply.
		 *
		 * @since 1.0.0
		 *
		 * @param string $action  Moderation action: approve|spam|trash|hold.
		 * @param string $type    'post' or 'reply'.
		 * @param int    $id      Object row ID.
		 * @param int    $user_id Acting user ID (0 = system).
		 */
		do_action( 'jetonomy_content_moderated', $action, $type, $id, $user_id );

		return true;
	}
}
