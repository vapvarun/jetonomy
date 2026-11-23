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
	 * A yes/no flag. A bare `--flag` is yes; `--flag=false` (or 0, no, off) is no;
	 * anything else stops the command with exit 1 instead of being read as yes.
	 *
	 * @param array<string,mixed> $assoc   Associative args.
	 * @param string              $key     Flag name without the dashes.
	 * @param bool                $default Value when the flag is absent.
	 */
	protected function bool_arg( array $assoc, string $key, bool $default = false ): bool {
		if ( ! array_key_exists( $key, $assoc ) ) {
			return $default;
		}
		$value = \Jetonomy\strict_bool( $assoc[ $key ] );
		if ( null === $value ) {
			\WP_CLI::error( sprintf( '--%s must be true or false (or 1/0, yes/no, on/off); got "%s".', $key, (string) $assoc[ $key ] ) );
		}
		return (bool) $value;
	}

	/**
	 * A whole-number argument (positional by index, or a --flag by name).
	 * "abc" or "5abc" stops the command with exit 1 instead of being read as 0 or 5,
	 * and so does a value outside the range. Every caller states its own minimum:
	 * 1 for a required id, 0 where 0 means "none", null where any whole number is
	 * allowed (a signed delta) or the journey checks the range itself.
	 *
	 * @param array<int|string,mixed> $from    $args or $assoc.
	 * @param int|string              $key     Positional index or flag name.
	 * @param int|null                $default Value when it is absent (not range-checked).
	 * @param int|null                $min     Lowest value accepted, or null for no lower limit.
	 * @param int|null                $max     Highest value accepted, or null for no upper limit.
	 */
	protected function int_arg( array $from, $key, ?int $default, ?int $min, ?int $max = null ): ?int {
		if ( ! array_key_exists( $key, $from ) ) {
			return $default;
		}
		$name  = is_int( $key ) ? sprintf( 'Argument %d', $key + 1 ) : '--' . $key;
		$value = \Jetonomy\strict_int( $from[ $key ] );
		if ( null === $value ) {
			\WP_CLI::error( sprintf( '%s must be a whole number; got "%s".', $name, (string) $from[ $key ] ) );
		}
		if ( ( null !== $min && $value < $min ) || ( null !== $max && $value > $max ) ) {
			\WP_CLI::error(
				null === $max
					? sprintf( '%s must be %d or more; got %d.', $name, $min, $value )
					: sprintf( '%s must be between %d and %d; got %d.', $name, (int) $min, $max, $value )
			);
		}
		return $value;
	}

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
	protected function render( Journey_Result $result, array $assoc = array() ): void {
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
	protected function render_list( Journey_Result $result, array $assoc = array() ): void {
		if ( ! $result->is_success() ) {
			\WP_CLI::error( $result->first_error() ?? 'Journey failed.' );
			return;
		}

		$items   = $result->data['items'] ?? array();
		$columns = $result->data['columns'] ?? array();

		if ( ! is_array( $items ) || empty( $items ) ) {
			\WP_CLI::log( 'No rows.' );
			return;
		}

		$format = (string) ( $assoc['format'] ?? 'table' );
		$fields = $assoc['fields'] ?? ( is_array( $columns ) ? implode( ',', $columns ) : '' );

		// A table prints a boolean false as an empty cell, so a 'no' looked like missing data. Readable yes/no there;
		// json and yaml keep real booleans for scripts.
		if ( ! in_array( $format, array( 'json', 'yaml' ), true ) ) {
			$items = array_map(
				static fn( $row ) => is_array( $row )
					? array_map( static fn( $v ) => is_bool( $v ) ? ( $v ? 'yes' : 'no' ) : $v, $row )
					: $row,
				$items
			);
		}

		\WP_CLI\Utils\format_items(
			$format,
			$items,
			$fields
		);
	}
}
