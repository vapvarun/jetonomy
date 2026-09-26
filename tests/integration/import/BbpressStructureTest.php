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

	/** Drive run_batch() the way Import_Handler does: fresh instance per batch. */
	private function import( bool $new_run = true ): void {
		$phase  = 'forums';
		$offset = 0;
		if ( $new_run ) {
			( new BBPress_Importer() )->reset_run_state();
		}
		do {
			$importer         = new BBPress_Importer();
			$importer->id_map = get_option( 'jetonomy_import_id_map', [] );
			$r                = $importer->run_batch( $phase, $offset, 2 );
			$phase            = $r['phase'];
			$offset           = $r['offset'];
		} while ( ! $r['done'] );
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
	}
}
