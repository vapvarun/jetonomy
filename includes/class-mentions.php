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
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title ): void {
		$actor      = get_userdata( $actor_id );
		$actor_name = $actor ? $actor->display_name : __( 'Someone', 'jetonomy' );

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

			// Check user email preference for mentions.
			$profile    = Models\UserProfile::find_by_user( $uid );
			$settings   = $profile ? json_decode( $profile->settings ?? '{}', true ) : [];
			$user_prefs = $settings['notifications'] ?? [];

			if ( isset( $user_prefs['mention']['email'] ) ) {
				$send_email = ! empty( $user_prefs['mention']['email'] );
			} else {
				$global     = get_option( 'jetonomy_settings', [] )['notification_defaults'] ?? [];
				$send_email = ! empty( $global['mention']['email'] );
			}

			if ( $send_email ) {
				$email_adapter = Adapters\Adapter_Registry::get_email();
				if ( $email_adapter ) {
					$user = get_userdata( $uid );
					if ( $user && $user->user_email ) {
						$site_name = get_bloginfo( 'name' );
						$subject   = sprintf( '[%s] %s', $site_name, wp_strip_all_tags( $message ) );

						// Build List-Unsubscribe headers (RFC 8058).
						$unsub_token = wp_hash( $uid . ':mention:unsubscribe' );
						$unsub_url   = add_query_arg(
							[
								'jetonomy_unsubscribe' => $unsub_token,
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
