<?php
namespace Jetonomy\Tests\Integration;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Post;
use Jetonomy\Models\Space;

/**
 * The topic breadcrumb links its category like the space crumb. The category
 * comes from Category::find_visible(), and a category hidden from the viewer
 * hides its topics too, so the link never points at a page they cannot open.
 *
 * single-post.php declares a function, so it can be included once per process:
 * each render runs in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class TopicBreadcrumbCategoryTest extends WP_UnitTestCase {

	private int $admin;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		\Jetonomy\Cache::flush();
		$this->admin  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function space( int $category_id ): object {
		$id = (int) Space::create(
			array(
				'title'       => 'Weekend Hikers',
				'slug'        => 'tbc-' . uniqid(),
				'category_id' => $category_id,
				'visibility'  => 'public',
			),
			$this->admin
		);
		return Space::find( $id );
	}

	public function test_topic_breadcrumb_links_its_category(): void {
		$category = Category::find(
			(int) Category::create(
				array(
					'name' => 'Outdoors',
					'slug' => 'rw5-cat-' . uniqid(),
				)
			)
		);
		$space    = $this->space( (int) $category->id );
		$post     = (int) Post::create(
			array(
				'space_id'  => (int) $space->id,
				'author_id' => $this->admin,
				'title'     => 'Trail conditions this weekend',
				'slug'      => 'trail-' . uniqid(),
				'content'   => '<p>Mud on the north loop.</p>',
				'status'    => 'publish',
			)
		);
		wp_set_current_user( $this->member );

		$data = array( 'slug' => Post::find( $post )->slug );
		ob_start();
		include JETONOMY_DIR . 'templates/views/single-post.php';
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<a href="' . esc_url( \Jetonomy\route_url( 'category', $category->slug ) ) . '">Outdoors</a>', $html );
	}
}
