<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Jetonomy 2.0.8 (schema step 2.0.8.1): the hourly expired-restriction cleanup, verification reminder and roster
 * reconcile became one job armed when something is due (Cron::DUE_HOOK). Cancel the old recurring copies so they
 * stop ticking; the next scheduling request arms the new job if anything is due. Also drops the retired 2.0.1
 * jetonomy_publish_single_armed flag, which ensure_scheduled() used to delete on every scheduling request.
 *
 * Kept as its own step rather than folded into 2_0_8: internal sites were already stamped 2.0.8 when it landed.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_2_0_8_1 {

	public function up(): void {
		delete_option( 'jetonomy_publish_single_armed' ); // Retired 2.0.1 one-time flag.
		\Jetonomy\Queue::when_ready(
			static function (): void {
				foreach ( \Jetonomy\Cron::RETIRED_HOOKS as $hook ) {
					\Jetonomy\Queue::cancel( $hook );
				}
			}
		);
	}
}
