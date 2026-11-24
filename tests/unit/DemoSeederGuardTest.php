<?php
/**
 * The seeder itself refuses a community with spaces or topics of its own (REST, the setup wizard and WP-CLI all go
 * through it), and counts real rows by id, so demo rows deleted by hand cannot hide real ones.
 *
 * @package Jetonomy\Tests\Unit
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Admin\Ajax\Demo_Seeder;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

class DemoSeederGuardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	public function test_the_seeder_refuses_real_content_and_counts_it_by_id(): void {
		$suffix = uniqid( 'dsg_', true );
		$space  = (int) Space::create(
			array(
				'category_id' => (int) Category::create( array( 'name' => 'Real', 'slug' => 'c-' . $suffix ) ),
				'title'       => 'Real space',
				'slug'        => 's-' . $suffix,
				'visibility'  => 'public',
			),
			0
		);
		$before = Demo_Seeder::real_content_count();
		$this->assertGreaterThan( 0, $before );

		// A demo set whose rows were deleted by hand: the same number of ids, none of them real rows.
		$fake = range( 900000001, 900000000 + Space::count() );
		update_option( 'jetonomy_demo_data', array( 'spaces' => $fake ), false );
		$this->assertSame( $before, Demo_Seeder::real_content_count(), 'ids that are not rows hide nothing' );

		$refused = Demo_Seeder::import( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertWPError( $refused );
		$this->assertSame( 'jetonomy_demo_real_content', $refused->get_error_code() );
		$this->assertSame( array( 'spaces' => $fake ), get_option( 'jetonomy_demo_data' ), 'nothing seeded or removed' );
		$this->assertNotNull( Space::find( $space ) );
		delete_option( 'jetonomy_demo_data' );
	}
}
