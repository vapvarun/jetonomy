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
	 * @return object[]
	 */
	public static function list_pending_flags( int $user_id, ?int $space_id = null ): array {
		if ( ! $user_id ) {
			return [];
		}

		if ( null !== $space_id ) {
			if ( ! Moderation_Permissions::can_view_space_queue( $user_id, $space_id ) ) {
				return [];
			}
			return Flag::list_pending_in_space( $space_id );
		}

		if ( Moderation_Permissions::can_view_admin_dashboard( $user_id ) ) {
			return Flag::list_pending();
		}

		$space_ids = SpaceMember::moderated_space_ids( $user_id );
		if ( empty( $space_ids ) ) {
			return [];
		}
		return Flag::list_pending_in_spaces( $space_ids );
	}

	/**
	 * Count pending flags per space for every space the user may moderate.
	 *
	 * Used by the admin cross-space dashboard.
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
			// Already-deleted content makes the report moot, not failed —
			// keep the flag valid and let the cascade clean up siblings.
			if ( is_wp_error( $trash ) && 'jetonomy_not_found' !== $trash->get_error_code() ) {
				return $trash;
			}

			Flag::resolve_siblings_for( $type, $object_id, $user_id, 'valid', $flag_id );
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
	 * Change status of a post or reply (approve / spam / trash) with permission.
	 *
	 * Mirrors the existing controller::set_status behaviour and adds the
	 * spam reputation penalty when applicable.
	 *
	 * @param int    $user_id
	 * @param string $type   'post' or 'reply'
	 * @param int    $id     Object row ID.
	 * @param string $action 'approve' | 'spam' | 'trash'
	 * @return true|WP_Error
	 */
	public static function set_object_status( int $user_id, string $type, int $id, string $action ) {
		if ( ! in_array( $type, [ 'post', 'reply' ], true ) ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid object type.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}
		$map = [
			'approve' => 'publish',
			'spam'    => 'spam',
			'trash'   => 'trash',
		];
		if ( ! isset( $map[ $action ] ) ) {
			return new WP_Error(
				'jetonomy_validation',
				__( 'Invalid action.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		if ( ! Moderation_Permissions::can_act_on_object( $user_id, $type, $id ) ) {
			return new WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot moderate this content.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

		if ( 'post' === $type ) {
			$row = Post::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', __( 'Post not found.', 'jetonomy' ), [ 'status' => 404 ] );
			}
			Post::update( $id, [ 'status' => $map[ $action ] ] );
		} else {
			$row = Reply::find( $id );
			if ( ! $row ) {
				return new WP_Error( 'jetonomy_not_found', __( 'Reply not found.', 'jetonomy' ), [ 'status' => 404 ] );
			}
			Reply::update( $id, [ 'status' => $map[ $action ] ] );
		}

		if ( 'spam' === $action && ! empty( $row->author_id ) ) {
			Reputation::award( (int) $row->author_id, 'post_removed' );
		}

		do_action( 'jetonomy_content_moderated', $action, $type, $id, $user_id );

		return true;
	}
}
