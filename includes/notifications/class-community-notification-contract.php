<?php
/**
 * Community notification contract.
 *
 * The single door Jetonomy uses to hand notifications to a host community
 * plugin (BuddyNext today, any future one tomorrow): every notification this
 * plugin already fires through `jetonomy_notification_created` carries one
 * more argument, a plain payload array, built here and nowhere else. The
 * host reads the payload; Jetonomy's own web-notification row and its own
 * email are unaffected — this class never sends anything, it only describes
 * what already happened.
 *
 * @package Jetonomy
 * @since   2.0.1
 */

namespace Jetonomy\Notifications;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Restriction;
use Jetonomy\Permissions\Permission_Engine;

/**
 * Builds, declares and answers for the community notification contract.
 */
class Community_Notification_Contract {

	/**
	 * Register the contract's three hooks (payload building is called directly
	 * by Notifier::emit_notification_created(), not hooked here).
	 */
	public static function init(): void {
		add_filter( 'jetonomy_community_notification_types', array( __CLASS__, 'filter_types' ) );
		add_filter( 'jetonomy_community_notification_visible', array( __CLASS__, 'filter_visible' ), 10, 3 );

		// A purged topic or reply takes its bell rows with it. Trash is a status
		// change (visibility handles that); these two hooks fire only on the
		// real DELETE (Post::delete() / Reply::delete()).
		add_action(
			'jetonomy_after_delete_post',
			static function ( int $post_id ): void {
				do_action( 'jetonomy_community_notification_removed', 'post', $post_id );
			}
		);
		add_action(
			'jetonomy_after_delete_reply',
			static function ( int $reply_id ): void {
				do_action( 'jetonomy_community_notification_removed', 'reply', $reply_id );
			}
		);
	}

	/**
	 * Build the contract payload for one notification. Returns an empty array
	 * when the notification should not reach a host's inbox (no recipient, no
	 * message, or the actor notifying themself) — Notifier fires the hook
	 * regardless, and an empty payload is a no-op for any listener.
	 *
	 * @param int    $notification_id Jetonomy's own notification row id.
	 * @param int    $user_id         Recipient.
	 * @param int    $actor_id        Acting member, or 0 for a system notice.
	 * @param string $type            Jetonomy's own type slug (reply_to_post, mention, …).
	 * @param string $object_type     'post' | 'reply' | 'space' | 'badge' | ''.
	 * @param int    $object_id       Object id, or 0 (badge_earned uses the new trust level).
	 * @param string $message         Already-translated, plain-text sentence.
	 * @param string $url             Deep link, or '' when the caller didn't resolve one.
	 * @return array<string,mixed>
	 */
	public static function payload( int $notification_id, int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message, string $url ): array {
		$message = trim( wp_strip_all_tags( $message ) );
		if ( $user_id <= 0 || '' === $message || ( $actor_id > 0 && $actor_id === $user_id ) ) {
			return array();
		}

		// notification_deep_link() is Jetonomy's own single source of truth for
		// a deep link — reused rather than re-derived. Most callers already pass
		// a resolved $url; a few (moderation, flag_resolved, join_request*) rely
		// on the object id alone, and this fills that in the same way the
		// notifications page and the email already do.
		$link = '' !== $url ? $url : \Jetonomy\notification_deep_link( $object_type, $object_id );
		if ( '' === $link ) {
			// badge_earned has no deep-linkable object (object_id is a trust
			// level, not a row) — send the member to their own profile instead
			// of dropping the notification for want of a link.
			$link = \Jetonomy\get_profile_url( $user_id );
		}
		if ( '' === $link ) {
			$link = \Jetonomy\base_url();
		}
		if ( '' === $link ) {
			return array();
		}

		$slug = sanitize_key( $type );

		return array(
			'recipient_id'    => $user_id,
			'type'            => $slug,
			'actor_id'        => $actor_id,
			'object_type'     => sanitize_key( $object_type ),
			'object_id'       => max( 0, $object_id ),
			'message'         => $message,
			'url'             => $link,
			// Per object, never per actor — matches the spec's own example
			// (`reply_to_post_1153`). Merges e.g. several join requests for the
			// same space into one row; a reply keeps its own row because
			// $object_id is the reply id, which is unique per reply.
			'group_key'       => $object_id > 0 ? $slug . '_' . $object_id : '',
			'notification_id' => $notification_id,
		);
	}

