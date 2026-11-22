<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Jetonomy 2.0.8 (schema step 2.0.8.1): the hourly expired-restriction cleanup, verification reminder and roster
 * reconcile became one job armed when something is due (Cron::DUE_HOOK). Cancel the old recurring copies so they
 * stop ticking; the next scheduling request arms the new job if anything is due.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_8_1 {

	public function up(): void {
		$cancel = static function (): void {
			foreach ( \Jetonomy\Cron::RETIRED_HOOKS as $hook ) {
				\Jetonomy\Queue::cancel( $hook );
			}
		};
		// Action Scheduler's store is ready only from action_scheduler_init.
		if ( did_action( 'action_scheduler_init' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			$cancel();
		} else {
			add_action( 'action_scheduler_init', $cancel, 30 );
		}
	}
}
