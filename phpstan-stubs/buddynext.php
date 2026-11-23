<?php
/**
 * Minimal BuddyNext stubs for static analysis.
 *
 * Jetonomy calls BuddyNext's presence API only behind class_exists(). PHPStan
 * narrows a class_exists() guard for code in the same scope but not inside a
 * closure defined there, so the self-test's per-viewer closure needs the symbol
 * declared. Signatures copied from BuddyNext includes/Realtime/PresenceService.php.
 *
 * Never loaded at runtime: listed under scanFiles in phpstan.neon.dist only.
 *
 * @package Jetonomy
 */

// phpcs:ignoreFile -- symbol stub for PHPStan, never loaded.

namespace BuddyNext\Realtime;

/**
 * BuddyNext presence service (stub).
 */
class PresenceService {

	/**
	 * Record a member's last-seen time.
	 *
	 * @param int $user_id   Member id.
	 * @param int $timestamp Unix time.
	 */
	public static function write( int $user_id, int $timestamp ): void {}

	/**
	 * Whether presence is shown to this viewer at all.
	 *
	 * @param int $viewer_id Viewer id.
	 */
	public static function visible_to_viewer( int $viewer_id ): bool {
		return false;
	}

	/**
	 * Whether a viewer may see a member's presence.
	 *
	 * @param int $viewer_id Viewer id.
	 * @param int $target_id Member id.
	 */
	public static function can_see( int $viewer_id, int $target_id ): bool {
		return false;
	}

	/**
	 * Whether a member is online.
	 *
	 * @param int $user_id Member id.
	 * @param int $within  Window in seconds; 0 for the default.
	 */
	public static function is_online( int $user_id, int $within = 0 ): bool {
		return false;
	}
}
