<?php
/**
 * Global helper functions.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

function table( string $name ): string {
	global $wpdb;
	return $wpdb->prefix . 'jt_' . $name;
}

/**
 * Get the community base URL (e.g. http://forums.local/discussion).
 *
 * Reads the `base_slug` from jetonomy_settings (default: 'community').
 * Every template and PHP class should call this instead of hardcoding /community/.
 *
 * @return string Base URL without trailing slash.
 */
function base_url(): string {
	$settings  = get_option( 'jetonomy_settings', [] );
	$base_slug = $settings['base_slug'] ?? 'community';
	return home_url( '/' . $base_slug );
}

function now(): string {
	return current_time( 'mysql', true );
}

/**
 * Whether the private-messaging (DM) feature is available on this request.
 *
 * The Pro private-messaging extension registers its `/messages/` route via the
 * `jetonomy_template_map` filter only when the extension is enabled AND licensed
 * (its boot() ran). Gate every Messages / DM link on this so it never points at a
 * 404 when Pro is installed but messaging is off. Result is cached per request.
 *
 * @return bool True when the messages route is registered.
 */
function messaging_active(): bool {
	static $active = null;
	if ( null === $active ) {
		$active = array_key_exists( 'messages', (array) apply_filters( 'jetonomy_template_map', array() ) );
	}
	return (bool) $active;
}

/**
 * Resolve the requesting client's IP, honouring a trusted reverse-proxy chain.
 *
 * Single source of truth for "who is this request from" — used by IP-bans and
 * rate limiting. Defaults to REMOTE_ADDR, the only value an attacker cannot
 * forge. X-Forwarded-For is honoured ONLY when the request demonstrably arrived
 * through a proxy the site owner declared via `jetonomy_trusted_proxies`;
 * otherwise the header is attacker-controlled and trusting it would let anyone
 * spoof their IP to dodge a ban or reset a rate-limit bucket. Behind a CDN /
 * reverse proxy the owner adds the edge IP(s) to the filter and bans/limits then
 * see the real visitor. `jetonomy_client_ip` allows a full override.
 *
 * @return string Client IP (may be empty if REMOTE_ADDR is unset, e.g. CLI).
 */
function client_ip(): string {
	$remote = isset( $_SERVER['REMOTE_ADDR'] )
		? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) )
		: '';

	$ip = $remote;

	/**
	 * Trusted reverse-proxy / CDN addresses (exact REMOTE_ADDR match). Empty by
	 * default so X-Forwarded-For is ignored unless the request really came from
	 * a declared proxy.
	 *
	 * @param string[] $proxies Trusted proxy IPs.
	 */
	$trusted = (array) apply_filters( 'jetonomy_trusted_proxies', array() );

	if ( '' !== $remote && ! empty( $trusted ) && in_array( $remote, $trusted, true ) ) {
		$xff = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
			: '';
		if ( '' !== $xff ) {
			// XFF is "client, proxy1, proxy2…". Walk right-to-left, skip our own
			// trusted proxies, and take the first remaining address — the real
			// client as seen at our edge. A spoofed left-hand entry can't promote
			// itself past a trusted hop.
			foreach ( array_reverse( array_map( 'trim', explode( ',', $xff ) ) ) as $candidate ) {
				if ( '' === $candidate || in_array( $candidate, $trusted, true ) ) {
					continue;
				}
				if ( filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
					$ip = $candidate;
				}
				break;
			}
		}
	}

	/**
	 * Final resolved client IP. Override for setups the trusted-proxy logic
	 * above does not cover.
	 *
	 * @param string $ip     Resolved IP.
	 * @param string $remote Raw REMOTE_ADDR.
	 */
	return (string) apply_filters( 'jetonomy_client_ip', $ip, $remote );
}

/**
 * Get the profile URL for a user.
 *
 * Returns the Jetonomy profile URL by default, but can be filtered
 * to point to BuddyPress, BuddyBoss, Ultimate Member, or any other
 * profile system.
 *
 * @param int $user_id The user ID.
 * @return string The profile URL.
 */
function get_profile_url( int $user_id ): string {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return '';
	}

	$settings  = get_option( 'jetonomy_settings', [] );
	$base_slug = $settings['base_slug'] ?? 'community';
	$default   = home_url( '/' . $base_slug . '/u/' . $user->user_login . '/' );

	/**
	 * Filter the user profile URL.
	 *
	 * Allows third-party plugins (BuddyPress, BuddyBoss, Ultimate Member)
	 * to override where user profile links point to.
	 *
	 * @param string $url     The default Jetonomy profile URL.
	 * @param int    $user_id The user ID.
	 * @param object $user    The WP_User object.
	 */
	return apply_filters( 'jetonomy_profile_url', $default, $user_id, $user );
}

