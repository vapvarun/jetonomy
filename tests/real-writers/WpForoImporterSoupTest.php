<?php
namespace Jetonomy\Tests\RealWriters;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

/**
 * Div soup through the REAL wpForo importer (Basecamp 10138808747).
 *
 * Twin of AsgarosImporterSoupTest. wpForo is the deeper of the two source
 * schemas - boards own per-board table prefixes, and a topic's body is the
 * first row in that board's posts table - so it gets its own fixture rather
 * than being folded into the Asgaros one.
 *
 * Same contract as every other real-writer test: soup goes in through the
 * importer a migrating customer actually runs, and the assertion is on the
 * PERSISTED row, not a return value.
 */
class WpForoImporterSoupTest extends WP_UnitTestCase {

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
	 * Board 0's schema: boards, forums, topics, posts.
	 *
	 * Board 0 uses the bare `wpforo_` prefix (later boards get `wpforo2_`,
	 * `wpforo3_`), which is what the importer's board discovery expects.
	 */
	private function create_source_tables(): void {
		global $wpdb;
		$p = $wpdb->prefix;

		// WP_UnitTestCase rewrites CREATE TABLE into CREATE TEMPORARY TABLE, and
		// a temporary table is invisible to SHOW TABLES - which is exactly how
		// the importer discovers a board and how is_source_available() detects
		// wpForo at all. Real tables are needed; tear_down drops them.
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		$this->tables = array(
			"{$p}wpforo_boards",
			"{$p}wpforo_forums",
			"{$p}wpforo_topics",
			"{$p}wpforo_posts",
		);
		foreach ( $this->tables as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB
		}

		// phpcs:disable WordPress.DB
		$wpdb->query(
			"CREATE TABLE {$p}wpforo_boards (
				boardid bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(255) NOT NULL DEFAULT '',
				status tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY (boardid)
			)"
		);
		$wpdb->query(
			"CREATE TABLE {$p}wpforo_forums (
				forumid bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				title varchar(255) NOT NULL DEFAULT '',
				slug varchar(255) NOT NULL DEFAULT '',
				description text,
				parentid bigint(20) unsigned NOT NULL DEFAULT 0,
				`order` int(11) NOT NULL DEFAULT 0,
				PRIMARY KEY (forumid)
			)"
		);
		$wpdb->query(
			"CREATE TABLE {$p}wpforo_topics (
				topicid bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				forumid bigint(20) unsigned NOT NULL DEFAULT 0,
				userid bigint(20) unsigned NOT NULL DEFAULT 0,
				title varchar(255) NOT NULL DEFAULT '',
				slug varchar(255) NOT NULL DEFAULT '',
				status tinyint(1) NOT NULL DEFAULT 0,
				closed tinyint(1) NOT NULL DEFAULT 0,
				type tinyint(1) NOT NULL DEFAULT 0,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (topicid)
			)"
		);
		$wpdb->query(
			"CREATE TABLE {$p}wpforo_posts (
				postid bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				topicid bigint(20) unsigned NOT NULL DEFAULT 0,
				userid bigint(20) unsigned NOT NULL DEFAULT 0,
				parentid bigint(20) unsigned NOT NULL DEFAULT 0,
				body longtext,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY (postid)
			)"
		);
		// phpcs:enable WordPress.DB
	}

	public function test_wpforo_importer_normalizes_topic_and_reply_soup(): void {
		global $wpdb;
		$p      = $wpdb->prefix;
		$author = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$wpdb->insert( "{$p}wpforo_boards", array( 'boardid' => 0, 'title' => 'Main Board', 'status' => 1 ) );

		$wpdb->insert(
			"{$p}wpforo_forums",
			array( 'title' => 'Imported wpForo Forum', 'slug' => 'imported-wpforo-forum', 'parentid' => 0, 'order' => 0 )
		);
		$forum_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			"{$p}wpforo_topics",
			array(
				'forumid' => $forum_id,
				'userid'  => $author,
				'title'   => 'wpForo soup topic',
				'slug'    => 'wpforo-soup-topic',
				'status'  => 0,
				'created' => '2026-01-01 00:00:00',
			)
		);
		$topic_id = (int) $wpdb->insert_id;

		// Lowest postid becomes the TOPIC BODY; the next becomes a REPLY.
		$wpdb->insert(
			"{$p}wpforo_posts",
			array( 'topicid' => $topic_id, 'userid' => $author, 'body' => self::SOUP, 'created' => '2026-01-01 00:00:00' )
		);
		$wpdb->insert(
			"{$p}wpforo_posts",
			array( 'topicid' => $topic_id, 'userid' => $author, 'body' => self::SOUP, 'created' => '2026-01-02 00:00:00' )
		);

		$importer = new \Jetonomy\Import\WpForo_Importer();
		$this->assertTrue( $importer->is_source_available(), 'precondition: the importer sees the seeded wpForo tables' );
		$importer->run( array() );

		$post = $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}jt_posts WHERE title = 'wpForo soup topic'" ); // phpcs:ignore WordPress.DB
		$this->assertNotNull( $post, 'the importer must create the jt_posts row' );
		$this->assertSame(
			self::NORMALIZED,
			$post->content,
			'an imported topic body must persist as paragraphs, not the div soup wpForo stored'
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
