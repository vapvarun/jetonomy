<?php
/**
 * Shared rendering helpers for journey-backed WP-CLI commands.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journey_Result;

defined( 'ABSPATH' ) || exit;

/**
 * Base class that every journey-backed command extends for uniform output.
 *
 * Subclasses contain one public method per subcommand and delegate to a
 * journey class for business logic. This base provides {@see render()} and
 * {@see render_list()} so every command emits identical terminal output
 * regardless of the journey's internal details — successes through
 * WP_CLI::success(), failures through WP_CLI::error(), data through
 * format_items() or a plain key: value dump.
 */
abstract class Base_Command {

	/**
	 * Render a single Journey_Result to the terminal.
	 *
	 * Prints any breadcrumb logs first, then either emits success output
	 * with the serialized data payload or exits non-zero via
	 * WP_CLI::error() with the first error message. JSON output is
	 * toggled by `--format=json` on the invoking command.
	 *
	 * @param Journey_Result      $result The journey outcome.
	 * @param array<string,mixed> $assoc Associative args from the command invocation.
	 */
	protected function render( Journey_Result $result, array $assoc = [] ): void {
		$format = (string) ( $assoc['format'] ?? 'table' );

		foreach ( $result->logs as $line ) {
			\WP_CLI::log( $line );
		}

		if ( ! $result->is_success() ) {
			if ( 'json' === $format ) {
				\WP_CLI::log( (string) wp_json_encode( $result->to_array() ) );
			}
			\WP_CLI::error( $result->first_error() ?? 'Journey failed.' );
			return;
		}

		if ( 'json' === $format ) {
			\WP_CLI::log( (string) wp_json_encode( $result->to_array() ) );
			return;
		}

		if ( empty( $result->data ) ) {
			\WP_CLI::success( 'OK' );
			return;
		}

		foreach ( $result->data as $key => $value ) {
			\WP_CLI::log( sprintf( '%s: %s', $key, is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
		}
		\WP_CLI::success( sprintf( 'Done in %dms.', $result->duration_ms ) );
	}

	/**
	 * Render a list-shaped Journey_Result using WP-CLI's table formatter.
	 *
	 * The journey must place the rows under `data.items` and the column
	 * keys under `data.columns`, otherwise this falls back to a JSON dump.
	 *
	 * @param Journey_Result      $result Journey outcome carrying items + columns.
	 * @param array<string,mixed> $assoc  Associative args (read format/fields).
	 */
	protected function render_list( Journey_Result $result, array $assoc = [] ): void {
		if ( ! $result->is_success() ) {
			\WP_CLI::error( $result->first_error() ?? 'Journey failed.' );
			return;
		}

		$items   = $result->data['items'] ?? [];
		$columns = $result->data['columns'] ?? [];

		if ( ! is_array( $items ) || empty( $items ) ) {
			\WP_CLI::log( 'No rows.' );
			return;
		}

		$format = (string) ( $assoc['format'] ?? 'table' );
		$fields = $assoc['fields'] ?? ( is_array( $columns ) ? implode( ',', $columns ) : '' );

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			$fields
		);
	}
}
