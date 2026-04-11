<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Moderation_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Restriction;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * Exercises every Moderation_Journey method against the real model layer.
 *
 * Each run seeds a category/space/post fixture under a unique slug, files a
 * pending flag against the post, and creates a reporter + resolver user.
 * The journey is pure PHP with no WP-CLI coupling, so these tests run through
 * the standard WP_UnitTestCase bootstrap without any CLI harness involvement.
 */
class ModerationJourneyTest extends WP_UnitTestCase {

	private Moderation_Journey $journey;

	private int $space_id;

	private int $post_id;

	private int $reporter_id;

	private int $resolver_id;

	private int $flag_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Moderation_Journey();

		$suffix   = uniqid( 'mj_', true );
		$cat_id   = Category::create(
			[
				'name' => 'MJ Test Category',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = Space::create(
			[
				'category_id' => $cat_id,
				'title'       => 'MJ Test Space',
				'slug'        => 'space-' . $suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$this->reporter_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->resolver_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->post_id = (int) Post::create(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->reporter_id,
				'title'     => 'Fixture post ' . $suffix,
				'content'   => 'Body.',
			]
		);

		$this->flag_id = (int) Flag::create(
			[
				'object_type' => 'post',
				'object_id'   => $this->post_id,
				'reporter_id' => $this->reporter_id,
				'reason'      => 'spam',
				'description' => 'seeded for mj tests',
			]
		);
	}

	public function test_list_pending_flags_returns_seeded_flag(): void {
		$result = $this->journey->list_pending_flags();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertNotEmpty( $result->data['items'] );

		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( $this->flag_id, $ids );
	}

	public function test_list_flags_by_status_pending(): void {
		$result = $this->journey->list_flags_by_status( 'pending' );

		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'pending', $result->data['status'] );
		$ids = array_column( $result->data['items'], 'id' );
		$this->assertContains( $this->flag_id, $ids );
	}

	public function test_list_flags_by_status_rejects_invalid_status(): void {
		$result = $this->journey->list_flags_by_status( 'bogus' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'status', $result->first_error() );
	}

	public function test_resolve_flag_transitions_to_valid(): void {
		$result = $this->journey->resolve_flag( $this->flag_id, $this->resolver_id, 'valid' );

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertSame( $this->flag_id, $result->data['id'] );
		$this->assertSame( 'valid', $result->data['status'] );
		$this->assertSame( $this->resolver_id, $result->data['resolved_by'] );

		$pending = $this->journey->list_flags_by_status( 'pending' );
		$this->assertNotContains( $this->flag_id, array_column( $pending->data['items'], 'id' ) );

		$valid_rows = $this->journey->list_flags_by_status( 'valid' );
		$this->assertContains( $this->flag_id, array_column( $valid_rows->data['items'], 'id' ) );
	}

	public function test_resolve_flag_rejects_invalid_decision(): void {
		$result = $this->journey->resolve_flag( $this->flag_id, $this->resolver_id, 'maybe' );

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'decision', $result->first_error() );
	}

	public function test_ban_user_creates_restriction(): void {
		$target = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = $this->journey->ban_user(
			$target,
			$this->resolver_id,
			'global_ban',
			null,
			'test ban'
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );
		$this->assertGreaterThan( 0, $result->data['id'] );
		$this->assertSame( $target, $result->data['user_id'] );
		$this->assertSame( 'global_ban', $result->data['type'] );
		$this->assertTrue( Restriction::is_banned( $target ) );
	}

	public function test_ban_user_requires_space_id_for_space_ban(): void {
		$target = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = $this->journey->ban_user(
			$target,
			$this->resolver_id,
			'space_ban',
			null,
			'no space'
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'space_id', $result->first_error() );
	}

	public function test_unban_removes_restriction(): void {
		$target = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$ban = $this->journey->ban_user( $target, $this->resolver_id, 'global_ban', null, 'temp' );
		$this->assertTrue( $ban->is_success() );

		$unban = $this->journey->unban( (int) $ban->data['id'] );
		$this->assertTrue( $unban->is_success() );
		$this->assertFalse( Restriction::is_banned( $target ) );
	}

	public function test_is_banned_returns_false_for_clean_user(): void {
		$target = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$result = $this->journey->is_banned( $target );

		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['banned'] );
	}

	public function test_is_banned_returns_true_after_ban(): void {
		$target = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$this->journey->ban_user( $target, $this->resolver_id, 'global_ban', null, 'test' );

		$result = $this->journey->is_banned( $target );

		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['banned'] );
	}
}
