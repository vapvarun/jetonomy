<?php
/**
 * Theme integration — bridges BuddyX / BuddyX Pro / Reign Kirki colors
 * and dark mode into Jetonomy's CSS variables and `.jt-dark` class.
 *
 * Jetonomy's token system inherits from BuddyNext (`--brand`) and WP
 * theme.json (`--wp--preset--color--primary`), but BuddyX, BuddyX Pro, and
 * Reign store their colors in Kirki theme mods which are not exposed as
 * CSS variables. This class reads the relevant theme mods on
 * `wp_enqueue_scripts:20` and injects both a light-mode token block
 * (scoped to `:root,.jt-app`) and a dark-mode token block (scoped to
 * `.jt-dark .jt-app`) as inline style on the `jetonomy` handle. A small
 * inline footer script then mirrors the theme's runtime dark class onto
 * the `<body>` as `.jt-dark` so Jetonomy's existing dark tokens engage
 * in sync with whatever the user actually sees.
 *
 * Supported theme mods:
 *
 * - BuddyX / BuddyX Pro: `site_primary_color`, `body_text_color`,
 *   `body_background_color`, `content_background_color`,
 *   `site_border_color`. BuddyX Pro additionally exposes a `dark_`
 *   prefixed variant for each of these plus the `site_dark_mode_switch`
 *   feature toggle.
 * - Reign: `{reign_color_scheme}-reign_accent_color`,
 *   `{reign_color_scheme}-reign_site_body_text_color`,
 *   `{reign_color_scheme}-reign_site_body_bg_color`,
 *   `{reign_color_scheme}-reign_site_sections_bg_color`. Dark variants
 *   live under the `reign_dark-` prefix.
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
		add_action( 'wp_enqueue_scripts', array( $this, 'output_dark_mode_mirror' ), 20 );
	}

	/**
	 * Read the active theme's color mods and inject light + dark token blocks.
	 *
	 * @return void
	 */
	public function output_color_bridge() {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$light = $this->resolve_token_map( false );
		$dark  = $this->resolve_token_map( true );

		// An explicitly chosen admin palette (Settings → Appearance →
		// Color Palette) outranks automatic theme bridging, token by
		// token — the bridge keeps covering tokens the owner left empty.
		// Light mode only: the palette never touches dark tokens.
		$jt_settings = get_option( 'jetonomy_settings', array() );
		$palette     = \Jetonomy\Template_Loader::palette_tokens( is_array( $jt_settings ) ? $jt_settings : array() );
		if ( ! empty( $palette ) ) {
			$light = array_diff_key( $light, $palette );
		}

		/**
		 * Filter the resolved light-mode token → hex map before injection.
		 *
		 * Return an empty array to disable the light override.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,string> $light Map of `--jt-*` token => hex color.
		 */
		$light = (array) apply_filters( 'jetonomy_theme_light_tokens', $light );

		/**
		 * Filter the resolved dark-mode token → hex map before injection.
		 *
		 * Return an empty array to disable the dark override.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string,string> $dark Map of `--jt-*` token => hex color.
		 */
		$dark = (array) apply_filters( 'jetonomy_theme_dark_tokens', $dark );

		$css = '';
		if ( ! empty( $light ) ) {
			$css .= ':root,.jt-app{' . $this->build_token_css( $light ) . '}';
		}
		if ( ! empty( $dark ) ) {
			$css .= '.jt-dark .jt-app,.jt-app.jt-dark{' . $this->build_token_css( $dark ) . '}';
		}

		if ( '' !== $css ) {
			wp_add_inline_style( self::STYLE_HANDLE, $css );
		}
	}

	/**
	 * Mirror the active theme's runtime dark-mode class onto `<body>` as
	 * `.jt-dark` so Jetonomy's existing dark tokens engage automatically.
	 *
	 * Reign and BuddyX Pro manage their dark state at runtime via JS (the
	 * customizer toggle only sets the default; the user's current state
	 * lives in a cookie / localStorage), so a server-side `body_class`
	 * filter can't reliably reflect what the user actually sees. This
	 * inline observer watches both `<html>` and `<body>` class lists for
	 * the common dark-mode classes emitted by these themes and the
	 * wp-dark-mode script, and toggles `.jt-dark` to match.
	 *
	 * @return void
	 */
	public function output_dark_mode_mirror() {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$template = get_template();
		if ( 'reign-theme' !== $template
			&& ! in_array( $template, array( 'buddyx', 'buddyx-pro' ), true ) ) {
			return;
		}

		wp_enqueue_script(
			'jetonomy-dark-mode-mirror',
			JETONOMY_URL . 'assets/js/dark-mode-mirror.js',
			array(),
			JETONOMY_VERSION,
			true
		);
	}

	/**
	 * Resolve a `--jt-*` token map for the current theme in light or dark mode.
	 *
	 * @param bool $dark Whether to read dark-mode variants.
	 * @return array<string,string> Token name => hex color (with leading `#`).
	 */
	private function resolve_token_map( $dark ) {
		$template = get_template();

		if ( 'reign-theme' === $template ) {
			return $this->reign_token_map( (bool) $dark );
		}

		if ( in_array( $template, array( 'buddyx', 'buddyx-pro' ), true ) ) {
			return $this->buddyx_token_map( (bool) $dark );
		}

		return array();
	}

	/**
	 * Read Reign's color mods and map them to Jetonomy tokens.
	 *
	 * Reign scopes every color to a preset scheme (e.g. `reign_clean`,
	 * `reign_dating`). Dark variants live under the `reign_dark-` prefix.
	 *
	 * @param bool $dark Whether to read the `reign_dark-` variants.
	 * @return array<string,string>
	 */
	private function reign_token_map( $dark ) {
		$prefix = $dark
			? 'reign_dark'
			: (string) get_theme_mod( 'reign_color_scheme', 'reign_clean' );

		$keys = array(
			'--jt-accent'    => 'reign_accent_color',
			'--jt-text'      => 'reign_site_body_text_color',
			'--jt-bg'        => 'reign_site_body_bg_color',
			'--jt-bg-subtle' => 'reign_site_sections_bg_color',
		);

		$map = array();
		foreach ( $keys as $token => $mod ) {
			$color = $this->sanitize_color( (string) get_theme_mod( $prefix . '-' . $mod, '' ) );
			if ( '' !== $color ) {
				$map[ $token ] = $color;
			}
		}

		// Legacy single-key fallback for themes that pre-date the scheme model.
		if ( ! isset( $map['--jt-accent'] ) ) {
			$legacy = $this->sanitize_color( (string) get_theme_mod( 'reign_accent_color', '' ) );
			if ( '' !== $legacy ) {
				$map['--jt-accent'] = $legacy;
			}
		}

		if ( isset( $map['--jt-accent'] ) ) {
			$map['--jt-accent-hover'] = $this->darken( $map['--jt-accent'], 15 );
		}

		return $map;
	}

	/**
	 * Read BuddyX / BuddyX Pro color mods and map them to Jetonomy tokens.
	 *
	 * BuddyX Free has no dark mode, so the dark branch is a no-op when the
	 * active template is `buddyx`.
	 *
	 * @param bool $dark Whether to read the `dark_` variants.
	 * @return array<string,string>
	 */
	private function buddyx_token_map( $dark ) {
		if ( $dark && 'buddyx-pro' !== get_template() ) {
			return array();
		}

		$prefix = $dark ? 'dark_' : '';

		$keys = array(
			'--jt-accent'    => $prefix . 'site_primary_color',
			'--jt-text'      => $prefix . 'body_text_color',
			'--jt-bg'        => $prefix . 'body_background_color',
			'--jt-bg-subtle' => $prefix . 'content_background_color',
			'--jt-border'    => $prefix . 'site_border_color',
		);

		$map = array();
		foreach ( $keys as $token => $mod ) {
			$color = $this->sanitize_color( (string) get_theme_mod( $mod, '' ) );
			if ( '' !== $color ) {
				$map[ $token ] = $color;
			}
		}

		if ( isset( $map['--jt-accent'] ) ) {
			$map['--jt-accent-hover'] = $this->darken( $map['--jt-accent'], 15 );
		}

		return $map;
	}

	/**
	 * Convert a token map to the inner body of a CSS declaration block.
	 *
	 * @param array<string,string> $map Token name => hex color.
	 * @return string
	 */
	private function build_token_css( array $map ) {
		$parts = array();
		foreach ( $map as $token => $color ) {
			$parts[] = $token . ':' . $color;
		}
		return implode( ';', $parts ) . ';';
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
