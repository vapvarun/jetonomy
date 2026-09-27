<?php
/**
 * Writes against trashed content, and topic scheduling.
 *
 * Basecamp 10335997408 (QA bounce): DELETE on an already-trashed topic
 * returned 200 {"deleted":true} and changed nothing, so the author's "Delete"
 * on their own trashed topic was a dead action.
 *
 * Basecamp 10344391319: past and time-less schedules were accepted, a second
 * submit queued a duplicate copy, and publishing ran on an hourly poll.
 *
 * @package Jetonomy\Tests\Unit\API
 */

namespace Jetonomy\Tests\Unit\API;

use WP_UnitTestCase;
use WP_REST_Request;
use WP_REST_Server;
use Jetonomy\Cron;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

class TrashAndScheduleRestTest extends WP_UnitTestCase {

	private int $space_id;
	private int $author_id;
	private int $mod_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init' );

		$suffix         = uniqid( 'tas_', true );
		$cat            = (int) Category::create(
			[
				'name' => 'Trash and schedule',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Trash and schedule space',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			],
			0
		);

		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->mod_id    = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		foreach ( [ $this->author_id, $this->mod_id ] as $uid ) {
			UserProfile::find_or_create( $uid );
		}
		SpaceMember::add( $this->space_id, $this->author_id, 'member' );
		SpaceMember::add( $this->space_id, $this->mod_id, 'moderator' );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		update_option( 'timezone_string', '' );
		update_option( 'gmt_offset', 0 );
		parent::tear_down();
	}

