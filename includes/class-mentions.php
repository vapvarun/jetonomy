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
	 * Regex matching a linkifiable @mention in rendered content.
	 *
	 * The negative lookbehind prevents matching inside URL paths like
	 * `tiktok.com/@username/video/...` — a `/`, `.`, `:`, `-`, or word
	 * character immediately before the `@` blocks the match.
	 */
	private const LINK_PATTERN = '/(?<![\w\/.:-])@([a-zA-Z0-9_-]+)/u';

	/**
	 * Regex matching an @mention for notification purposes.
	 *
	 * Deliberately looser than LINK_PATTERN (it accepts `.` inside the login)
	 * so logins containing a dot still notify. Kept separate on purpose:
	 * tightening it would silently stop notifying those users.
	 */
	private const NOTIFY_PATTERN = '/@([a-zA-Z0-9_\-\.]+)/';

	/**
	 * Resolve a list of @logins to user IDs in ONE query.
	 *
	 * The whole point of this helper: mention rendering used to be either
	 * per-mention `get_user_by()` (an N+1 on a busy topic) or no validation at
	 * all. One WP_User_Query with `login__in` resolves every login in the
	 * render pass, regardless of how many mentions the content carries.
	 *
	 * `blog_id => 0` keeps the lookup network-wide, matching the
	 * `get_user_by( 'login', ... )` semantics this replaces.
	 *
	 * `fields => all_with_meta` is deliberate: it primes the user cache, so the
	 * `get_userdata()` inside get_profile_url() is served from cache instead of
	 * costing a query per resolved user. Measured on a 40-mention body: 17
	 * queries with a lean `[ID, user_login]` select vs 3 with this one, and 3
	 * stays flat as the mention count grows.
	 *
	 * @param string[] $logins Raw @logins (no leading `@`), may repeat.
	 * @return array<string,int> Map of lowercased login => user ID. Logins that
	 *                           don't resolve to a real user are absent.
	 */
	private static function resolve_logins( array $logins ): array {
		$logins = array_values( array_unique( array_filter( $logins ) ) );
		if ( empty( $logins ) ) {
			return [];
		}

		$query = new \WP_User_Query(
			[
				'login__in'   => $logins,
				'fields'      => 'all_with_meta',
				'number'      => count( $logins ),
				'blog_id'     => 0,
				'count_total' => false,
			]
		);

		$map = [];
		foreach ( $query->get_results() as $user ) {
			$map[ strtolower( $user->user_login ) ] = (int) $user->ID;
		}
		return $map;
	}

	/**
	 * Build a login => profile-URL map for every valid @mention in $content.
	 *
	 * Call once per render pass, then hand the map to linkify() for each text
	 * segment. URLs come from get_profile_url(), so the `jetonomy_profile_url`
	 * filter is honoured — third-party profile systems (BuddyPress, BuddyBoss,
	 * Ultimate Member) get their URLs into mention links like everywhere else.
	 *
	 * @param string $content Raw content about to be rendered.
	 * @return array<string,string> Map of lowercased login => profile URL.
	 */
	public static function link_map( string $content ): array {
		if ( ! preg_match_all( self::LINK_PATTERN, $content, $matches ) || empty( $matches[1] ) ) {
			return [];
		}

		$urls = [];
		foreach ( self::resolve_logins( $matches[1] ) as $login => $user_id ) {
			$urls[ $login ] = get_profile_url( $user_id );
		}
		return $urls;
	}

	/**
	 * Wrap every valid @mention in $text with a profile link.
	 *
	 * The single mention-linkifying implementation in the plugin. A mention
	 * that doesn't resolve to a real user is left as plain text — an @word that
	 * isn't a member should not render as a broken profile link.
	 *
	 * @param string                $text Text segment (no HTML tags).
	 * @param array<string,string> $urls Map from link_map().
	 * @return string
	 */
	public static function linkify( string $text, array $urls ): string {
		if ( empty( $urls ) ) {
			return $text;
		}

		return preg_replace_callback(
			self::LINK_PATTERN,
			function ( $matches ) use ( $urls ) {
				$username = $matches[1];
				$url      = $urls[ strtolower( $username ) ] ?? '';
				if ( '' === $url ) {
					return $matches[0]; // Not a real user — leave as typed.
				}
				return '<a href="' . esc_url( $url ) . '" class="jt-mention">@' . esc_html( $username ) . '</a>';
			},
			$text
		);
	}

	/**
	 * Extract mentioned user IDs from content.
	 */
	public static function extract_user_ids( string $content ): array {
		preg_match_all( self::NOTIFY_PATTERN, $content, $matches );
		if ( empty( $matches[1] ) ) {
			return [];
		}

		return array_values( self::resolve_logins( $matches[1] ) );
	}

	/**
	 * Notify mentioned users.
	 */
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title, ?int $space_id = null, bool $is_private = false ): void {
		$object          = 'reply' === $object_type
			? Models\Reply::find( $object_id )
			: Models\Post::find( $object_id );
		$actor_name      = \Jetonomy\Author::for_display( $actor_id, $object )['name'] ?: __( 'Someone', 'jetonomy' );
		$actor_anonymous = (bool) ( $object->is_anonymous ?? false );

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
					'user_id'         => $uid,
					'actor_id'        => $actor_id,
					'actor_anonymous' => $actor_anonymous ? 1 : 0,
					'type'            => 'mention',
					'object_type'     => $object_type,
					'object_id'       => $object_id,
					'message'         => $message,
					'created_at'      => now(),
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
