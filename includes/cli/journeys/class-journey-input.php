<?php
/**
 * Shared input guard for the journeys that accept a free-form payload array.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

defined( 'ABSPATH' ) || exit;

/**
 * Rejects payload keys a journey does not understand.
 *
 * WHY THIS EXISTS. The create journeys build their payload key by key from the
 * caller's array. Anything they do not recognise is dropped in silence - the
 * write still succeeds, and the caller is told it worked. That is how the
 * importer's backdating shipped looking finished: buddynext-importer sent
 * `created_at` for months, three journeys never read it, no error was ever
 * raised, and every migrated space, topic and reply was quietly stamped with
 * the migration run time instead of its real date. A hard failure on the first
 * call would have caught it the day it was written.
 *
 * So an unrecognised key is now an error, not a shrug. The cost of failing
 * loudly on a typo is one clear message; the cost of failing silently is wrong
 * data nobody notices until a customer does.
 *
 * ESCAPE HATCH. This tightens a public contract, so per the plugin's stability
 * rules the strictness can be turned off in one line from a mu-plugin:
 *
 *     add_filter( 'jetonomy_journey_strict_input', '__return_false' );
 */
final class Journey_Input {

	/**
	 * Keys present in the payload that the journey does not accept.
	 *
	 * @param array<string,mixed> $input   Caller payload.
	 * @param array<int,string>   $allowed Every key the journey reads.
	 * @return array<int,string> Unrecognised keys, in the order supplied.
	 */
	public static function unknown_keys( array $input, array $allowed ): array {
		/**
		 * Filters whether journeys reject payload keys they do not understand.
		 *
		 * Returning false restores the pre-1.8.1 behaviour of silently ignoring
		 * them. Intended as a compatibility escape hatch for an integration that
		 * passes extra keys on purpose, not as a default.
		 *
		 * @param bool                $strict  Whether to reject unknown keys.
		 * @param array<string,mixed> $input   The payload being validated.
		 * @param array<int,string>   $allowed The keys the journey accepts.
		 */
		if ( ! apply_filters( 'jetonomy_journey_strict_input', true, $input, $allowed ) ) {
			return [];
		}

		return array_values( array_diff( array_keys( $input ), $allowed ) );
	}

	/**
	 * A ready-to-return failure message for unrecognised keys, or '' when the
	 * payload is clean.
	 *
	 * The message names the offending keys AND the accepted set, because the
	 * caller is usually a developer who mistyped one of them.
	 *
	 * @param array<string,mixed> $input   Caller payload.
	 * @param array<int,string>   $allowed Every key the journey reads.
	 */
	public static function error( array $input, array $allowed ): string {
		$unknown = self::unknown_keys( $input, $allowed );

		if ( ! $unknown ) {
			return '';
		}

		return sprintf(
			/* translators: 1: unrecognised field names, 2: accepted field names. */
			__( 'Unrecognised fields: %1$s. This journey accepts: %2$s.', 'jetonomy' ),
			implode( ', ', $unknown ),
			implode( ', ', $allowed )
		);
	}
}
