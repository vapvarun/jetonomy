<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\Subscription;
use Jetonomy\Recount_Backfill;

/**
 * Queued jobs run the way Action Scheduler runs them: by firing the hook name
 * with the stored args. Each test proves the job's effect, so a renamed hook or
 * a dropped add_action() fails here instead of leaving the job to no-op on a site.
 *
 * @covers-existing The jobs already worked; this pins them to their hook names.
 */
class QueuedJobsRunByHookTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	private function notices( int $user_id, string $type ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_notifications WHERE user_id = %d AND type = %s", $user_id, $type )
		);
	}

	/** @return array{int,int,int} space id, topic id, topic author id */
	private function public_topic(): array {
		$author = $this->factory()->user->create();
		$space  = (int) Space::create(
			array(
				'title'      => 'Hook job space',
				'slug'       => 'hook-job-' . uniqid(),
				'visibility' => 'public',
			),
			$this->factory()->user->create( array( 'role' => 'administrator' ) )
		);
		$post   = (int) Post::create(
			array(
				'space_id'  => $space,
				'author_id' => $author,
				'title'     => 'Hook job topic',
				'slug'      => 'hook-job-topic-' . uniqid(),
				'content'   => '<p>Body.</p>',
				'status'    => 'publish',
			)
		);
		return array( $space, $post, $author );
	}

	public function test_post_fanout_job_tells_space_followers(): void {
		[ $space, $post ] = $this->public_topic();
		$follower         = $this->factory()->user->create();
		Subscription::subscribe( $follower, 'space', $space );

		do_action( 'jetonomy_fanout_post_subscribers', $post, $space, 0 );

		$this->assertSame( 1, $this->notices( $follower, 'new_post_in_sub' ) );
	}

	public function test_reply_fanout_job_tells_topic_followers(): void {
		[ , $post ] = $this->public_topic();
		$follower   = $this->factory()->user->create();
		$reply      = (int) Reply::create(
			array(
				'post_id'   => $post,
				'author_id' => $this->factory()->user->create(),
				'content'   => '<p>A reply.</p>',
				'status'    => 'publish',
			)
		);
		Subscription::subscribe( $follower, 'post', $post );

		do_action( 'jetonomy_fanout_reply_subscribers', $reply, $post, 0 );

		$this->assertSame( 1, $this->notices( $follower, 'reply_to_post' ) );
	}

	public function test_gravatar_check_job_stores_the_answer(): void {
		$user  = $this->factory()->user->create();
		$reply = static fn() => array(
			'headers'  => array(),
			'body'     => '',
			'response' => array( 'code' => 200 ),
		);
		add_filter( 'pre_http_request', $reply );

		do_action( \Jetonomy\Avatar::CHECK_HOOK, $user );

		remove_filter( 'pre_http_request', $reply );
		$this->assertSame( '1', get_user_meta( $user, 'jetonomy_has_gravatar', true ) );
	}

	public function test_recount_backfill_job_moves_the_cursor(): void {
		delete_option( 'jetonomy_recount_backfill' );
		Recount_Backfill::mark_pending();
		$before = Recount_Backfill::state();

		do_action( Recount_Backfill::BATCH_HOOK );

		$after = Recount_Backfill::state();
		$this->assertNotSame( array( $before['stage'], $before['cursor'], $before['done'] ), array( $after['stage'], $after['cursor'], $after['done'] ) );
	}
}
