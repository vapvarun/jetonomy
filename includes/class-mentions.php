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
	 * Regex matching an @mention, for BOTH linking and notifying.
	 *
	 * One pattern on purpose. There used to be two - a strict LINK_PATTERN and
	 * a looser NOTIFY_PATTERN - and they disagreed, so a login containing a dot
	 * was notified but rendered a link to the wrong user: `@john.smith`
	 * notified `john.smith` while the link resolved `john` (Basecamp
	 * 10212719567). Splitting them was meant to protect dotted logins, but only
	 * half the pipeline learned about dots.
	 *
	 * The lookbehind keeps an email address or a URL from reading as a mention -
	 * `contact@example.com` and `tiktok.com/@someone` must match neither. The
	 * old NOTIFY_PATTERN lacked it and fired a user lookup for every email in a
	 * post.
	 *
	 * Dots are allowed only BETWEEN segments, never trailing, so
	 * "thanks @john.smith." captures `john.smith` rather than an unresolvable
	 * `john.smith.`. WordPress permits dots in user_login even under
	 * sanitize_user( $login, true ), so this is a real account shape, commonly
	 * seen where logins are seeded from email addresses.
	 */
	private const MENTION_PATTERN = '/(?<![\w\/.:-])@([a-zA-Z0-9_-]+)/u';

	/**
	 * Resolve a list of @handles to user IDs, batched.
	 *
	 * A handle is `user_nicename`, NOT `user_login`. That is the contract the
	 * Wbcom family shares: BuddyNext's Profile\Handle documents it as "the
	 * string members type after an `@`... WordPress's user_nicename, the field
	 * core designates as a user's public slug", and names Jetonomy as one of the
	 * partners that must agree. Resolving on login alone meant a handle typed in
	 * BuddyNext did not resolve here whenever the two differ - the imported-user
	 * case: a login of `john.smith` gets the nicename `john-smith`.
	 *
	 * It also retires the dotted-handle problem rather than working around it.
	 * A nicename is sanitize_title() output and cannot contain a dot, so the
	 * pattern needs no special case and an email address can never read as a
	 * mention.
	 *
	 * Resolution order, deliberately mirroring BuddyNext's Handle::resolve() so
	 * the two agree on the same install - the pair ships together on most sites:
	 *
	 *   1. `jetonomy_resolve_mention_handles` filter - a partner's own handle
	 *      vocabulary. BuddyNext's custom slug (`bn_profile_slug`) and its
	 *      reserved `user-{id}` form both live here rather than being hardcoded,
	 *      because free Jetonomy must not depend on a plugin that may be absent.
	 *   2. user_nicename - the shared contract.
	 *   3. user_login - last resort, for content written before 1.9.3 when login
	 *      WAS the handle. BuddyNext keeps the same fallback for the same reason.
	 *
	 * Each step is one query over whatever is still unresolved, so a body full
	 * of mentions costs a fixed handful of queries rather than one per mention,
	 * and the common case (all nicenames) never runs the login query at all.
	 *
	 * @param string[] $handles Raw @handles (no leading `@`), may repeat.
	 * @return array<string,int> Map of lowercased handle => user ID. Handles
	 *                           that don't resolve to a real user are absent.
	 */
	private static function resolve_handles( array $handles ): array {
		$handles = array_values( array_unique( array_filter( $handles ) ) );
		if ( empty( $handles ) ) {
			return [];
		}

		$map = [];

		/**
		 * Resolve @handles to user IDs before Jetonomy's own lookup.
		 *
		 * The escape hatch for a partner that owns a handle Jetonomy cannot see.
		 * BuddyNext lets a member choose a custom slug (`bn_profile_slug`
		 * usermeta) which takes precedence over their nicename everywhere it
		 * renders, so without this that member is `@theirslug` in BuddyNext and
		 * unresolvable here. Jetonomy deliberately does NOT read that meta key
		 * itself - free must not depend on a plugin that may not be installed -
		 * so the BuddyNext bridge hooks this instead.
		 *
		 * Return a map of LOWERCASED handle => user ID. Handles already present
		 * are left alone; anything still unresolved falls through to nicename
		 * and then login below.
		 *
		 * @since 1.9.3
		 *
		 * @param array<string,int> $map     Handle => user ID, empty to start.
		 * @param string[]          $handles Raw handles found in the content.
		 */
		$filtered = apply_filters( 'jetonomy_resolve_mention_handles', $map, $handles );
		if ( is_array( $filtered ) ) {
			foreach ( $filtered as $handle => $user_id ) {
				$user_id = (int) $user_id;
				if ( $user_id > 0 && is_string( $handle ) && '' !== $handle ) {
					$map[ strtolower( $handle ) ] = $user_id;
				}
			}
		}

		$map = self::resolve_by_field( $handles, $map, 'nicename__in', 'user_nicename' );

		// Last resort: user_login. Jetonomy resolved mentions by login until
		// 1.9.3, so years of existing content spells handles that way. Dropping
		// it outright would silently stop notifying on every one of those posts.
		// Mirrors BuddyNext's own step 4 - never reached for a handle any
		// current surface displays, since the typeahead inserts the nicename.
		$map = self::resolve_by_field( $handles, $map, 'login__in', 'user_login' );

		return $map;
	}

	/**
	 * Resolve whatever handles are still unmapped against one user field.
	 *
	 * One WP_User_Query per field, never one per handle - the whole point of
	 * batching here. Returns early when nothing is left to look up, so the
	 * common case (every handle already resolved by nicename) costs no extra
	 * query at all.
	 *
	 * `blog_id => 0` keeps the lookup network-wide.
	 *
	 * `fields => all_with_meta` primes the user cache, so the get_userdata()
	 * inside get_profile_url() is served from cache instead of costing a query
	 * per resolved user. Measured on a 40-mention body: 17 queries with a lean
	 * select vs 3 with this one, and 3 stays flat as the mention count grows.
	 *
	 * @param string[]          $handles   Every handle found in the content.
	 * @param array<string,int> $map       Resolutions so far, keyed lowercased.
	 * @param string            $query_arg WP_User_Query arg: nicename__in|login__in.
	 * @param string            $field     User property to key the result on.
	 * @return array<string,int> The map, with any new resolutions merged in.
	 */
	private static function resolve_by_field( array $handles, array $map, string $query_arg, string $field ): array {
		$pending = [];
		foreach ( $handles as $handle ) {
			if ( ! isset( $map[ strtolower( $handle ) ] ) ) {
				$pending[] = $handle;
			}
		}

		if ( empty( $pending ) ) {
			return $map;
		}

		// Explicit branches rather than a dynamic key: WP_User_Query's arg shape
		// is typed, and a variable key defeats static analysis of it.
		$args = [
			'fields'      => 'all_with_meta',
			'number'      => count( $pending ),
			'blog_id'     => 0,
			'count_total' => false,
		];
		if ( 'login__in' === $query_arg ) {
			$args['login__in'] = $pending;
		} else {
			$args['nicename__in'] = $pending;
		}

		$query = new \WP_User_Query( $args );

		foreach ( $query->get_results() as $user ) {
			$key = strtolower( (string) $user->{$field} );
			if ( '' !== $key && ! isset( $map[ $key ] ) ) {
				$map[ $key ] = (int) $user->ID;
			}
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
		if ( ! preg_match_all( self::MENTION_PATTERN, $content, $matches ) || empty( $matches[1] ) ) {
			return [];
		}

		$urls = [];
		foreach ( self::resolve_handles( $matches[1] ) as $handle => $user_id ) {
			$urls[ $handle ] = get_profile_url( $user_id );
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
	 * @param string               $text Text segment (no HTML tags).
	 * @param array<string,string> $urls Map from link_map().
	 * @return string
	 */
	public static function linkify( string $text, array $urls ): string {
		if ( empty( $urls ) ) {
			return $text;
		}

		return preg_replace_callback(
			self::MENTION_PATTERN,
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
		preg_match_all( self::MENTION_PATTERN, $content, $matches );
		if ( empty( $matches[1] ) ) {
			return [];
		}

		return array_values( self::resolve_handles( $matches[1] ) );
	}

	/**
	 * Notify mentioned users.
	 */
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title, ?int $space_id = null, bool $is_private = false ): void {
		// Global veto (documented in Notification::create()) — bail before the
		// membership/visibility queries and the per-user loop: during an import
		// run every row would be vetoed anyway, and the parallel email path in
		// this method must not fire either.
		if ( ! apply_filters( 'jetonomy_notification_should_send', true ) ) {
			return;
		}

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
				$staff    = array_keys( Models\SpaceMember::roles_for_users( $space_id, $user_ids ) );
				$allowed  = array_merge( $staff, [ $actor_id ] ); // actor filtered out below anyway.
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

			// Routed through the shared emitter so the push gate applies here too
			// (1.8.0). This path fired the hook raw, so @mentioning someone who
			// had blocked you put your words on their phone.
			\Jetonomy\Notifications\Notifier::emit_notification_created( $notification_id, $uid, $actor_id, 'mention', $object_type, $object_id, $message, $content_url );

			// Check email preference via the shared gate (master kill-switch +
			// per-user per-type + admin default). $user_prefs already loaded.
			$profile    = Models\UserProfile::find_by_user( $uid );
			$settings   = $profile ? json_decode( $profile->settings ?? '{}', true ) : [];
			$user_prefs = $settings['notifications'] ?? [];

			// Block gate — Notifier::create_and_maybe_email() has had one since
			// 1.7.1, but this second, parallel email path never got it, so a
			// mention email from a blocked user still landed in the blocker's
			// inbox. Same predicate, one implementation (1.8.0).
			if ( ! \Jetonomy\Notifications\Notifier::recipient_blocked_actor( $uid, $actor_id )
				&& \Jetonomy\Notifications\Notifier::should_email( $uid, 'mention', $user_prefs ) ) {
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
