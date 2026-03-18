<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

function table( string $name ): string {
    global $wpdb;
    return $wpdb->prefix . 'jt_' . $name;
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
