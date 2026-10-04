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

use Jetonomy\Models\Notification;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

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
		// real DELETE (Post::delete() / Reply::delete()). A space purge deletes
		// in bulk and emits the same ('post'|'reply', id) signals itself, plus
		// ('space', space_id) once, from Space_Purge::purge_step().
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
	 * @param bool   $actor_anonymous The actor's content is anonymous: the host gets no actor at all.
	 * @return array<string,mixed>
	 */
	public static function payload( int $notification_id, int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message, string $url, bool $actor_anonymous = false ): array {
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

		$slug    = sanitize_key( $type );
		$grouped = '';
		$single  = '';
		$item_id = 0;

		// Replies to one topic collapse into one bell row: the row is about the
		// TOPIC (its status decides visibility, its link always exists). The host
		// keeps the first row's object and link when it merges, so the group key
		// and the object must describe the same thing. Own list and email are
		// untouched; a reply whose topic can't be resolved stays per reply.
		//
		// An anonymous reply is not grouped: a merged row names its newest actor,
		// and the host would count anonymous repliers as one person.
		if ( 'reply_to_post' === $slug && 'reply' === $object_type && $object_id > 0 && ! $actor_anonymous ) {
			$reply_id = $object_id;
			$reply    = Reply::find( $reply_id );
			$post     = $reply ? Post::find( (int) $reply->post_id ) : null;
			if ( $post ) {
				$topic_link  = \Jetonomy\notification_deep_link( 'post', (int) $post->id );
				$title       = mb_substr( (string) $post->title, 0, 50 );
				$object_type = 'post';
				$object_id   = (int) $post->id;
				$link        = '' !== $topic_link ? $topic_link : $link;
				$item_id     = $reply_id;
				$owner       = (int) $post->author_id === $user_id;
				$grouped     = $owner
					/* translators: 1: singular topic label, lowercase, 2: topic title. {actor} and {others} are placeholders filled by the host; keep them. */
					? sprintf( __( '{actor} and {others} replied to your %1$s "%2$s"', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), $title )
					/* translators: 1: post title. {actor} and {others} are placeholders filled by the host; keep them. */
					: sprintf( __( '{actor} and {others} replied in "%s"', 'jetonomy' ), $title );
				// The host words a row left with ONE visible person from this, so it
				// never names a member Jetonomy hides (a trashed or banned replier).
				$single = $owner
					/* translators: 1: singular topic label, lowercase, 2: topic title. {actor} is a placeholder filled by the host; keep it. */
					? sprintf( __( '{actor} replied to your %1$s "%2$s"', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), $title )
					/* translators: 1: post title. {actor} is a placeholder filled by the host; keep it. */
					: sprintf( __( '{actor} replied in "%s"', 'jetonomy' ), $title );
			}
		}

		$payload = array(
			'recipient_id'    => $user_id,
			'type'            => $slug,
			// The host names the member from this id, so an anonymous actor is 0
			// (the message already says "Anonymous"). The self-notify guard above
			// used the real id.
			'actor_id'        => $actor_anonymous ? 0 : $actor_id,
			'object_type'     => sanitize_key( $object_type ),
			'object_id'       => max( 0, $object_id ),
			'message'         => $message,
			'url'             => $link,
			// Per object, never per actor - matches the spec's own example
			// (`reply_to_post_1153`). Merges e.g. several join requests for the
			// same space into one row, and several replies to one topic.
			'group_key'       => $object_id > 0 ? $slug . '_' . $object_id : '',
			'notification_id' => $notification_id,
		);
		if ( '' !== $grouped ) {
			$payload['message_grouped'] = $grouped;
			$payload['message_single']  = $single;
			// The host keeps who did what: the topic is the row's object, the reply
			// is the item this event came from (its visibility is asked per item).
			$payload['item_type'] = 'reply';
			$payload['item_id']   = $item_id;
		}

		return $payload;
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
		if ( \Jetonomy\messaging_active() ) {
			$defs['message'] = array(
				'label'       => __( 'Private messages', 'jetonomy' ),
				'description' => __( 'Someone sent you a private message.', 'jetonomy' ),
			);
		}

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
	 * still see. The rule is not written here: it is Notification::targets_visible(),
	 * the PHP twin of the SQL every Jetonomy list already applies, so the host's
	 * bell and Jetonomy's own notifications page can never disagree.
	 *
	 * @param array<int|string,bool>                                                                            $visible   Every key starts true.
	 * @param int                                                                                               $viewer_id Recipient viewing their bell.
	 * @param array<int|string,array{type?:string,object_type?:string,object_id?:int,actor_id?:int,item?:bool}> $targets   Rows on this page; `item` = one event of a grouped host row.
	 * @return array<int|string,bool>
	 */
	public static function filter_visible( array $visible, int $viewer_id, array $targets ): array {
		foreach ( Notification::targets_visible( $viewer_id, $targets ) as $key => $ok ) {
			$visible[ $key ] = ( $visible[ $key ] ?? true ) && $ok;
		}
		return $visible;
	}
}
