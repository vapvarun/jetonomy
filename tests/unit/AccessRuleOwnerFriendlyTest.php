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
 * QA 10453398183: "Full" (removed in 2.0.7, it never gave more than Participate)
 * is saved as Participate,
 * the gate names a way in that can still be bought, and says "subscribe" for
 * a subscription instead of "join this subscription".
 */
class AccessRuleOwnerFriendlyTest extends WP_UnitTestCase {

	private int $space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
		$cat         = Category::create( array( 'name' => 'OF', 'slug' => 'of-' . uniqid() ) );
		$this->space = Space::create( array( 'title' => 'OF', 'slug' => 'of-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
	}

	public function test_full_is_saved_as_participate_on_every_rule_type(): void {
		$membership = AccessRule::create( array( 'space_id' => $this->space, 'rule_type' => 'membership', 'rule_value' => 'x_1', 'grants' => 'full' ) );
		$role       = AccessRule::create( array( 'space_id' => $this->space, 'rule_type' => 'role', 'rule_value' => 'editor', 'grants' => 'read' ) );

		$this->assertSame( 'participate', AccessRule::find( $membership )->grants );
		AccessRule::update_grants( $role, 'full' );
		$this->assertSame( 'participate', AccessRule::find( $role )->grants, 'Full on a role rule is Participate too.' );
	}

	public function test_a_legacy_full_row_resolves_as_participate(): void {
		global $wpdb;
		$id = AccessRule::create( array( 'space_id' => $this->space, 'rule_type' => 'logged_in', 'grants' => 'read' ) );
		$wpdb->update( $wpdb->prefix . 'jt_access_rules', array( 'grants' => 'full' ), array( 'id' => $id ) ); // Not yet migrated.
		AccessRule::reset_memo();
		$this->assertSame( 'participate', AccessRule::resolve_access( self::factory()->user->create(), $this->space )['grants'] );
	}

	public function test_upgrade_turns_every_full_rule_into_participate(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'jt_access_rules';
		$id    = AccessRule::create( array( 'space_id' => $this->space, 'rule_type' => 'role', 'rule_value' => 'editor', 'grants' => 'read' ) );
		$wpdb->update( $table, array( 'grants' => 'full' ), array( 'id' => $id ) ); // A row saved before 2.0.7.

		( new \Jetonomy\DB\Migrations\Migration_2_0_7() )->up();

		$this->assertSame( 'participate', $wpdb->get_var( $wpdb->prepare( "SELECT grants FROM {$table} WHERE id = %d", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public function test_gate_requirements_say_what_can_still_be_had_and_how(): void {
		Adapter_Registry::register_membership(
			'of_test',
			new class() implements Membership_Adapter {
				public function is_active(): bool {
					return true;
				}
				public function get_user_levels( int $user_id ): array {
					return array();
				}
				public function user_has_level( int $user_id, string $level_id ): bool {
					return false;
				}
				public function get_all_levels(): array {
					return array();
				}
				public function get_level_label( string $level_id ): string {
					return 'sub_live' === $level_id ? 'Gold' : 'Old course';
				}
				public function register_hooks(): void {}
				public function level_prefixes(): array {
					return array(
						'sub_'    => 'Subscription',
						'course_' => 'Course',
					);
				}
				public function get_level_url( string $level_id ): string {
					return 'sub_live' === $level_id ? 'https://example.com/gold' : '';
				}
				public function level_available( string $level_id ): bool {
					return 'course_gone' !== $level_id;
				}
				public function level_is_purchase( string $level_id ): bool {
					return str_starts_with( $level_id, 'sub_' );
				}
			}
		);
		foreach ( array( 'sub_live', 'course_gone' ) as $level ) {
			AccessRule::create( array( 'space_id' => $this->space, 'rule_type' => 'membership', 'rule_value' => $level, 'grants' => 'participate' ) );
		}

		$unmet = array_column( AccessRule::unmet_membership_requirements( self::factory()->user->create(), $this->space ), null, 'level_id' );

		$this->assertTrue( $unmet['sub_live']['purchase'], 'A subscription is bought, not joined.' );
		$this->assertTrue( $unmet['sub_live']['available'] );
		$this->assertFalse( $unmet['course_gone']['purchase'] );
		$this->assertFalse( $unmet['course_gone']['available'], 'A trashed course is flagged, not offered.' );
		$this->assertSame( 'Gold', $unmet['sub_live']['label'], 'A paid plan is named without its kind ("Gold (Subscription)" is noise).' );
		$this->assertSame( 'Old course (Course)', $unmet['course_gone']['label'], 'A course or group keeps its kind: it tells two ways in apart.' );
		$this->assertTrue( ( new \Jetonomy\Adapters\PMPro_Adapter() )->level_is_purchase( 'pmpro_3' ), 'A PMPro level is bought at checkout.' );
	}
}
