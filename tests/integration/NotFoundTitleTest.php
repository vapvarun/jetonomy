<?php
/**
 * A topic the viewer may not read and a topic that does not exist get the same
 * page title. Before 2.0.3 the missing one was titled from its URL slug and the
 * hidden one "Community", so the <title> told a guest which topics exist.
 *
 * @package Jetonomy\Tests\Integration
 */

namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;
use Jetonomy\Template_Loader;

class NotFoundTitleTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		wp_set_current_user( 0 );
	}

	/** Run the pre-flight 404 check and the title filter the way a page load does. */
	private function title_for( string $slug ): string {
		$flag = new \ReflectionProperty( Template_Loader::class, 'not_found' );
		$flag->setValue( null, false );
		$data = array(
			'route' => 'post',
			'slug'  => $slug,
		);
		foreach ( array( 'maybe_set_404', 'set_seo_meta' ) as $method ) {
			( new \ReflectionMethod( Template_Loader::class, $method ) )->invoke( null, $data );
		}
		$parts = apply_filters( 'document_title_parts', array( 'title' => '' ) );
		remove_all_filters( 'document_title_parts' );
		return (string) $parts['title'];
	}

	public function test_a_hidden_topic_and_a_missing_topic_share_one_title(): void {
		$space = Space::create(
			array(
				'title'       => 'Staff room',
				'slug'        => 'staff-room-' . uniqid(),
				'category_id' => Category::create( array( 'name' => 'Staff', 'slug' => 'staff-' . uniqid() ) ),
				'visibility'  => 'hidden',
			)
		);
		Post::create(
			array(
				'space_id'  => $space,
				'author_id' => self::factory()->user->create(),
				'title'     => 'Secret plans',
				'slug'      => 'secret-plans',
				'content'   => 'Body',
				'status'    => 'publish',
			)
		);

		$hidden  = $this->title_for( 'secret-plans' );
		$missing = $this->title_for( 'no-such-topic' );

		$this->assertSame( $missing, $hidden );
		$this->assertStringNotContainsStringIgnoringCase( 'no such topic', $missing, 'a missing page is never titled from its URL' );
	}
}