	/** @return array{0:int,1:mixed} Status and response data. */
	private function call( int $uid, string $method, string $route, array $params = [] ): array {
		wp_set_current_user( $uid );
		$req = new WP_REST_Request( $method, '/jetonomy/v1' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = rest_do_request( $req );
		return [ $res->get_status(), $res->get_data() ];
	}

	private function schedule( string $title, string $published_at ): array {
		return $this->call(
			$this->author_id,
			'POST',
			"/spaces/{$this->space_id}/posts",
			[
				'title'        => $title,
				'content'      => '<p>body</p>',
				'status'       => 'draft',
				'published_at' => $published_at,
			]
		);
	}

	private function next_publish_run(): int {
		return function_exists( 'as_next_scheduled_action' )
			? (int) as_next_scheduled_action( Cron::PUBLISH_HOOK, [], 'jetonomy' )
			: (int) wp_next_scheduled( Cron::PUBLISH_HOOK );
	}

	public function test_writes_to_a_trashed_topic_or_reply_are_409_not_a_silent_200(): void {
		$post_id  = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Trash me',
				'content'   => '<p>body</p>',
				'status'    => 'publish',
			]
		);
		$reply_id = (int) Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => '<p>reply</p>',
				'status'    => 'publish',
			]
		);

		$this->assertSame( 200, $this->call( $this->author_id, 'DELETE', "/replies/{$reply_id}" )[0] );
		[ $status, $data ] = $this->call( $this->author_id, 'DELETE', "/replies/{$reply_id}" );
		$this->assertSame( 409, $status );
		$this->assertSame( 'jetonomy_already_trashed', $data['code'] );
		$this->assertSame( 409, $this->call( $this->author_id, 'PATCH', "/replies/{$reply_id}", [ 'content' => '<p>x</p>' ] )[0] );

		$this->assertSame( 200, $this->call( $this->author_id, 'DELETE', "/posts/{$post_id}" )[0] );
		[ $status, $data ] = $this->call( $this->author_id, 'DELETE', "/posts/{$post_id}" );
		$this->assertSame( 409, $status );
		$this->assertSame( 'jetonomy_already_trashed', $data['code'] );
		$this->assertSame( 409, $this->call( $this->author_id, 'PATCH', "/posts/{$post_id}", [ 'title' => 'Edited' ] )[0] );
		$this->assertSame( 'Trash me', Post::find( $post_id )->title, 'a refused edit must not write' );

		// Moderators get the same 409 for a plain re-trash; purging is ?force=true.
		$this->assertSame( 409, $this->call( $this->mod_id, 'DELETE', "/posts/{$post_id}" )[0] );
		$this->assertSame( 200, $this->call( $this->mod_id, 'DELETE', "/posts/{$post_id}", [ 'force' => true ] )[0] );
		$this->assertNull( Post::find( $post_id ) );
	}

	public function test_schedule_rejects_past_and_timeless_values(): void {
		[ $status, $data ] = $this->schedule( 'Past', wp_date( 'Y-m-d\TH:i:s', time() - HOUR_IN_SECONDS ) );
		$this->assertSame( 400, $status );
		$this->assertSame( 'jetonomy_schedule_in_past', $data['code'] );

		[ $status, $data ] = $this->schedule( 'Date only', wp_date( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) );
		$this->assertSame( 400, $status );
		$this->assertSame( 'jetonomy_schedule_time_required', $data['code'] );

		$this->assertSame( 201, $this->schedule( 'Future', wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) )[0] );
	}

	/** "Future" is judged in the site timezone, the one the member picked in. */
	public function test_schedule_future_check_uses_site_timezone(): void {
		update_option( 'timezone_string', 'Pacific/Kiritimati' ); // UTC+14.

		// One hour from now on the site's clock: future.
		$this->assertSame( 201, $this->schedule( 'Site clock', wp_date( 'Y-m-d\TH:i:s', time() + HOUR_IN_SECONDS ) )[0] );
		// One hour from now on the UTC clock, read as site time: 13 hours ago.
		$this->assertSame( 400, $this->schedule( 'UTC clock', gmdate( 'Y-m-d\TH:i:s', time() + HOUR_IN_SECONDS ) )[0] );
	}

	public function test_identical_schedule_twice_is_refused(): void {
		$when = wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS );
		[ $status, $first ] = $this->schedule( 'Twice', $when );
		$this->assertSame( 201, $status );

		[ $status, $data ] = $this->schedule( 'Twice', $when );
		$this->assertSame( 409, $status );
		$this->assertSame( 'jetonomy_duplicate_scheduled', $data['code'] );
		$this->assertSame( (int) $first['id'], (int) $data['data']['post_id'] );

		// A different time is a different topic.
		$this->assertSame( 201, $this->schedule( 'Twice', wp_date( 'Y-m-d\TH:i:s', time() + 2 * DAY_IN_SECONDS ) )[0] );
	}

	public function test_publisher_is_armed_at_the_earliest_schedule_and_rearmed_after_a_run(): void {
		[ , $later ] = $this->schedule( 'Later', wp_date( 'Y-m-d\TH:i:s', time() + 2 * DAY_IN_SECONDS ) );
		[ , $soon ]  = $this->schedule( 'Sooner', wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) );

		$soon_ts = strtotime( Post::find( (int) $soon['id'] )->published_at . ' UTC' );
		$this->assertSame( $soon_ts, $this->next_publish_run(), 'one run, at the earliest schedule - no hourly poll' );

		// Make the earlier one due and run the publisher.
		Post::update( (int) $soon['id'], [ 'published_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ] );
		( new Cron() )->publish_scheduled_posts();

		$this->assertSame( 'publish', Post::find( (int) $soon['id'] )->status );
		$this->assertSame( 'draft', Post::find( (int) $later['id'] )->status );
		$later_ts = strtotime( Post::find( (int) $later['id'] )->published_at . ' UTC' );
		$this->assertSame( $later_ts, $this->next_publish_run(), 're-armed for the next schedule' );

		// Nothing left scheduled: disarmed, nothing polls.
		Post::update( (int) $later['id'], [ 'published_at' => null ] );
		$this->assertSame( 0, $this->next_publish_run() );

		// Deleting the only scheduled draft disarms too.
		[ , $gone ] = $this->schedule( 'Deleted', wp_date( 'Y-m-d\TH:i:s', time() + DAY_IN_SECONDS ) );
		$this->assertGreaterThan( 0, $this->next_publish_run() );
		Post::delete( (int) $gone['id'] );
		$this->assertSame( 0, $this->next_publish_run() );
	}
}
