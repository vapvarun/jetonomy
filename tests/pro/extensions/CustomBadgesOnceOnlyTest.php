<?php
/**
 * A non-repeatable badge is held at most once per member.
 *
 * Covers the write guard in award_badge(), the one-off repair of rows that
 * an outside writer (the pre-2.0.2 demo importer) duplicated, and the
 * "Earned by" count, which counts members rather than award rows.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;
use WP_REST_Request;
use Jetonomy_Pro\Extensions\Custom_Badges\Extension;

class CustomBadgesOnceOnlyTest extends WP_UnitTestCase {

	private Extension $ext;

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( Extension::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		$this->ext = new Extension();
		$this->ext->activate();
	}

	private function make_badge( int $repeatable ): object {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'jt_pro_badges',
			array(
				'name'          => 'Once ' . uniqid(),
				'slug'          => 'once-' . uniqid(),
				'tier'          => 'bronze',
				'is_repeatable' => $repeatable,
				'is_active'     => 1,
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}jt_pro_badges WHERE id = %d", $wpdb->insert_id ) );
	}

	private function call( string $method, ...$args ) {
		$ref = new \ReflectionMethod( $this->ext, $method );
		return $ref->invoke( $this->ext, ...$args );
	}

	private function rows( int $user_id, int $badge_id ): array {
		global $wpdb;
		return array_map(
			'intval',
			$wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}jt_pro_user_badges WHERE user_id = %d AND badge_id = %d ORDER BY id", $user_id, $badge_id ) )
		);
	}

	/**
	 * Sequential double award was already refused; the guarded insert adds race safety.
	 *
	 * @covers-existing
	 */
	public function test_award_badge_never_writes_a_second_row_for_a_once_only_badge(): void {
		$user  = self::factory()->user->create();
		$badge = $this->make_badge( 0 );

		$this->call( 'award_badge', $user, $badge );
		$this->call( 'award_badge', $user, $badge );
		$this->call( 'award_badge', $user, $badge, array( 'awarded_by' => 1 ) );

		$this->assertCount( 1, $this->rows( $user, (int) $badge->id ) );
	}

	/**
	 * @covers-existing
	 */
	public function test_repeatable_badge_still_awards_once_per_event(): void {
		$user  = self::factory()->user->create();
		$badge = $this->make_badge( 1 );

		$this->call( 'award_badge', $user, $badge, array( 'event_key' => 'a' ) );
		$this->call( 'award_badge', $user, $badge, array( 'event_key' => 'b' ) );

		$this->assertCount( 2, $this->rows( $user, (int) $badge->id ) );
	}

	public function test_repair_keeps_the_earliest_row_and_earned_by_counts_members(): void {
		global $wpdb;
		$user       = self::factory()->user->create();
		$badge      = $this->make_badge( 0 );
		$repeatable = $this->make_badge( 1 );
		$table      = $wpdb->prefix . 'jt_pro_user_badges';

		// The old demo importer wrote straight to the table, bypassing award_badge().
		foreach ( array( $badge, $badge, $badge, $repeatable, $repeatable ) as $b ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'   => $user,
					'badge_id'  => (int) $b->id,
					'earned_at' => current_time( 'mysql', true ),
				)
			);
		}
		$first = $this->rows( $user, (int) $badge->id )[0];

		$request = new WP_REST_Request( 'GET', '/jetonomy/v1/badges/' . $badge->id );
		$request->set_param( 'id', (int) $badge->id );
		$this->assertSame( 1, $this->ext->get_badge( $request )->get_data()['data']['earned_count'], 'earned by counts members, not rows' );

		delete_option( 'jetonomy_pro_badges_once_only_deduped' );
		$this->call( 'maybe_dedupe_once_only_awards' );

		$this->assertSame( array( $first ), $this->rows( $user, (int) $badge->id ) );
		$this->assertCount( 2, $this->rows( $user, (int) $repeatable->id ), 'repeatable awards are left alone' );
	}
}