	/**
	 * Declare every type this plugin fires through the contract.
	 *
	 * Declaring is what tells BuddyNext (or any host) to stop reading Jetonomy
	 * the old, undifferentiated way — so every type Jetonomy has ever created a
	 * notification for is listed here, not just the ones a person notices day
	 * to day.
	 *
	 * @param array<string,array<string,mixed>> $types Incoming types.
	 * @return array<string,array<string,mixed>>
	 */
	public static function filter_types( array $types ): array {
		$defs = array(
			'new_post_in_sub'     => array(
				'label'       => __( 'New topics in spaces you follow', 'jetonomy' ),
				'description' => __( 'A new topic was posted in a space you subscribed to.', 'jetonomy' ),
			),
			'reply_to_post'       => array(
				'label'       => __( 'Replies to your topics', 'jetonomy' ),
				'description' => __( 'Someone replied to a topic you started.', 'jetonomy' ),
			),
			'reply_to_reply'      => array(
				'label'       => __( 'Replies to your comments', 'jetonomy' ),
				'description' => __( 'Someone replied to your comment.', 'jetonomy' ),
			),
			'vote_on_post'        => array(
				'label'       => __( 'Votes on your topics', 'jetonomy' ),
				'description' => __( 'Someone voted on your topic.', 'jetonomy' ),
			),
			'reaction'            => array(
				'label'       => __( 'Reactions', 'jetonomy' ),
				'description' => __( 'Someone reacted to your post.', 'jetonomy' ),
			),
			'flag_resolved'       => array(
				'label'       => __( 'Report updates', 'jetonomy' ),
				'description' => __( 'A report you filed was reviewed.', 'jetonomy' ),
			),
			'accepted_answer'     => array(
				'label'       => __( 'Accepted answers', 'jetonomy' ),
				'description' => __( 'Your reply was accepted as the answer.', 'jetonomy' ),
			),
			'idea_status_changed' => array(
				'label'       => __( 'Idea status changes', 'jetonomy' ),
				'description' => __( 'The status of an idea you posted changed.', 'jetonomy' ),
			),
			'badge_earned'        => array(
				'label'       => __( 'Badges and trust levels', 'jetonomy' ),
				'description' => __( 'You reached a new trust level.', 'jetonomy' ),
			),
			'moderation'          => array(
				'label'       => __( 'Moderation notices', 'jetonomy' ),
				'description' => __( 'Your content, or a report you filed, needs your attention.', 'jetonomy' ),
			),
			'join_request_result' => array(
				'label'       => __( 'Join request decisions', 'jetonomy' ),
				'description' => __( 'Your request to join a space was decided.', 'jetonomy' ),
			),
			'join_request'        => array(
				'label'       => __( 'Join requests to review', 'jetonomy' ),
				'description' => __( 'Someone requested to join a space you moderate.', 'jetonomy' ),
			),
			'mention'             => array(
				'label'       => __( 'Mentions', 'jetonomy' ),
				'description' => __( 'Someone mentioned you.', 'jetonomy' ),
			),
		);

		foreach ( $defs as $slug => $def ) {
			$types[ $slug ] = array(
				'label'       => $def['label'],
				'description' => $def['description'],
				'default_on'  => true,
			);
		}

		return $types;
	}

