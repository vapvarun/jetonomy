<?php
namespace Jetonomy\Tests\Unit\Moderation;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Flag;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Moderation\Moderation_Service;

/**
 * Cross-space moderation dashboard scoping.
 *
 * Locks the contract end users actually rely on: a multi-space mod
 * sees the queues they own (and only those), an admin sees every
 * queue, and content in spaces a user does not moderate stays out
 * of their summary.
 */
class ModerationServiceDashboardTest extends WP_UnitTestCase {

	private int $space_a;
	private int $space_b;
	private int $space_c;
	private int $reporter_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$suffix = uniqid( 'msdt_', true );
		$cat    = (int) Category::create(
			[
				'name' => 'Dash test',
				'slug' => 'cat-' . $suffix,
			]
		);

		// Three spaces. The dashboard tests scope queries by which one
		// the viewer moderates, so we want at least three distinct
		// spaces with pending flags to exercise the include / exclude
		// edges.
		$this->space_a = (int) Space::create(
			[ 'category_id' => $cat, 'title' => 'Space A', 'slug' => 'a-' . $suffix, 'visibility' => 'public' ],
			0
		);
		$this->space_b = (int) Space::create(
			[ 'category_id' => $cat, 'title' => 'Space B', 'slug' => 'b-' . $suffix, 'visibility' => 'public' ],
			0
		);
		$this->space_c = (int) Space::create(
			[ 'category_id' => $cat, 'title' => 'Space C', 'slug' => 'c-' . $suffix, 'visibility' => 'public' ],
			0
		);

		$this->reporter_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// One pending flag in each space, varying counts so we can
		// assert ordering by 'pending' DESC.
		$this->seed_flag( $this->space_a );
		$this->seed_flag( $this->space_b );
		$this->seed_flag( $this->space_b );
		$this->seed_flag( $this->space_c );
	}

	public function test_admin_sees_every_space_with_pending_flags(): void {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );

		$summary = Moderation_Service::dashboard_summary( $admin_id );

		$space_ids = array_column( $summary, 'space_id' );
		$this->assertContains( $this->space_a, $space_ids );
		$this->assertContains( $this->space_b, $space_ids );
		$this->assertContains( $this->space_c, $space_ids );

		// Highest pending count first — Space B has two flags.
		$this->assertSame( $this->space_b, (int) $summary[0]['space_id'] );
		$this->assertSame( 2, (int) $summary[0]['pending'] );
	}

	public function test_multi_space_mod_sees_only_their_moderated_spaces(): void {
		$mod_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		// User moderates A and B but NOT C.
		SpaceMember::add( $this->space_a, $mod_id, 'moderator' );
		SpaceMember::add( $this->space_b, $mod_id, 'admin' );

		$summary   = Moderation_Service::dashboard_summary( $mod_id );
		$space_ids = array_column( $summary, 'space_id' );

		$this->assertContains( $this->space_a, $space_ids, 'Mod should see Space A — they moderate it.' );
		$this->assertContains( $this->space_b, $space_ids, 'Mod should see Space B — they moderate it.' );
		$this->assertNotContains(
			$this->space_c,
			$space_ids,
			'Mod must not see Space C — they do not moderate it, even though it has pending flags.'
		);
		$this->assertCount( 2, $summary );
	}

	public function test_single_space_mod_sees_only_their_one_space(): void {
		$mod_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		SpaceMember::add( $this->space_a, $mod_id, 'moderator' );

		$summary = Moderation_Service::dashboard_summary( $mod_id );

		$this->assertCount( 1, $summary );
		$this->assertSame( $this->space_a, (int) $summary[0]['space_id'] );
	}

	public function test_user_with_no_moderated_spaces_gets_empty_summary(): void {
		$plain = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		$summary = Moderation_Service::dashboard_summary( $plain );

		$this->assertSame( [], $summary );
	}

	public function test_guest_gets_empty_summary(): void {
		$summary = Moderation_Service::dashboard_summary( 0 );
		$this->assertSame( [], $summary );
	}

	private function seed_flag( int $space_id ): void {
		$post_id = (int) Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => $this->reporter_id,
				'title'     => 'Flagged content for ' . $space_id,
				'content'   => 'body',
			]
		);

		Flag::create(
			[
				'object_type' => 'post',
				'object_id'   => $post_id,
				'reporter_id' => $this->reporter_id,
				'reason'      => 'spam',
			]
		);
	}
}
