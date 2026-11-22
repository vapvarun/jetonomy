<?php
/**
 * The deprecated Jetonomy_Pro\Queue keeps add-ons written against 2.0.7 working: each old-signature call reaches
 * \Jetonomy\Queue and logs a deprecation.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class ProQueueShimTest extends WP_UnitTestCase {

	private const HOOK = 'jetonomy_test_shim_job';

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Jetonomy_Pro\Queue' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
	}

	public function tear_down(): void {
		\Jetonomy\Queue::cancel( self::HOOK );
		parent::tear_down();
	}

	/**
	 * @expectedDeprecated Jetonomy_Pro\Queue::async
	 * @expectedDeprecated Jetonomy_Pro\Queue::next
	 * @expectedDeprecated Jetonomy_Pro\Queue::cancel
	 * @expectedDeprecated Jetonomy_Pro\Queue::init
	 */
	public function test_old_signatures_still_queue_look_up_and_cancel(): void {
		\Jetonomy_Pro\Queue::init();
		$when = time() + HOUR_IN_SECONDS;
		\Jetonomy\Queue::at( $when, self::HOOK, array( 8 ) );
		\Jetonomy_Pro\Queue::async( self::HOOK, array( 7 ), 'jetonomy' );
		$this->assertTrue( \Jetonomy\Queue::is_queued( self::HOOK, array( 7 ) ) );
		\Jetonomy\Queue::cancel( self::HOOK, array( 7 ) );
		$this->assertSame( $when, \Jetonomy_Pro\Queue::next( self::HOOK, 'jetonomy' ) );

		\Jetonomy_Pro\Queue::cancel( self::HOOK );
		$this->assertFalse( \Jetonomy\Queue::is_queued( self::HOOK ) );
	}

	/**
	 * @expectedDeprecated Jetonomy_Pro\Queue::batch
	 * @expectedDeprecated Jetonomy_Pro\Queue::next
	 */
	public function test_batch_queues_one_job_per_slice_and_next_answers_false_when_none(): void {
		$this->assertFalse( \Jetonomy_Pro\Queue::next( self::HOOK ) );
		\Jetonomy_Pro\Queue::batch( self::HOOK, 120, 50, array( 'frequency' => 'daily' ) );
		foreach ( array( 0, 50, 100 ) as $offset ) {
			$this->assertTrue(
				\Jetonomy\Queue::is_queued(
					self::HOOK,
					array(
						array(
							'frequency' => 'daily',
							'offset'    => $offset,
							'limit'     => 50,
						),
					)
				),
				"slice at {$offset} queued"
			);
		}
	}

	/**
	 * The 2.0.7 five-parameter recurring( hook, interval, args, group, first_run ): with arguments it keeps one
	 * recurring copy with those arguments at first_run; without, it registers with Free's scheduler and arms one copy.
	 *
	 * @expectedDeprecated Jetonomy_Pro\Queue::recurring
	 */
	public function test_recurring_five_parameter_form_with_and_without_args(): void {
		add_filter( 'wp_doing_cron', '__return_true' ); // Recurring jobs are armed on scheduling requests.
		try {
			$first = time() + 2 * HOUR_IN_SECONDS;
			\Jetonomy_Pro\Queue::recurring( self::HOOK, DAY_IN_SECONDS, array( 9 ), 'other-group', $first );
			\Jetonomy_Pro\Queue::recurring( self::HOOK, DAY_IN_SECONDS, array( 9 ), 'other-group', $first );
			$with = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array( 9 ), 'status' => 'pending' ) );
			$this->assertCount( 1, $with, 'one recurring copy with its arguments, called twice' );
			$action = reset( $with );
			$this->assertSame( $first, $action->get_schedule()->get_date()->getTimestamp() );
			$this->assertSame( 'jetonomy', $action->get_group(), 'the group argument is ignored' );

			\Jetonomy\Queue::cancel( self::HOOK );
			\Jetonomy_Pro\Queue::recurring( self::HOOK, DAY_IN_SECONDS, array(), 'jetonomy', $first );
			$without = as_get_scheduled_actions( array( 'hook' => self::HOOK, 'args' => array(), 'status' => 'pending' ) );
			$this->assertCount( 1, $without );
			$this->assertArrayHasKey( self::HOOK, apply_filters( 'jetonomy_recurring_jobs', array() ), 'registered with Free\'s scheduler' );
		} finally {
			remove_filter( 'wp_doing_cron', '__return_true' );
		}
	}
}
