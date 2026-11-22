<?php
/**
 * Deactivation (step one of every manual update) cancels recomputable jobs and leaves pending the ones that finish
 * something a member or the owner already did: a space purge and a scheduled topic among them.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\Queue;
use Jetonomy\Space_Purge;
use Jetonomy\Recount_Backfill;

class DeactivationKeepsPromisedJobsTest extends WP_UnitTestCase {

	private const KEPT      = array( Space_Purge::BATCH_HOOK, Cron::PUBLISH_HOOK, 'jetonomy_fanout_post_subscribers' );
	private const CANCELLED = array( Cron::DUE_HOOK, 'jetonomy_trust_evaluation_batch', Recount_Backfill::BATCH_HOOK, 'jetonomy_gravatar_check' );

	public function set_up(): void {
		parent::set_up();
		if ( ! Queue::has_as() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	public function tear_down(): void {
		foreach ( array_merge( self::KEPT, self::CANCELLED ) as $hook ) {
			Queue::cancel( $hook );
		}
		parent::tear_down();
	}

	private function pending( string $hook ): int {
		return count( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'per_page' => 10 ), 'ids' ) );
	}

	public function test_deactivation_keeps_a_space_purge_and_a_scheduled_topic_and_cancels_recomputable_jobs(): void {
		as_enqueue_async_action( Space_Purge::BATCH_HOOK, array( 'space_id' => 42 ), Queue::GROUP );
		as_schedule_single_action( time() + DAY_IN_SECONDS, Cron::PUBLISH_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_fanout_post_subscribers', array( 7, 0 ), Queue::GROUP );
		as_schedule_single_action( time() + HOUR_IN_SECONDS, Cron::DUE_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_trust_evaluation_batch', array(), Queue::GROUP );
		as_enqueue_async_action( Recount_Backfill::BATCH_HOOK, array(), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_gravatar_check', array( 3 ), Queue::GROUP );

		\Jetonomy\Jetonomy::instance()->deactivate();

		foreach ( self::KEPT as $hook ) {
			$this->assertSame( 1, $this->pending( $hook ), $hook . ' finishes something already done: kept pending' );
		}
		foreach ( self::CANCELLED as $hook ) {
			$this->assertSame( 0, $this->pending( $hook ), $hook . ' is recomputed: cancelled' );
		}
	}

	/** Pro: an extension's deactivate() goes through the same list. */
	public function test_a_pro_extension_deactivation_keeps_the_announcement_and_cancels_its_sends(): void {
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		require_once JETONOMY_PRO_DIR . 'includes/extensions/site-announcements/class-extension.php';
		require_once JETONOMY_PRO_DIR . 'includes/extensions/webhooks/class-extension.php';
		as_enqueue_async_action( 'jetonomy_pro_announce_post', array( 5, 0 ), Queue::GROUP );
		as_enqueue_async_action( 'jetonomy_pro_deliver_webhook', array( 1, 'e', '{}' ), Queue::GROUP );
		try {
			( new \Jetonomy_Pro\Extensions\Site_Announcements\Extension() )->deactivate();
			( new \Jetonomy_Pro\Extensions\Webhooks\Extension() )->deactivate();
			$this->assertSame( 1, $this->pending( 'jetonomy_pro_announce_post' ) );
			$this->assertSame( 0, $this->pending( 'jetonomy_pro_deliver_webhook' ) );
		} finally {
			Queue::cancel( 'jetonomy_pro_announce_post' );
			Queue::cancel( 'jetonomy_pro_deliver_webhook' );
		}
	}
}