/**
 * Resolve a deep-link URL for a notification's target object.
 *
 * Single source of truth for notification deep links. Used by the notifier,
 * the mentions dispatcher, and the notifications REST controller, and passed
 * as the `$link` argument of the `jetonomy_notification_created` action so
 * consumers (e.g. BuddyNext's central notification center) can mirror the
 * notification 1:1 without re-deriving the URL from object IDs.
 *
 * @param string $object_type 'post', 'reply', or 'user'.
 * @param int    $object_id   The target object ID.
 * @return string Deep-link URL, or '' if unresolvable.
 */
function notification_deep_link( string $object_type, int $object_id ): string {
	if ( 'post' === $object_type ) {
		$post = Models\Post::find( $object_id );
		if ( ! $post ) {
			return '';
		}
		$space = Models\Space::find( (int) $post->space_id );
		if ( ! $space ) {
			return '';
		}
		return base_url() . '/s/' . $space->slug . '/t/' . $post->slug . '/';
	}

	if ( 'reply' === $object_type ) {
		$reply = Models\Reply::find( $object_id );
		if ( ! $reply ) {
			return '';
		}
		$post = Models\Post::find( (int) $reply->post_id );
		if ( ! $post ) {
			return '';
		}
		$space = Models\Space::find( (int) $post->space_id );
		if ( ! $space ) {
			return '';
		}
		return base_url() . '/s/' . $space->slug . '/t/' . $post->slug . '/#reply-' . $object_id;
	}

	if ( 'user' === $object_type ) {
		return get_profile_url( $object_id );
	}

	return '';
}

/**
 * Get a linked avatar + name for a user.
 *
 * Returns HTML with avatar and display name wrapped in a profile link.
 *
 * @param int    $user_id    The user ID.
 * @param string $avatar_class CSS class for avatar size (jt-avatar-sm, jt-avatar-md).
 * @param int    $avatar_size  Avatar pixel size.
 * @param bool   $show_name   Whether to show the display name.
 * @return string HTML output.
 */
function get_user_link( int $user_id, string $avatar_class = 'jt-avatar-sm', int $avatar_size = 30, bool $show_name = true ): string {
	$user = get_userdata( $user_id );
	if ( ! $user ) {
		// Unknown / anonymous author: show a generic user-silhouette icon rather
		// than a "??" placeholder, so the avatar reads as a real (if nameless)
		// person instead of looking broken.
		return '<span class="jt-avatar jt-avatar-anon ' . esc_attr( $avatar_class ) . '">'
			. jetonomy_icon( 'user', max( 14, (int) round( $avatar_size * 0.6 ) ) )
			. '</span>';
	}

	$url        = get_profile_url( $user_id );
	$name       = $user->display_name;
	$avatar_url = get_avatar_url( $user_id, [ 'size' => $avatar_size * 2 ] );
	$initials   = strtoupper( mb_substr( $name, 0, 2 ) );

	$avatar_html = $avatar_url
		? '<img src="' . esc_url( $avatar_url ) . '" alt="' . esc_attr( $name ) . '" class="jt-avatar ' . esc_attr( $avatar_class ) . '" width="' . (int) $avatar_size . '" height="' . (int) $avatar_size . '" loading="lazy">'
		: '<span class="jt-avatar ' . esc_attr( $avatar_class ) . '">' . esc_html( $initials ) . '</span>';

	$name_html = $show_name ? ' <span class="jt-user-name">' . esc_html( $name ) . '</span>' : '';

	if ( $url ) {
		return '<a href="' . esc_url( $url ) . '" class="jt-user-link">' . $avatar_html . $name_html . '</a>';
	}

	return $avatar_html . $name_html;
}

/**
 * Return the URL where a space admin should land to edit a space.
 *
 * 1.4.0 G5 shipped the front-end edit view at /community/s/:slug/edit/, so
 * this now defaults to that URL. Integrators can flip the filter back to
 * false to send admins to wp-admin instead, e.g. for a custom workflow.
 *
 * @param object $space Space row (must have `slug` and `id`).
 * @return string Absolute URL.
 */
