<?php
namespace Jetonomy\Tests\Integration\Import;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Import\Asgaros_Importer;
use Jetonomy\Import\Importer;
use Jetonomy\Import\WPForo_Importer;
use Jetonomy\Models\Import_Map;

/**
 * The batched wpForo and Asgaros paths, one row per batch.
 *
 * Nothing is carried between batches but the database: each batch loads the
 * parents it needs from jt_import_map. So a topic in batch 2 must find the
 * forum from batch 1, a threaded reply its parent from an earlier batch, and
 * a wpForo like the reply it points at - without the old id-map option.
 * Also: rows whose parent was not imported are tallied with a reason, and the
 * progress total equals the rows the batches report.
 */
class ForumTableImportBatchTest extends WP_UnitTestCase {

	/** @var string[] */
	private array $tables = [];

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		// Real tables: SHOW TABLES (source detection) cannot see temporary ones.
		remove_filter( 'query', [ $this, '_create_temporary_tables' ] );
		remove_filter( 'query', [ $this, '_drop_temporary_tables' ] );
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
		}
		parent::tear_down();
	}

	/** @param array<string,string> $defs table suffix => column SQL. */
	private function tables( array $defs ): void {
		global $wpdb;
		foreach ( $defs as $name => $cols ) {
			$t              = $wpdb->prefix . $name;
			$this->tables[] = $t;
			// phpcs:disable WordPress.DB
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" );
			$wpdb->query( "CREATE TABLE {$t} ( {$cols} )" );
			// phpcs:enable WordPress.DB
		}
	}

	/** Drive run_batch() like Import_Handler: fresh instance per batch. */
	private function drive( string $class ): array {
		( new $class() )->reset_run_state();
		$phase = 'forums';
		$off   = 0;
		$sum   = [
			'processed' => 0,
			'imported'  => 0,
			'orphans'   => [],
		];
		do {
			$importer = new $class();
			$r        = $importer->run_batch( $phase, $off, 1 );
			$t        = $importer->get_tally();

			$sum['processed'] += $r['processed'];
			$sum['imported']  += $t['imported'];
			$sum['total']      = $importer->get_total_count();
			foreach ( $t['orphans'] as $type => $n ) {
				$sum['orphans'][ $type ] = ( $sum['orphans'][ $type ] ?? 0 ) + $n;
			}
			$phase = $r['phase'];
			$off   = $r['offset'];
		} while ( ! $r['done'] );

		return $sum;
	}

	private function parent_of( string $source, string $source_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT parent_id FROM {$wpdb->prefix}jt_replies WHERE id = %d", Import_Map::find( $source, 'reply', $source_id ) ) );
	}

	public function test_wpforo_batches_resolve_parents_threads_and_likes_from_the_map(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$this->tables(
			[
				'wpforo_boards' => 'boardid bigint(20) unsigned NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT \'\', status tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (boardid)',
				'wpforo_forums' => 'forumid bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(255) NOT NULL DEFAULT \'\', slug varchar(255) NOT NULL DEFAULT \'\', description text, parentid bigint(20) unsigned NOT NULL DEFAULT 0, `order` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (forumid)',
				'wpforo_topics' => 'topicid bigint(20) unsigned NOT NULL AUTO_INCREMENT, forumid bigint(20) unsigned NOT NULL DEFAULT 0, userid bigint(20) unsigned NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT \'\', slug varchar(255) NOT NULL DEFAULT \'\', status tinyint(1) NOT NULL DEFAULT 0, closed tinyint(1) NOT NULL DEFAULT 0, type tinyint(1) NOT NULL DEFAULT 0, created datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\', PRIMARY KEY (topicid)',
				'wpforo_posts'  => 'postid bigint(20) unsigned NOT NULL AUTO_INCREMENT, topicid bigint(20) unsigned NOT NULL DEFAULT 0, userid bigint(20) unsigned NOT NULL DEFAULT 0, parentid bigint(20) unsigned NOT NULL DEFAULT 0, body longtext, created datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\', PRIMARY KEY (postid)',
				'wpforo_likes'  => 'likeid bigint(20) unsigned NOT NULL AUTO_INCREMENT, postid bigint(20) unsigned NOT NULL DEFAULT 0, userid bigint(20) unsigned NOT NULL DEFAULT 0, PRIMARY KEY (likeid)',
			]
		);
		[ $u1, $u2 ] = self::factory()->user->create_many( 2 );

		$wpdb->insert( "{$p}wpforo_forums", [ 'title' => 'Cafe ☕', 'slug' => 'cafe-%e2%98%95' ] );
		$forum = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpforo_topics", [ 'forumid' => $forum, 'userid' => $u1, 'title' => 'T1', 'created' => '2026-01-01 00:00:00' ] );
		$t1 = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}wpforo_topics", [ 'forumid' => 999, 'userid' => $u1, 'title' => 'Lost', 'created' => '2026-01-01 00:00:00' ] );
		$t2   = (int) $wpdb->insert_id;
		$post = function ( int $topic, int $parent = 0 ) use ( $wpdb, $p, $u1 ): int {
			$wpdb->insert( "{$p}wpforo_posts", [ 'topicid' => $topic, 'userid' => $u1, 'parentid' => $parent, 'body' => 'b' . wp_rand(), 'created' => '2026-01-02 00:00:00' ] );
			return (int) $wpdb->insert_id;
		};
		$post( $t1 ); // Topic body.
		$r1 = $post( $t1 );
		$r2 = $post( $t1, $r1 );
		$post( $t2 ); // Body of a topic whose forum is gone.
		$post( $t2 );
		$wpdb->insert( "{$p}wpforo_likes", [ 'postid' => $r1, 'userid' => $u2 ] );

		$run = $this->drive( WPForo_Importer::class );

		$this->assertSame( [ 'topic' => 1, 'reply' => 1 ], $run['orphans'] );
		$this->assertSame( Import_Map::find( 'wpforo', 'reply', (string) $r1 ), $this->parent_of( 'wpforo', (string) $r2 ), 'threaded across batches' );
		$this->assertNotNull( \Jetonomy\Models\Vote::get_user_vote( $u2, 'reply', Import_Map::find( 'wpforo', 'reply', (string) $r1 ) ), 'like resolved its reply from the map' );
		$this->assertSame( $run['total'], $run['processed'], 'progress ends exactly at the total' );
		$this->assertSame( 'cafe', $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$p}jt_spaces WHERE id = %d", Import_Map::find( 'wpforo', 'space', (string) $forum ) ) ) );
		$this->assertFalse( get_option( 'jetonomy_import_id_map' ) );
	}

	/**
	 * wpForo 2.0+ keeps likes in {prefix}reactions (the likes table is gone).
	 * The likes phase pages on the reaction id, one row per batch here, counts
	 * in the progress total, and a re-run neither adds a vote nor toggles an
	 * existing one off (Vote::cast() toggles a repeat).
	 */
	public function test_wpforo_reactions_page_and_rerun_adds_and_retracts_nothing(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$this->tables(
			[
				'wpforo_boards'    => 'boardid bigint(20) unsigned NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT \'\', status tinyint(1) NOT NULL DEFAULT 1, PRIMARY KEY (boardid)',
				'wpforo_forums'    => 'forumid bigint(20) unsigned NOT NULL AUTO_INCREMENT, title varchar(255) NOT NULL DEFAULT \'\', slug varchar(255) NOT NULL DEFAULT \'\', description text, parentid bigint(20) unsigned NOT NULL DEFAULT 0, `order` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (forumid)',
				'wpforo_topics'    => 'topicid bigint(20) unsigned NOT NULL AUTO_INCREMENT, forumid bigint(20) unsigned NOT NULL DEFAULT 0, userid bigint(20) unsigned NOT NULL DEFAULT 0, title varchar(255) NOT NULL DEFAULT \'\', slug varchar(255) NOT NULL DEFAULT \'\', status tinyint(1) NOT NULL DEFAULT 0, closed tinyint(1) NOT NULL DEFAULT 0, type tinyint(1) NOT NULL DEFAULT 0, created datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\', PRIMARY KEY (topicid)',
				'wpforo_posts'     => 'postid bigint(20) unsigned NOT NULL AUTO_INCREMENT, topicid bigint(20) unsigned NOT NULL DEFAULT 0, userid bigint(20) unsigned NOT NULL DEFAULT 0, parentid bigint(20) unsigned NOT NULL DEFAULT 0, body longtext, created datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\', PRIMARY KEY (postid)',
				'wpforo_reactions' => 'reactionid bigint(20) unsigned NOT NULL AUTO_INCREMENT, userid bigint(20) unsigned NOT NULL, postid bigint(20) unsigned NOT NULL, post_userid bigint(20) unsigned NOT NULL DEFAULT 0, reaction tinyint NOT NULL DEFAULT 1, type varchar(50) NOT NULL DEFAULT \'up\', name varchar(50) DEFAULT NULL, email varchar(100) DEFAULT NULL, PRIMARY KEY (reactionid)',
			]
		);
		[ $u1, $u2, $u3 ] = self::factory()->user->create_many( 3 );

		$wpdb->insert( "{$p}wpforo_forums", [ 'title' => 'F', 'slug' => 'f' ] );
		$wpdb->insert( "{$p}wpforo_topics", [ 'forumid' => (int) $wpdb->insert_id, 'userid' => $u1, 'title' => 'T', 'created' => '2026-01-01 00:00:00' ] );
		$topic = (int) $wpdb->insert_id;
		$posts = [];
		foreach ( range( 0, 3 ) as $i ) {
			$wpdb->insert( "{$p}wpforo_posts", [ 'topicid' => $topic, 'userid' => $u1, 'body' => "b{$i}", 'created' => '2026-01-02 00:00:0' . $i ] );
			$posts[] = (int) $wpdb->insert_id;
		}
		$react = function ( int $user, int $post, int $value = 1, string $type = 'up' ) use ( $wpdb, $p ): void {
			$wpdb->insert( "{$p}wpforo_reactions", [ 'userid' => $user, 'postid' => $post, 'reaction' => $value, 'type' => $type ] );
		};
		$react( $u2, $posts[1] );
		$react( $u3, $posts[1] );
		$react( $u2, $posts[2] );
		$react( $u2, $posts[2], 1, 'love' ); // Second reaction by the same member: one vote.
		$react( $u3, $posts[3], -1, 'down' ); // A down-vote is not a like.
		$react( 0, $posts[3] );                // Guest reaction: no account to vote as.

		$votes = fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jt_votes WHERE object_type = 'reply'" );

		$run = $this->drive( WPForo_Importer::class );
		$this->assertSame( 3, $votes() );
		$this->assertSame( $run['total'], $run['processed'], 'likes counted in progress; ends at the total' );

		$again = $this->drive( WPForo_Importer::class );
		$this->assertSame( 0, $again['imported'], 're-run adds nothing' );
		$this->assertSame( 3, $votes(), 're-run toggles nothing off' );
		$this->assertSame( $again['total'], $again['processed'] );
	}

	public function test_asgaros_batches_resolve_parents_from_the_map(): void {
		global $wpdb;
		$p = $wpdb->prefix;
		$this->tables(
			[
				'forum_forums' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(255) NOT NULL DEFAULT \'\', description text, parent_forum bigint(20) unsigned NOT NULL DEFAULT 0, sort int(11) NOT NULL DEFAULT 0, PRIMARY KEY (id)',
				'forum_topics' => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, name varchar(255) NOT NULL DEFAULT \'\', parent_id bigint(20) unsigned NOT NULL DEFAULT 0, author_id bigint(20) unsigned NOT NULL DEFAULT 0, approved tinyint(1) NOT NULL DEFAULT 1, sticky tinyint(1) NOT NULL DEFAULT 0, closed tinyint(1) NOT NULL DEFAULT 0, PRIMARY KEY (id)',
				'forum_posts'  => 'id bigint(20) unsigned NOT NULL AUTO_INCREMENT, parent_id bigint(20) unsigned NOT NULL DEFAULT 0, author_id bigint(20) unsigned NOT NULL DEFAULT 0, text longtext, date datetime NOT NULL DEFAULT \'0000-00-00 00:00:00\', PRIMARY KEY (id)',
			]
		);
		$u = self::factory()->user->create();

		$wpdb->insert( "{$p}forum_forums", [ 'name' => 'F' ] );
		$forum = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}forum_topics", [ 'name' => 'T1', 'parent_id' => $forum, 'author_id' => $u ] );
		$t1 = (int) $wpdb->insert_id;
		$wpdb->insert( "{$p}forum_topics", [ 'name' => 'Lost', 'parent_id' => 999, 'author_id' => $u ] );
		$t2 = (int) $wpdb->insert_id;
		foreach ( [ $t1, $t1, $t1, $t2, $t2 ] as $topic ) {
			$wpdb->insert( "{$p}forum_posts", [ 'parent_id' => $topic, 'author_id' => $u, 'text' => 'x' . wp_rand(), 'date' => '2026-01-02 00:00:00' ] );
		}

		$run = $this->drive( Asgaros_Importer::class );

		$this->assertSame( [ 'topic' => 1, 'reply' => 1 ], $run['orphans'] );
		$this->assertSame( 2, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}jt_replies" ), 'both replies found their topic from an earlier batch' );
		$this->assertSame( $run['total'], $run['processed'], 'progress ends exactly at the total' );
		$this->assertStringContainsString( '1 topic was not imported', implode( ' ', Importer::describe_tally( $run ) ) );
	}
}
