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
}
