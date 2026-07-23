<?php
/**
 * Shared resolver for the create journeys' optional backdated timestamps.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

defined( 'ABSPATH' ) || exit;

/**
 * Importer seam: buddynext-importer replays a source forum through the create
 * journeys, and without a date pass-through every migrated space/topic/reply
 * was stamped with the migration run time. The models already honour a caller
 * created_at (their defaults are array_merge'd under the payload); this class
 * decides whether the caller's value is safe to forward.
 *
 * Contract (mirrors BuddyNext's Core\Backdate):
 *   - input is a UTC "Y-m-d H:i:s" string (all Jetonomy datetime columns are
 *     written via now() = current_time('mysql', true), i.e. UTC);
 *   - future or unparseable values resolve to null, so the model default
 *     (now) applies — these seams BACKDATE only;
 *   - absent input resolves to null: live behaviour is byte-identical.
 */
final class Journey_Backdate {

	/**
	 * Resolve an optional caller-supplied created_at to a safe UTC value.
	 *
	 * @param array<string,mixed> $input Journey create payload.
	 * @return string|null UTC mysql datetime to forward, or null to let the
	 *                     model default (now) apply.
	 */
	public static function resolve( array $input ): ?string {
		if ( empty( $input['created_at'] ) ) {
			return null;
		}

		$timestamp = strtotime( (string) $input['created_at'] . ' UTC' );
		if ( false === $timestamp || $timestamp > time() ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}
}
