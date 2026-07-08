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

	public function test_create_persists_is_anonymous_from_filter(): void {
		$cat   = \Jetonomy\Models\Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = \Jetonomy\Models\Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$uid   = self::factory()->user->create();

		$cb = function ( $data ) {
			$data['is_anonymous'] = 1;
			return $data;
		};
		add_filter( 'jetonomy_before_create_post', $cb );
		$id = \Jetonomy\Models\Post::create( array( 'space_id' => $space, 'author_id' => $uid, 'title' => 'Secret', 'content' => 'x' ) );
		remove_filter( 'jetonomy_before_create_post', $cb );

		$row = \Jetonomy\Models\Post::find( $id );
		$this->assertEquals( 1, (int) $row->is_anonymous );
	}

	public function test_create_defaults_is_anonymous_to_zero(): void {
		$cat   = \Jetonomy\Models\Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$space = \Jetonomy\Models\Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$uid   = self::factory()->user->create();
		$id    = \Jetonomy\Models\Post::create( array( 'space_id' => $space, 'author_id' => $uid, 'title' => 'Open', 'content' => 'y' ) );
		$row   = \Jetonomy\Models\Post::find( $id );
		$this->assertEquals( 0, (int) $row->is_anonymous );
	}
}
