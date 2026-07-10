<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Attachments\Extension;
use Jetonomy_Pro\Extensions\Attachments\Model;

class AttachmentsModelTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Pro attachments extension not loaded.' );
		}
		( new Extension() )->activate();
	}

	public function test_table_created(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_attachments';
		$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
	}

	public function test_meta_shape(): void {
		$meta = ( new Extension() )->meta();
		$this->assertSame( 'attachments', $meta['id'] );
		$this->assertSame( 'starter', $meta['requires'] );
	}

	public function test_link_count_and_get(): void {
		$id1 = Model::link( 'post', 4242, 900, 0 );
		$id2 = Model::link( 'post', 4242, 901, 1 );
		$this->assertGreaterThan( 0, $id1 );
		$this->assertSame( 2, Model::count_for( 'post', 4242 ) );
		$rows = Model::get_for( 'post', 4242 );
		$this->assertSame( array( 900, 901 ), array_map( static fn( $r ) => (int) $r->attachment_id, $rows ) );
		$this->assertTrue( Model::unlink( $id2 ) );
		$this->assertSame( 1, Model::count_for( 'post', 4242 ) );
	}

	public function test_delete_post_cascades_detach(): void {
		Model::link( 'post', 3131, 88, 0 );
		( new Extension() )->on_delete_post( 3131 );
		$this->assertSame( 0, Model::count_for( 'post', 3131 ) );
	}

	public function test_delete_reply_cascades_detach(): void {
		Model::link( 'reply', 3232, 89, 0 );
		( new Extension() )->on_delete_reply( 3232 );
		$this->assertSame( 0, Model::count_for( 'reply', 3232 ) );
	}

	public function test_gc_removes_orphan_link_rows(): void {
		global $wpdb;
		Model::link( 'post', 5151, 999999999, 0 ); // attachment id that does not exist.
		( new Extension() )->gc();
		$this->assertSame( 0, Model::count_for( 'post', 5151 ) );
	}

	public function test_gc_schedule_and_clear(): void {
		$ext = new Extension();
		$ext->activate();
		$scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( Extension::GC_HOOK, array(), 'jetonomy' )
			: (bool) wp_next_scheduled( Extension::GC_HOOK );
		$this->assertTrue( (bool) $scheduled );

		$ext->deactivate();
		$still_scheduled = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( Extension::GC_HOOK, array(), 'jetonomy' )
			: (bool) wp_next_scheduled( Extension::GC_HOOK );
		$this->assertFalse( (bool) $still_scheduled );
	}
}
