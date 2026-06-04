<?php
/**
 * Layout CSS — emits a small, scoped CSS block on community pages so admins
 * can adjust container width, sidebar visibility, and page padding from
 * Settings without writing custom CSS or hunting through theme controls.
 *
 * The class is deliberately conservative: when every setting is left at
 * "Theme Default" it emits nothing, so existing installs see zero behaviour
 * change. The CSS scope is `body.jt-page` (already added by Template_Loader),
 * so overrides cannot leak onto non-community pages.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Integrations;

defined( 'ABSPATH' ) || exit;

class Layout_CSS {

	/**
	 * Jetonomy frontend style handle that receives the inline override.
	 */
	const STYLE_HANDLE = 'jetonomy';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'output_layout_css' ), 25 );
	}

	/**
	 * Build the layout CSS from saved settings and attach it as inline style
	 * to the Jetonomy frontend stylesheet. Only runs on community pages
	 * (where `jetonomy` is enqueued).
	 *
	 * @return void
	 */
	public function output_layout_css(): void {
		if ( ! wp_style_is( self::STYLE_HANDLE, 'enqueued' ) ) {
			return;
		}

		$settings = get_option( 'jetonomy_settings', array() );
		if ( ! is_array( $settings ) ) {
			return;
		}

		$rules = $this->build_rules( $settings );
		if ( '' === $rules ) {
			return;
		}

		wp_add_inline_style( self::STYLE_HANDLE, $rules );
	}

	/**
	 * Resolve the container width setting to a CSS max-width value.
	 *
	 * Single source of truth for the `container_width` setting — consumed by
	 * the frontend rules below AND by Pro surfaces (e.g. the Analytics admin
	 * page), so the resolved value is never duplicated or hard-coded.
	 *
	 * @param array<string, mixed>|null $settings Saved jetonomy_settings, or
	 *                                            null to read the option.
	 * @return string|null '100%' (full), 'NNNpx' (custom, clamped 600–2400),
	 *                     or null for Theme Default (no override).
	 */
	public static function container_max_width( ?array $settings = null ): ?string {
		if ( null === $settings ) {
			$settings = get_option( 'jetonomy_settings', array() );
			$settings = is_array( $settings ) ? $settings : array();
		}

		$width = isset( $settings['container_width'] ) ? (string) $settings['container_width'] : 'theme';
		if ( 'full' === $width ) {
			return '100%';
		}
		if ( 'custom' === $width ) {
			return max( 600, min( 2400, absint( $settings['container_width_custom'] ?? 1280 ) ) ) . 'px';
		}
		return null;
	}

	/**
	 * Compose the CSS string from saved settings. Returns an empty string
	 * when every setting is at its default — callers should treat empty
	 * output as a no-op.
	 *
	 * @param array<string, mixed> $settings Saved jetonomy_settings option.
	 * @return string CSS to inject (already minified).
	 */
	private function build_rules( array $settings ): string {
		$css = '';

		// ── Container width ──
		// Targets common WordPress theme container classes inside community pages.
		// `body.jt-page` keeps every override scoped to Jetonomy routes only, so
		// the same theme class on a regular WP page is untouched.
		$value = self::container_max_width( $settings );
		if ( null !== $value ) {
			$css .= 'body.jt-page .site-content,'
				. 'body.jt-page .entry-content,'
				. 'body.jt-page .content-area,'
				. 'body.jt-page #primary,'
				. 'body.jt-page main#main,'
				. 'body.jt-page .container,'
				. 'body.jt-page .wrap,'
				. 'body.jt-page #jetonomy-app,'
				. 'body.jt-page .jt-app,'
				. 'body.jt-page .jt-container'
				. '{max-width:' . $value . ';width:100%;margin-inline:auto;}';
		}

		// ── Sidebar visibility ──
		// Hides common sidebar containers shipped by most WP themes. Uses
		// `display:none` rather than width tricks so the main column reflows.
		$sidebar = isset( $settings['sidebar_visibility'] ) ? (string) $settings['sidebar_visibility'] : 'theme';
		if ( 'hide' === $sidebar ) {
			$css .= 'body.jt-page #secondary,'
				. 'body.jt-page .widget-area,'
				. 'body.jt-page .sidebar,'
				. 'body.jt-page aside.sidebar,'
				. 'body.jt-page .sidebar-primary'
				. '{display:none!important;}';

			// Let the main column reclaim the space the sidebar left behind.
			$css .= 'body.jt-page #primary,'
				. 'body.jt-page .content-area,'
				. 'body.jt-page main#main'
				. '{width:100%!important;float:none!important;margin-inline:auto;}';
		}

		// ── Page padding ──
		// "None" pulls Jetonomy's outer wrapper to the viewport edge; "comfortable"
		// adds a generous responsive inline padding. Theme Default → no rule.
		$padding = isset( $settings['padding_preset'] ) ? (string) $settings['padding_preset'] : 'theme';
		if ( 'none' === $padding ) {
			$css .= 'body.jt-page #jetonomy-app,'
				. 'body.jt-page .jt-app,'
				. 'body.jt-page .jt-container'
				. '{padding-inline:0;}';
		} elseif ( 'comfortable' === $padding ) {
			$css .= 'body.jt-page #jetonomy-app,'
				. 'body.jt-page .jt-app,'
				. 'body.jt-page .jt-container'
				. '{padding-inline:clamp(16px,4vw,48px);}';
		}

		return $css;
	}
}
