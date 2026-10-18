<?php
/**
 * Unlinking a tag from a space deletes its access rule with a raw query, so it
 * must queue the same roster sweep AccessRule::delete() queues: the synced rows
 * of that rule are trimmed in the background, not at the next hourly reconcile.
 *
 * @package Jetonomy\Tests\Pro
 */

namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use Jetonomy\DB\Schema;
use Jetonomy\Membership_Roster_Sync;
use Jetonomy\Models\AccessRule;
use Jetonomy\Models\Space;
use Jetonomy\Queue;

class TagRuleRemovalSweepTest extends WP_UnitTestCase {

	public function test_unlinking_a_tag_queues_the_roster_sweep(): void {
		if ( ! class_exists( '\Jetonomy_Pro\Adapters\WPFusion_Space_Integration' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro tag integration not present.' );
		}
		Schema::create_tables();

		$space = (int) Space::create(
			array(
				'title'      => 'Chapter leaders',
				'slug'       => 'chapter-leaders-' . uniqid(),
				'visibility' => 'private',
			)
		);
		AccessRule::create(
			array(
				'space_id'   => $space,
				'rule_type'  => 'membership',
				'rule_value' => 'wpfusion_102',
				'grants'     => 'full',
				'space_role' => 'member',
			)
		);

		$integration = new \Jetonomy_Pro\Adapters\WPFusion_Space_Integration( new \Jetonomy_Pro\Adapters\WPFusion_Adapter() );
		( new \ReflectionMethod( $integration, 'sync_access_rules' ) )->invoke( $integration, $space, array() );

		$this->assertSame( array(), AccessRule::spaces_for_level( 'wpfusion_102' ), 'the tag rule is gone' );
		$this->assertTrue( Queue::is_queued( Membership_Roster_Sync::SWEEP_HOOK, array( $space ) ) );
	}
}
