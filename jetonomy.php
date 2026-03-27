<?php
/**
 * Plugin Name: Jetonomy
 * Plugin URI:  https://store.wbcomdesigns.com/jetonomy/
 * Description: Next-gen discussion platform for WordPress — forums, Q&A, and more.
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author:      Wbcom Designs
 * Author URI:  https://wbcomdesigns.com/
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jetonomy
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'JETONOMY_VERSION', '1.0.0' );
define( 'JETONOMY_DB_VERSION', '1.0.0' );
define( 'JETONOMY_FILE', __FILE__ );
define( 'JETONOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETONOMY_URL', plugin_dir_url( __FILE__ ) );

require_once JETONOMY_DIR . 'includes/class-autoloader.php';
Jetonomy\Autoloader::register();

require_once JETONOMY_DIR . 'includes/class-jetonomy.php';

function jetonomy(): Jetonomy\Jetonomy {
    return Jetonomy\Jetonomy::instance();
}

jetonomy();

// EDD Software Licensing SDK — free plugin auto-updates with preset key.
add_action(
	'edd_sl_sdk_registry',
	function ( $registry ) {
		$registry->register(
			array(
				'id'      => 'jetonomy',
				'url'     => 'https://wbcomdesigns.com',
				'item_id' => 1660320,
				'version' => JETONOMY_VERSION,
				'file'    => JETONOMY_FILE,
				'license' => 'wbcomfreec7e2a9b45d8f1c3e6a0b9d2f7c4e8a11',
			)
		);
	}
);

if ( file_exists( JETONOMY_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php' ) ) {
	require_once JETONOMY_DIR . 'vendor/easy-digital-downloads/edd-sl-sdk/edd-sl-sdk.php';
}

/**
 * Render an SVG icon from assets/icons/.
 *
 * @param string $name Icon slug (filename without .svg).
 * @param int    $size Width/height in px (default 24).
 * @return string Sanitized SVG markup.
 */
function jetonomy_icon( string $name, int $size = 24 ): string {
	static $cache = array();
	if ( isset( $cache[ $name ] ) ) {
		$svg = $cache[ $name ];
	} else {
		$file = JETONOMY_DIR . 'assets/icons/' . sanitize_file_name( $name ) . '.svg';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$cache[ $name ] = $svg;
	}
	$svg = str_replace( '<svg ', '<svg width="' . $size . '" height="' . $size . '" ', $svg );
	return $svg;
}

/**
 * Echo an SVG icon.
 *
 * @param string $name Icon slug.
 * @param int    $size Width/height in px.
 */
function jetonomy_echo_icon( string $name, int $size = 24 ): void {
	echo jetonomy_icon( $name, $size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG from trusted local file.
}

/**
 * Format post/reply content with @mention and #hashtag auto-linking.
 *
 * Expects already-sanitized HTML (via wp_kses_post). Applies regex only
 * to text segments outside HTML tags to avoid mangling existing markup.
 * Individual replacement values are escaped with esc_html/esc_url.
 *
 * @param string $content Sanitized HTML content string.
 * @return string Processed content with mention and tag links.
 */
function jetonomy_format_content( string $content ): string {
	$base = \Jetonomy\base_url();

	// Split content into HTML tags and text segments, process only text segments.
	$parts = preg_split( '/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
	if ( false === $parts ) {
		return $content;
	}

	$inside_a = 0;
	foreach ( $parts as $i => $part ) {
		// Track whether we're inside an <a> tag to avoid nesting links.
		if ( preg_match( '/<a[\s>]/i', $part ) ) {
			++$inside_a;
			continue;
		}
		if ( preg_match( '/<\/a>/i', $part ) ) {
			--$inside_a;
			continue;
		}
		// Skip HTML tags and text inside anchor tags.
		if ( isset( $part[0] ) && '<' === $part[0] ) {
			continue;
		}
		if ( $inside_a > 0 ) {
			continue;
		}

		// @mentions → profile links.
		$part = preg_replace_callback(
			'/@([a-zA-Z0-9_-]+)/u',
			function ( $matches ) use ( $base ) {
				$username = $matches[1];
				$url      = $base . '/u/' . rawurlencode( $username ) . '/';
				return '<a href="' . esc_url( $url ) . '" class="jt-mention">@' . esc_html( $username ) . '</a>';
			},
			$part
		);

		// #hashtags → tag page links.
		$part = preg_replace_callback(
			'/#([a-zA-Z0-9_-]+)/u',
			function ( $matches ) use ( $base ) {
				$tag  = $matches[1];
				$slug = sanitize_title( $tag );
				$url  = $base . '/tag/' . $slug . '/';
				return '<a href="' . esc_url( $url ) . '" class="jt-tag-link">#' . esc_html( $tag ) . '</a>';
			},
			$part
		);

		$parts[ $i ] = $part;
	}

	return implode( '', $parts );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once JETONOMY_DIR . 'includes/class-cli.php';
    \WP_CLI::add_command( 'jetonomy', 'Jetonomy\\CLI' );
}
