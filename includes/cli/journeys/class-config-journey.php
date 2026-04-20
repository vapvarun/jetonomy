<?php
/**
 * Config journey — read/write any key in the jetonomy_settings option.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Permissions\Rate_Limiter;
use Jetonomy\Trust\Trust_Levels;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper exposing the `jetonomy_settings` option to the CLI layer
 * using dotted-path notation (e.g. `trust_thresholds.1.posts`).
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method returns a
 * {@see Journey_Result}. Commands format the result for the terminal; PHPUnit
 * tests read the same fields and assert on them.
 *
 * The dotted-path traversal creates intermediate arrays on `set` so callers
 * can seed new nested blocks without pre-creating parents, and every known
 * default block (trust_thresholds, rate_limits, notification_defaults) can
 * be reseeded via `reset` to the same literals used by the activation
 * bootstrap in {@see \Jetonomy\Jetonomy::activate()}.
 */
final class Config_Journey {

	/**
	 * WordPress option key used as the config store.
	 */
	private const OPTION_KEY = 'jetonomy_settings';

	/**
	 * Top-level blocks that have canonical defaults and can be `reset`.
	 *
	 * @var array<int,string>
	 */
	private const RESETTABLE_BLOCKS = [ 'trust_thresholds', 'rate_limits', 'notification_defaults' ];

