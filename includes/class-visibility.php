<?php
/**
 * Community Visibility helper.
 *
 * Centralizes the `jetonomy_settings.guest_read` ("Community Access") check so
 * the same gate can be applied uniformly to the front-end template loader and
 * to every public-read REST endpoint. Public mode (`guest_read = true`, the
 * default) lets anyone read forum content; private mode (`guest_read = false`)
 * requires the caller to be logged in.
 *
 * Auth endpoints (/auth/*) and admin / moderation endpoints intentionally do
 * NOT route through this helper — locking /auth/* would lock users out
 * forever, and admin / mod surfaces have their own capability gates.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Visibility helper — single source of truth for the public/private community
 * mode flag. Used by both the front-end template loader and the REST API.
 */
final class Visibility {

	/**
	 * Whether the current caller is allowed to view ANY community content.
	 *
	 * In public mode this is always true; in private mode it requires the
	 * caller to be authenticated. Callers do NOT need to pass any arguments —
	 * the check is global, not per-resource (per-resource visibility filters
	 * still run inside individual REST handlers).
	 */
	public static function can_view_community(): bool {
		if ( 'public' === self::get_mode() ) {
			return true;
		}
		return is_user_logged_in();
	}

	/**
	 * Returns the effective visibility mode: 'public' or 'private'.
	 *
	 * `guest_read` defaults to true (public) on a fresh install — an unset /
	 * null / true value all resolve to public, only an explicit false flips
	 * the community to private.
	 */
	public static function get_mode(): string {
		$settings = get_option( 'jetonomy_settings', array() );
		$public   = ! isset( $settings['guest_read'] ) || ! empty( $settings['guest_read'] );
		return $public ? 'public' : 'private';
	}

	/**
	 * REST `permission_callback` helper.
	 *
	 * Returns `true` when the caller may proceed, or a 401 `WP_Error` when the
	 * community is in private mode and the caller is not logged in. Wrap every
	 * existing public-read `permission_callback` so the chain becomes:
	 *
	 *     'permission_callback' => static function ( $req ) {
	 *         $vis = \Jetonomy\Visibility::rest_check( $req );
	 *         if ( is_wp_error( $vis ) ) {
	 *             return $vis;
	 *         }
	 *         return $existing_callback( $req );
	 *     },
	 *
	 * @param \WP_REST_Request $request The incoming request (unused; param
	 *                                  kept for permission_callback signature
	 *                                  compatibility).
	 * @return true|\WP_Error
	 */
	public static function rest_check( \WP_REST_Request $request ) {
		unset( $request ); // Signature compat — community visibility is global, not per-request.
		if ( self::can_view_community() ) {
			return true;
		}
		return new \WP_Error(
			'community_private',
			__( 'This community is private. Please log in to view content.', 'jetonomy' ),
			array( 'status' => 401 )
		);
	}
}
