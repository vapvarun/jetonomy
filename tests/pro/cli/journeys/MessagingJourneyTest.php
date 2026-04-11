<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Messaging_Journey;

/**
 * Integration tests for Messaging_Journey against the live Pro messaging tables.
 *
 * The Pro plugin must be active during the test run so the `wp_jt_pro_*`
 * tables exist. Each test creates its own fixtures and tear_down() removes
 * every row the suite may have inserted so runs are independent.
 */
class MessagingJourneyTest extends WP_UnitTestCase {

	private Messaging_Journey $journey;

	private int $user_a;
	private int $user_b;
	private int $user_c;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Messaging_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Messaging_Journey.' );
		}

		$this->journey = new Messaging_Journey();

		$this->user_a = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->user_b = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->user_c = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->truncate_tables();
	}

	public function tear_down(): void {
		$this->truncate_tables();
		parent::tear_down();
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_messages" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_conversation_participants" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_conversations" );
	}

	public function test_create_conversation_with_two_participants_creates_direct(): void {
		$result = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 2, $result->data['participant_count'] );
		$this->assertSame( 'direct', $result->data['type'] );
		$this->assertFalse( $result->data['reused'] );
		$this->assertGreaterThan( 0, (int) $result->data['conversation_id'] );
	}

	public function test_create_conversation_with_three_participants_creates_group(): void {
		$result = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b, $this->user_c ),
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 3, $result->data['participant_count'] );
		$this->assertSame( 'group', $result->data['type'] );
	}

	public function test_create_conversation_reuses_existing_direct(): void {
		$first = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$this->assertTrue( $first->is_success() );

		$second = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_b,
				'participant_ids' => array( $this->user_a ),
			)
		);

		$this->assertTrue( $second->is_success() );
		$this->assertTrue( $second->data['reused'] );
		$this->assertSame( (int) $first->data['conversation_id'], (int) $second->data['conversation_id'] );
	}

	public function test_create_conversation_rejects_empty_participants(): void {
		$result = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array(),
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'participant', strtolower( (string) $result->first_error() ) );
	}

	public function test_create_conversation_with_initial_message_inserts_it(): void {
		$result = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
				'initial_message' => 'Hello there',
			)
		);

		$this->assertTrue( $result->is_success() );
		$this->assertNotNull( $result->data['message_id'] );
		$this->assertGreaterThan( 0, (int) $result->data['message_id'] );

		$list = $this->journey->list_messages( (int) $result->data['conversation_id'], $this->user_a );
		$this->assertTrue( $list->is_success() );
		$this->assertCount( 1, $list->data['items'] );
		$this->assertSame( 'Hello there', $list->data['items'][0]['content'] );
	}

	public function test_send_message_rejects_non_participant(): void {
		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$this->assertTrue( $created->is_success() );

		$result = $this->journey->send_message(
			(int) $created->data['conversation_id'],
			$this->user_c,
			'Should fail'
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'participant', strtolower( (string) $result->first_error() ) );
	}

	public function test_send_message_updates_last_message_at_and_count(): void {
		global $wpdb;

		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$this->assertTrue( $created->is_success() );

		$conv_id = (int) $created->data['conversation_id'];

		$send = $this->journey->send_message( $conv_id, $this->user_a, 'First message' );
		$this->assertTrue( $send->is_success() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT last_message_at, message_count FROM {$wpdb->prefix}jt_pro_conversations WHERE id = %d",
				$conv_id
			)
		);
		$this->assertNotNull( $row->last_message_at );
		$this->assertSame( 1, (int) $row->message_count );

		$this->journey->send_message( $conv_id, $this->user_b, 'Second message' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT message_count FROM {$wpdb->prefix}jt_pro_conversations WHERE id = %d",
				$conv_id
			)
		);
		$this->assertSame( 2, $count_after );
	}

	public function test_list_conversations_returns_only_user_conversations(): void {
		$ab = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
				'initial_message' => 'AB',
			)
		);
		$bc = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_b,
				'participant_ids' => array( $this->user_c ),
				'initial_message' => 'BC',
			)
		);
		$this->assertTrue( $ab->is_success() );
		$this->assertTrue( $bc->is_success() );

		$result = $this->journey->list_conversations( $this->user_a );
		$this->assertTrue( $result->is_success() );

		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( (int) $ab->data['conversation_id'], $ids );
		$this->assertNotContains( (int) $bc->data['conversation_id'], $ids );
	}

	public function test_get_conversation_rejects_non_participant(): void {
		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$this->assertTrue( $created->is_success() );

		$result = $this->journey->get_conversation( (int) $created->data['conversation_id'], $this->user_c );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'participant', strtolower( (string) $result->first_error() ) );
	}

	public function test_list_messages_returns_ordered_asc(): void {
		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$conv_id = (int) $created->data['conversation_id'];

		$this->journey->send_message( $conv_id, $this->user_a, 'First' );
		$this->journey->send_message( $conv_id, $this->user_b, 'Second' );
		$this->journey->send_message( $conv_id, $this->user_a, 'Third' );

		$result = $this->journey->list_messages( $conv_id, $this->user_a );
		$this->assertTrue( $result->is_success() );

		$contents = array_column( $result->data['items'], 'content' );
		$this->assertSame( array( 'First', 'Second', 'Third' ), $contents );
	}

	public function test_mark_read_updates_last_read_at(): void {
		global $wpdb;

		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$conv_id = (int) $created->data['conversation_id'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}jt_pro_conversation_participants
				 SET last_read_at = NULL
				 WHERE conversation_id = %d AND user_id = %d",
				$conv_id,
				$this->user_b
			)
		);

		$result = $this->journey->mark_read( $conv_id, $this->user_b );
		$this->assertTrue( $result->is_success() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT last_read_at FROM {$wpdb->prefix}jt_pro_conversation_participants
				 WHERE conversation_id = %d AND user_id = %d",
				$conv_id,
				$this->user_b
			)
		);
		$this->assertNotNull( $value );
	}

	public function test_set_mute_toggles_is_muted(): void {
		global $wpdb;

		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$conv_id = (int) $created->data['conversation_id'];

		$this->journey->set_mute( $conv_id, $this->user_a, true );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$muted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_muted FROM {$wpdb->prefix}jt_pro_conversation_participants
				 WHERE conversation_id = %d AND user_id = %d",
				$conv_id,
				$this->user_a
			)
		);
		$this->assertSame( 1, $muted );

		$this->journey->set_mute( $conv_id, $this->user_a, false );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$unmuted = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_muted FROM {$wpdb->prefix}jt_pro_conversation_participants
				 WHERE conversation_id = %d AND user_id = %d",
				$conv_id,
				$this->user_a
			)
		);
		$this->assertSame( 0, $unmuted );
	}

	public function test_unread_count_matches_actual_unread(): void {
		$created = $this->journey->create_conversation(
			array(
				'created_by'      => $this->user_a,
				'participant_ids' => array( $this->user_b ),
			)
		);
		$conv_id = (int) $created->data['conversation_id'];

		// Nothing sent yet.
		$initial = $this->journey->unread_count( $this->user_b );
		$this->assertTrue( $initial->is_success() );
		$this->assertSame( 0, (int) $initial->data['unread_count'] );

		// After user_a sends a message, user_b should have 1 unread.
		$this->journey->send_message( $conv_id, $this->user_a, 'wake up' );

		$after_send = $this->journey->unread_count( $this->user_b );
		$this->assertSame( 1, (int) $after_send->data['unread_count'] );

		// Once user_b marks as read, it goes back to 0.
		$this->journey->mark_read( $conv_id, $this->user_b );
		$after_read = $this->journey->unread_count( $this->user_b );
		$this->assertSame( 0, (int) $after_read->data['unread_count'] );
	}
}
