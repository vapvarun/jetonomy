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
define( 'JETONOMY_DB_VERSION', '1.0.0' );
define( 'JETONOMY_FILE', __FILE__ );
define( 'JETONOMY_DIR', plugin_dir_path( __FILE__ ) );
define( 'JETONOMY_URL', plugin_dir_url( __FILE__ ) );

require_once JETONOMY_DIR . 'includes/class-jetonomy.php';

function jetonomy(): Jetonomy\Jetonomy {
    return Jetonomy\Jetonomy::instance();
}

jetonomy();
