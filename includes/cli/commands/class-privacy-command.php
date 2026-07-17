<?php
/**
 * wp jetonomy privacy — inspect and remediate orphaned user data.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Privacy_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Find and purge data belonging to already-deleted accounts.
 *
 * Both subcommands delegate to {@see Privacy_Journey} so PHPUnit asserts on the
 * same code path the terminal exercises. This class only formats results.
 */
final class Privacy_Command extends Base_Command {

	/**
	 * Report data still held for accounts that no longer exist. Read-only.
	 *
	 * Rows are orphaned when their user id is absent from wp_users. Rows with
	 * user id 0 are NOT orphans — 0 is the anonymized/system sentinel that the
	 * eraser and the delete path deliberately leave behind.
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
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy privacy scan
	 *     wp jetonomy privacy scan --format=json
	 *
	 * @subcommand scan
	 */
	public function scan( $args, $assoc ): void {
		$result = ( new Privacy_Journey() )->scan_orphans();

		if ( ! $result->is_success() ) {
			\WP_CLI::error( $result->first_error() ?? 'Scan failed.' );
			return;
		}

		if ( 'json' === (string) ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $result->to_array() ) );
			return;
		}

		$columns = (array) ( $result->data['columns'] ?? [] );
		if ( ! $columns ) {
			\WP_CLI::success( 'No orphaned user data. Nothing to remediate.' );
			return;
		}

		\WP_CLI\Utils\format_items(
			(string) ( $assoc['format'] ?? 'table' ),
			$columns,
			'table,column,orphan_rows'
		);
		\WP_CLI::warning(
			sprintf(
				'%d row(s) belonging to %d deleted account(s) are still stored. Run `wp jetonomy privacy purge-orphans` to remove them.',
				(int) $result->data['orphan_rows'],
				(int) $result->data['orphan_users']
			)
		);
	}

	/**
	 * Purge all data belonging to accounts that no longer exist.
	 *
	 * Safe to run repeatedly — a second run finds nothing and does nothing.
	 * Runs the same purge a live user deletion runs, so counters are recomputed
	 * and caches busted exactly as they would be.
	 *
	 * On multisite this cleans the CURRENT site's tables. Pass --url=<site> per
	 * site to sweep a network (each site keeps its own jt_* tables).
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would be removed without removing anything.
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
	 *     wp jetonomy privacy purge-orphans --dry-run
	 *     wp jetonomy privacy purge-orphans
	 *
	 * @subcommand purge-orphans
	 */
	public function purge_orphans( $args, $assoc ): void {
		if ( isset( $assoc['dry-run'] ) ) {
			$this->scan( $args, $assoc );
			return;
		}

		$result = ( new Privacy_Journey() )->purge_orphans();
		$this->render( $result, $assoc );
	}
}
