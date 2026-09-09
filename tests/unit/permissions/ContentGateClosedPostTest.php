<?php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Content_Gate;

/**
 * A moderator can reply to a closed topic. A member cannot.
 *
 * Closing a topic is a member-facing control, not a staff one: the moderator
 * who closes a thread still needs to post the ruling that explains it.
 * templates/views/single-post.php has always promised exactly that - on a
 * closed post it renders the composer plus an "As a moderator, you can still
 * add a reply" notice - while Content_Gate rejected every writer
 * unconditionally. So the UI offered a reply the server answered with 403
 * (Basecamp 10272481541).
 *
 * These tests pin both sides of that seam, because fixing only the first
 * would turn a display bug into a permission hole:
 *
 *   - a moderator passes the gate on a closed post
 *   - an ordinary member still does not
 *
 * The gate is the right place for this. It is shared by the REST controller,
 * the WP Abilities writer and the inbound-email writer, so a controller-level
 * fix would have left two writers behind.
 */
class ContentGateClosedPostTest extends WP_UnitTestCase {

	private int $space_id;
	private int $moderator_id;
	private int $member_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$this->moderator_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member_id    = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->grant_caps( $this->moderator_id );
		$this->grant_caps( $this->member_id );

		$cat_id         = Category::create( array( 'name' => 'Closed Cat', 'slug' => 'closed-cat-' . uniqid() ) );
		$this->space_id = Space::create( array(
			'title'       => 'Closed Space',
			'slug'        => 'closed-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'public',
			'join_policy' => 'open',
		) );

		// The space role is what `moderate` resolves against, and it is the
		// same permission single-post.php tests before rendering the composer.
		SpaceMember::add( $this->space_id, $this->moderator_id, 'moderator' );
		SpaceMember::add( $this->space_id, $this->member_id, 'member' );

		$this->post_id = Post::create( array(
			'space_id'  => $this->space_id,
			'author_id' => $this->member_id,
			'title'     => 'A topic that gets closed',
			'content'   => '<p>Body.</p>',
			'status'    => 'publish',
		) );
	}

	private function grant_caps( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		foreach ( array( 'jetonomy_read', 'jetonomy_create_posts', 'jetonomy_create_replies' ) as $cap ) {
			$user->add_cap( $cap );
		}
	}

	private function close_post(): object {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'jt_posts', array( 'is_closed' => 1 ), array( 'id' => $this->post_id ) );
		return Post::find( $this->post_id );
	}

	/**
	 * Baseline: while the topic is open, both roles pass. Without this the two
	 * tests below could both pass for the wrong reason - a fixture that never
	 * granted anyone the right to reply in the first place.
	 */
	public function test_open_post_admits_both_roles(): void {
		$post = Post::find( $this->post_id );

		$this->assertNotWPError(
			Content_Gate::check( $this->moderator_id, $post ),
			'A moderator should be able to reply to an open topic.'
		);
		$this->assertNotWPError(
			Content_Gate::check( $this->member_id, $post ),
			'A member should be able to reply to an open topic.'
		);
	}

	public function test_moderator_may_reply_to_closed_post(): void {
		$result = Content_Gate::check( $this->moderator_id, $this->close_post() );

		$this->assertNotWPError(
			$result,
			'A moderator must pass the gate on a closed topic - the template renders them a composer.'
		);
	}

	public function test_member_may_not_reply_to_closed_post(): void {
		$result = Content_Gate::check( $this->member_id, $this->close_post() );

		$this->assertWPError( $result, 'A member must NOT be able to reply to a closed topic.' );
		$this->assertSame(
			'jetonomy_post_closed',
			$result->get_error_code(),
			'The refusal must still be the closed-post error, not some other denial that happens to also fail.'
		);
		$this->assertSame(
			403,
			$result->get_error_data()['status'] ?? null,
			'A closed topic is a 403 for a member.'
		);
	}
}
