<?php
namespace Jetonomy\Tests\Integration\DB;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

class SchemaTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * Helper: check whether a table exists in the DB.
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;
		// Use SELECT instead of SHOW TABLES — temporary tables (used by
		// the WP test suite) are invisible to SHOW TABLES.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->suppress_errors( true );
		$result = $wpdb->get_var( "SELECT 1 FROM `{$table}` LIMIT 1" );
		$wpdb->suppress_errors( false );
		return $wpdb->last_error === '';
	}

	/**
	 * Helper: get columns from a table.
	 */
	private function get_columns( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" );
		return array_column( (array) $rows, 'Field' );
	}

	/**
	 * Helper: check whether an index exists on a table.
	 */
	private function index_exists( string $table, string $index_name ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = '{$index_name}'" );
		return ! empty( $rows );
	}

	public function test_all_tables_exist_after_create_tables(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;

		$expected = Schema::get_table_names();
		// 1.5.0: jt_space_tags, jt_space_tag_map, and jt_user_interests were
		// removed (never wired to any feature — audit A5; Migration_1_5_0
		// drops them on upgrade), taking the schema from 23 to 20 tables.
		// 1.7.1 added jt_blocked_users (member blocking) and jt_attachments (the
		// attachment link store, moved to free from jt_pro_attachments) -> 22.
		$this->assertCount( 22, $expected );
		foreach ( [ 'jt_space_tags', 'jt_space_tag_map', 'jt_user_interests' ] as $removed ) {
			$this->assertNotContains( $removed, $expected, "Removed table '{$removed}' must not be re-added to the schema." );
		}

		foreach ( $expected as $table_suffix ) {
			$full_name = $prefix . $table_suffix;
			$this->assertTrue(
				$this->table_exists( $full_name ),
				"Expected table '{$full_name}' to exist."
			);
		}
	}

	public function test_categories_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_categories';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'parent_id', 'name', 'slug', 'visibility', 'sort_order', 'space_count', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_spaces_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_spaces';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'category_id', 'title', 'slug', 'visibility', 'post_count', 'member_count', 'settings', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_posts_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_posts';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'space_id', 'author_id', 'title', 'slug', 'content', 'status', 'is_sticky', 'is_closed', 'is_resolved', 'vote_score', 'reply_count', 'view_count', 'accepted_reply_id', 'created_at', 'updated_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_posts_table_has_fulltext_index(): void {
		if ( defined( 'WP_TESTS_TABLE_PREFIX' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
			$this->markTestSkipped( 'FULLTEXT indexes are stripped in the WP test environment (temporary tables).' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jt_posts';
		$this->assertTrue(
			$this->index_exists( $table, 'ft_title_content' ),
			"FULLTEXT index 'ft_title_content' missing from {$table}"
		);
	}

	public function test_replies_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_replies';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'post_id', 'author_id', 'content', 'status', 'vote_score', 'is_accepted', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_replies_table_has_fulltext_index(): void {
		if ( defined( 'WP_TESTS_TABLE_PREFIX' ) || defined( 'WP_TESTS_DOMAIN' ) ) {
			$this->markTestSkipped( 'FULLTEXT indexes are stripped in the WP test environment (temporary tables).' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jt_replies';
		$this->assertTrue(
			$this->index_exists( $table, 'ft_content' ),
			"FULLTEXT index 'ft_content' missing from {$table}"
		);
	}

	public function test_votes_table_has_unique_index(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_votes';
		$this->assertTrue(
			$this->index_exists( $table, 'user_object' ),
			"UNIQUE index 'user_object' missing from {$table}"
		);
	}

	public function test_user_profiles_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_user_profiles';
		$columns = $this->get_columns( $table );

		foreach ( [ 'user_id', 'trust_level', 'reputation', 'post_count', 'reply_count', 'settings', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_space_members_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_space_members';
		$columns = $this->get_columns( $table );

		foreach ( [ 'space_id', 'user_id', 'role', 'joined_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_restrictions_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_restrictions';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'user_id', 'type', 'space_id', 'reason', 'issued_by', 'expires_at', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_join_requests_table_has_required_columns(): void {
		global $wpdb;
		$table   = $wpdb->prefix . 'jt_join_requests';
		$columns = $this->get_columns( $table );

		foreach ( [ 'id', 'space_id', 'user_id', 'message', 'status', 'reviewed_by', 'reviewed_at', 'created_at' ] as $col ) {
			$this->assertContains( $col, $columns, "Column '{$col}' missing from {$table}" );
		}
	}

	public function test_drop_tables_removes_all_tables(): void {
		global $wpdb;
		$prefix = $wpdb->prefix;

		// In wp-env the plugin is activated before the test suite boots, so the
		// REAL jt_* tables already exist. The WP test framework's query filter
		// converts in-test CREATE TABLE → CREATE TEMPORARY TABLE (so each test
		// gets a fresh fixture), but DROP TABLE in a test only drops the temp
		// copy — the real table underneath remains and shadows the assertion.
		// Skip when real tables exist underneath; this scenario is exercised by
		// the uninstall.php integration test instead.
		$real_table_exists = (bool) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND TABLE_TYPE = %s',
				DB_NAME,
				$prefix . 'jt_categories',
				'BASE TABLE'
			)
		);
		if ( $real_table_exists ) {
			$this->markTestSkipped(
				'Skipping drop_tables assertion: real (non-temporary) jt_* tables exist '
				. 'beneath the test suite\'s temporary tables (plugin was activated by '
				. 'wp-env). Drop semantics are covered by uninstall integration tests.'
			);
		}

		Schema::drop_tables();

		foreach ( Schema::get_table_names() as $table_suffix ) {
			$full_name = $prefix . $table_suffix;
			$this->assertFalse(
				$this->table_exists( $full_name ),
				"Expected table '{$full_name}' to be dropped."
			);
		}

		// Re-create so tear-down and subsequent tests don't error.
		Schema::create_tables();
	}

	public function test_create_tables_is_idempotent(): void {
		// Calling create_tables() twice should not cause errors or duplicate tables.
		Schema::create_tables();
		Schema::create_tables();

		global $wpdb;
		$prefix = $wpdb->prefix;

		foreach ( Schema::get_table_names() as $table_suffix ) {
			$full_name = $prefix . $table_suffix;
			$this->assertTrue( $this->table_exists( $full_name ) );
		}
	}
}
