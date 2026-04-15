<?php
/**
 * Theme integration — bridges BuddyX / BuddyX Pro / Reign Kirki colors
 * and dark-scheme toggle into Jetonomy's CSS variables and .jt-dark class.
 *
 * Jetonomy's token system inherits from BuddyNext (`--brand`) and WP
 * theme.json (`--wp--preset--color--primary`), but BuddyX, BuddyX Pro, and
 * Reign store their primary color in Kirki theme mods which are not exposed
 * as CSS variables. This class reads those theme mods on `wp_enqueue_scripts`
 * and injects `--jt-accent` + `--jt-accent-hover` as an inline style on the
 * `jetonomy` handle. It also toggles the `.jt-dark` body class when the
 * active theme is in dark mode so Jetonomy's existing dark tokens engage.
 *
 * Supported theme mods:
 *
 * - BuddyX / BuddyX Pro: `site_primary_color` (flat). BuddyX Pro adds
 *   `site_dark_mode_switch` (bool) and `dark_site_primary_color` (flat)
 *   for dark mode.
 * - Reign: `{reign_color_scheme}-reign_accent_color` where the scheme is
 *   a preset name like `reign_clean` / `reign_dating`. Dark mode is toggled
 *   via `reign_dark_mode_option` (bool); when active, Reign reads from
 *   the `reign_dark-reign_accent_color` key instead.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Bridge Kirki-based theme colors into Jetonomy tokens.
 */
class Theme_Integration {

	/**
	 * Jetonomy frontend style handle that receives the inline override.
	 */
	const STYLE_HANDLE = 'jetonomy';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'output_color_bridge' ), 20 );
		add_filter( 'body_class', array( $this, 'maybe_add_dark_class' ) );
	}

	/**
	 * Read the theme accent color and inject it as an inline style on the
	 * `jetonomy` handle.
	 *
	 * @return void
	 */
	public function output_color_bridge() {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$color = $this->resolve_primary_color();

		/**
		 * Filter the theme-bridged accent color before it is injected.
		 *
		 * Return an empty string to disable the override entirely.
		 *
		 * @since 1.3.0
		 *
		 * @param string $color Hex color resolved from the active theme.
		 */
		$color = (string) apply_filters( 'jetonomy_theme_primary_color', $color );

		if ( '' === $color ) {
			return;
		}

		$hover = $this->darken( $color, 15 );
		$css   = sprintf(
			':root,.jt-app{--jt-accent:%1$s;--jt-accent-hover:%2$s;}',
			$color,
			$hover
		);

		wp_add_inline_style( self::STYLE_HANDLE, $css );
	}

	/**
	 * Add `.jt-dark` to the body class when the active theme is in dark scheme.
	 *
	 * @param array $classes Existing body classes.
	 * @return array
	 */
	public function maybe_add_dark_class( $classes ) {
		if ( $this->is_theme_dark_scheme() ) {
			$classes[] = 'jt-dark';
		}
		return $classes;
	}

	/**
	 * Resolve the primary accent color from the active theme.
	 *
	 * @return string Hex color with leading `#`, or empty string if unsupported / unset.
	 */
	private function resolve_primary_color() {
		$template = get_template();

		if ( 'reign-theme' === $template ) {
			return $this->reign_primary_color();
		}

		if ( in_array( $template, array( 'buddyx', 'buddyx-pro' ), true ) ) {
			return $this->buddyx_primary_color();
		}

		return '';
	}

	/**
	 * Whether the active theme is in dark color scheme.
	 *
	 * @return bool
	 */
	private function is_theme_dark_scheme() {
		$template = get_template();

		if ( 'reign-theme' === $template ) {
			return (bool) get_theme_mod( 'reign_dark_mode_option', false );
		}

		if ( 'buddyx-pro' === $template ) {
			return (bool) get_theme_mod( 'site_dark_mode_switch', false );
		}

		// BuddyX Free has no dark mode.
		return false;
	}

	/**
	 * Read Reign's primary (accent) color.
	 *
	 * Reign stores colors per preset scheme: `{scheme}-reign_accent_color` where
	 * `$scheme` is `reign_clean`, `reign_dating`, etc. When the theme's dark
	 * mode option is on, Reign uses a dedicated `reign_dark` scheme prefix.
	 *
	 * @return string
	 */
	private function reign_primary_color() {
		if ( (bool) get_theme_mod( 'reign_dark_mode_option', false ) ) {
			$dark = (string) get_theme_mod( 'reign_dark-reign_accent_color', '' );
			if ( '' !== $dark ) {
				return $this->sanitize_color( $dark );
			}
		}

		$scheme = (string) get_theme_mod( 'reign_color_scheme', 'reign_clean' );
		$color  = (string) get_theme_mod( $scheme . '-reign_accent_color', '' );

		if ( '' === $color ) {
			$color = (string) get_theme_mod( 'reign_accent_color', '' );
		}

		return $this->sanitize_color( $color );
	}

	/**
	 * Read BuddyX / BuddyX Pro primary color.
	 *
	 * Both themes use a flat `site_primary_color` key. BuddyX Pro adds a
	 * `dark_site_primary_color` key that applies when `site_dark_mode_switch`
	 * is enabled.
	 *
	 * @return string
	 */
	private function buddyx_primary_color() {
		if ( 'buddyx-pro' === get_template()
			&& (bool) get_theme_mod( 'site_dark_mode_switch', false ) ) {
			$dark = (string) get_theme_mod( 'dark_site_primary_color', '' );
			if ( '' !== $dark ) {
				return $this->sanitize_color( $dark );
			}
		}

		return $this->sanitize_color( (string) get_theme_mod( 'site_primary_color', '' ) );
	}

	/**
	 * Darken a hex color by reducing each RGB channel by $amount.
	 *
	 * @param string $hex    Hex color, with or without leading `#`.
	 * @param int    $amount 0–255.
	 * @return string Darkened hex color with leading `#`.
	 */
	private function darken( $hex, $amount ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}

		$r = max( 0, hexdec( substr( $hex, 0, 2 ) ) - (int) $amount );
		$g = max( 0, hexdec( substr( $hex, 2, 2 ) ) - (int) $amount );
		$b = max( 0, hexdec( substr( $hex, 4, 2 ) ) - (int) $amount );

		return sprintf( '#%02x%02x%02x', $r, $g, $b );
	}

	/**
	 * Validate and normalise a color string.
	 *
	 * @param string $color Raw value from a theme mod.
	 * @return string Valid hex color (with `#`) or empty string.
	 */
	private function sanitize_color( $color ) {
		$clean = sanitize_hex_color( (string) $color );
		return null === $clean ? '' : $clean;
	}
}
