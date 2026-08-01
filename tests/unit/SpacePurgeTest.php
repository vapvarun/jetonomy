<?php
namespace Jetonomy\Tests\Unit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Space_Backfill;
use Jetonomy\Space_Purge;
use WP_UnitTestCase;

/**
 * Space purge + orphan sweep.
 *
 * The load-bearing assertions here are the NEGATIVE ones. A cascade that
 * removes too much is far worse than one that removes too little — it destroys
 * other members' topics and replies — so every test that purges also asserts a
 * neighbouring live space came through untouched.
 */
class SpacePurgeTest extends WP_UnitTestCase {

	private int $live_space;
	private int $live_post;

	public function set_up(): void {
		parent::set_up();

		// A bystander space that must survive every purge in this file.
		$this->live_space = Space::create(
			[
				'title'      => 'Live space',
				'slug'       => 'live-space',
				'visibility' => 'public',
			],
			1
		);
		$this->live_post = Post::create(
			[
				'space_id'  => $this->live_space,
				'author_id' => 1,
				'title'     => 'Live topic',
				'slug'      => 'live-topic',
				'content'   => 'body',
				'status'    => 'publish',
			]
		);
	}

	/** Build a space with a topic, a reply and a member. */
	private function seed_space( string $slug ): array {
		$space_id = Space::create(
			[
				'title'      => 'Doomed ' . $slug,
				'slug'       => $slug,
				'visibility' => 'public',
			],
			1
		);
		$post_id  = Post::create(
			[
				'space_id'  => $space_id,
				'author_id' => 1,
				'title'     => 'Topic ' . $slug,
				'slug'      => $slug . '-topic',
				'content'   => 'body',
				'status'    => 'publish',
			]
		);
		$reply_id = Reply::create(
			[
				'post_id'   => $post_id,
				'author_id' => 1,
				'content'   => 'a reply',
			]
		);
		SpaceMember::add( $space_id, 1, 'admin' );

		return [ $space_id, $post_id, $reply_id ];
	}

	public function test_purge_removes_the_space_and_its_content(): void {
		global $wpdb;
		[ $space_id, $post_id, $reply_id ] = $this->seed_space( 'doomed-a' );

		Space_Purge::purge( $space_id );

		$this->assertNull( Space::find( $space_id ), 'space row survived the purge' );
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE id = %d", $post_id ) ),
			'topic survived the purge'
		);
		$this->assertSame(
			'0',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_replies WHERE id = %d", $reply_id ) ),
			'reply survived the purge — the post -> reply hop did not resolve'
		);
	}

	public function test_purge_leaves_other_spaces_alone(): void {
		global $wpdb;
		[ $space_id ] = $this->seed_space( 'doomed-b' );

		Space_Purge::purge( $space_id );

		$this->assertNotNull( Space::find( $this->live_space ), 'purge removed a bystander space' );
		$this->assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE id = %d", $this->live_post ) ),
			'purge removed a bystander space\'s topic'
		);
	}

	public function test_purge_is_idempotent(): void {
		[ $space_id ] = $this->seed_space( 'doomed-c' );

		Space_Purge::purge( $space_id );
		$second = Space_Purge::purge( $space_id );

		$this->assertSame( [], $second, 'a second purge found rows to remove; the first was incomplete' );
	}

	public function test_backfill_finds_a_space_deleted_without_a_cascade(): void {
		global $wpdb;
		[ $space_id ] = $this->seed_space( 'doomed-d' );

		// Exactly what Space::delete() does today: the row, nothing else.
		$wpdb->delete( $wpdb->prefix . 'jt_spaces', [ 'id' => $space_id ], [ '%d' ] );

		$this->assertContains( $space_id, Space_Backfill::find_orphans(), 'orphaned space was not discovered' );
		$this->assertNotSame( [], Space_Backfill::count_orphans(), 'orphan report was empty' );
	}

	public function test_backfill_sweep_cleans_orphans_and_spares_live_data(): void {
		global $wpdb;
		[ $space_id ] = $this->seed_space( 'doomed-e' );
		$wpdb->delete( $wpdb->prefix . 'jt_spaces', [ 'id' => $space_id ], [ '%d' ] );

		$result = Space_Backfill::run_batch();

		$this->assertSame( 1, $result['purged'] );
		$this->assertSame( [], Space_Backfill::count_orphans(), 'orphans remained after the sweep' );
		$this->assertNotNull( Space::find( $this->live_space ), 'the sweep removed a live space' );
		$this->assertSame(
			'1',
			$wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_posts WHERE id = %d", $this->live_post ) ),
			'the sweep removed a live topic'
		);
	}

	public function test_a_live_space_is_never_an_orphan(): void {
		$this->seed_space( 'doomed-f' );

		$this->assertNotContains(
			$this->live_space,
			Space_Backfill::find_orphans(),
			'a space that still exists was reported as an orphan — the sweep would delete a live community'
		);
	}

	public function test_space_id_zero_is_never_swept(): void {
		// 0 is the "no space" sentinel, not a reference. Sweeping it would
		// delete rows that were never attached to a space at all.
		$this->assertNotContains( 0, Space_Backfill::find_orphans() );
	}

	public function test_every_relation_names_a_resolvable_id_set(): void {
		foreach ( Space_Purge::relations() as $r ) {
			$this->assertContains(
				$r['ref'],
				[ 'space', 'post', 'reply' ],
				$r['table'] . '.' . $r['column'] . ' declares an id set nothing can resolve'
			);
			$this->assertNotSame( '', $r['table'] );
			$this->assertNotSame( '', $r['column'] );
		}
	}

	public function test_discovery_targets_exclude_the_spaces_table_itself(): void {
		// The spaces row is what existence is checked AGAINST, so including it
		// would make every space its own orphan.
		foreach ( Space_Backfill::columns() as $c ) {
			$this->assertStringNotContainsString(
				'jt_spaces',
				$c['table'],
				'the spaces table is a discovery target; every space would report as orphaned'
			);
		}
	}
}
