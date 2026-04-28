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
		return '<span class="jt-avatar ' . esc_attr( $avatar_class ) . '">??</span>';
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
