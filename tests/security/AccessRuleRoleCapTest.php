<?php
namespace Jetonomy\Tests\Security;

use WP_UnitTestCase;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Permissions\Permission_Engine;
use Jetonomy\DB\Schema;

/**
 * An access rule must never hand out more roster power than it advertises.
 *
 * A rule stores what someone may do (`grants`) and, separately, the role they
 * are recorded as when "Sync Members" materialises them (`space_role`). Those
 * used to be independent, and the combination was a privilege escalation
 * rather than a cosmetic mismatch: a rule labelled "Read - view posts and
 * replies, cannot take part" with space_role=admin gave every matched person
 * `delete_others_posts` and `moderate` as soon as an owner pressed Sync,
 * because Permission_Engine's space-moderator bypass reads the ROSTER role and
 * never consults the rule's grants.
 *
 * Two rules are pinned here:
 *
 *   1. the roster role can never exceed what the grants justify; and
 *   2. a `membership` rule never mints moderators at all - "anyone holding
 *      this plan" must not be able to buy moderation, which is an appointment
 *      made per person on the Members tab.
 */
class AccessRuleRoleCapTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_tables();
	}

	/**
	 * @dataProvider grants_ceiling_provider
	 */
	public function test_role_never_exceeds_what_grants_justify( string $grants, string $asked, string $expected ): void {
		$this->assertSame(
			$expected,
			AccessRule::cap_space_role( $grants, $asked, 'role' ),
			sprintf( 'grants=%s asking for %s must resolve to %s', $grants, $asked, $expected )
		);
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function grants_ceiling_provider(): array {
		return array(
			'read cannot mint an admin'          => array( 'read', 'admin', 'viewer' ),
			'read cannot mint a moderator'       => array( 'read', 'moderator', 'viewer' ),
			'participate cannot mint an admin'   => array( 'participate', 'admin', 'member' ),
			'full tops out at moderator'         => array( 'full', 'admin', 'moderator' ),
			'a lower role is left alone'         => array( 'full', 'viewer', 'viewer' ),
			'an aligned role is left alone'      => array( 'participate', 'member', 'member' ),
		);
	}

	/**
	 * @dataProvider membership_provider
	 */
	public function test_membership_rules_never_mint_moderators( string $grants, string $asked, string $expected ): void {
		$this->assertSame(
			$expected,
			AccessRule::cap_space_role( $grants, $asked, 'membership' ),
			'nobody should become a space moderator by buying a plan'
		);
	}

	/**
	 * @return array<string, array{0:string,1:string,2:string}>
	 */
	public function membership_provider(): array {
		return array(
			'paid + read'        => array( 'read', 'admin', 'viewer' ),
			'paid + participate' => array( 'participate', 'admin', 'member' ),
			'paid + full'        => array( 'full', 'admin', 'member' ),
			'paid asking mod'    => array( 'full', 'moderator', 'member' ),
		);
	}

	/**
	 * End-to-end: the escalation that prompted the cap, on a rule of the shape
	 * an install could already have stored before upgrading.
	 */
	public function test_read_rule_cannot_produce_a_moderator_after_sync(): void {
		$cat_id   = Category::create( array( 'name' => 'Cap Cat', 'slug' => 'cap-cat-' . uniqid() ) );
		$space_id = (int) Space::create( array(
			'title'       => 'Capped Space',
			'slug'        => 'capped-space-' . uniqid(),
			'category_id' => $cat_id,
			'visibility'  => 'private',
		) );
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		// The dangerous pair, as an owner could have saved it before 1.8.1.
		$grants     = 'read';
		$stored     = 'admin';
		$safe_role  = AccessRule::cap_space_role( $grants, $stored, 'membership' );

		SpaceMember::add( $space_id, $user_id, $safe_role );
		\Jetonomy\Cache::flush();

		$this->assertSame( 'viewer', $safe_role, 'a Read rule must sync people as viewers' );
		$this->assertFalse(
			Permission_Engine::can( $user_id, 'delete_others_posts', $space_id ),
			'a rule advertising Read must never confer deletion of other people\'s posts'
		);
		$this->assertFalse(
			Permission_Engine::can( $user_id, 'moderate', $space_id ),
			'a rule advertising Read must never confer moderation'
		);
	}
}
