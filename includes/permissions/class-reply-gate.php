<?php
/**
 * The state checks that decide whether a reply may be written at all.
 *
 * These are the gates that depend only on WHO is replying and WHAT they are
 * replying to - not on how the request arrived. They were previously written
 * out inside REST_Replies_Controller::create_item() and nowhere else, so the
 * inbound-email writer, which reaches Reply::create() through
 * `jetonomy_reply_from_email` and Notifier::on_reply_from_email(), had none of
 * them.
 *
 * Reproduced before this existed (Basecamp 10228771444), each request carrying
 * a VALID webhook signature so nothing was hiding behind the auth fix:
 *
 *   banned member replies          HTTP 200  reply created
 *   reply to a CLOSED post         HTTP 200  reply created
 *   reply into an ARCHIVED space   HTTP 200  reply created
 *
 * A banned member kept a working write path into the community for as long as
 * they held any notification email, and closing a thread or archiving a space
 * did not stop email replies landing in it.
 *
 * Request-shaped concerns deliberately stay at the callers: rate limiting and
 * CAPTCHA are per-surface policy (the email path has its own rate limit keyed
 * differently, and a CAPTCHA is meaningless for a mail webhook). What lives
 * here is only what must be true on every surface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Permissions;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;

class Reply_Gate {

	/**
	 * Whether this user may add a reply to this post right now.
	 *
	 * Error codes match the ones REST_Replies_Controller already returned, so
	 * routing that controller through here does not change a single response
	 * it was already producing.
	 *
	 * @param int    $user_id Author.
	 * @param object $post    Post row being replied to.
	 * @return true|\WP_Error True when the reply may proceed.
	 */
	public static function check( int $user_id, object $post ) {
		if ( $user_id <= 0 ) {
			return new \WP_Error(
				'jetonomy_not_logged_in',
				__( 'You must be logged in to reply.', 'jetonomy' ),
				array( 'status' => 401 )
			);
		}

		if ( Restriction::is_banned( $user_id ) ) {
			return new \WP_Error(
				'jetonomy_user_banned',
				__( 'Your account has been banned from this community.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		if ( Restriction::is_silenced( $user_id ) ) {
			return new \WP_Error(
				'jetonomy_user_silenced',
				__( 'Your account is currently silenced and cannot post.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		$space_id = (int) ( $post->space_id ?? 0 );
		$space    = $space_id ? Space::find( $space_id ) : null;
		if ( $space && in_array( $space->status ?? '', array( 'archived', 'locked' ), true ) ) {
			return new \WP_Error(
				'jetonomy_space_restricted',
				__( 'This space is archived or locked and no longer accepts new replies.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		if ( ! empty( $post->is_closed ) ) {
			return new \WP_Error(
				'jetonomy_post_closed',
				__( 'This post is closed and cannot receive new replies.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		/*
		 * Reaching a post by emailed token is not the same as being allowed to
		 * read it: a member can be removed from a private space and still hold
		 * an old notification. The token proves identity, not entitlement.
		 */
		if ( $space_id && ! Permission_Engine::can( $user_id, 'create_replies', $space_id ) ) {
			return new \WP_Error(
				'jetonomy_forbidden',
				__( 'You cannot post in this space.', 'jetonomy' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
