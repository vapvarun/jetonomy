<?php
/**
 * What the Access Rules screen promises a site owner, asserted.
 *
 * Every test here quotes a sentence the product actually shows, and checks the
 * code keeps it. The expectations are NOT derived from Permission_Engine - if
 * they were, they would encode current behaviour including its bugs, and would
 * happily have asserted that enrolling in a course grants moderation, because
 * that is what the code did until 1.9.1 (Basecamp 10169081143).
 *
 * The source of truth is includes/admin/views/space-edit.php - the "How access
 * rules work" panel - plus AccessRule::cap_space_role()'s docblock. When a test
 * here fails, exactly one of two things is true: the code broke a promise, or
 * the promise is wrong and the copy needs changing. Both are worth knowing, and
 * neither is visible to a test written from the implementation.
 *
 * @package Jetonomy\Tests\Functional
 */

namespace Jetonomy\Tests\Functional;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Permission_Engine;

class AccessRulePromisesTest extends WP_UnitTestCase {

	private const PARTICIPATE_ACTIONS = array( 'read', 'create_posts', 'create_replies', 'vote', 'flag' );
	private const MODERATION_ACTIONS  = array( 'moderate', 'edit_others_posts', 'delete_others_posts', 'pin_posts' );

	private int $public_space;
	private int $private_space;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat = Category::create( array( 'name' => 'P', 'slug' => 'p-' . uniqid() ) );

