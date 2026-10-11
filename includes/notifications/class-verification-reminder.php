<?php
/**
 * Verification reminder runner.
 *
 * Single-shot nudge for accounts that signed up while
 * `jetonomy_settings.require_email_verification = true` was on, and that
 * never clicked the link in their welcome email. Cron picks up rows in
 * `jt_user_profiles` whose owner is still pending verification (per the
 * `_jetonomy_pending_verification` user-meta written by Auth_Controller),
 * registered ≥ N hours ago, and have a NULL
 * `verification_reminder_sent_at` column. The column itself is the
 * rate-limit — once we send, we never send again, so the user gets at
 * most one reminder regardless of how often the cron fires.
 *
 * Threshold defaults to 24h. Admin can override via
 * `jetonomy_settings.verification_reminder_hours`. Set to 0 to disable.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Notifications;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\UserProfile;

class Verification_Reminder {

	/**
	 * Hard upper bound per cron tick. Each cron run only sweeps this many
	 * accounts so a backlog (e.g. plugin freshly turned on for an existing
	 * forum with thousands of legacy unverified members) can't push wp-cron
	 * past PHP's max-execution-time. Hourly schedule means the queue still
	 * drains quickly without ever holding the request open.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Default hours after registration before we send the reminder. Admin
	 * can override via `jetonomy_settings.verification_reminder_hours`.
	 */
	private const DEFAULT_HOURS = 24;

	/**
	 * Cron entry point. Registered against `jetonomy_verification_reminder`
	 * in `Jetonomy\Cron`.
	 */
	public static function run(): void {
		$settings = get_option( 'jetonomy_settings', array() );

		// Respect the master toggle — if the admin turned email
		// verification off entirely, there's nothing to remind about.
		if ( empty( $settings['require_email_verification'] ) ) {
			return;
		}

		$hours = isset( $settings['verification_reminder_hours'] )
			? (int) $settings['verification_reminder_hours']
			: self::DEFAULT_HOURS;

		// 0 disables the reminder without unschedule()'ing the cron.
		if ( $hours <= 0 ) {
			return;
		}

		// Oldest registrations first so a backlog drains predictably.
		$candidates = UserProfile::list_awaiting_verification_reminder( gmdate( 'Y-m-d H:i:s', time() - ( $hours * HOUR_IN_SECONDS ) ), self::BATCH_SIZE );
		if ( empty( $candidates ) ) {
			return;
		}

		foreach ( $candidates as $row ) {
			$user_id = (int) $row->user_id;

			// Guard against ghost rows: profile exists but the WP user has
			// been deleted. The stamp still runs against a stale
			// row, but skipping the email keeps wp_mail() happy.
			$user = get_userdata( $user_id );
			if ( ! $user || empty( $user->user_email ) ) {
				UserProfile::mark_verification_reminder_sent( $user_id );
				continue;
			}

			// Re-check pending status from user-meta — the source of truth
			// for "did this person verify?" lives in usermeta written by
			// Auth_Controller, not on the profile row. A user could have
			// verified after the SELECT but before this iteration.
			$pending = (bool) get_user_meta( $user_id, '_jetonomy_pending_verification', true );
			if ( ! $pending ) {
				UserProfile::mark_verification_reminder_sent( $user_id );
				continue;
			}

			// Honour the user's email opt-out if one is on file. Documented
			// in A10-WORK-BRIEF.md as the only outbound-email preference
			// the reminder is required to respect.
			if ( get_user_meta( $user_id, 'jetonomy_email_opt_out', true ) ) {
				UserProfile::mark_verification_reminder_sent( $user_id );
				continue;
			}

			self::send_reminder( $user_id, $user );
			UserProfile::mark_verification_reminder_sent( $user_id );
		}
	}

	/**
	 * Send the reminder email itself. Uses the same branded template
	 * pipeline as every other Jetonomy notification — admins can
	 * customise subject + body via Settings → Email → Email Templates
	 * once the A8 admin editor ships, falling back to the defaults
	 * registered in `Jetonomy::activate()` until then.
	 *
	 * @param int      $user_id Recipient user id.
	 * @param \WP_User $user    Recipient user object (already loaded by run()).
	 */
	private static function send_reminder( int $user_id, \WP_User $user ): void {
		// Issue a fresh verification token — the original is likely
		// expired (default 24h TTL) and the whole point of the reminder
		// is to give the user a working link.
		$token      = wp_generate_password( 40, false );
		$token_hash = wp_hash_password( $token );
		$expires_at = time() + DAY_IN_SECONDS;

		update_user_meta( $user_id, '_jetonomy_verification_token_hash', $token_hash );
		update_user_meta( $user_id, '_jetonomy_verification_token_expires', $expires_at );
		update_user_meta( $user_id, '_jetonomy_verification_sent_at', time() );

		Notifier::send_verification_email( $user_id, $token );

		/**
		 * Fires after a verification reminder email is dispatched. Useful
		 * for analytics / re-engagement tracking.
		 *
		 * @param int      $user_id Recipient user id.
		 * @param \WP_User $user    Recipient user object.
		 */
		do_action( 'jetonomy_verification_reminder_sent', $user_id, $user );
	}
}
