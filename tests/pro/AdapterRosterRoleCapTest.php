<?php
/**
 * Regression guard for the membership adapters' roster write.
 *
 * WHY THIS EXISTS, and why it is not a duplicate of
 * tests/security/AccessRuleRoleCapTest.php:
 *
 * That test proves AccessRule::cap_space_role() returns the right answer, and
 * its "after sync" case CALLS the cap itself and then asserts on its own local
 * variable. So it demonstrates correct usage rather than exercising any caller.
 * It stayed green while all nine membership adapters wrote the rule's raw
 * space_role straight onto the roster, and a rule carrying space_role=admin
 * handed moderation to everyone who enrolled (Basecamp 10169081143).
 *
 * A guard with exactly one caller can be bypassed silently. These tests drive
 * the CALL SITE instead: one at runtime through an adapter's real hook handler,
 * one statically across every adapter so a newly added tenth cannot repeat it.
 *
 * The runtime case needs no LMS installed. apply_level() reads Jetonomy's own
 * jt_access_rules and writes Jetonomy's own roster; the third-party plugin only
 * decides WHEN the hook fires, never what it does.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Permission_Engine;

class AdapterRosterRoleCapTest extends WP_UnitTestCase {

	private int $space_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active - adapter roster tests skipped.' );
		}

		Schema::create_tables();

		$cat            = Category::create( array( 'name' => 'A', 'slug' => 'a-' . uniqid() ) );
		$this->space_id = Space::create(
			array(
				'title'       => 'Gated',
				'slug'        => 'gated-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'private',
			)
		);
		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * The exact escalation, driven through an adapter's real hook handler.
	 *
	 * A "Read" rule that stores space_role=admin must not make an enrolling
	 * learner a space admin. Before the fix this produced roster role 'admin'
	 * and Permission_Engine granted moderate / edit_others_posts, because
	 * Layer 0d reads the ROSTER role and returns before the capability check.
	 */
	public function test_enrolment_does_not_grant_the_rules_raw_space_role(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Adapters\Learnomy_Adapter' ) ) {
			$this->markTestSkipped( 'Learnomy adapter class not present.' );
		}

		$course_id = 4242;

		AccessRule::create(
			array(
				'space_id'   => $this->space_id,
				'rule_type'  => 'membership',
				'rule_value' => 'lrn_course_' . $course_id,
				'grants'     => 'read',
				'space_role' => 'admin',
			)
		);

		$adapter = new \Jetonomy_Pro\Adapters\Learnomy_Adapter();
		// The real handler the LMS calls. No LMS needs to be installed: this
		// path reads jt_access_rules and writes the Jetonomy roster only.
		$adapter->on_course_enrolled( 1, $this->user_id, $course_id );

		$this->assertSame(
			'viewer',
			SpaceMember::get_role( $this->space_id, $this->user_id ),
			'a Read rule must record an enrolling member as a viewer, whatever space_role it stores'
		);

		foreach ( array( 'moderate', 'edit_others_posts', 'delete_others_posts', 'pin_posts' ) as $action ) {
			$this->assertFalse(
				Permission_Engine::can( $this->user_id, $action, $this->space_id ),
				"enrolment must never grant {$action}"
			);
		}
	}

	/**
	 * The 90% case must keep working: a Participate rule makes a member who
	 * can post and reply, and still cannot moderate.
	 */
	public function test_participate_rule_makes_a_member_who_can_post_and_reply(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Adapters\Learnomy_Adapter' ) ) {
			$this->markTestSkipped( 'Learnomy adapter class not present.' );
		}

		$course_id = 4243;

		AccessRule::create(
			array(
				'space_id'   => $this->space_id,
				'rule_type'  => 'membership',
				'rule_value' => 'lrn_course_' . $course_id,
				'grants'     => 'participate',
				'space_role' => 'admin',
			)
		);

		$adapter = new \Jetonomy_Pro\Adapters\Learnomy_Adapter();
		$adapter->on_course_enrolled( 1, $this->user_id, $course_id );

		$this->assertSame( 'member', SpaceMember::get_role( $this->space_id, $this->user_id ) );
		$this->assertTrue( Permission_Engine::can( $this->user_id, 'create_posts', $this->space_id ) );
		$this->assertTrue( Permission_Engine::can( $this->user_id, 'create_replies', $this->space_id ) );
		$this->assertFalse( Permission_Engine::can( $this->user_id, 'moderate', $this->space_id ) );
	}

	/**
	 * Static sweep, so adapter number ten cannot reintroduce this.
	 *
	 * Every roster write in an adapter must run the rule's space_role through
	 * AccessRule::cap_space_role(). Passing $rule->space_role straight into
	 * SpaceMember::add() is the defect, and it was copy-pasted nine times
	 * precisely because nothing checked for it.
	 */
	public function test_no_adapter_writes_a_rules_raw_space_role(): void {
		$dir = defined( 'JETONOMY_PRO_DIR' ) ? JETONOMY_PRO_DIR . 'includes/adapters' : '';
		if ( '' === $dir || ! is_dir( $dir ) ) {
			$this->markTestSkipped( 'Pro adapters directory not found.' );
		}

		$offenders = array();
		foreach ( (array) glob( $dir . '/class-*-adapter.php' ) as $file ) {
			$src = (string) file_get_contents( $file );

			// Look at each roster write on its own. Testing the whole file for
			// "space_role near cap_space_role" would pass a file that has one
			// capped call and one raw one, which is exactly how this defect
			// would come back.
			if ( ! preg_match_all( '/SpaceMember::add\(.*?\);/s', $src, $calls ) ) {
				continue;
			}

			foreach ( $calls[0] as $call ) {
				if ( false === strpos( $call, '$rule->space_role' ) ) {
					continue; // Writes a literal role; nothing to cap.
				}
				if ( false === strpos( $call, 'cap_space_role' ) ) {
					$offenders[] = basename( $file );
					break;
				}
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"these adapters pass the rule's raw space_role to SpaceMember::add(); "
				. 'wrap it in AccessRule::cap_space_role( $rule->grants, $rule->space_role, \'membership\' )'
		);
	}
}
