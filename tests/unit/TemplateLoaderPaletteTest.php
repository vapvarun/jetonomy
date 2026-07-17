<?php
/**
 * Verifies the Color Palette token override builder: only explicitly set
 * colors are emitted, invalid values are dropped, the legacy accent default
 * is treated as unset, and "Inherit theme colors" suppresses the palette
 * entirely — so installs without a saved palette get zero behaviour change.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Template_Loader;

defined( 'ABSPATH' ) || exit;

/**
 * @covers \Jetonomy\Template_Loader::palette_css
 */
class TemplateLoaderPaletteTest extends WP_UnitTestCase {

	public function test_empty_settings_emit_nothing(): void {
		$this->assertSame( '', Template_Loader::palette_css( array() ) );
	}

	public function test_legacy_accent_default_is_treated_as_unset(): void {
		$this->assertSame( '', Template_Loader::palette_css( array( 'accent_color' => '#0073aa' ) ) );
	}

	public function test_single_accent_emits_accent_and_computed_foreground(): void {
		// A picked accent also emits a server-computed readable foreground so it
		// stays legible on any accent in every browser (accent_fg()). #10b981 is
		// light enough that black text wins.
		$this->assertSame(
			':root{--jt-accent:#10b981;--jt-accent-fg:#000000;--jt-accent-hover-fg:#000000;}',
			Template_Loader::palette_css( array( 'accent_color' => '#10b981' ) )
		);
	}

	public function test_full_palette_emits_all_tokens(): void {
		$css = Template_Loader::palette_css(
			array(
				'accent_color'    => '#10b981',
				'text_color'      => '#111827',
				'bg_color'        => '#ffffff',
				'bg_subtle_color' => '#f3f4f6',
				'border_color'    => '#e5e7eb',
			)
		);
		$this->assertSame(
			':root{--jt-accent:#10b981;--jt-text:#111827;--jt-bg:#ffffff;--jt-bg-subtle:#f3f4f6;--jt-border:#e5e7eb;--jt-accent-fg:#000000;--jt-accent-hover-fg:#000000;}',
			$css
		);
	}

	public function test_empty_fields_are_skipped(): void {
		$css = Template_Loader::palette_css(
			array(
				'accent_color'    => '',
				'text_color'      => '#111827',
				'bg_color'        => '',
				'bg_subtle_color' => '',
				'border_color'    => '',
			)
		);
		$this->assertSame( ':root{--jt-text:#111827;}', $css );
	}

	public function test_invalid_values_are_dropped(): void {
		$css = Template_Loader::palette_css(
			array(
				'text_color'   => 'red',                                  // named colors rejected
				'bg_color'     => 'url(evil)',                            // junk rejected
				'border_color' => '#e5e7eb"}</style><script>x</script>',  // injection rejected
			)
		);
		$this->assertSame( '', $css );
	}

	public function test_inherit_colors_key_is_ignored(): void {
		// The `inherit_colors` toggle was removed in 1.8.0 — adoption is
		// unconditional and the picked palette always wins. A stale key on an
		// upgraded install no longer suppresses the palette.
		$css = Template_Loader::palette_css(
			array(
				'inherit_colors' => true,
				'accent_color'   => '#10b981',
				'text_color'     => '#111827',
			)
		);
		$this->assertSame(
			':root{--jt-accent:#10b981;--jt-text:#111827;--jt-accent-fg:#000000;--jt-accent-hover-fg:#000000;}',
			$css
		);
	}

	public function test_dark_accent_gets_white_foreground(): void {
		// The computed foreground flips with the accent's luminance: a dark
		// accent gets white text, a light one black (see the emerald cases above).
		$this->assertSame(
			':root{--jt-accent:#7c3aed;--jt-accent-fg:#ffffff;--jt-accent-hover-fg:#ffffff;}',
			Template_Loader::palette_css( array( 'accent_color' => '#7c3aed' ) )
		);
	}

	public function test_sanitizer_persists_valid_hex_and_blanks_invalid(): void {
		$admin = new \Jetonomy\Admin\Admin();
		$clean = $admin->sanitize_settings(
			array(
				'accent_color'    => '#10b981', // appearance-tab gate field
				'text_color'      => '#111827',
				'bg_color'        => 'not-a-color',
				'bg_subtle_color' => '',
				'border_color'    => '#e5e7eb',
			)
		);
		$this->assertSame( '#111827', $clean['text_color'] );
		$this->assertSame( '', $clean['bg_color'] );
		$this->assertSame( '', $clean['bg_subtle_color'] );
		$this->assertSame( '#e5e7eb', $clean['border_color'] );
	}
}
