<?php
/**
 * The state checks that decide whether content may be written at all.
 *
 * These are the gates that depend only on WHO is replying and WHAT they are
 * replying to - not on how the request arrived - so every writer shares them: REST,
 * the WP Abilities create-post and create-reply paths, and the inbound-email writer
 * (which reaches Reply::create() through `jetonomy_reply_from_email` and
 * Notifier::on_reply_from_email()). Without them a banned member, a reply to a CLOSED
 * post or a reply into an ARCHIVED space would succeed on the surfaces that skip them.
 *
 * Request-shaped concerns deliberately stay at the callers: rate limiting and
 * CAPTCHA are per-surface policy (the email path has its own rate limit keyed
 * differently, and a CAPTCHA is meaningless for a mail webhook). What lives
 * here is only what must be true on every surface.
 *
 * Named for CONTENT, not replies, deliberately: post creation needs the same gate, and
 * a gate named for one caller is a gate the next caller does not think to look for.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

class Content_Gate {

	/**
	 * Whether this user may add a reply to this post right now.
	 *
	 * Error codes match the ones REST_Replies_Controller already returned, so
	 * routing that controller through here does not change a single response
	 * it was already producing.
	 *
	 * @param int    $user_id   Author.
	 * @param object $post      Post row being replied to.
	 * @param int    $parent_id Reply being answered in a thread, 0 for top level.
	 * @return true|\WP_Error True when the reply may proceed.
	 */
	public static function check( int $user_id, object $post, int $parent_id = 0 ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'jetonomy_not_logged_in',
				// jt-terms-ok: "reply" is the verb here, not the noun.
				__( 'You must be logged in to reply.', 'jetonomy' ),
				array( 'status' => 401 )
			);
		}

		/*
		 * Only a published topic takes replies. A trashed one would otherwise keep accepting
		 * them on every surface - REST, the ability, emailed replies - letting its author keep
		 * a thread nobody else can see alive. Drafts and scheduled topics (drafts with a
		 * published_at) are refused too: there is no thread yet.
		 */
		$live = self::status_error( $post );
		if ( $live ) {
			return $live;
		}

		if ( $parent_id > 0 ) {
			$live = self::target_is_live( 'reply', $parent_id );
			if ( is_wp_error( $live ) ) {
				return $live;
			}
		}

		$space_id = (int) ( $post->space_id ?? 0 );

		$restricted = Permission_Engine::restriction_error( $user_id, 'create_replies', $space_id );
		if ( $restricted ) {
			return $restricted;
		}

		$open = self::space_accepts_content( $space_id );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		/*
		 * Closing a topic is a member-facing control, not a staff one: a
		 * moderator still needs to post the ruling that explains the close.
		 * single-post.php has always promised exactly that - it renders the
		 * composer plus a "As a moderator, you can still add a reply" notice -
		 * while this gate rejected everyone, so the UI offered a reply the
		 * server refused with a 403.
		 *
		 * The permission slug must stay `moderate`, the same one the template
		 * tests, or the display/enforce split just moves rather than closes.
		 */
		if ( ! empty( $post->is_closed ) && ! Permission_Engine::can( $user_id, 'moderate', $space_id ) ) {
			return self::closed_error();
		}

		/*
		 * Reaching a post by emailed token is not the same as being allowed to
		 * read it: a member can be removed from a private space and still hold
		 * an old notification. The token proves identity, not entitlement.
		 */
		if ( $space_id && ! Permission_Engine::can( $user_id, 'create_replies', $space_id ) ) {
			return self::cannot_post_error();
		}

		return true;
	}

	/**
	 * Whether this user may start a topic in this space right now: check()'s twin
	 * for posts, shared by REST, the WP Abilities path and WP-CLI.
	 *
	 * @param int $user_id  Author.
	 * @param int $space_id Space.
	 * @return true|\WP_Error True when the topic may proceed.
	 */
	public static function check_post( int $user_id, int $space_id ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error( 'jetonomy_not_logged_in', __( 'You must be logged in to post.', 'jetonomy' ), array( 'status' => 401 ) );
		}

		$restricted = Permission_Engine::restriction_error( $user_id, 'create_posts', $space_id );
		if ( $restricted ) {
			return $restricted;
		}

		// Permission before the space state, so a space this member may not post in
		// (a hidden one included) reveals nothing about its status.
		if ( ! Permission_Engine::can( $user_id, 'create_posts', $space_id ) ) {
			return self::cannot_post_error();
		}

		$open = self::space_accepts_content( $space_id );
		return is_wp_error( $open ) ? $open : true;
	}

	/**
	 * Whether this user may cast this vote on a post or reply: live and readable
	 * target, the vote permission in its space, and the self-vote rules. Shared by
	 * REST and WP-CLI. Withdrawing a vote does not come through here.
	 *
	 * @param int    $user_id Voter.
	 * @param string $type    'post' or 'reply'.
	 * @param int    $id      Object ID.
	 * @param int    $value   1 or -1.
	 * @return true|\WP_Error
	 */
	public static function check_vote( int $user_id, string $type, int $id, int $value ) {
		$live = self::target_is_live( $type, $id, $user_id );
		if ( is_wp_error( $live ) ) {
			return $live;
		}

		$object   = 'reply' === $type ? Reply::find( $id ) : Post::find( $id );
		$post     = 'reply' === $type && $object ? Post::find( (int) $object->post_id ) : $object;
		$space_id = (int) ( $post->space_id ?? 0 );

		if ( ! Permission_Engine::can( $user_id, 'vote', $space_id ) ) {
			return Permission_Engine::restriction_error( $user_id, 'vote', $space_id ) ?? \Jetonomy\API\REST_Auth::forbidden();
		}

		if ( ! in_array( $value, array( 1, -1 ), true ) ) {
			return new \WP_Error( 'jetonomy_validation', __( 'Vote value must be 1 or -1.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		$is_self = $object && (int) ( $object->author_id ?? 0 ) === $user_id;

		// A self-downvote drives the author's own score negative, against "I'm posting my view".
		if ( -1 === $value && $is_self ) {
			return new \WP_Error( 'jetonomy_validation', __( 'You cannot downvote your own content.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		/**
		 * Whether a member may upvote their own content.
		 *
		 * @param bool   $allowed Default true.
		 * @param int    $user_id Voting user.
		 * @param string $type    'post' or 'reply'.
		 * @param int    $id      Object ID.
		 */
		if ( 1 === $value && $is_self
			&& ! apply_filters( 'jetonomy_allow_self_upvote', true, $user_id, $type, $id ) ) {
			return new \WP_Error( 'jetonomy_validation', __( 'You cannot upvote your own content.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		return true;
	}

	/**
	 * The refusal for a member the space does not let post.
	 */
	private static function cannot_post_error(): \WP_Error {
		return new \WP_Error(
			'jetonomy_forbidden',
			sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'You cannot post in this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether a space is in a state that accepts new content at all.
	 *
	 * Split out of check() because POST creation needs it too and has no post
	 * object to hand - the WP Abilities create-post path had no space-status
	 * check and could start a topic in an archived space, which REST refuses.
	 *
	 * @param int $space_id Space ID.
	 * @return true|\WP_Error True when the space accepts content.
	 */
	public static function space_accepts_content( int $space_id ) {
		if ( $space_id <= 0 ) {
			return true;
		}

		$space = Space::find( $space_id );
		if ( $space && in_array( $space->status ?? '', array( 'archived', 'locked' ), true ) ) {
			return new \WP_Error(
				'jetonomy_space_restricted',
				sprintf( /* translators: %s: the singular Space label, lowercase. */ __( 'This %s is archived or locked and no longer accepts new content.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Whether a post or reply is published, so it may take member writes:
	 * votes, reactions, bookmarks, follows, accepted answers, pins.
	 *
	 * A reply is live only when its topic is too - voting on a reply inside a
	 * trashed topic is a write to the trashed topic. A missing target returns
	 * true: the caller already owns its 404.
	 *
	 * Pass $viewer from any route that has not already checked read access. A
	 * target that viewer cannot read, or that does not exist, then answers the
	 * same 404, so the trashed / not published answer never tells a member
	 * anything about a topic they are not allowed to see.
	 *
	 * Removals (unvote, unbookmark, unfollow) deliberately do not call this,
	 * so a member can still undo what they did before the item was trashed.
	 *
	 * @param string   $type   'post' or 'reply'.
	 * @param int      $id     Object ID.
	 * @param int|null $viewer Member to check read access for, or null when the caller already did.
	 * @return true|\WP_Error 404 when the viewer may not read it, 409 when it is trashed or unpublished.
	 */
	public static function target_is_live( string $type, int $id, ?int $viewer = null ) {
		$reply = 'reply' === $type ? Reply::find( $id ) : null;
		$post  = Post::find( $reply ? (int) $reply->post_id : $id );

		if ( null !== $viewer && ! self::viewer_may_read( $viewer, $post, $reply, $type ) ) {
			return new \WP_Error(
				'jetonomy_not_found',
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' === $type ? 'reply' : 'topic' ) ),
				array( 'status' => 404 )
			);
		}

		if ( 'reply' === $type ) {
			if ( ! $reply ) {
				return true;
			}
			$error = self::status_error( $reply );
			if ( $error ) {
				return $error;
			}
		}

		return ( $post ? self::status_error( $post ) : null ) ?? true;
	}

	/**
	 * Whether the viewer could read the target were it published: the status is
	 * left out on purpose, target_is_live() answers that part with its 409.
	 *
	 * @param int         $viewer Member.
	 * @param object|null $post   Topic row.
	 * @param object|null $reply  Reply row, for a reply target.
	 * @param string      $type   'post' or 'reply'.
	 */
	private static function viewer_may_read( int $viewer, ?object $post, ?object $reply, string $type ): bool {
		if ( ! $post || ( 'reply' === $type && ! $reply ) ) {
			return false;
		}
		$as_published = (object) array_merge( (array) $post, array( 'status' => 'publish' ) );
		if ( ! Permission_Engine::can_read_post( $viewer, $as_published ) ) {
			return false;
		}
		return ! $reply || Permission_Engine::can_read_reply( $viewer, $reply, $post );
	}


	/**
	 * The one "this item is in the trash" error, shared with
	 * Base_Controller::already_trashed_error() so the REST edit/trash paths
	 * and the write gates answer with the same code.
	 */
	public static function trashed_error(): \WP_Error {
		return new \WP_Error(
			'jetonomy_already_trashed',
			__( 'This item is already in the trash. A moderator can restore it or delete it permanently.', 'jetonomy' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * 409 for a row that is not published, null when it is.
	 *
	 * @param object $row Post or reply row.
	 */
	private static function status_error( object $row ): ?\WP_Error {
		$status = (string) ( $row->status ?? 'publish' );
		if ( 'publish' === $status ) {
			return null;
		}
		if ( 'trash' === $status ) {
			return self::trashed_error();
		}
		return new \WP_Error(
			'jetonomy_not_published',
			__( 'This content is not published, so it cannot be replied to, voted on or reacted to.', 'jetonomy' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a reply to a closed topic. The topic page shows the same
	 * message, so the notice and the server never disagree.
	 *
	 * @return \WP_Error
	 */
	public static function closed_error(): \WP_Error {
		return new \WP_Error(
			'jetonomy_post_closed',
			/* translators: 1: singular topic label; 2: plural reply label. */
			sprintf( __( 'This %1$s is closed and cannot receive new %2$s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
			array( 'status' => 403 )
		);
	}
}
