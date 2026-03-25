<?php
/**
 * Plugin Name: Jetonomy
 * Plugin URI:  https://jetonomy.com
 * Description: Next-gen discussion platform for WordPress — forums, Q&A, and more.
 * Version:     1.0.0
 * Requires at least: 6.7
 * Requires PHP: 8.1
 * Author:      Jetonomy
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: jetonomy
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'JETONOMY_VERSION', '1.0.0' );
define( 'JETONOMY_DB_VERSION', '1.1.0' );
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

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once JETONOMY_DIR . 'includes/class-cli.php';
    \WP_CLI::add_command( 'jetonomy', 'Jetonomy\\CLI' );
}