	/**
	 * Return the full settings array or the leaf value at the given dotted path.
	 *
	 * When `$path` is null the entire option is returned. Otherwise the path is
	 * split on `.` and each segment is used to descend into the nested array.
	 * A missing segment is reported as a failure rather than silently returning
	 * null so callers can distinguish "not set" from "set to null".
	 *
	 * @param string|null $path Optional dotted path (e.g. `trust_thresholds.1.posts`).
	 */
	public function get( ?string $path = null ): Journey_Result {
		$start = microtime( true );

		if ( null !== $path ) {
			$error = $this->validate_path( $path );
			if ( null !== $error ) {
				return Journey_Result::fail( $error );
			}
		}

		$settings = $this->read_settings();

		if ( null === $path || '' === $path ) {
			return Journey_Result::ok(
				[
					'path'  => null,
					'value' => $settings,
				],
				[],
				$this->duration_ms( $start )
			);
		}

		$found = false;
		$value = $this->descend( $settings, $path, $found );
		if ( ! $found ) {
			return Journey_Result::fail( sprintf( 'Key not found: %s', $path ) );
		}

		return Journey_Result::ok(
			[
				'path'  => $path,
				'value' => $value,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Set the leaf value at a dotted path, creating intermediate arrays as needed.
	 *
	 * The new value is coerced via {@see coerce_scalar()} so `"true"`, `"42"`,
	 * `"3.14"` and `"null"` become their typed equivalents. Persists via
	 * `update_option()` and returns the previous leaf (or null if it was absent)
	 * so callers can audit the change.
	 *
	 * @param string $path  Dotted path to the leaf.
	 * @param mixed  $value New value (string from CLI, any type from tests).
	 */
	public function set( string $path, $value ): Journey_Result {
		$start = microtime( true );

		if ( '' === $path ) {
			return Journey_Result::fail( 'path must not be empty.' );
		}
		$error = $this->validate_path( $path );
		if ( null !== $error ) {
			return Journey_Result::fail( $error );
		}

		$settings = $this->read_settings();

		$found     = false;
		$old_value = $this->descend( $settings, $path, $found );
		if ( ! $found ) {
			$old_value = null;
		}

		$new_value = is_string( $value ) ? $this->coerce_scalar( $value ) : $value;

		$this->assign( $settings, $path, $new_value );
		update_option( self::OPTION_KEY, $settings );

		return Journey_Result::ok(
			[
				'path'      => $path,
				'old_value' => $old_value,
				'new_value' => $new_value,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Reset a single path to its canonical default.
	 *
	 * For known top-level blocks (`trust_thresholds`, `rate_limits`,
	 * `notification_defaults`) this re-seeds the entire block from the
	 * defaults helpers. For anything else the leaf is simply unset from
	 * the option, and `reset_to_default` flips to false in the response.
	 *
	 * @param string $path Top-level block name or dotted path to unset.
	 */
	public function reset( string $path ): Journey_Result {
		$start = microtime( true );

		if ( '' === $path ) {
			return Journey_Result::fail( 'path must not be empty.' );
		}
		$error = $this->validate_path( $path );
		if ( null !== $error ) {
			return Journey_Result::fail( $error );
		}

		$settings = $this->read_settings();

		if ( in_array( $path, self::RESETTABLE_BLOCKS, true ) ) {
			$settings[ $path ] = $this->default_for_block( $path );
			update_option( self::OPTION_KEY, $settings );

			return Journey_Result::ok(
				[
					'path'             => $path,
					'reset_to_default' => true,
					'value'            => $settings[ $path ],
				],
				[],
				$this->duration_ms( $start )
			);
		}

		$this->unset_path( $settings, $path );
		update_option( self::OPTION_KEY, $settings );

		return Journey_Result::ok(
			[
				'path'             => $path,
				'reset_to_default' => false,
				'value'            => null,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Reseed every resettable block at once.
	 *
	 * Unrelated keys (`email_from_name`, `base_slug`, etc.) are left alone —
	 * only blocks with a canonical default helper are touched.
	 */
	public function reset_all(): Journey_Result {
		$start = microtime( true );

		$settings = $this->read_settings();
		$reset    = [];

		foreach ( self::RESETTABLE_BLOCKS as $block ) {
			$settings[ $block ] = $this->default_for_block( $block );
			$reset[]            = $block;
		}

		update_option( self::OPTION_KEY, $settings );

		return Journey_Result::ok(
			[
				'reset'  => $reset,
				'values' => array_intersect_key( $settings, array_flip( $reset ) ),
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List immediate child keys at the given path (or top-level if null).
	 *
	 * Useful for discovery — the CLI caller can walk the option tree without
	 * dumping the full blob. Non-array leaves are reported as `not_an_array`.
	 *
	 * @param string|null $path Optional dotted parent path.
	 */
	public function list_keys( ?string $path = null ): Journey_Result {
		$start = microtime( true );

		if ( null !== $path && '' !== $path ) {
			$error = $this->validate_path( $path );
			if ( null !== $error ) {
				return Journey_Result::fail( $error );
			}
		}

		$settings = $this->read_settings();

		if ( null === $path || '' === $path ) {
			$target = $settings;
		} else {
			$found  = false;
			$target = $this->descend( $settings, $path, $found );
			if ( ! $found ) {
				return Journey_Result::fail( sprintf( 'Key not found: %s', $path ) );
			}
		}

		if ( ! is_array( $target ) ) {
			return Journey_Result::fail( sprintf( 'Path %s is not an array.', $path ?? '' ) );
		}

		$items = [];
		foreach ( $target as $key => $value ) {
			$items[] = [
				'key'  => (string) $key,
				'type' => $this->describe_type( $value ),
			];
		}

		return Journey_Result::ok(
			[
				'path'    => $path,
				'items'   => $items,
				'columns' => [ 'key', 'type' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Read the effective settings — raw option merged with canonical defaults
	 * for each resettable block, so a dotted-path getter returns the value
	 * the runtime actually uses rather than failing with "Key not found" when
	 * the admin has not explicitly saved that block. Raw values win; defaults
	 * only fill the gap for blocks/keys the admin never touched.
	 *
	 * @return array<string,mixed>
	 */
	private function read_settings(): array {
		$raw      = get_option( self::OPTION_KEY, [] );
		$settings = is_array( $raw ) ? $raw : [];
		foreach ( self::RESETTABLE_BLOCKS as $block ) {
			$defaults = $this->default_for_block( $block );
			if ( empty( $defaults ) ) {
				continue;
			}
			$existing           = isset( $settings[ $block ] ) && is_array( $settings[ $block ] ) ? $settings[ $block ] : [];
			$settings[ $block ] = $existing + $defaults;
		}
		return $settings;
	}

	/**
	 * Reject paths containing `..` or leading/trailing `.`.
	 *
	 * @return string|null Error message, or null when the path is well-formed.
	 */
	private function validate_path( string $path ): ?string {
		if ( false !== strpos( $path, '..' ) ) {
			return 'Malformed path: empty segment (contains "..").';
		}
		if ( '.' === substr( $path, 0, 1 ) || '.' === substr( $path, -1 ) ) {
			return 'Malformed path: leading or trailing "." is not allowed.';
		}
		return null;
	}

	/**
	 * Walk the dotted path into `$haystack`, setting `$found` to true on hit.
	 *
	 * @param array<string,mixed> $haystack Source array to walk.
	 * @param string              $path     Dotted path.
	 * @param bool                $found    Out parameter flipped to true on hit.
	 * @return mixed The leaf value, or null when `$found` stays false.
	 */
	private function descend( array $haystack, string $path, bool &$found ) {
		$segments = explode( '.', $path );
		$cursor   = $haystack;
		foreach ( $segments as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				$found = false;
				return null;
			}
			$cursor = $cursor[ $segment ];
		}
		$found = true;
		return $cursor;
	}

	/**
	 * Assign `$value` at the dotted path inside `$target` by reference,
	 * creating intermediate arrays as needed.
	 *
	 * @param array<string,mixed> $target Mutated in place.
	 * @param string              $path   Dotted path.
	 * @param mixed               $value  Value to assign.
	 */
	private function assign( array &$target, string $path, $value ): void {
		$segments = explode( '.', $path );
		$cursor   = &$target;
		foreach ( $segments as $i => $segment ) {
			if ( $i === count( $segments ) - 1 ) {
				$cursor[ $segment ] = $value;
				return;
			}
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor = &$cursor[ $segment ];
		}
	}

	/**
	 * Unset the leaf at `$path` inside `$target`, leaving parents alone.
	 *
	 * @param array<string,mixed> $target Mutated in place.
	 * @param string              $path   Dotted path.
	 */
	private function unset_path( array &$target, string $path ): void {
		$segments = explode( '.', $path );
		$cursor   = &$target;
		foreach ( $segments as $i => $segment ) {
			if ( $i === count( $segments ) - 1 ) {
				unset( $cursor[ $segment ] );
				return;
			}
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				return;
			}
			$cursor = &$cursor[ $segment ];
		}
	}

	/**
	 * Coerce a CLI-provided string into a sensible native type.
	 *
	 * `"true"`/`"false"`/`"null"` map to their literal PHP values (case
	 * insensitive). Numeric strings become int or float. Everything else
	 * is preserved as a string.
	 *
	 * @param string $raw Raw string from the CLI.
	 * @return mixed Coerced value.
	 */
	private function coerce_scalar( string $raw ) {
		$lower = strtolower( $raw );
		if ( 'true' === $lower ) {
			return true;
		}
		if ( 'false' === $lower ) {
			return false;
		}
		if ( 'null' === $lower ) {
			return null;
		}
		if ( is_numeric( $raw ) ) {
			return false === strpos( $raw, '.' ) ? (int) $raw : (float) $raw;
		}
		return $raw;
	}

	/**
	 * Canonical defaults for a resettable block.
	 *
	 * @return array<int|string,mixed>
	 */
	private function default_for_block( string $block ): array {
		switch ( $block ) {
			case 'trust_thresholds':
				return Trust_Levels::defaults();
			case 'rate_limits':
				return Rate_Limiter::defaults();
			case 'notification_defaults':
				return self::notification_defaults();
			default:
				return [];
		}
	}

	/**
	 * Notification defaults literal — mirrors the seed block in
	 * {@see \Jetonomy\Jetonomy::activate()}. Extracted here so both the
	 * activation bootstrap and the CLI reset path can share a single source.
	 *
	 * @return array<string,array<string,bool>>
	 */
	public static function notification_defaults(): array {
		return [
			'reply_to_post'   => [
				'web'   => true,
				'email' => true,
			],
			'reply_to_reply'  => [
				'web'   => true,
				'email' => false,
			],
			'mention'         => [
				'web'   => true,
				'email' => true,
			],
			'accepted_answer' => [
				'web'   => true,
				'email' => true,
			],
			'new_post_in_sub' => [
				'web'   => true,
				'email' => false,
			],
			'badge_earned'    => [
				'web'   => true,
				'email' => false,
			],
			'vote_on_post'    => [
				'web'   => true,
				'email' => false,
			],
			'moderation'      => [
				'web'   => true,
				'email' => true,
			],
			'join_request'    => [
				'web'   => true,
				'email' => true,
			],
		];
	}

	/**
	 * Describe the PHP type of a value for `list_keys()` output.
	 *
	 * @param mixed $value Any settings value.
	 */
	private function describe_type( $value ): string {
		if ( is_array( $value ) ) {
			return 'array(' . count( $value ) . ')';
		}
		if ( is_bool( $value ) ) {
			return 'bool';
		}
		if ( is_int( $value ) ) {
			return 'int';
		}
		if ( is_float( $value ) ) {
			return 'float';
		}
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( null === $value ) {
			return 'null';
		}
		return gettype( $value );
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
