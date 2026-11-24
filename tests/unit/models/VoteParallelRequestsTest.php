<?php
/**
 * Two parallel requests from one member must change a vote (or a report) once.
 *
 * A "parallel request" is played by a wpdb query filter: when the request under test opens its transaction (after it
 * has read the vote), the other request's write lands first, exactly as two tabs would interleave.
 *
 * @package Jetonomy\Tests
 */

namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\Vote;
use Jetonomy\Moderation\Moderation_Service;

class VoteParallelRequestsTest extends WP_UnitTestCase {

	private int $post_id;
	private int $author_id;
	private int $voter_id;

	/** @var array<string,int> Hook name => times fired. */
	private array $fired = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id          = Category::create( array( 'name' => 'General', 'slug' => 'parallel-' . uniqid() ) );
		$space_id        = Space::create( array( 'title' => 'Parallel', 'slug' => 'parallel-space-' . uniqid(), 'category_id' => $cat_id, 'visibility' => 'public' ) );
		$this->author_id = $this->factory()->user->create();
		$this->voter_id  = $this->factory()->user->create();
		$this->post_id   = Post::create( array( 'space_id' => $space_id, 'author_id' => $this->author_id, 'title' => 'Parallel post', 'slug' => 'parallel-post-' . uniqid(), 'content' => '<p>Body.</p>' ) );