function get_space_edit_url( $space ): string {
	$slug = isset( $space->slug ) ? (string) $space->slug : '';
	$id   = isset( $space->id ) ? (int) $space->id : 0;

	/**
	 * Filter whether to use the front-end space-edit URL (G5).
	 *
	 * Default true since G5 shipped in 1.4.0. Set false to route the
	 * sidebar Edit-space link to wp-admin instead.
	 *
	 * @param bool   $use_frontend Whether to return the front-end URL.
	 * @param object $space        Space row.
	 */
	$use_frontend = (bool) apply_filters( 'jetonomy_use_frontend_space_edit', true, $space );

	if ( $use_frontend && '' !== $slug ) {
		return base_url() . '/s/' . rawurlencode( $slug ) . '/edit/';
	}

	if ( $id > 0 ) {
		return admin_url( 'admin.php?page=jetonomy-spaces&edit=' . $id );
	}

	return admin_url( 'admin.php?page=jetonomy-spaces' );
}

/**
 * Return 'admin' / 'moderator' / null for a user in a space.
 *
 * Thin namespaced wrapper around `Models\SpaceMember::role_label()` so
 * templates can write `\Jetonomy\get_space_role_label( $author_id, $space_id )`
 * without a long-form class reference. The model method is the source
 * of truth for the per-request cache; this helper only exists for
 * template ergonomics (1.4.0 G3).
 *
 * Templates that render a list of authors should call
 * `Models\SpaceMember::warm_role_cache($space_id, $author_ids)` BEFORE
 * the loop so each per-row call here is O(1) instead of O(N).
 *
 * @param int $user_id
 * @param int $space_id
 * @return ?string  'admin' | 'moderator' | null
 */
function get_space_role_label( int $user_id, int $space_id ): ?string {
	if ( $user_id <= 0 || $space_id <= 0 ) {
		return null;
	}
	return \Jetonomy\Models\SpaceMember::role_label( $space_id, $user_id );
}

/**
 * Header logo URL for Jetonomy-rendered surfaces (emails, blocks, shortcodes).
 *
 * Themes own the site header — this helper exists for surfaces Jetonomy
 * renders itself. Filterable via `jetonomy_header_logo` so extensions
 * (e.g. Pro white-label) can override the default with a custom URL.
 *
 * @since 1.4.1
 *
 * @param string $default Default logo URL when no override is set.
 * @return string Filtered logo URL (may be empty for "no logo, use site name").
 */
function header_logo( string $default = '' ): string {
	/**
	 * Filter the header logo URL used by Jetonomy-rendered surfaces.
	 *
	 * @param string $url The current logo URL. Empty string means "no logo set".
	 */
	return (string) apply_filters( 'jetonomy_header_logo', $default );
}

/**
 * Footer text for Jetonomy-rendered surfaces (emails, blocks, shortcodes).
 *
 * Filterable via `jetonomy_footer_text` so extensions (e.g. Pro white-label)
 * can replace the default copy with their own.
 *
 * @since 1.4.1
 *
 * @param string $default Default footer text.
 * @return string Filtered footer text (may be empty).
 */
function footer_text( string $default = '' ): string {
	/**
	 * Filter the footer text used by Jetonomy-rendered surfaces.
	 *
	 * @param string $text The current footer text. May be empty.
	 */
	return (string) apply_filters( 'jetonomy_footer_text', $default );
}

/**
 * jetonomy_settings option merged with SEO defaults (single source of truth).
 *
 * The admin SEO checkboxes render "Default: On" via `?? true`, but the
 * consumers used `empty($settings['seo_x'])` which treats an absent key as OFF
 * — so a fresh install had the sitemap/noindex features silently disabled
 * despite the UI. Routing BOTH the render and the consumers through this
 * defaults union makes them agree, and it applies to existing installs on the
 * next page load (an activation seed would never re-fire on a plugin update).
 *
 * Uses the `+` union (stored values win, only absent keys fall to defaults) so
 * an admin's explicit `false`/`0` is preserved.
 *
 * @return array
 */
function seo_settings(): array {
	$defaults = array(
		'seo_schema'           => true,
		'seo_sitemap'          => true,
		'seo_noindex_profiles' => true,
		'seo_noindex_search'   => true,
		'seo_post_title'       => '{post_title} - {space_name} | {site_name}',
		'seo_space_title'      => '{space_name} | {site_name}',
		'seo_twitter_handle'   => '',
		'seo_default_og_image' => '',
	);
	$stored = get_option( 'jetonomy_settings', array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return $stored + $defaults;
}
