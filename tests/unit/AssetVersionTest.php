<?php
/**
 * Every Jetonomy asset URL carries the file mtime in ?ver=, so a CSS/JS fix
 * shipped under an unchanged plugin version is not served stale from browser
 * or CDN caches. CSS used the bare version while three JS enqueues had their
 * own inline mtime copies.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use function Jetonomy\asset_version;

defined( 'ABSPATH' ) || exit;

/**
 * @covers ::Jetonomy\asset_version
 * @covers ::Jetonomy\version_assets_by_mtime
 */
class AssetVersionTest extends WP_UnitTestCase {

	public function test_version_appends_mtime_and_falls_back(): void {
		$file = JETONOMY_DIR . 'assets/css/jetonomy.css';
		$this->assertSame( '9.9.9+' . filemtime( $file ), asset_version( $file, '9.9.9' ) );
		$this->assertSame( '9.9.9', asset_version( JETONOMY_DIR . 'no-such-file.css', '9.9.9' ) );
	}

	public function test_loader_src_is_rewritten_for_plugin_assets_only(): void {
		$src  = JETONOMY_URL . 'assets/css/jetonomy.css?ver=' . JETONOMY_VERSION;
		$want = JETONOMY_VERSION . '+' . filemtime( JETONOMY_DIR . 'assets/css/jetonomy.css' );
		$this->assertStringContainsString( 'ver=' . $want, (string) apply_filters( 'style_loader_src', $src, 'jetonomy' ) );

		// Another plugin's URL, and an explicit version, are left alone.
		$other = 'https://example.org/wp-content/plugins/x/a.css?ver=' . JETONOMY_VERSION;
		$this->assertSame( $other, apply_filters( 'style_loader_src', $other, 'x' ) );
		$pinned = JETONOMY_URL . 'assets/js/view.js?ver=1.0.0';
		$this->assertSame( $pinned, apply_filters( 'script_loader_src', $pinned, 'y' ) );
	}
}
