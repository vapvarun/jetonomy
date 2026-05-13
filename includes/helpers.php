<?php
/**
 * Public global helpers for templates and integrations.
 *
 * Functions in this file deliberately live in the GLOBAL namespace so theme
 * authors and template overrides can call them without importing namespaces.
 * Namespaced internals belong in includes/functions.php (the Jetonomy\*
 * namespace), not here.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'jetonomy_post_title_or_excerpt' ) ) {
	/**
	 * Return a displayable label for a post — title if present, otherwise a
	 * short excerpt of the body. Feed-space posts (introduced in 1.4.3) are
	 * stored with empty titles by design; every template that renders a
	 * post label runs through this helper so feed posts never display a
	 * blank string or a misleading placeholder.
	 *
	 * The excerpt strips tags, collapses whitespace, and appends an ellipsis
	 * when the body is longer than $len characters. Multi-byte safe.
	 *
	 * @param object $post Post row (must expose ->title and ->content).
	 * @param int    $len  Maximum visible length when falling back to an excerpt.
	 * @return string Plain-text label, never null.
	 */
	function jetonomy_post_title_or_excerpt( $post, int $len = 80 ): string {
		if ( ! is_object( $post ) ) {
			return '';
		}

		$title = isset( $post->title ) ? trim( (string) $post->title ) : '';
		if ( '' !== $title ) {
			return $title;
		}

		$body = isset( $post->content ) ? (string) $post->content : '';
		// Prefer content_plain if a model has already cached one, but tolerate
		// either field being absent on rows materialised by older code paths.
		if ( '' === $body && isset( $post->content_plain ) ) {
			$body = (string) $post->content_plain;
		}

		$plain = trim( wp_strip_all_tags( $body ) );
		if ( '' === $plain ) {
			return '';
		}

		$plain = preg_replace( '/\s+/u', ' ', $plain );

		if ( function_exists( 'mb_strlen' ) && mb_strlen( $plain ) > $len ) {
			$truncated = mb_substr( $plain, 0, $len );
			$last_sp   = mb_strrpos( $truncated, ' ' );
			$cut       = ( false !== $last_sp && $last_sp > 0 )
				? mb_substr( $truncated, 0, $last_sp )
				: $truncated;
			return rtrim( $cut ) . '…';
		}

		if ( strlen( $plain ) > $len ) {
			$truncated = substr( $plain, 0, $len );
			$last_sp   = strrpos( $truncated, ' ' );
			$cut       = ( false !== $last_sp && $last_sp > 0 )
				? substr( $truncated, 0, $last_sp )
				: $truncated;
			return rtrim( $cut ) . '…';
		}

		return $plain;
	}
}
