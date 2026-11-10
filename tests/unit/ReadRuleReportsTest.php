<?php
namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\DB\Schema;
use Jetonomy\Permissions\Permission_Engine;

/**
 * A Read rule promises "read and report": a member admitted by the rule alone
 * (no roster row) may flag content but not post, reply or vote.
 */
class ReadRuleReportsTest extends WP_UnitTestCase {

	public function test_read_rule_admits_reading_and_reporting_only(): void {
		Schema::create_tables();
		$cat   = Category::create( array( 'name' => 'RR', 'slug' => 'rr-' . uniqid() ) );
		$space = (int) Space::create( array( 'title' => 'RR', 'slug' => 'rr-' . uniqid(), 'category_id' => $cat, 'visibility' => 'private' ) );
		AccessRule::create( array( 'space_id' => $space, 'rule_type' => 'role', 'rule_value' => 'subscriber', 'grants' => 'read' ) );
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertTrue( Permission_Engine::can( $user, 'read', $space ) );
		$this->assertTrue( Permission_Engine::can( $user, 'flag', $space ), 'a Read rule lets the member report' );
		$this->assertFalse( Permission_Engine::can( $user, 'create_posts', $space ) );
		$this->assertFalse( Permission_Engine::can( $user, 'create_replies', $space ) );
		$this->assertFalse( Permission_Engine::can( $user, 'vote', $space ) );
	}
}
