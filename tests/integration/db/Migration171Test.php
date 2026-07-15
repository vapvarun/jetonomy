<?php
namespace Jetonomy\Tests\Integration\DB;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\DB\Migrations\Migration_1_7_1;
use Jetonomy\Models\Attachment;

/**
 * Free-side coverage of the 1.7.1 migration that moved attachments out of Pro.
 *
 * The Pro suite covers the destructive both-tables-exist MERGE path; these tests
 * cover the parts a free-only site relies on: the table is in the schema, up()
 * creates it on a fresh install, and re-running the migration is safe and lossless.
 */
class Migration171Test extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	private function table_exists( string $table ): bool {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$wpdb->get_var( "SELECT 1 FROM `{$table}` LIMIT 1" ); // phpcs:ignore WordPress.DB
		$ok = ( '' === $wpdb->last_error );
		$wpdb->suppress_errors( false );
		return $ok;
	}

	public function test_jt_attachments_is_in_the_schema_table_names(): void {
		$this->assertContains( 'jt_attachments', Schema::get_table_names(), 'The moved-to-free table must be part of the canonical schema so uninstall + install both know about it.' );
		$this->assertContains( 'jt_blocked_users', Schema::get_table_names() );
	}

	private function columns( string $table ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_column( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ), 'Field' );
	}

	/**
	 * On a free-only / fresh site there is no jt_pro_attachments, so up() takes the
	 * neither-old-nor-merge branch and just dbDelta's the table into existence — the
	 * exact path this env exercises. Assert its outcome: a well-formed jt_attachments
	 * (and the jt_blocked_users the same migration owns), and no stray old table.
	 */
	public function test_up_ensures_the_table_on_a_free_only_site(): void {
		global $wpdb;
		$old = $wpdb->prefix . 'jt_pro_attachments';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$old}" ); // guarantee the free-only branch

		( new Migration_1_7_1() )->up();

		$table = $wpdb->prefix . 'jt_attachments';
		$this->assertTrue( $this->table_exists( $table ), 'A free-only site must have jt_attachments after the migration.' );
		$this->assertTrue( $this->table_exists( $wpdb->prefix . 'jt_blocked_users' ) );

		foreach ( array( 'id', 'object_type', 'object_id', 'attachment_id', 'sort', 'created_at' ) as $col ) {
			$this->assertContains( $col, $this->columns( $table ), "jt_attachments must have the '{$col}' column." );
		}
	}

	public function test_up_is_idempotent_and_lossless_on_rerun(): void {
		// A live row exists; running the migration again (dbDelta is idempotent) must
		// neither error nor drop the customer's data.
		$link = Attachment::link( 'post', 848484, 606, 0 );
		$this->assertGreaterThan( 0, $link );

		( new Migration_1_7_1() )->up();
		( new Migration_1_7_1() )->up();

		$this->assertSame( 1, Attachment::count_for( 'post', 848484 ), 'Re-running the migration must preserve existing attachment rows.' );
	}

	public function test_created_table_has_the_unique_key_that_backs_link_idempotency(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_attachments';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $wpdb->get_results( "SHOW INDEX FROM `{$table}` WHERE Key_name = 'object_attachment'" );
		$this->assertNotEmpty( $rows, 'The UNIQUE KEY object_attachment is what makes link() idempotent and the merge INSERT IGNORE safe.' );
	}
}
