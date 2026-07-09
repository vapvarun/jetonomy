<?php
namespace Jetonomy\Tests\Pro\Extensions;

use WP_UnitTestCase;
use Jetonomy_Pro\Extensions\Attachments\Extension;

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
}
