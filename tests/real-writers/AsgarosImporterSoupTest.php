<?php
namespace Jetonomy\Tests\RealWriters;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

/**
 * Div soup through the REAL Asgaros importer (Basecamp 10138808747).
 *
 * The choke-point suite proves the model barrier, and RealWriterPersistenceTest
 * drives the Abilities callback, the bbPress importer and Pro's inbound email.
 * QA's remaining blocker was that no fixture carried soup through the wpForo or
 * Asgaros importers, which are the paths a migrating customer actually uses -
 * and which are exactly where soup arrives, because both source forums stored
 * TinyMCE-era markup.
 *
 * This builds the three Asgaros source tables, seeds a topic whose first post
 * and reply are both div soup, runs `Asgaros_Importer::run()`, and asserts on
 * the PERSISTED rows - not on a return value.
 */
class AsgarosImporterSoupTest extends WP_UnitTestCase {

	private const SOUP       = '<div>first line<br>second line</div><div><br></div><div>next paragraph</div>';
	private const NORMALIZED = "<p>first line<br />second line</p>\n<p>next paragraph</p>";

	/** @var string[] Fully-prefixed source table names, dropped in tear_down. */
	private array $tables = array();

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$this->create_source_tables();
	}

	public function tear_down(): void {
		global $wpdb;
		foreach ( $this->tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
		}
		parent::tear_down();
	}

	/**
	 * The minimum Asgaros schema the importer reads: forums, topics, and posts
	 * (first post of a topic becomes the topic body, the rest become replies).
	 */
	private function create_source_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// WP_UnitTestCase filters `query` to rewrite CREATE TABLE into CREATE
		// TEMPORARY TABLE, and a temporary table is invisible to SHOW TABLES -
		// which is exactly how Asgaros_Importer::is_source_available() detects
		// the source. Real tables are needed here; tear_down drops them.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->tables = array( "{$p}forum_forums", "{$p}forum_topics", "{$p}forum_posts" );
		foreach ( $this->tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
		}

		// phpcs:disable WordPress.DB
		$wpdb->query(
			"CREATE TABLE {$p}forum_forums (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(255) NOT NULL DEFAULT '',
				description text,
				parent_forum bigint(20) unsigned NOT NULL DEFAULT 0,
				sort int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE {$p}forum_topics (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(255) NOT NULL DEFAULT '',
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				author_id bigint(20) unsigned NOT NULL DEFAULT 0,
				approved tinyint(1) NOT NULL DEFAULT 1,
				sticky tinyint(1) NOT NULL DEFAULT 0,
				closed tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
			)"
		);
		$wpdb->query(
			"CREATE TABLE {$p}forum_posts (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
				author_id bigint(20) unsigned NOT NULL DEFAULT 0,
				text longtext,
				date datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (id)
			)"
		);
		// phpcs:enable WordPress.DB
	}

	public function test_asgaros_importer_normalizes_topic_and_reply_soup(): void {
		global $wpdb;
		$p      = $wpdb->prefix;
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$wpdb->insert( "{$p}forum_forums", array( 'name' => 'Imported Asgaros Forum', 'parent_forum' => 0, 'sort' => 0 ) );
		$forum_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			"{$p}forum_topics",
			array( 'name' => 'Asgaros soup topic', 'parent_id' => $forum_id, 'author_id' => $author, 'approved' => 1 )
		);
		$topic_id = (int) $wpdb->insert_id;

		// First post becomes the TOPIC BODY; the second becomes a REPLY. Both
		// carry the soup a real TinyMCE-era forum stores.
		$wpdb->insert(
			"{$p}forum_posts",
			array( 'parent_id' => $topic_id, 'author_id' => $author, 'text' => self::SOUP, 'date' => '2026-01-01 00:00:00' )
		);
		$wpdb->insert(
			"{$p}forum_posts",
			array( 'parent_id' => $topic_id, 'author_id' => $author, 'text' => self::SOUP, 'date' => '2026-01-02 00:00:00' )
		);

		$importer = new \Jetonomy\Import\Asgaros_Importer();
		$this->assertTrue( $importer->is_source_available(), 'precondition: the importer sees the seeded source tables' );
		$importer->run( array() );

		$post = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jt_posts WHERE title = 'Asgaros soup topic'" ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $post, 'the importer must create the jt_posts row' );
		$this->assertSame(
			self::NORMALIZED,
			$post->content,
			'an imported topic body must persist as paragraphs, not the div soup Asgaros stored'
		);

		$reply = $wpdb->get_row( // phpcs:ignore WordPress.DB
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_replies WHERE post_id = %d ORDER BY id ASC LIMIT 1", (int) $post->id )
		);
		$this->assertNotNull( $reply, 'the importer must create the jt_replies row' );
		$this->assertSame(
			self::NORMALIZED,
			$reply->content,
			'an imported reply must persist as paragraphs too'
		);

		$this->assertStringNotContainsString( '<div>', (string) $post->content, 'no div soup may survive the import' );
		$this->assertStringNotContainsString( '<div>', (string) $reply->content, 'no div soup may survive the import' );
	}
}
