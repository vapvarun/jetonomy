<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Reactions_Journey;

/**
 * Integration tests for Reactions_Journey against the live `wp_jt_pro_reactions` table.
 *
 * The Pro plugin must be active during the test run so the reactions table
 * exists (it's created in Reactions\Extension::activate() via dbDelta). Each
 * test creates its own fixtures and tear_down() removes every row inserted
 * under the test user ids so runs remain independent.
 */
class ReactionsJourneyTest extends WP_UnitTestCase {

	private Reactions_Journey $journey;

	private int $user_a;
	private int $user_b;

	private int $post_id  = 9001;
	private int $reply_id = 7001;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Reactions_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Reactions_Journey.' );
		}

		$this->journey = new Reactions_Journey();

		$this->user_a = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->user_b = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->truncate_fixture_rows();
	}

	public function tear_down(): void {
		$this->truncate_fixture_rows();
		parent::tear_down();
	}

	private function truncate_fixture_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_reactions';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE object_id IN (%d, %d)",
				$this->post_id,
				$this->reply_id
			)
		);
	}

	public function test_add_reaction_persists(): void {
		global $wpdb;

		$result = $this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'thumbsup' );

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( $result->data['created'] );
		$this->assertGreaterThan( 0, (int) $result->data['reaction_id'] );
		$this->assertSame( 'post', $result->data['object_type'] );
		$this->assertSame( $this->post_id, (int) $result->data['object_id'] );
		$this->assertSame( 'thumbsup', $result->data['emoji'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id = %d AND emoji = %s",
				$this->user_a,
				'post',
				$this->post_id,
				'thumbsup'
			)
		);
		$this->assertSame( 1, $row_count );
	}

	/**
	 * The extension enforces UNIQUE(user_id, object_type, object_id, emoji),
	 * so adding the same reaction twice is idempotent — the journey returns
	 * `created: false` on the second call and no duplicate row is inserted.
	 */
	public function test_add_reaction_is_idempotent_or_upserts(): void {
		global $wpdb;

		$first  = $this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'heart' );
		$second = $this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'heart' );

		$this->assertTrue( $first->is_success() );
		$this->assertTrue( $first->data['created'] );

		$this->assertTrue( $second->is_success() );
		$this->assertFalse( $second->data['created'] );
		$this->assertSame( (int) $first->data['reaction_id'], (int) $second->data['reaction_id'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id = %d AND emoji = %s",
				$this->user_a,
				'post',
				$this->post_id,
				'heart'
			)
		);
		$this->assertSame( 1, $row_count );
	}

	public function test_add_reaction_rejects_invalid_object_type(): void {
		$result = $this->journey->add_reaction( $this->post_id, 'banana', $this->user_a, 'thumbsup' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'object_type', strtolower( (string) $result->first_error() ) );
	}

	public function test_add_reaction_rejects_empty_emoji(): void {
		$result = $this->journey->add_reaction( $this->post_id, 'post', $this->user_a, '' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'emoji', strtolower( (string) $result->first_error() ) );
	}

	public function test_add_reaction_rejects_negative_ids(): void {
		$neg_user = $this->journey->add_reaction( $this->post_id, 'post', -5, 'thumbsup' );
		$this->assertFalse( $neg_user->is_success() );
		$this->assertStringContainsString( 'user_id', strtolower( (string) $neg_user->first_error() ) );

		$neg_object = $this->journey->add_reaction( 0, 'post', $this->user_a, 'thumbsup' );
		$this->assertFalse( $neg_object->is_success() );
		$this->assertStringContainsString( 'object_id', strtolower( (string) $neg_object->first_error() ) );
	}

	public function test_remove_reaction_deletes_row(): void {
		global $wpdb;

		$this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'rocket' );

		$result = $this->journey->remove_reaction( $this->post_id, 'post', $this->user_a, 'rocket' );
		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['removed'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_reactions
				 WHERE user_id = %d AND object_type = %s AND object_id = %d AND emoji = %s",
				$this->user_a,
				'post',
				$this->post_id,
				'rocket'
			)
		);
		$this->assertSame( 0, $row_count );
	}

	public function test_remove_reaction_is_noop_when_absent(): void {
		$result = $this->journey->remove_reaction( $this->post_id, 'post', $this->user_a, 'rocket' );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['removed'] );
	}

	public function test_list_reactions_returns_items_and_columns(): void {
		$this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'thumbsup' );
		$this->journey->add_reaction( $this->post_id, 'post', $this->user_b, 'heart' );

		$result = $this->journey->list_reactions( $this->post_id, 'post' );

		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$this->assertCount( 2, $result->data['items'] );
		$this->assertIsArray( $result->data['columns'] );
		$this->assertContains( 'emoji', $result->data['columns'] );
		$this->assertContains( 'user_id', $result->data['columns'] );
		$this->assertContains( 'created_at', $result->data['columns'] );

		$emojis = array_column( $result->data['items'], 'emoji' );
		$this->assertContains( 'thumbsup', $emojis );
		$this->assertContains( 'heart', $emojis );
	}

	public function test_list_reactions_returns_empty_for_no_reactions(): void {
		$result = $this->journey->list_reactions( $this->post_id, 'post' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( array(), $result->data['items'] );
	}

	public function test_count_reactions_aggregates_by_emoji(): void {
		$this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'thumbsup' );
		$this->journey->add_reaction( $this->post_id, 'post', $this->user_b, 'thumbsup' );
		$this->journey->add_reaction( $this->post_id, 'post', $this->user_a, 'rocket' );

		$result = $this->journey->count_reactions( $this->post_id, 'post' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 3, (int) $result->data['total_count'] );
		$this->assertSame( 2, (int) $result->data['counts']['thumbsup'] );
		$this->assertSame( 1, (int) $result->data['counts']['rocket'] );
	}

	public function test_list_available_emojis_returns_nonempty_list(): void {
		$result = $this->journey->list_available_emojis();

		$this->assertTrue( $result->is_success() );
		$this->assertGreaterThan( 0, (int) $result->data['count'] );
		$this->assertNotEmpty( $result->data['items'] );

		$slugs = array_column( $result->data['items'], 'slug' );
		// thumbsup is in the hard-coded defaults, so any allowlist built on
		// top of those defaults is guaranteed to include it.
		$this->assertContains( 'thumbsup', $slugs );
	}
}
