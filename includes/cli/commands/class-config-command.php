<?php
/**
 * wp jetonomy config — dotted-path access to jetonomy_settings via Config_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Config_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage the `jetonomy_settings` option from the terminal.
 *
 * Every subcommand delegates to {@see Config_Journey} so PHPUnit tests can
 * assert on the same code path the terminal exercises. The journey returns
 * structured results; this class only concerns itself with formatting them.
 */
final class Config_Command extends Base_Command {

	/**
	 * Read a settings value or the full option.
	 *
	 * ## OPTIONS
	 *
	 * [--key=<dotted_path>]
	 * : Dotted path into `jetonomy_settings`. Omit to dump the full option.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy config get
	 *     wp jetonomy config get --key=trust_thresholds.1.posts
	 *     wp jetonomy config get --key=rate_limits --format=json
	 */
	public function get( $args, $assoc ): void {
		$path   = isset( $assoc['key'] ) ? (string) $assoc['key'] : null;
		$result = ( new Config_Journey() )->get( $path );
		$this->render( $result, $assoc );
	}

	/**
	 * Write a settings value at a dotted path.
	 *
	 * ## OPTIONS
	 *
	 * --key=<dotted_path>
	 * : Dotted path into `jetonomy_settings` (e.g. trust_thresholds.1.posts).
	 *
	 * --value=<value>
	 * : New value. Coerced: "true"/"false" → bool, "null" → null, numeric strings → int/float.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy config set --key=trust_thresholds.1.posts --value=7
	 *     wp jetonomy config set --key=notification_defaults.mention.email --value=false
	 */
	public function set( $args, $assoc ): void {
		$path   = (string) ( $assoc['key'] ?? '' );
		$value  = $assoc['value'] ?? '';
		$result = ( new Config_Journey() )->set( $path, $value );
		$this->render( $result, $assoc );
	}

	/**
	 * Reset a block or leaf to its canonical default.
	 *
	 * ## OPTIONS
	 *
	 * <path>
	 * : Top-level block or dotted path to reset. Known blocks (trust_thresholds, rate_limits, notification_defaults) are reseeded from defaults; unknown paths are unset.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy config reset trust_thresholds
	 *     wp jetonomy config reset rate_limits
	 *     wp jetonomy config reset notification_defaults
	 */
	public function reset( $args, $assoc ): void {
		$path   = (string) ( $args[0] ?? '' );
		$result = ( new Config_Journey() )->reset( $path );
		$this->render( $result, $assoc );
	}

	/**
	 * Reseed every block that has a known default.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy config reset-all
	 *
	 * @subcommand reset-all
	 */
	public function reset_all( $args, $assoc ): void {
		$result = ( new Config_Journey() )->reset_all();
		$this->render( $result, $assoc );
	}

	/**
	 * List immediate child keys at a path (or top level).
	 *
	 * ## OPTIONS
	 *
	 * [--key=<parent_path>]
	 * : Dotted parent path. Omit to list top-level keys.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Columns to display.
	 *
	 * ## EXAMPLES
	 *     wp jetonomy config keys
	 *     wp jetonomy config keys --key=trust_thresholds
	 *     wp jetonomy config keys --key=trust_thresholds.1
	 */
	public function keys( $args, $assoc ): void {
		$path   = isset( $assoc['key'] ) ? (string) $assoc['key'] : null;
		$result = ( new Config_Journey() )->list_keys( $path );
		$this->render_list( $result, $assoc );
	}
}
