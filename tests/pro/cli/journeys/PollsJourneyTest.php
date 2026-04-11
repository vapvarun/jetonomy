<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\DB\Schema;
use Jetonomy_Pro\CLI\Journeys\Polls_Journey;

/**
 * Integration tests for Polls_Journey against the live Pro polls tables.
 *
 * The Pro plugin must be active during the test run so the `wp_jt_pro_poll*`
 * tables exist. Each test creates a fresh Jetonomy post via Post::create()
 * so every test runs against a clean fixture, and tear_down() removes
 * every row the suite may have inserted so runs are independent.
 *
 * Single-choice correctness is enforced by the journey itself — it will
 * fail with "only one choice" when multiple indexes are passed to a
 * `single` poll, matching the REST handler in the Polls extension.
 */
class PollsJourneyTest extends WP_UnitTestCase {

	private Polls_Journey $journey;

	private int $user_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Polls_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Polls_Journey.' );
		}

		Schema::create_tables();

		// Ensure the polls extension is active and its tables exist.
		update_option( 'jetonomy_pro_extensions', array( 'polls' ) );
		update_option(
			'jetonomy_pro_license',
			array(
				'key'        => 'test-key',
				'status'     => 'valid',
				'expires'    => 'lifetime',
				'tier'       => 'lifetime',
				'item_name'  => 'Jetonomy Pro',
				'checked_at' => current_time( 'mysql', true ),
			)
		);

		if ( class_exists( 'Jetonomy_Pro\\Extensions\\Polls\\Extension' ) ) {
			$ext = new \Jetonomy_Pro\Extensions\Polls\Extension();
			$ext->activate();
		}

		$this->journey = new Polls_Journey();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$cat_id           = Category::create(
			array(
				'name' => 'Poll Journey Cat',
				'slug' => 'polls-journey-cat-' . uniqid(),
			)
		);
		$space_id         = Space::create(
			array(
				'title'       => 'Polls Journey Space',
				'slug'        => 'polls-journey-space-' . uniqid(),
				'category_id' => $cat_id,
				'visibility'  => 'public',
			)
		);
		$post_id_or_error = Post::create(
			array(
				'space_id'  => $space_id,
				'author_id' => $this->user_id,
				'title'     => 'Polls Journey Post',
				'slug'      => 'polls-journey-post-' . uniqid(),
				'content'   => '<p>Journey fixture</p>',
				'status'    => 'publish',
			)
		);
		$this->post_id    = is_int( $post_id_or_error ) ? $post_id_or_error : 0;

		$this->truncate_poll_tables();
	}

	public function tear_down(): void {
		$this->truncate_poll_tables();
		parent::tear_down();
	}

	private function truncate_poll_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_poll_votes" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_poll_options" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_polls" );
	}

	private function create_single_poll( array $overrides = array() ): Journey_Result {
		return $this->journey->create_poll(
			array_merge(
				array(
					'post_id'    => $this->post_id,
					'question'   => 'Favourite colour?',
					'options'    => array( 'Red', 'Green', 'Blue' ),
					'created_by' => $this->user_id,
				),
				$overrides
			)
		);
	}

	public function test_create_poll_stores_options(): void {
		$result = $this->create_single_poll();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 3, (int) $result->data['option_count'] );
		$this->assertSame( $this->post_id, (int) $result->data['post_id'] );
		$this->assertSame( 'single', (string) $result->data['type'] );
		$this->assertGreaterThan( 0, (int) $result->data['poll_id'] );

		$fetched = $this->journey->get_poll( $this->post_id );
		$this->assertTrue( $fetched->is_success() );
		$this->assertCount( 3, $fetched->data['options'] );
		$this->assertSame( 'Red', $fetched->data['options'][0]['label'] );
	}

	public function test_create_poll_requires_at_least_two_options(): void {
		$result = $this->journey->create_poll(
			array(
				'post_id'    => $this->post_id,
				'question'   => 'Too few?',
				'options'    => array( 'Only one' ),
				'created_by' => $this->user_id,
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'at least 2', strtolower( (string) $result->first_error() ) );
	}

	public function test_create_poll_rejects_empty_question(): void {
		$result = $this->journey->create_poll(
			array(
				'post_id'    => $this->post_id,
				'question'   => '   ',
				'options'    => array( 'A', 'B' ),
				'created_by' => $this->user_id,
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'question', strtolower( (string) $result->first_error() ) );
	}

	public function test_cast_vote_records_selection(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$vote = $this->journey->cast_vote( $this->post_id, $this->user_id, array( 1 ) );
		$this->assertTrue( $vote->is_success(), implode( '; ', $vote->errors ) );
		$this->assertTrue( (bool) $vote->data['changed'] );

		$results = $this->journey->list_poll_results( $this->post_id );
		$this->assertTrue( $results->is_success() );

		$tallies = array_column( $results->data['items'], 'vote_count', 'index' );
		$this->assertSame( 0, (int) $tallies[0] );
		$this->assertSame( 1, (int) $tallies[1] );
		$this->assertSame( 0, (int) $tallies[2] );
	}

	public function test_cast_vote_rejects_out_of_range_index(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$result = $this->journey->cast_vote( $this->post_id, $this->user_id, array( 99 ) );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'out of range', strtolower( (string) $result->first_error() ) );
	}

	public function test_cast_vote_rejects_empty_options(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$result = $this->journey->cast_vote( $this->post_id, $this->user_id, array() );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'at least one', strtolower( (string) $result->first_error() ) );
	}

	public function test_cast_vote_rejects_multiple_on_single_choice_poll(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$result = $this->journey->cast_vote( $this->post_id, $this->user_id, array( 0, 1 ) );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'one choice', strtolower( (string) $result->first_error() ) );
	}

	public function test_remove_vote_deletes_user_vote(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );
		$this->assertTrue( $this->journey->cast_vote( $this->post_id, $this->user_id, array( 0 ) )->is_success() );

		$removed = $this->journey->remove_vote( $this->post_id, $this->user_id );
		$this->assertTrue( $removed->is_success(), implode( '; ', $removed->errors ) );
		$this->assertSame( 1, (int) $removed->data['removed_votes'] );

		$results = $this->journey->list_poll_results( $this->post_id );
		$tallies = array_column( $results->data['items'], 'vote_count' );
		$this->assertSame( array( 0, 0, 0 ), array_map( 'intval', $tallies ) );
	}

	public function test_close_poll_prevents_further_voting(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$close = $this->journey->close_poll( $this->post_id );
		$this->assertTrue( $close->is_success() );
		$this->assertTrue( (bool) $close->data['closed'] );

		$vote = $this->journey->cast_vote( $this->post_id, $this->user_id, array( 0 ) );
		$this->assertFalse( $vote->is_success() );
		$this->assertStringContainsString( 'closed', strtolower( (string) $vote->first_error() ) );
	}

	public function test_delete_poll_removes_all_data(): void {
		global $wpdb;

		$created = $this->create_single_poll();
		$this->assertTrue( $created->is_success() );
		$poll_id = (int) $created->data['poll_id'];
		$this->assertTrue( $this->journey->cast_vote( $this->post_id, $this->user_id, array( 0 ) )->is_success() );

		$delete = $this->journey->delete_poll( $this->post_id );
		$this->assertTrue( $delete->is_success() );
		$this->assertTrue( (bool) $delete->data['deleted'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$poll_row = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_polls WHERE id = %d", $poll_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$opt_row = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_poll_options WHERE poll_id = %d", $poll_id ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$vote_row = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_poll_votes WHERE poll_id = %d", $poll_id ) );

		$this->assertSame( 0, (int) $poll_row );
		$this->assertSame( 0, (int) $opt_row );
		$this->assertSame( 0, (int) $vote_row );

		$miss = $this->journey->get_poll( $this->post_id );
		$this->assertFalse( $miss->is_success() );
	}

	public function test_list_poll_results_includes_tallies_and_percentages(): void {
		$this->assertTrue( $this->create_single_poll()->is_success() );

		$voter_a = $this->user_id;
		$voter_b = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$voter_c = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( $this->journey->cast_vote( $this->post_id, $voter_a, array( 0 ) )->is_success() );
		$this->assertTrue( $this->journey->cast_vote( $this->post_id, $voter_b, array( 0 ) )->is_success() );
		$this->assertTrue( $this->journey->cast_vote( $this->post_id, $voter_c, array( 1 ) )->is_success() );

		$results = $this->journey->list_poll_results( $this->post_id );
		$this->assertTrue( $results->is_success() );
		$this->assertSame( 3, (int) $results->data['total_votes'] );

		$items = $results->data['items'];
		$this->assertCount( 3, $items );

		$this->assertSame( 2, (int) $items[0]['vote_count'] );
		$this->assertEqualsWithDelta( 66.7, (float) $items[0]['percentage'], 0.1 );

		$this->assertSame( 1, (int) $items[1]['vote_count'] );
		$this->assertEqualsWithDelta( 33.3, (float) $items[1]['percentage'], 0.1 );

		$this->assertSame( 0, (int) $items[2]['vote_count'] );
		$this->assertSame( 0.0, (float) $items[2]['percentage'] );

		$this->assertSame(
			array( 'index', 'option_id', 'label', 'vote_count', 'percentage' ),
			$results->data['columns']
		);
	}
}
