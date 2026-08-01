<?php
namespace Jetonomy\Tests\Unit\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journeys\Content_Journey;
use Jetonomy\CLI\Journeys\Space_Journey;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * Importer seam: the create journeys forward an optional backdated created_at.
 *
 * buddynext-importer replays a source forum through these journeys; without
 * the seam every migrated space/topic/reply was stamped with the migration
 * run time (BuddyNext card 10124307318). The MODELS already honour a caller
 * created_at (array_merge defaults); the journeys dropped it from their
 * whitelists. A backdated reply must also carry its date into the parent
 * post's last_reply_at, or migrated forums all sort as "active just now".
 */
class JourneyBackdateTest extends WP_UnitTestCase {

	private Content_Journey $journey;

	private int $space_id;

	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->journey = new Content_Journey();

		$suffix         = uniqid( 'bd_', true );
		$cat_id         = Category::create(
			[
				'name' => 'Backdate Category',
				'slug' => 'cat-' . $suffix,
			]
		);
		$this->space_id = Space::create(
			[
				'category_id' => $cat_id,
				'title'       => 'Backdate Space',
				'slug'        => 'space-' . $suffix,
				'type'        => 'forum',
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);
		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
	}

	public function test_create_post_honours_backdated_created_at(): void {
		$result = $this->journey->create_post(
			[
				'space_id'   => $this->space_id,
				'author_id'  => $this->author_id,
				'title'      => 'Imported topic',
				'content'    => 'Historic body.',
				'created_at' => '2019-09-09 09:09:09',
			]
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );

		$post = Post::find( (int) $result->data['id'] );
		$this->assertSame( '2019-09-09 09:09:09', (string) $post->created_at );
		$this->assertSame( '2019-09-09 09:09:09', (string) $post->last_reply_at, 'a backdated topic with no replies must not claim activity now' );
	}

	public function test_create_post_clamps_future_created_at(): void {
		$result = $this->journey->create_post(
			[
				'space_id'   => $this->space_id,
				'author_id'  => $this->author_id,
				'title'      => 'Future topic',
				'content'    => 'Body.',
				'created_at' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ),
			]
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );

		$post = Post::find( (int) $result->data['id'] );
		$this->assertLessThanOrEqual( time() + 2, strtotime( (string) $post->created_at . ' UTC' ) );
	}

	public function test_create_reply_honours_backdated_created_at_and_bumps_last_reply_at(): void {
		$post_result = $this->journey->create_post(
			[
				'space_id'   => $this->space_id,
				'author_id'  => $this->author_id,
				'title'      => 'Imported topic',
				'content'    => 'Historic body.',
				'created_at' => '2019-09-09 09:09:09',
			]
		);
		$post_id     = (int) $post_result->data['id'];

		$reply_result = $this->journey->create_reply(
			[
				'post_id'    => $post_id,
				'author_id'  => $this->author_id,
				'content'    => 'Historic reply.',
				'created_at' => '2019-10-10 10:10:10',
			]
		);

		$this->assertTrue( $reply_result->is_success(), implode( ',', $reply_result->errors ) );

		$reply = Reply::find( (int) $reply_result->data['id'] );
		$this->assertSame( '2019-10-10 10:10:10', (string) $reply->created_at );

		$post = Post::find( $post_id );
		$this->assertSame( '2019-10-10 10:10:10', (string) $post->last_reply_at, 'last_reply_at must reflect the backdated reply, not the migration run time' );
	}

	public function test_live_reply_still_stamps_now(): void {
		$post_result = $this->journey->create_post(
			[
				'space_id'  => $this->space_id,
				'author_id' => $this->author_id,
				'title'     => 'Live topic',
				'content'   => 'Body.',
			]
		);
		$post_id     = (int) $post_result->data['id'];

		$reply_result = $this->journey->create_reply(
			[
				'post_id'   => $post_id,
				'author_id' => $this->author_id,
				'content'   => 'Live reply.',
			]
		);
		$this->assertTrue( $reply_result->is_success() );

		$post = Post::find( $post_id );
		$this->assertGreaterThan( time() - MINUTE_IN_SECONDS, strtotime( (string) $post->last_reply_at . ' UTC' ), 'live replies keep bumping last_reply_at to now' );
	}

	public function test_space_journey_honours_backdated_created_at(): void {
		$suffix = uniqid( 'bds_', true );
		$cat_id = Category::create(
			[
				'name' => 'Backdate Space Category',
				'slug' => 'cat-' . $suffix,
			]
		);

		$result = ( new Space_Journey() )->create(
			[
				'category_id' => $cat_id,
				'title'       => 'Imported Forum',
				'slug'        => 'forum-' . $suffix,
				'created_at'  => '2017-07-07 07:07:07',
			]
		);

		$this->assertTrue( $result->is_success(), implode( ',', $result->errors ) );

		$space = Space::find( (int) $result->data['id'] );
		$this->assertSame( '2017-07-07 07:07:07', (string) $space->created_at );
	}
}
