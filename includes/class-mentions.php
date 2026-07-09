<?php
/**
 * Mention parser.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Mentions {

	/**
	 * Parse content for @username mentions.
	 * Returns content with mentions wrapped in profile links.
	 */
	public static function parse( string $content ): string {
		return preg_replace_callback(
			'/@([a-zA-Z0-9_\-\.]+)/',
			function ( $matches ) {
				$username = $matches[1];
				$user     = get_user_by( 'login', $username );
				if ( ! $user ) {
					return $matches[0]; // Not a valid user, leave as-is
				}

				$url = get_profile_url( $user->ID );
				return '<a href="' . esc_url( $url ) . '" class="jt-mention">@' . esc_html( $username ) . '</a>';
			},
			$content
		);
	}

	/**
	 * Extract mentioned user IDs from content.
	 */
	public static function extract_user_ids( string $content ): array {
		preg_match_all( '/@([a-zA-Z0-9_\-\.]+)/', $content, $matches );
		if ( empty( $matches[1] ) ) {
			return [];
		}

		$ids = [];
		foreach ( array_unique( $matches[1] ) as $username ) {
			$user = get_user_by( 'login', $username );
			if ( $user ) {
				$ids[] = (int) $user->ID;
			}
		}
		return $ids;
	}

	/**
	 * Notify mentioned users.
	 */
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title, ?int $space_id = null, bool $is_private = false ): void {
		$object     = 'reply' === $object_type
			? Models\Reply::find( $object_id )
			: Models\Post::find( $object_id );
		$actor_name = \Jetonomy\Author::for_display( $actor_id, $object )['name'] ?: __( 'Someone', 'jetonomy' );

		// Visibility filter: never notify a user who can't read the mentioned
		// content. Done ONCE, set-based, before the loop — no per-recipient
		// permission check (that would be an N+1 at scale). A public space needs
		// no filtering (everyone can read); a private/hidden space is gated to
		// its members; an is_private post is gated to author + space staff.
		if ( $space_id && ! empty( $user_ids ) ) {
			$space = Models\Space::find( $space_id );
			if ( $space && in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
				$members  = Models\SpaceMember::members_among( $space_id, $user_ids );
				$user_ids = array_values( array_intersect( $user_ids, $members ) );
			}
			if ( $is_private && ! empty( $user_ids ) ) {
				// is_private post: only the author + space admins/moderators.
				$staff   = array_keys( Models\SpaceMember::roles_for_users( $space_id, $user_ids ) );
				$allowed = array_merge( $staff, [ $actor_id ] ); // actor filtered out below anyway.
				$user_ids = array_values( array_intersect( $user_ids, $allowed ) );
			}
		}

		foreach ( $user_ids as $uid ) {
			if ( $uid === $actor_id ) {
				continue; // Don't notify yourself
			}

			$message = sprintf(
				/* translators: 1: actor display name, 2: post/reply title */
				__( '%1$s mentioned you in "%2$s"', 'jetonomy' ),
				$actor_name,
				mb_substr( $context_title, 0, 50 )
			);

			// Resolve the deep link once — reused for the action payload and the email CTA.
			$content_url = notification_deep_link( $object_type, $object_id );

			$notification_id = Models\Notification::create(
				[
					'user_id'     => $uid,
					'actor_id'    => $actor_id,
					'type'        => 'mention',
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'message'     => $message,
					'created_at'  => now(),
				]
			);

			do_action( 'jetonomy_notification_created', $notification_id, $uid, 'mention', $object_type, $object_id, $message, $content_url );

			// Check email preference via the shared gate (master kill-switch +
			// per-user per-type + admin default). $user_prefs already loaded.
			$profile    = Models\UserProfile::find_by_user( $uid );
			$settings   = $profile ? json_decode( $profile->settings ?? '{}', true ) : [];
			$user_prefs = $settings['notifications'] ?? [];

			if ( \Jetonomy\Notifications\Notifier::should_email( $uid, 'mention', $user_prefs ) ) {
				$email_adapter = Adapters\Adapter_Registry::get_email();
				if ( $email_adapter ) {
					$user = get_userdata( $uid );
					if ( $user && $user->user_email ) {
						$site_name = get_bloginfo( 'name' );
						$subject   = sprintf( '[%s] %s', $site_name, wp_strip_all_tags( $message ) );

						// Build List-Unsubscribe headers (RFC 8058) with a signed,
						// time-limited unsubscribe token.
						$unsub_exp   = \Jetonomy\Notifications\Notifier::unsubscribe_expiry();
						$unsub_token = \Jetonomy\Notifications\Notifier::unsubscribe_token( $uid, 'mention', $unsub_exp );
						$unsub_url   = add_query_arg(
							[
								'jetonomy_unsubscribe' => $unsub_token,
								'jetonomy_unsub_exp'   => $unsub_exp,
								'uid'                  => $uid,
								'type'                 => 'mention',
							],
							home_url( '/' )
						);
						$headers     = [
							'List-Unsubscribe: <' . $unsub_url . '>',
							'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
						];

						// $content_url already resolved above via notification_deep_link().
						$html = Notifications\Notifier::render_email_template( 'mention', $message, $user, $unsub_url, $content_url );
						$email_adapter->send( $user->user_email, $subject, $html, wp_strip_all_tags( $message ), $headers );
					}
				}
			}
		}
	}
}
