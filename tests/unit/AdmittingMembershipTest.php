<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Adapters\Adapter_Registry;
use Jetonomy\Adapters\Membership_Adapter;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;

/**
 * The space header's "You have access through X" note names the membership
 * level that admits the viewer, and nothing for any other way in.
 */
class AdmittingMembershipTest extends WP_UnitTestCase {

	public function test_names_the_level_that_admits_and_nothing_otherwise(): void {
		Schema::create_tables();
		$holder = self::factory()->user->create();
		$other  = self::factory()->user->create();

		Adapter_Registry::register_membership(
			'am_test',
			new class( $holder ) implements Membership_Adapter {
				public function __construct( private int $holder ) {}
				public function is_active(): bool {
					return true;
				}
				public function get_user_levels( int $user_id ): array {
					return $user_id === $this->holder ? array( 'amtest_1' ) : array();
				}
				public function user_has_level( int $user_id, string $level_id ): bool {
					return $user_id === $this->holder && 'amtest_1' === $level_id;
				}
				public function get_all_levels(): array {
					return array( array( 'id' => 'amtest_1', 'label' => 'Writers Plan' ) );
				}
				public function get_level_label( string $level_id ): string {
					return 'Writers Plan';
				}
				public function register_hooks(): void {}
			}
		);

		$cat   = Category::create( array( 'name' => 'AM', 'slug' => 'am-' . uniqid() ) );
		$space = Space::create( array( 'title' => 'AM', 'slug' => 'am-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		AccessRule::create( array( 'space_id' => $space, 'rule_type' => 'membership', 'rule_value' => 'amtest_1', 'grants' => 'participate' ) );

		$this->assertSame( 'Writers Plan', AccessRule::admitting_membership( $holder, $space )['name'] );
		$this->assertNull( AccessRule::admitting_membership( $other, $space ) );
		$this->assertNull( AccessRule::admitting_membership( 0, $space ) );

		$open = Space::create( array( 'title' => 'AM2', 'slug' => 'am2-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		AccessRule::create( array( 'space_id' => $open, 'rule_type' => 'logged_in', 'grants' => 'read' ) );
		$this->assertNull( AccessRule::admitting_membership( $holder, $open ), 'A non-membership rule names no level.' );
	}
}
