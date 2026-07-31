<?php
namespace Jetonomy\Tests\Unit\Permissions;

use WP_UnitTestCase;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\DB\Schema;

/**
 * An access rule must ADMIT a user to a gated space, not merely upgrade
 * someone already on the roster.
 *
 * Until 1.8.1 the private/hidden branch of Permission_Engine demanded roster
 * membership BEFORE access rules were evaluated, so a rule could never let
 * anyone in. A site owner who gated a space on a paid membership tier got a
 * space their subscribers could not enter; the only way in was a manual
 * per-rule "Sync Members" button that wrote roster rows nothing ever removed
 * when the subscription lapsed, so cancelled members kept access forever.
 *
 * These tests pin both directions: the rule admits while it matches, and
 * access disappears the moment it stops matching - with no roster row, which
 * is what makes revocation automatic.
 *
 * A capability rule stands in for the membership adapters here so the suite
 * has no third-party dependency; membership, role, capability and trust_level
 * all funnel through the same resolve_access() path.
 */
class AccessRuleAdmitsTest extends WP_UnitTestCase {

	private int $private_space_id;
	private int $hidden_space_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();

		$cat_id = Category::create( array( 'name' => 'Gate Cat', 'slug' => 'gate-cat-' . uniqid() ) );

		$this->private_space_id = Space::create( array(
			'title'       => 'Gated Private',
			'slug'        => 'gated-private-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'private',
		) );

		$this->hidden_space_id = Space::create( array(
			'title'       => 'Gated Hidden',
			'slug'        => 'gated-hidden-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'hidden',
		) );

		$this->user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
	}

	private function add_rule( int $space_id, string $cap, string $grants = 'participate' ): int {
		return (int) AccessRule::create( array(
			'space_id'   => $space_id,
			'rule_type'  => 'capability',
			'rule_value' => $cap,
			'grants'     => $grants,
			'space_role' => 'member',
			'priority'   => 0,
		) );
	}

	public function test_no_rule_means_no_access_to_private_space(): void {
		$this->assertFalse(
			Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ),
			'a non-member must not read a private space with no rule'
		);
	}

	public function test_rule_that_does_not_match_grants_nothing(): void {
		$this->add_rule( $this->private_space_id, 'jetonomy_nonexistent_cap' );

		$this->assertFalse(
			Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ),
			'a rule the user does not satisfy must not leak access'
		);
	}

	public function test_matching_rule_admits_a_non_member(): void {
		$cap = 'jetonomy_test_gate_cap';
		get_user_by( 'id', $this->user_id )->add_cap( $cap );
		$this->add_rule( $this->private_space_id, $cap );

		$this->assertTrue(
			Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ),
			'a matching rule must admit a non-member (the 1.8.1 regression)'
		);
		$this->assertTrue(
			Permission_Engine::can( $this->user_id, 'create_posts', $this->private_space_id ),
			'grants=participate must carry posting rights'
		);
		$this->assertFalse(
			SpaceMember::is_member( $this->private_space_id, $this->user_id ),
			'access must NOT depend on a roster row - that is what makes revocation automatic'
		);
	}

	public function test_access_is_revoked_when_the_rule_stops_matching(): void {
		$cap = 'jetonomy_test_gate_cap';
		$user = get_user_by( 'id', $this->user_id );
		$user->add_cap( $cap );
		$this->add_rule( $this->private_space_id, $cap );

		$this->assertTrue( Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ) );

		// Losing the capability stands in for a lapsed subscription.
		$user->remove_cap( $cap );
		\Jetonomy\Cache::flush();
		wp_cache_flush();

		$this->assertFalse(
			Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ),
			'access must fall away as soon as the rule stops matching, with no sync step'
		);
	}

	public function test_read_only_rule_does_not_grant_posting(): void {
		$cap = 'jetonomy_test_gate_cap';
		get_user_by( 'id', $this->user_id )->add_cap( $cap );
		$this->add_rule( $this->private_space_id, $cap, 'read' );

		$this->assertTrue( Permission_Engine::can( $this->user_id, 'read', $this->private_space_id ) );
		$this->assertFalse(
			Permission_Engine::can( $this->user_id, 'create_posts', $this->private_space_id ),
			'grants=read must not carry posting rights'
		);
	}

	public function test_hidden_space_is_not_concealed_from_an_admitted_user(): void {
		$cap = 'jetonomy_test_gate_cap';
		$user = get_user_by( 'id', $this->user_id );
		$space = Space::find( $this->hidden_space_id );

		$this->assertTrue(
			Space::concealed_from_viewer( $space, $this->user_id ),
			'precondition: a hidden space 404s for an outsider'
		);

		$user->add_cap( $cap );
		$this->add_rule( $this->hidden_space_id, $cap );
		\Jetonomy\Cache::flush();
		wp_cache_flush();

		$this->assertFalse(
			Space::concealed_from_viewer( Space::find( $this->hidden_space_id ), $this->user_id ),
			'a user the rule admits must not be 404d out of the space the rule exists to open'
		);
	}
}
