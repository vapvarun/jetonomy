<?php
/**
 * A scheduled topic that never publishes is tried 4 times, then once a day: the self-heal on every cron and
 * wp-admin request must not re-arm it, while another topic scheduled sooner still gets its run.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Cron;
use Jetonomy\Queue;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

class PublishRetryCapTest extends WP_UnitTestCase {

	private int $space_id;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		if ( ! Queue::has_as() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		Schema::create_tables();
		Queue::cancel( Cron::PUBLISH_HOOK );
		$suffix          = uniqid( 'prc_', true );
		$cat             = (int) Category::create(
			array(
				'name' => 'Retry cap',
				'slug' => 'cat-' . $suffix,
			)
		);
		$this->space_id  = (int) Space::create(
			array(
				'category_id' => $cat,
				'title'       => 'Retry cap space',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			),
			0
		);
		$this->author_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		add_filter( 'wp_doing_cron', '__return_true' ); // ensure_scheduled() checks only on scheduling requests.
	}

	public function tear_down(): void {
		remove_filter( 'wp_doing_cron', '__return_true' );
		Queue::cancel( Cron::PUBLISH_HOOK );
		parent::tear_down();
	}

	/** A draft scheduled at $when (UTC Unix time). */
	private function scheduled( string $title, int $when ): int {
		$id = (int) Post::create(
			array(
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => $title,
				'content'   => 'Body of ' . $title,
				'status'    => 'draft',
			)
		);
		Post::update( $id, array( 'published_at' => gmdate( 'Y-m-d H:i:s', $when ) ) );
		return $id;
	}

	/** Pending publisher copies: [ scheduled time => args ]. */
	private function runs(): array {
		$runs = array();
		foreach ( as_get_scheduled_actions( array( 'hook' => Cron::PUBLISH_HOOK, 'status' => 'pending', 'per_page' => 10 ) ) as $action ) {
			$runs[] = array( $action->get_schedule()->get_date()->getTimestamp(), array_values( $action->get_args() ) );
		}
		return $runs;
	}

	/** Run the publisher 4 times (the run and its 3 retries) with the topic refusing to publish. */
	private function give_up(): void {
		$down = static function () {
			throw new \RuntimeException( 'screening service down' );
		};
		add_filter( 'jetonomy_check_content', $down );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_ini_set -- keep the give-up line out of the test output.
		$log = ini_set( 'error_log', '/dev/null' );
		try {
			$cron = new Cron();
			for ( $attempt = 0; $attempt <= 3; $attempt++ ) {
				$cron->publish_scheduled_posts( $attempt );
			}
		} finally {
			ini_set( 'error_log', (string) $log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
			remove_filter( 'jetonomy_check_content', $down );
		}
	}

	public function test_after_giving_up_the_self_heal_does_not_rearm_a_failing_topic(): void {
		$failing = $this->scheduled( 'Never publishes', time() - MINUTE_IN_SECONDS );
		$this->give_up();
		$this->assertSame( 'draft', Post::find( $failing )->status );

		Cron::ensure_scheduled();
		Cron::ensure_scheduled();
		Cron::arm_scheduled_publish(); // As any schedule write would.

		$runs = $this->runs();
		$this->assertCount( 1, $runs, 'one publisher run, no stack' );
		$this->assertGreaterThan( time() + HOUR_IN_SECONDS, $runs[0][0], 'no new run within the hour' );
		$this->assertSame( array( 3 ), $runs[0][1], 'it is the give-up run' );
	}

	public function test_a_topic_scheduled_sooner_still_gets_its_run_after_a_give_up(): void {
		$this->scheduled( 'Never publishes', time() - MINUTE_IN_SECONDS );
		$this->give_up();

		$soon = time() + 2 * HOUR_IN_SECONDS;
		$this->scheduled( 'Due in two hours', $soon );
		Cron::arm_scheduled_publish();

		$runs = $this->runs();
		$this->assertCount( 1, $runs );
		$this->assertSame( $soon, $runs[0][0], 'the run moved to the sooner topic' );
	}
}
