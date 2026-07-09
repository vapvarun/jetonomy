<?php
/**
 * Unit tests for the Anonymous Posting Pro extension's Gate.
 *
 * Verifies that Gate::can_author_anonymously() is the single source of truth
 * requiring BOTH the global master switch (jetonomy_pro_anonymous_enabled)
 * AND the per-space opt-in (settings.allow_anonymous) to be true, plus a
 * logged-in author — matching the sibling Pro extension test convention of
 * skipping automatically when Jetonomy Pro is not active.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy_Pro\Extensions\Anonymous_Posting\Gate;

class AnonymousGateTest extends WP_UnitTestCase {

	private int $space_id;
	private int $user_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active — anonymous gate tests skipped.' );
		}

		Schema::create_tables();
		$cat            = Category::create( array( 'name' => 'G', 'slug' => 'g-' . uniqid() ) );
		$this->space_id = Space::create( array( 'title' => 'S', 'slug' => 's-' . uniqid(), 'category_id' => $cat ) );
		$this->user_id  = self::factory()->user->create();
		delete_option( 'jetonomy_pro_anonymous_enabled' );
	}

	public function test_gate_requires_global_and_space_and_user(): void {
		// Global off, space off.
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Global on only.
		update_option( 'jetonomy_pro_anonymous_enabled', true );
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Global on + space on.
		Space::update( $this->space_id, array( 'settings' => wp_json_encode( array( 'allow_anonymous' => true ) ) ) );
		$this->assertTrue( Gate::can_author_anonymously( $this->space_id, $this->user_id ) );

		// Guest never allowed.
		$this->assertFalse( Gate::can_author_anonymously( $this->space_id, 0 ) );
	}
}
