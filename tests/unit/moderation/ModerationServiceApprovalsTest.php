<?php
namespace Jetonomy\Tests\Unit\Moderation;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Moderation\Moderation_Service;

/**
 * Scoping for the pending-APPROVAL queue (1.9.4).
 *
 * The bug this locks down: content held by a space's require_approval setting
 * is written straight to status = 'pending' and creates NO flag row, so the
 * flag-only frontend queue reported "nothing pending" while held submissions
 * piled up — approvable from wp-admin and nowhere else.
 *
 * The scoping is the part worth testing rather than the rendering, because it
 * is a privilege boundary: the model layer reads a null space filter as "every
 * space", so any path that returns null where it meant "denied" hands the whole
 * site's unpublished content to someone with no rights at all. Each case below
 * pins one edge of that boundary.
 */
class ModerationServiceApprovalsTest extends WP_UnitTestCase {

	private int $space_a;
	private int $space_b;
	private int $author_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$suffix = uniqid( 'msat_', true );
		$cat    = (int) Category::create(
			[
				'name' => 'Approvals test',
				'slug' => 'cat-' . $suffix,
			]
		);

		$this->space_a = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Space A',
				'slug'        => 'a-' . $suffix,
				'visibility'  => 'public',
			],
			0
		);
		$this->space_b = (int) Space::create(
			[
				'category_id' => $cat,
				'title'       => 'Space B',
				'slug'        => 'b-' . $suffix,
				'visibility'  => 'public',
			],
			0
		);

		$this->author_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Two held posts in A, one in B — so "scoped to A" and "everything"
		// give different answers and a passing test cannot be an accident.
		$this->seed_held_post( $this->space_a );
		$this->seed_held_post( $this->space_a );
		$this->seed_held_post( $this->space_b );

		// One held reply in A, on a PUBLISHED parent: replies carry no
		// space_id, so this is what exercises the join in Reply's scoping.
		$this->seed_held_reply( $this->space_a );
	}

	public function test_admin_sees_held_content_in_every_space(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$this->assertSame( 3, Moderation_Service::count_pending_approvals( $admin_id, 'post' ) );
		$this->assertSame( 1, Moderation_Service::count_pending_approvals( $admin_id, 'reply' ) );
		$this->assertCount( 3, Moderation_Service::list_pending_approvals( $admin_id, 'post' ) );
	}

	public function test_space_moderator_sees_only_their_space(): void {
		$mod_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		SpaceMember::add( $this->space_a, $mod_id, 'moderator' );

		// Unscoped call still returns ONLY space A — this is the case that
		// would leak the whole site if "no cap" resolved to "no filter".
		$this->assertSame( 2, Moderation_Service::count_pending_approvals( $mod_id, 'post' ) );
		$this->assertSame( 2, Moderation_Service::count_pending_approvals( $mod_id, 'post', $this->space_a ) );
		$this->assertSame( 0, Moderation_Service::count_pending_approvals( $mod_id, 'post', $this->space_b ) );
	}

	public function test_reply_scoping_follows_the_parent_posts_space(): void {
		$mod_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		SpaceMember::add( $this->space_a, $mod_id, 'moderator' );

		$this->assertSame( 1, Moderation_Service::count_pending_approvals( $mod_id, 'reply' ) );
		$this->assertSame( 0, Moderation_Service::count_pending_approvals( $mod_id, 'reply', $this->space_b ) );
	}

	public function test_member_who_moderates_nothing_sees_nothing(): void {
		$member_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$this->assertSame( 0, Moderation_Service::count_pending_approvals( $member_id, 'post' ) );
		$this->assertSame( [], Moderation_Service::list_pending_approvals( $member_id, 'post' ) );
		$this->assertSame( 0, Moderation_Service::count_pending_approvals( $member_id, 'post', $this->space_a ) );
	}

	public function test_logged_out_visitor_sees_nothing(): void {
		$this->assertSame( 0, Moderation_Service::count_pending_approvals( 0, 'post' ) );
		$this->assertSame( [], Moderation_Service::list_pending_approvals( 0, 'post' ) );
	}

	public function test_published_content_is_not_in_the_held_queue(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		Post::create(
			[
				'space_id'  => $this->space_a,
				'author_id' => $this->author_id,
				'title'     => 'Already public',
				'content'   => '<p>Public.</p>',
				'status'    => 'publish',
			]
		);

		// Still 2 — publishing something must not inflate the held count.
		$this->assertSame( 2, Moderation_Service::count_pending_approvals( $admin_id, 'post', $this->space_a ) );
	}

	public function test_pagination_splits_the_scoped_set(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$page_1 = Moderation_Service::list_pending_approvals( $admin_id, 'post', $this->space_a, 1, 0 );
		$page_2 = Moderation_Service::list_pending_approvals( $admin_id, 'post', $this->space_a, 1, 1 );

		$this->assertCount( 1, $page_1 );
		$this->assertCount( 1, $page_2 );
		$this->assertNotEquals( (int) $page_1[0]->id, (int) $page_2[0]->id );
	}

	private function seed_held_post( int $space_id ): int {
		return (int) Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $this->author_id,
				'title'     => 'Held ' . uniqid(),
				'content'   => '<p>Waiting on a moderator.</p>',
				'status'    => 'pending',
			]
		);
	}

	private function seed_held_reply( int $space_id ): int {
		$parent = (int) Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $this->author_id,
				'title'     => 'Parent ' . uniqid(),
				'content'   => '<p>Published parent.</p>',
				'status'    => 'publish',
			]
		);

		return (int) Reply::create(
			[
				'post_id'   => $parent,
				'author_id' => $this->author_id,
				'content'   => '<p>Held reply.</p>',
				'status'    => 'pending',
			]
		);
	}
}