		$this->public_space = Space::create(
			array(
				'title'       => 'Open',
				'slug'        => 'open-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'public',
				'join_policy' => 'open',
			)
		);
		$this->private_space = Space::create(
			array(
				'title'       => 'Gated',
				'slug'        => 'gated-' . uniqid(),
				'category_id' => $cat,
				'visibility'  => 'private',
				'join_policy' => 'invite',
			)
		);
	}

	private function rule( int $space, string $grants, string $space_role, string $type = 'role', string $value = 'subscriber' ): int {
		return (int) AccessRule::create(
			array(
				'space_id'   => $space,
				'rule_type'  => $type,
				'rule_value' => $value,
				'grants'     => $grants,
				'space_role' => $space_role,
			)
		);
	}

	private function subscriber(): int {
		return self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	/**
	 * PROMISE: "A rule lets people in to this space. It never locks anyone out
	 * on its own - visibility and join policy do that."
	 *
	 * The strongest invariant on the screen: adding a rule may only ever widen
	 * what someone can do. If any action flips from allowed to denied because a
	 * rule was added, the sentence is false.
	 */
	public function test_a_rule_never_takes_away_something_that_worked_before(): void {
		$user    = $this->subscriber();
		$actions = array_merge( self::PARTICIPATE_ACTIONS, self::MODERATION_ACTIONS );

		$before = array();
		foreach ( $actions as $a ) {
			$before[ $a ] = Permission_Engine::can( $user, $a, $this->public_space );
		}

		$this->rule( $this->public_space, 'read', 'viewer' );
		\Jetonomy\Cache::flush();

		foreach ( $actions as $a ) {
			if ( $before[ $a ] ) {
				$this->assertTrue(
					Permission_Engine::can( $user, $a, $this->public_space ),
					"adding a rule removed '{$a}', but a rule never locks anyone out on its own"
				);
			}
		}
	}

	/**
	 * PROMISE: "Read - Lets people in to read. It does not hold anyone back -
	 * on a public space that anyone may join, a signed-in member can still
	 * post, because the rule admits and only visibility and join policy
	 * restrict."
	 */
	public function test_a_read_rule_does_not_stop_posting_on_a_public_open_space(): void {
		$user = $this->subscriber();
		$this->rule( $this->public_space, 'read', 'viewer' );
		\Jetonomy\Cache::flush();

		$this->assertTrue( Permission_Engine::can( $user, 'create_posts', $this->public_space ) );
	}

	/**
	 * PROMISE: the same Read row, read the other way - on a gated space a Read
	 * rule admits, and admits only to reading.
	 */
	public function test_a_read_rule_on_a_gated_space_admits_to_reading_only(): void {
		$user = $this->subscriber();
		$this->rule( $this->private_space, 'read', 'viewer' );
		\Jetonomy\Cache::flush();

		$this->assertTrue( Permission_Engine::can( $user, 'read', $this->private_space ) );
		foreach ( array( 'create_posts', 'create_replies', 'vote' ) as $a ) {
			$this->assertFalse(
				Permission_Engine::can( $user, $a, $this->private_space ),
				"a Read rule must not grant '{$a}' on a gated space"
			);
		}
	}

	/**
	 * PROMISE: "Participate - Read, plus post, reply, vote and report."
	 */
	public function test_participate_grants_exactly_the_five_it_names(): void {
		$user = $this->subscriber();
		$this->rule( $this->private_space, 'participate', 'member' );
		\Jetonomy\Cache::flush();

		foreach ( self::PARTICIPATE_ACTIONS as $a ) {
			$this->assertTrue(
				Permission_Engine::can( $user, $a, $this->private_space ),
				"Participate promises '{$a}'"
			);
		}
	}

	/**
	 * PROMISE: "Full - ... but only for people whose WordPress role already
	 * allows moderation. For an ordinary member this behaves exactly like
	 * Participate."
	 */
	public function test_full_behaves_exactly_like_participate_for_an_ordinary_member(): void {
		$user = $this->subscriber();
		$this->rule( $this->private_space, 'full', 'moderator' );
		\Jetonomy\Cache::flush();

		foreach ( self::PARTICIPATE_ACTIONS as $a ) {
			$this->assertTrue( Permission_Engine::can( $user, $a, $this->private_space ) );
		}
		foreach ( self::MODERATION_ACTIONS as $a ) {
			$this->assertFalse(
				Permission_Engine::can( $user, $a, $this->private_space ),
				"Full must behave exactly like Participate for an ordinary member, but granted '{$a}'"
			);
		}
	}

	/**
	 * PROMISE: "A rule can never hand out moderation. Editing, closing or
	 * pinning other people's topics comes from the person's WordPress role."
	 *
	 * Swept across every grant level, because the promise is unconditional.
	 */
	public function test_no_grant_level_hands_out_moderation_to_a_role_without_it(): void {
		foreach ( array( 'read', 'participate', 'full' ) as $grants ) {
			$user = $this->subscriber();
			$this->rule( $this->private_space, $grants, 'admin' );
			\Jetonomy\Cache::flush();

			foreach ( self::MODERATION_ACTIONS as $a ) {
				$this->assertFalse(
					Permission_Engine::can( $user, $a, $this->private_space ),
					"grants={$grants} handed out '{$a}'; a rule can never hand out moderation"
				);
			}
		}
	}

	/**
	 * PROMISE: "To give one person a different role, change it on the Members
	 * tab; that keeps it a visible, per-person decision rather than a side
	 * effect of a rule."
	 *
	 * This is the escalation, written on the admin screen months before it
	 * happened. A membership rule that stores an elevated space_role must not
	 * put that role on the roster when someone's level activates.
	 */
	public function test_no_rule_writes_moderator_or_admin_to_the_roster(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Adapters\Learnomy_Adapter' ) ) {
			$this->markTestSkipped( 'Pro adapters not present.' );
		}

		$course = 8811;
		$user   = $this->subscriber();
		$this->rule( $this->private_space, 'read', 'admin', 'membership', 'lrn_course_' . $course );

		( new \Jetonomy_Pro\Adapters\Learnomy_Adapter() )->on_course_enrolled( 1, $user, $course );
		\Jetonomy\Cache::flush();

		$this->assertNotContains(
			SpaceMember::get_role( $this->private_space, $user ),
			array( 'moderator', 'admin' ),
			'a rule put an elevated role on the roster; roles are a per-person decision on the Members tab'
		);
	}

	/**
	 * PROMISE: "'Sync Members' is only needed if you also want these people
	 * listed on the roster."
	 *
	 * So access must work without any roster row at all.
	 */
	public function test_a_rule_admits_without_putting_anyone_on_the_roster(): void {
		$user = $this->subscriber();
		$this->rule( $this->private_space, 'participate', 'member' );
		\Jetonomy\Cache::flush();

		$this->assertNull(
			SpaceMember::get_role( $this->private_space, $user ),
			'admission must not require a roster row'
		);
		$this->assertTrue( Permission_Engine::can( $user, 'create_posts', $this->private_space ) );
	}

	/**
	 * PROMISE: "Access begins the moment a plan becomes active and ends when it
	 * lapses - there is nothing to sync and nothing to undo by hand."
	 *
	 * Removing the rule is the closest free-only analogue of a lapse: access
	 * must close on the next check, with no admin action.
	 */
	public function test_access_ends_by_itself_when_the_rule_no_longer_matches(): void {
		$user = $this->subscriber();
		$rule = $this->rule( $this->private_space, 'participate', 'member' );
		\Jetonomy\Cache::flush();
		$this->assertTrue( Permission_Engine::can( $user, 'read', $this->private_space ) );

		AccessRule::delete( $rule );
		\Jetonomy\Cache::flush();

		$this->assertFalse(
			Permission_Engine::can( $user, 'read', $this->private_space ),
			'access must end by itself, with nothing to undo by hand'
		);
	}
}