		$this->fired = array();
		foreach ( array( 'jetonomy_vote_cast', 'jetonomy_vote_retracted', 'jetonomy_after_vote', 'jetonomy_reputation_changed' ) as $hook ) {
			$this->fired[ $hook ] = 0;
			add_action( $hook, function () use ( $hook ): void {
				++$this->fired[ $hook ];
			}, 10, 5 );
		}
	}

	/**
	 * Run $other once, the moment the request under test opens its transaction.
	 */
	private function interleave( callable $other ): void {
		$done = false;
		add_filter(
			'query',
			static function ( $sql ) use ( &$done, $other ) {
				if ( ! $done && 0 === stripos( ltrim( $sql ), 'START TRANSACTION' ) ) {
					$done = true;
					$other();
				}
				return $sql;
			}
		);
	}

	private function vote_row_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_votes WHERE user_id = %d AND object_type = 'post' AND object_id = %d", $this->voter_id, $this->post_id ) );
	}

	private function raw_vote( int $value ): void {
		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'jt_votes', array( 'user_id' => $this->voter_id, 'object_type' => 'post', 'object_id' => $this->post_id, 'value' => $value, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	}

	public function test_a_parallel_duplicate_vote_is_a_success_that_fires_and_awards_nothing(): void {
		$this->interleave( fn() => $this->raw_vote( 1 ) );

		$result = Vote::record( $this->voter_id, 'post', $this->post_id, 1 );

		$this->assertSame( 'unchanged', $result['action'] );
		$this->assertSame( 1, $this->vote_row_count() );
		$this->assertSame( 0, $this->fired['jetonomy_vote_cast'], 'The other request fires it, not this one.' );
		$this->assertSame( 0, $this->fired['jetonomy_after_vote'] );
		$this->assertSame( 0, $this->fired['jetonomy_reputation_changed'], 'No second reputation award.' );
	}

	public function test_a_parallel_retract_does_not_revoke_twice(): void {
		Vote::record( $this->voter_id, 'post', $this->post_id, 1 );
		$this->fired = array_map( static fn() => 0, $this->fired );
		global $wpdb;
		$this->interleave(
			function () use ( $wpdb ): void {
				$wpdb->delete( $wpdb->prefix . 'jt_votes', array( 'user_id' => $this->voter_id, 'object_type' => 'post', 'object_id' => $this->post_id ) );
			}
		);

		$result = Vote::record( $this->voter_id, 'post', $this->post_id, 1 );

		$this->assertSame( 'unchanged', $result['action'] );
		$this->assertSame( 0, $this->vote_row_count() );
		$this->assertSame( 0, $this->fired['jetonomy_vote_retracted'] );
		$this->assertSame( 0, $this->fired['jetonomy_after_vote'] );
		$this->assertSame( 0, $this->fired['jetonomy_reputation_changed'], 'The reputation is taken back once, by the request that removed the vote.' );
	}

	public function test_a_parallel_flip_does_not_award_twice(): void {
		Vote::record( $this->voter_id, 'post', $this->post_id, 1 );
		$this->fired = array_map( static fn() => 0, $this->fired );
		global $wpdb;
		$this->interleave(
			function () use ( $wpdb ): void {
				$wpdb->update( $wpdb->prefix . 'jt_votes', array( 'value' => -1 ), array( 'user_id' => $this->voter_id, 'object_type' => 'post', 'object_id' => $this->post_id ) );
			}
		);

		$result = Vote::record( $this->voter_id, 'post', $this->post_id, -1 );

		$this->assertSame( 'unchanged', $result['action'] );
		$this->assertSame( 0, $this->fired['jetonomy_after_vote'] );
		$this->assertSame( 0, $this->fired['jetonomy_reputation_changed'] );
	}

	public function test_without_a_parallel_request_every_outcome_still_fires_once(): void {
		$this->assertSame( 'created', Vote::record( $this->voter_id, 'post', $this->post_id, 1 )['action'] );
		$this->assertSame( 'updated', Vote::record( $this->voter_id, 'post', $this->post_id, -1 )['action'] );
		$this->assertSame( 'removed', Vote::record( $this->voter_id, 'post', $this->post_id, -1 )['action'] );
		$this->assertSame( 3, $this->fired['jetonomy_after_vote'] );
	}

	public function test_a_parallel_report_from_one_member_is_stored_once(): void {
		global $wpdb;
		$reporter = $this->factory()->user->create();
		$data     = array( 'reporter_id' => $reporter, 'object_type' => 'post', 'object_id' => $this->post_id, 'reason' => 'spam' );

		$first  = Flag::create( $data );
		$second = Flag::create( $data );

		$this->assertGreaterThan( 0, $first );
		$this->assertSame( 0, $second, 'The same member reporting the same object again stores nothing.' );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_flags WHERE reporter_id = %d AND object_id = %d", $reporter, $this->post_id ) ) );
		$this->assertSame( 1, (int) Post::find( $this->post_id )->flag_count, 'The flag counter moved once.' );

		$other = Flag::create( array_merge( $data, array( 'reporter_id' => $this->factory()->user->create() ) ) );
		$this->assertGreaterThan( 0, $other, 'A different member can still report it.' );
		$system = Flag::create( array( 'reporter_id' => 0, 'object_type' => 'post', 'object_id' => $this->post_id, 'reason' => 'spam' ) );
		$this->assertGreaterThan( 0, $system, 'A system report is not deduplicated.' );
	}

	/**
	 * Up in one tab, down in another: the request that lost reports the vote the member really has, so the page lights
	 * the right arrow instead of the one it clicked.
	 */
	public function test_the_vote_response_reports_the_stored_vote_when_a_parallel_request_decided_otherwise(): void {
		get_user_by( 'id', $this->voter_id )->add_cap( 'jetonomy_vote' );
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->voter_id );
		$this->interleave( fn() => $this->raw_vote( -1 ) );

		$request = new \WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/vote" );
		$request->set_body_params( [ 'value' => 1 ] );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( -1, $data['user_vote'], 'The stored vote is the downvote the other request cast.' );
		$this->assertTrue( $data['unchanged'] );
		$this->assertSame( 1, $this->vote_row_count() );
	}

	public function test_an_ordinary_vote_response_carries_the_stored_vote(): void {
		get_user_by( 'id', $this->voter_id )->add_cap( 'jetonomy_vote' );
		do_action( 'rest_api_init' );
		wp_set_current_user( $this->voter_id );

		$up = new \WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/vote" );
		$up->set_body_params( [ 'value' => 1 ] );
		$this->assertSame( 1, rest_do_request( $up )->get_data()['user_vote'] );

		$again = new \WP_REST_Request( 'POST', "/jetonomy/v1/posts/{$this->post_id}/vote" );
		$again->set_body_params( [ 'value' => 1 ] );
		$this->assertSame( 0, rest_do_request( $again )->get_data()['user_vote'], 'Pressing the active vote again takes it back.' );
	}
}
