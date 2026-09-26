<?php
namespace Jetonomy\Tests\Integration\Import;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Import\BBPress_Importer;

/**
 * bbPress stickies and reply threading survive the import.
 *
 * bbPress keeps stickies in the forum's `_bbp_sticky_topics` meta and the
 * `_bbp_super_sticky_topics` option, and the reply being answered in
 * `_bbp_reply_to`. The importer read none of them: every sticky imported
 * unstuck and every threaded discussion imported flat.
 */
class BbpressStructureTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * Drive run_batch() the way Import_Handler does: a fresh instance per batch,
	 * nothing carried over but the database. Returns the phases visited, the
	 * summed tally and the progress numbers the handler would show.
	 */
	private function import( bool $new_run = true, int $batch = 2 ): array {
		$phase  = 'forums';
		$offset = 0;
		$seen   = [];
		$tally  = [
			'imported'  => 0,
			'already'   => 0,
			'orphans'   => [],
			'processed' => 0,
			'total'     => 0,
		];
		if ( $new_run ) {
			( new BBPress_Importer() )->reset_run_state();
		}
		do {
			$importer = new BBPress_Importer();
			$r        = $importer->run_batch( $phase, $offset, $batch );
			$t        = $importer->get_tally();
			$seen[]   = $phase;

			$tally['imported']  += $t['imported'];
			$tally['already']   += $t['already'];
			$tally['processed'] += $r['processed'];
			$tally['total']      = $importer->get_total_count();
			foreach ( $t['orphans'] as $type => $count ) {
				$tally['orphans'][ $type ] = ( $tally['orphans'][ $type ] ?? 0 ) + $count;
			}

			$phase  = $r['phase'];
			$offset = $r['offset'];
		} while ( ! $r['done'] );

		$tally['phases'] = $seen;

		return $tally;
	}

	private function jt_id( string $type, int $source_id ): int {
		return \Jetonomy\Models\Import_Map::find( 'bbpress', $type, $source_id );
	}

	public function test_stickies_and_threading_are_preserved_across_runs(): void {
		global $wpdb;
		$p     = $wpdb->prefix . 'jt_';
		$user  = self::factory()->user->create();
		$forum = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'F' ] );
		$topic = function ( string $title ) use ( $forum, $user ): int {
			return self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $forum, 'post_author' => $user, 'post_title' => $title ] );
		};
		$reply = function ( int $topic_id, int $reply_to = 0 ) use ( $user ): int {
			$id = self::factory()->post->create( [ 'post_type' => 'reply', 'post_status' => 'publish', 'post_parent' => $topic_id, 'post_author' => $user, 'post_content' => 'r' . wp_rand() ] );
			update_post_meta( $id, '_bbp_reply_to', $reply_to );
			return $id;
		};

		$sticky = $topic( 'Forum sticky' );
		$super  = $topic( 'Super sticky' );
		$plain  = $topic( 'Plain' );
		update_post_meta( $forum, '_bbp_sticky_topics', [ $sticky ] );
		update_option( '_bbp_super_sticky_topics', [ $super ] );

		$r1 = $reply( $plain );
		$r2 = $reply( $plain, $r1 );
		$r3 = $reply( $plain, $r2 );
		$r4 = $reply( $plain, 999999 ); // Answers a reply that never imports.

		$this->import();

		$is_sticky = fn( int $src ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT is_sticky FROM {$p}posts WHERE id = %d", $this->jt_id( 'post', $src ) ) );
		$parent    = fn( int $src ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$p}replies WHERE id = %d", $this->jt_id( 'reply', $src ) ) );

		$this->assertSame( 1, $is_sticky( $sticky ) );
		$this->assertSame( 1, $is_sticky( $super ) );
		$this->assertSame( 0, $is_sticky( $plain ) );
		$this->assertSame( 0, $parent( $r1 ) );
		$this->assertSame( $this->jt_id( 'reply', $r1 ), $parent( $r2 ), 'parent in an earlier batch' );
		$this->assertSame( $this->jt_id( 'reply', $r2 ), $parent( $r3 ) );
		$this->assertSame( 0, $parent( $r4 ), 'unknown parent imports top-level, not dropped' );

		// A reply added after the first import, answering an old reply, threads under it on a re-run.
		$r5 = $reply( $plain, $r1 );
		$this->import();

		$this->assertSame( $this->jt_id( 'reply', $r1 ), $parent( $r5 ) );
		$this->assertSame( 5, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}replies" ), 'no duplicates on re-run' );

		// Threading across batches of 2 resolved through jt_import_map alone:
		// the whole id map is no longer carried between batches in an option.
		$this->assertFalse( get_option( 'jetonomy_import_id_map' ) );
	}

	/**
	 * Replies under a topic that is not imported are counted, with a reason,
	 * instead of the screen claiming nothing was skipped. And the progress
	 * total includes the profiles phase, so it never runs past 100%.
	 */
	public function test_orphaned_rows_are_tallied_and_progress_ends_at_total(): void {
		$user    = self::factory()->user->create();
		$forum   = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'F' ] );
		$live    = self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $forum, 'post_author' => $user ] );
		$pending = self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'pending', 'post_parent' => $forum, 'post_author' => $user ] );
		foreach ( [ $live, $pending, $pending, $pending ] as $topic ) {
			self::factory()->post->create( [ 'post_type' => 'reply', 'post_status' => 'publish', 'post_parent' => $topic, 'post_author' => $user ] );
		}

		$run = $this->import();

		$this->assertSame( [ 'reply' => 3 ], $run['orphans'] );
		$this->assertSame( 3, $run['imported'], 'forum, topic, one reply' );
		$this->assertSame( $run['total'], $run['processed'], 'progress ends exactly at the total' );
		$this->assertStringContainsString( '3 replies were not imported because their topic was not imported', implode( ' ', \Jetonomy\Import\Importer::describe_tally( $run ) ) );

		$again = $this->import();
		$this->assertSame( 0, $again['imported'] );
		$this->assertSame( 3, $again['already'] );
		$this->assertSame( [ 'reply' => 3 ], $again['orphans'] );
	}

	/**
	 * A private forum's participants are granted by a paged members phase,
	 * not one insert per person inside the forums batch.
	 */
	public function test_private_forum_participants_are_granted_in_pages(): void {
		global $wpdb;
		$forum = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'private', 'post_title' => 'Lounge' ] );
		$users = self::factory()->user->create_many( 5 );
		foreach ( $users as $user ) {
			self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $forum, 'post_author' => $user ] );
		}

		$run = $this->import();

		$space = $this->jt_id( 'space', $forum );
		$this->assertGreaterThanOrEqual( 3, count( array_keys( $run['phases'], 'members', true ) ), '5 people at 2 per batch take 3 members batches' );
		$this->assertSame( 5, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_space_members WHERE space_id = %d", $space ) ) );
		$this->assertFalse( get_option( 'jetonomy_import_bbpress_grants' ), 'the grant queue is cleared once granted' );
	}

	/**
	 * A bbPress category (and the group forums root) becomes a Jetonomy
	 * category holding its forums, not an empty space; emoji leave the slug.
	 */
	public function test_category_forum_becomes_a_category_and_slugs_are_clean(): void {
		global $wpdb;
		$user = self::factory()->user->create();
		$cat  = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'Community', 'post_name' => 'community' ] );
		update_post_meta( $cat, '_bbp_forum_type', 'category' );
		$cafe = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'Cafe', 'post_name' => 'off-topic-cafe-%e2%98%95', 'post_parent' => $cat ] );
		$sub  = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'Sub', 'post_parent' => $cafe ] );
		$top  = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'Top level', 'menu_order' => 5 ] );
		self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $sub, 'post_author' => $user, 'post_title' => 'Hot ☕' ] );

		$this->import();

		$this->assertSame( 0, $this->jt_id( 'space', $cat ), 'no space for the category forum' );
		$category = \Jetonomy\Models\Import_Map::find( 'bbpress', 'category', 'forum-' . $cat );
		$this->assertGreaterThan( 0, $category );

		$space = $wpdb->get_row( $wpdb->prepare( "SELECT slug, category_id, parent_id FROM {$wpdb->prefix}jt_spaces WHERE id = %d", $this->jt_id( 'space', $cafe ) ) );
		$this->assertSame( 'off-topic-cafe', $space->slug );
		$this->assertSame( $category, (int) $space->category_id, 'filed under the category it sat in' );
		$this->assertSame( 0, (int) $space->parent_id );
		$sub_row = $wpdb->get_row( $wpdb->prepare( "SELECT parent_id, category_id FROM {$wpdb->prefix}jt_spaces WHERE id = %d", $this->jt_id( 'space', $sub ) ) );
		$this->assertSame( $this->jt_id( 'space', $cafe ), (int) $sub_row->parent_id, 'deeper nesting kept' );
		$this->assertSame( $category, (int) $sub_row->category_id, 'a sub-forum shares its parent space category' );
		$this->assertSame( 'hot', $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}jt_posts WHERE id = %d", \Jetonomy\Models\Import_Map::find( 'bbpress', 'post', (string) ( $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'topic'" ) ) ) ) ) );

		// A top-level forum imported after the category existed goes to the
		// import's own category, not the category forum's (legacy lookup must
		// never adopt a category the map already owns).
		$top_cat = (int) $wpdb->get_var( $wpdb->prepare( "SELECT category_id FROM {$wpdb->prefix}jt_spaces WHERE id = %d", $this->jt_id( 'space', $top ) ) );
		$this->assertSame( \Jetonomy\Models\Import_Map::find( 'bbpress', 'category', 'default' ), $top_cat );
		$this->assertNotSame( $category, $top_cat );

		// A re-run reuses the category and creates nothing.
		$again = $this->import();
		$this->assertSame( 0, $again['imported'] );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jt_categories WHERE name = %s", 'Community' ) ) );
	}

	/**
	 * Data left behind by a deactivated forum plugin is still importable, and
	 * the screen is told the plugin is not active instead of "Available".
	 */
	public function test_source_with_data_but_no_plugin_is_listed_as_inactive(): void {
		self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'Left behind' ] );

		$importer = new BBPress_Importer();
		$this->assertTrue( $importer->is_source_available() );
		$this->assertSame( class_exists( 'bbPress', false ), $importer->is_source_active() );

		\Jetonomy\Import\Import_Manager::init();
		$available = \Jetonomy\Import\Import_Manager::get_available();
		$this->assertArrayHasKey( 'bbpress', $available );
		$this->assertSame( class_exists( 'bbPress', false ), $available['bbpress']['active'] );
	}

	/**
	 * A re-run restores threading on replies an earlier release imported flat,
	 * without overwriting a reply the owner has re-threaded since.
	 */
	public function test_rerun_rethreads_flat_legacy_replies_only_where_parent_is_missing(): void {
		global $wpdb;
		$p     = $wpdb->prefix . 'jt_';
		$user  = self::factory()->user->create();
		$forum = self::factory()->post->create( [ 'post_type' => 'forum', 'post_status' => 'publish', 'post_title' => 'F' ] );
		$topic = self::factory()->post->create( [ 'post_type' => 'topic', 'post_status' => 'publish', 'post_parent' => $forum, 'post_author' => $user, 'post_title' => 'T' ] );
		$reply = function ( int $reply_to = 0 ) use ( $topic, $user ): int {
			$id = self::factory()->post->create( [ 'post_type' => 'reply', 'post_status' => 'publish', 'post_parent' => $topic, 'post_author' => $user, 'post_content' => 'r' . wp_rand() ] );
			update_post_meta( $id, '_bbp_reply_to', $reply_to );
			return $id;
		};
		$r1 = $reply();
		$r2 = $reply( $r1 );
		$r3 = $reply( $r2 );

		$this->import();

		// What a 1.9.x import left behind: every reply top-level, except one the
		// owner has since moved under $r1 by hand.
		$wpdb->query( "UPDATE {$p}replies SET parent_id = NULL" );
		$wpdb->update( "{$p}replies", [ 'parent_id' => $this->jt_id( 'reply', $r1 ) ], [ 'id' => $this->jt_id( 'reply', $r3 ) ] );

		$this->import();

		$parent = fn( int $src ) => (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$p}replies WHERE id = %d", $this->jt_id( 'reply', $src ) ) );
		$this->assertSame( $this->jt_id( 'reply', $r1 ), $parent( $r2 ), 'flat legacy reply re-threaded' );
		$this->assertSame( $this->jt_id( 'reply', $r1 ), $parent( $r3 ), "owner's own threading kept" );
		$this->assertSame( 0, $parent( $r1 ) );
		$this->assertSame( 3, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}replies" ), 'no duplicates' );
	}

	public function test_fill_missing_parents_refuses_a_parent_on_another_post(): void {
		global $wpdb;
		$p    = $wpdb->prefix . 'jt_';
		$user = self::factory()->user->create();
		$make = fn( int $post_id ) => \Jetonomy\Models\Reply::insert( [ 'post_id' => $post_id, 'author_id' => $user, 'content' => 'x', 'status' => 'publish' ] );
		$a    = $make( 101 );
		$b    = $make( 202 );

		$this->assertSame( 0, \Jetonomy\Models\Reply::fill_missing_parents( [ $b => $a ] ) );
		$this->assertNull( $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$p}replies WHERE id = %d", $b ) ) );
	}
}
