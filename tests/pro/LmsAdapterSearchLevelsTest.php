<?php
/**
 * Tutor LMS and LifterLMS access-rule pickers search on the server.
 *
 * Before 2.0.7 both adapters returned every published course from
 * get_all_levels() (numberposts -1) and had no search_levels(), so a site with
 * 502 courses embedded all 502 into the Access Rules page and the picker could
 * not search past them. They now follow LearnDash/WooCommerce: a bounded,
 * title-searched search_levels( $query, $limit ), which also marks the adapter
 * "searchable" in the picker.
 *
 * Neither LMS runs in the test env, so is_active() is forced true and the
 * post types are registered locally; the adapters only query WP posts.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class LmsAdapterSearchLevelsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\Jetonomy_Pro\Adapters\LifterLMS_Adapter' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		register_post_type( 'course', array( 'public' => true ) );
		register_post_type( 'llms_membership', array( 'public' => true ) );
		register_post_type( 'courses', array( 'public' => true ) );
	}

	private function seed( string $post_type, int $n, string $title ): void {
		for ( $i = 1; $i <= $n; $i++ ) {
			self::factory()->post->create(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'post_title'  => "$title $i",
				)
			);
		}
	}

	public function test_lifterlms_search_is_bounded_and_filtered(): void {
		$adapter = new class() extends \Jetonomy_Pro\Adapters\LifterLMS_Adapter {
			public function is_active(): bool {
				return true;
			}
		};
		$this->assertTrue( method_exists( $adapter, 'search_levels' ), 'Picker needs search_levels() to search past the preload cap.' );

		$this->seed( 'course', 5, 'Needle course' );
		$this->seed( 'course', 3, 'Other course' );
		$this->seed( 'llms_membership', 2, 'Needle membership' );

		$rows = $adapter->search_levels( 'Needle', 3 );
		$ids  = wp_list_pluck( $rows, 'id' );

		$this->assertCount( 3, preg_grep( '/^llms_course_\d+$/', $ids ), 'Courses are capped at $limit.' );
		$this->assertCount( 2, preg_grep( '/^llms_membership_\d+$/', $ids ) );
		foreach ( wp_list_pluck( $rows, 'label' ) as $label ) {
			$this->assertStringContainsString( 'Needle', $label );
		}
	}

	public function test_tutor_search_is_bounded_and_filtered(): void {
		if ( ! function_exists( 'tutor' ) ) {
			// Minimal stand-in for Tutor's tutor() config accessor; TUTOR_VERSION stays undefined, so the real adapter stays inactive elsewhere.
			eval( 'function tutor() { return (object) array( "course_post_type" => "courses" ); }' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
		}
		$adapter = new class() extends \Jetonomy_Pro\Adapters\Tutor_Adapter {
			public function is_active(): bool {
				return true;
			}
		};
		$this->assertTrue( method_exists( $adapter, 'search_levels' ), 'Picker needs search_levels() to search past the preload cap.' );

		$this->seed( 'courses', 4, 'Needle course' );
		$this->seed( 'courses', 2, 'Other course' );

		$rows = $adapter->search_levels( 'Needle', 2 );
		$this->assertCount( 2, $rows );
		$this->assertMatchesRegularExpression( '/^tutor_course_\d+$/', $rows[0]['id'] );
		$this->assertStringContainsString( 'Needle', $rows[0]['label'] );
	}
}
