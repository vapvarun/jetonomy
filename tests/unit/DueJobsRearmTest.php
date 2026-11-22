<?php
/**
 * The two due-time jobs re-arm from inside their own run when Action Scheduler's real runner executes them: the
 * runner marks the row in-progress first, and AS 4.0's unique insert refuses while that row runs, so Queue::at()
 * must still add the next copy. Also: recover_kept_jobs() costs a normal page view nothing.
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
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;

class DueJobsRearmTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->assertTrue( Queue::has_as(), 'Action Scheduler must be loaded for these tests' );
		Schema::create_tables();
		Queue::cancel( Cron::DUE_HOOK );
		Queue::cancel( Cron::PUBLISH_HOOK );
	}

	public function tear_down(): void {
		Queue::cancel( Cron::DUE_HOOK );
		Queue::cancel( Cron::PUBLISH_HOOK );
		parent::tear_down();
	}

	/** Pending copies of $hook: their scheduled times. */
	private function pending_at( string $hook ): array {
		$at = array();
		foreach ( as_get_scheduled_actions( array( 'hook' => $hook, 'status' => 'pending', 'per_page' => 10 ) ) as $action ) {
			$at[] = $action->get_schedule()->get_date()->getTimestamp();
		}
		return $at;
	}

	private function run_through_runner( string $hook ): void {
		$id = (int) as_enqueue_async_action( $hook, array(), Queue::GROUP );
		\ActionScheduler::runner()->process_action( $id, 'WP Cron' ); // In-progress, then execute(): the real path.
		$this->assertSame( 'complete', \ActionScheduler::store()->get_status( (string) $id ) );
	}

	public function test_the_due_job_rearms_itself_from_inside_its_run(): void {
		$expires = time() + 2 * HOUR_IN_SECONDS;
		Restriction::insert(
			array(
				'user_id'    => self::factory()->user->create(),
				'type'       => 'silence',
				'expires_at' => gmdate( 'Y-m-d H:i:s', $expires ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$this->run_through_runner( Cron::DUE_HOOK );
		$this->assertSame( array( $expires ), $this->pending_at( Cron::DUE_HOOK ), 'the next run is armed for the expiry' );
	}

	public function test_the_publisher_rearms_itself_from_inside_its_run(): void {
		$suffix = uniqid( 'rearm_', true );
		$space  = (int) Space::create(
			array(
				'category_id' => (int) Category::create( array( 'name' => 'Rearm', 'slug' => 'c-' . $suffix ) ),
				'title'       => 'Rearm',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			),
			0
		);
		$post = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => self::factory()->user->create(),
				'title'     => 'Later',
				'content'   => 'Body',
				'status'    => 'draft',
			)
		);
		$when = time() + 2 * HOUR_IN_SECONDS;
		Post::update( $post, array( 'published_at' => gmdate( 'Y-m-d H:i:s', $when ) ) );
		Queue::cancel( Cron::PUBLISH_HOOK ); // Post::update armed one; the test queues its own run.

		$this->run_through_runner( Cron::PUBLISH_HOOK );
		$this->assertSame( array( $when ), $this->pending_at( Cron::PUBLISH_HOOK ), 'the next run is armed for the scheduled topic' );
	}

	public function test_recovery_costs_a_normal_page_view_no_query(): void {
		global $wpdb;
		$read  = false;
		$probe = static function ( $value ) use ( &$read ) {
			$read = true;
			return $value;
		};
		add_filter( 'pre_transient_' . Queue::RECOVER_FLAG, $probe );
		set_current_screen( 'front' );
		$before = $wpdb->num_queries;
		try {
			$this->assertFalse( Queue::is_scheduling_request(), 'a front-end page view' );
			$this->assertSame( 0, Queue::recover_kept_jobs() );
		} finally {
			remove_filter( 'pre_transient_' . Queue::RECOVER_FLAG, $probe );
		}
		$this->assertFalse( $read, 'the flag is not even read' );
		$this->assertSame( $before, $wpdb->num_queries, 'no query' );
	}
}
