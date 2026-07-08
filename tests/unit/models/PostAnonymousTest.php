<?php
namespace Jetonomy\Tests\Unit\Models;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;

class PostAnonymousTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_posts_table_has_is_anonymous_column(): void {
		global $wpdb;
		$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}jt_posts LIKE 'is_anonymous'" );
		$this->assertSame( 'is_anonymous', $col );
	}

	public function test_replies_table_has_is_anonymous_column(): void {
		global $wpdb;
		$col = $wpdb->get_var( "SHOW COLUMNS FROM {$wpdb->prefix}jt_replies LIKE 'is_anonymous'" );
		$this->assertSame( 'is_anonymous', $col );
	}
}