	/**
	 * Answer which of the host's bell rows for Jetonomy content the viewer may
	 * still see. The DECISION never re-derives Jetonomy's own rules — it calls
	 * the plugin's existing authoritative checks: `Permission_Engine::can_read_post()`
	 * / `can_read_reply()` (status, private content, space visibility including
	 * hidden-category concealment and access rules, exactly as the front end and
	 * REST enforce it) and `Restriction::is_banned()` (the same global-ban check
	 * `can()` itself uses for the viewer, applied here to the CONTENT's author).
	 * Both are request-memoized (`Permission_Engine::can()`, `Restriction::is_banned()`),
	 * so this only fetches the raw rows itself — one query per table, regardless
	 * of page size — and asks the canonical functions per row.
	 *
	 * @param array<int|string,bool>                                                                 $visible Every key starts true.
	 * @param int                                                                                    $viewer_id Recipient viewing their bell.
	 * @param array<int|string,array{type?:string,object_type?:string,object_id?:int,actor_id?:int}> $targets Rows on this page; a caller of the shared filter is not
	 *        guaranteed to fill every key.
	 * @return array<int|string,bool>
	 */
	public static function filter_visible( array $visible, int $viewer_id, array $targets ): array {
		$post_keys  = array(); // key => post_id
		$reply_keys = array(); // key => reply_id
		$space_keys = array(); // key => space_id

		foreach ( $targets as $key => $target ) {
			$object_id = (int) ( $target['object_id'] ?? 0 );
			if ( $object_id <= 0 ) {
				continue;
			}
			switch ( (string) ( $target['object_type'] ?? '' ) ) {
				case 'post':
					$post_keys[ $key ] = $object_id;
					break;
				case 'reply':
					$reply_keys[ $key ] = $object_id;
					break;
				case 'space':
					$space_keys[ $key ] = $object_id;
					break;
				default:
					// 'badge', '' and anything unrecognised: no content object to
					// hide behind, always visible.
					break;
			}
		}

		if ( empty( $post_keys ) && empty( $reply_keys ) && empty( $space_keys ) ) {
			return $visible;
		}

		global $wpdb;

		// One query for every reply this page references — data only; the
		// visibility DECISION for each row happens below via Permission_Engine.
		$reply_rows = array();
		if ( ! empty( $reply_keys ) ) {
			$ids          = array_values( array_unique( $reply_keys ) );
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a trusted prefixed constant, ids are %d-placeholders.
			$reply_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . \Jetonomy\table( 'replies' ) . " WHERE id IN ({$placeholders})",
					$ids
				),
				OBJECT_K
			);
		}

		// One query for every post this page references, directly or via a reply
		// (can_read_post()/can_read_reply() both need the parent post row).
		$post_ids = array_values( $post_keys );
		foreach ( $reply_rows as $reply_row ) {
			$post_ids[] = (int) $reply_row->post_id;
		}
		$post_ids = array_values( array_unique( array_filter( $post_ids ) ) );

		$post_rows = array();
		if ( ! empty( $post_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$post_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM ' . \Jetonomy\table( 'posts' ) . " WHERE id IN ({$placeholders})",
					$post_ids
				),
				OBJECT_K
			);
		}

		foreach ( $post_keys as $key => $post_id ) {
			$post = $post_rows[ $post_id ] ?? null;
			if ( ! $post
				|| ! Permission_Engine::can_read_post( $viewer_id, $post )
				|| Restriction::is_banned( (int) $post->author_id )
			) {
				$visible[ $key ] = false;
			}
		}

		foreach ( $reply_keys as $key => $reply_id ) {
			$reply = $reply_rows[ $reply_id ] ?? null;
			$post  = $reply ? ( $post_rows[ (int) $reply->post_id ] ?? null ) : null;
			if ( ! $reply || ! $post
				|| 'publish' !== $reply->status
				|| ! Permission_Engine::can_read_post( $viewer_id, $post )
				|| ! Permission_Engine::can_read_reply( $viewer_id, $reply, $post )
				|| Restriction::is_banned( (int) $reply->author_id )
			) {
				$visible[ $key ] = false;
			}
		}

		// Space targets (join_request / join_request_result) — the same 'read'
		// action can_read_post() checks at the space level, memoized per
		// (viewer, space) so repeats across this page cost nothing extra.
		foreach ( $space_keys as $key => $space_id ) {
			if ( ! Permission_Engine::can( $viewer_id, 'read', $space_id ) ) {
				$visible[ $key ] = false;
			}
		}

		return $visible;
	}
}
