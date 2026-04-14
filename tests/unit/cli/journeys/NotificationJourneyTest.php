<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Notification_Journey;
use Jetonomy\Models\Notification;
use Jetonomy\DB\Schema;

/**
 * Exercises every Notification_Journey method against the real model layer.
 *
 * Each test provisions a fresh recipient user and seeds two notification rows
 * directly via {@see Notification::create()} so unread-count and mark-read
 * assertions have known starting state. Journey methods are pure PHP with no
 * WP-CLI coupling, so these tests run through the standard WP_UnitTestCase
 * bootstrap.
 */
class NotificationJourneyTest extends WP_UnitTestCase {

	private Notification_Journey $journey;

	private int $user_id;

	private int $actor_id;

	/**
	 * @var int[] Seeded notification row IDs.
	 */
	private array $seeded_ids = [];

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey  = new Notification_Journey();
		$this->user_id  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->actor_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->seeded_ids[] = (int) Notification::create(
			[
				'user_id'     => $this->user_id,
				'actor_id'    => $this->actor_id,
				'type'        => 'reply_to_post',
				'object_type' => 'post',
				'object_id'   => 101,
				'message'     => 'Seeded reply #1',
			]
		);
		$this->seeded_ids[] = (int) Notification::create(
			[
				'user_id'     => $this->user_id,
				'actor_id'    => $this->actor_id,
				'type'        => 'vote_on_post',
				'object_type' => 'post',
				'object_id'   => 102,
				'message'     => 'Seeded vote #2',
			]
		);
	}

	public function test_trigger_creates_row(): void {
		$result = $this->journey->trigger(
			[
				'type'        => 'accepted_answer',
				'user_id'     => $this->user_id,
				'actor_id'    => $this->actor_id,
				'object_type' => 'reply',
				'object_id'   => 555,
				'message'     => 'Your answer was accepted',
			]
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( 'accepted_answer', $result->data['type'] );

		$row = Notification::find( (int) $result->data['id'] );
		$this->assertNotNull( $row );
		$this->assertSame( 'Your answer was accepted', $row->message );
		$this->assertSame( 'reply', $row->object_type );
		$this->assertSame( 0, (int) $row->is_read );
	}

	public function test_trigger_requires_all_fields(): void {
		$result = $this->journey->trigger(
			[
				'type'    => 'reply_to_post',
				'user_id' => $this->user_id,
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'Missing required fields', $result->first_error() );
		$this->assertStringContainsString( 'actor_id', $result->first_error() );
		$this->assertStringContainsString( 'object_type', $result->first_error() );
		$this->assertStringContainsString( 'object_id', $result->first_error() );
		$this->assertStringContainsString( 'message', $result->first_error() );
	}

	public function test_trigger_rejects_invalid_user_id(): void {
		$result = $this->journey->trigger(
			[
				'type'        => 'reply_to_post',
				'user_id'     => 0,
				'actor_id'    => $this->actor_id,
				'object_type' => 'post',
				'object_id'   => 10,
				'message'     => 'hi',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'user_id must be positive', $result->first_error() );
	}

	public function test_trigger_rejects_invalid_object_type(): void {
		$result = $this->journey->trigger(
			[
				'type'        => 'reply_to_post',
				'user_id'     => $this->user_id,
				'actor_id'    => $this->actor_id,
				'object_type' => 'comment',
				'object_id'   => 10,
				'message'     => 'hi',
			]
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'object_type must be one of', $result->first_error() );
	}

	public function test_list_for_user_returns_items_and_columns(): void {
		$result = $this->journey->list_for_user( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertSame(
			[ 'id', 'user_id', 'actor_id', 'type', 'object_type', 'object_id', 'message', 'is_read', 'created_at' ],
			$result->data['columns']
		);
		$this->assertCount( 2, $result->data['items'] );

		$ids = array_column( $result->data['items'], 'id' );
		foreach ( $this->seeded_ids as $seed_id ) {
			$this->assertContains( $seed_id, $ids );
		}
	}

	public function test_unread_count_matches_seeded_rows(): void {
		$result = $this->journey->unread_count( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $this->user_id, $result->data['user_id'] );
		$this->assertSame( 2, $result->data['unread'] );
	}

	public function test_mark_read_flips_single_row(): void {
		$target = $this->seeded_ids[0];

		$result = $this->journey->mark_read( $target );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $target, $result->data['id'] );
		$this->assertSame( 1, $result->data['is_read'] );

		$row = Notification::find( $target );
		$this->assertSame( 1, (int) $row->is_read );

		$remaining = $this->journey->unread_count( $this->user_id );
		$this->assertSame( 1, $remaining->data['unread'] );
	}

	public function test_mark_read_rejects_missing_row(): void {
		$result = $this->journey->mark_read( 9999999 );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not found', $result->first_error() );
	}

	public function test_mark_all_read_flips_every_row(): void {
		$result = $this->journey->mark_all_read( $this->user_id );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( $this->user_id, $result->data['user_id'] );
		$this->assertSame( 2, $result->data['affected'] );

		$after = $this->journey->unread_count( $this->user_id );
		$this->assertSame( 0, $after->data['unread'] );

		foreach ( $this->seeded_ids as $seed_id ) {
			$row = Notification::find( $seed_id );
			$this->assertSame( 1, (int) $row->is_read );
		}
	}

	public function test_test_email_rejects_missing_user(): void {
		$result = $this->journey->test_email( 0 );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'user_id must be positive', $result->first_error() );
	}

	public function test_test_email_rejects_nonexistent_user(): void {
		$result = $this->journey->test_email( 9999999 );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not found', $result->first_error() );
	}
}
