<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Custom_Badges_Journey;

/**
 * Integration tests for Custom_Badges_Journey against the live Pro
 * `wp_jt_pro_badges` and `wp_jt_pro_user_badges` tables.
 *
 * The Pro plugin must be active during the test run so the tables exist.
 * Each test creates its own fixtures and tear_down() removes any rows the
 * suite may have inserted so runs are independent.
 */
class CustomBadgesJourneyTest extends WP_UnitTestCase {

	private Custom_Badges_Journey $journey;

	private int $user_a;
	private int $user_b;
	private int $admin;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Custom_Badges_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Custom_Badges_Journey.' );
		}

		$this->journey = new Custom_Badges_Journey();

		$this->admin  = (int) self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->user_a = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->user_b = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->truncate_tables();
	}

	public function tear_down(): void {
		$this->truncate_tables();
		parent::tear_down();
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_user_badges" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_badges" );
	}

	private function make_badge( string $name = 'Test Badge', string $tier = 'bronze' ): int {
		$result = $this->journey->create_badge(
			array(
				'name'        => $name,
				'description' => 'A badge created during tests.',
				'icon'        => '🏆',
				'tier'        => $tier,
				'category'    => 'participation',
			)
		);
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		return (int) $result->data['badge_id'];
	}

	public function test_create_badge_stores_definition(): void {
		global $wpdb;

		$result = $this->journey->create_badge(
			array(
				'name'             => 'First Steps',
				'description'      => 'Created your first post.',
				'icon'             => '✍️',
				'tier'             => 'bronze',
				'category'         => 'participation',
				'reputation_bonus' => 5,
			)
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertGreaterThan( 0, (int) $result->data['badge_id'] );
		$this->assertSame( 'First Steps', $result->data['name'] );
		$this->assertSame( 'bronze', $result->data['tier'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, description, icon, tier, category, reputation_bonus FROM {$wpdb->prefix}jt_pro_badges WHERE id = %d",
				(int) $result->data['badge_id']
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'First Steps', $row->name );
		$this->assertSame( 'bronze', $row->tier );
		$this->assertSame( 'participation', $row->category );
		$this->assertSame( 5, (int) $row->reputation_bonus );
	}

	public function test_create_badge_requires_name_description_icon(): void {
		$missing_name = $this->journey->create_badge(
			array(
				'description' => 'desc',
				'icon'        => 'x',
			)
		);
		$this->assertFalse( $missing_name->is_success() );
		$this->assertStringContainsString( 'name', strtolower( (string) $missing_name->first_error() ) );

		$missing_desc = $this->journey->create_badge(
			array(
				'name' => 'n',
				'icon' => 'x',
			)
		);
		$this->assertFalse( $missing_desc->is_success() );
		$this->assertStringContainsString( 'description', strtolower( (string) $missing_desc->first_error() ) );

		$missing_icon = $this->journey->create_badge(
			array(
				'name'        => 'n',
				'description' => 'd',
			)
		);
		$this->assertFalse( $missing_icon->is_success() );
		$this->assertStringContainsString( 'icon', strtolower( (string) $missing_icon->first_error() ) );
	}

	public function test_create_badge_rejects_invalid_tier(): void {
		$result = $this->journey->create_badge(
			array(
				'name'        => 'Bogus',
				'description' => 'd',
				'icon'        => 'x',
				'tier'        => 'platinum',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'tier', strtolower( (string) $result->first_error() ) );
	}

	public function test_update_badge_whitelists_fields(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$result = $this->journey->update_badge(
			$badge_id,
			array(
				'name'             => 'Renamed',
				'tier'             => 'gold',
				'is_active'        => false,
				'not_a_real_field' => 'ignored',
				'reputation_bonus' => 99,
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertContains( 'name', $result->data['updated'] );
		$this->assertContains( 'tier', $result->data['updated'] );
		$this->assertContains( 'reputation_bonus', $result->data['updated'] );
		$this->assertNotContains( 'not_a_real_field', $result->data['updated'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT name, tier, is_active, reputation_bonus FROM {$wpdb->prefix}jt_pro_badges WHERE id = %d",
				$badge_id
			)
		);
		$this->assertSame( 'Renamed', $row->name );
		$this->assertSame( 'gold', $row->tier );
		$this->assertSame( 0, (int) $row->is_active );
		$this->assertSame( 99, (int) $row->reputation_bonus );
	}

	public function test_update_badge_rejects_empty_patch(): void {
		$badge_id = $this->make_badge();

		$result = $this->journey->update_badge( $badge_id, array() );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'no updatable', strtolower( (string) $result->first_error() ) );

		// Patch containing only unknown fields should also fail.
		$nope = $this->journey->update_badge( $badge_id, array( 'unknown_field' => 'x' ) );
		$this->assertFalse( $nope->is_success() );
	}

	public function test_delete_badge_removes_row(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$result = $this->journey->delete_badge( $badge_id );
		$this->assertTrue( $result->is_success() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_badges WHERE id = %d",
				$badge_id
			)
		);
		$this->assertSame( 0, $count );
	}

	/**
	 * The journey's delete is a HARD delete and cascades to user_badges,
	 * unlike the REST endpoint which soft-deletes (`is_active=0`) and
	 * orphans awards. Verify the cascade explicitly so the contract is
	 * locked in.
	 */
	public function test_delete_badge_cascades_to_awards(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$award = $this->journey->award_badge( $badge_id, $this->user_a, $this->admin );
		$this->assertTrue( $award->is_success() );

		$delete = $this->journey->delete_badge( $badge_id );
		$this->assertTrue( $delete->is_success() );
		$this->assertSame( 1, (int) $delete->data['awards_removed'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$remaining = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_user_badges WHERE badge_id = %d",
				$badge_id
			)
		);
		$this->assertSame( 0, $remaining );
	}

	public function test_get_badge_returns_definition(): void {
		$badge_id = $this->make_badge( 'Inspect Me', 'silver' );

		$result = $this->journey->get_badge( $badge_id );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( $badge_id, (int) $result->data['id'] );
		$this->assertSame( 'Inspect Me', $result->data['name'] );
		$this->assertSame( 'silver', $result->data['tier'] );
		$this->assertSame( 'participation', $result->data['category'] );
		$this->assertArrayHasKey( 'description', $result->data );
		$this->assertArrayHasKey( 'icon', $result->data );
	}

	public function test_get_badge_fails_for_missing_id(): void {
		$result = $this->journey->get_badge( 999999 );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not found', strtolower( (string) $result->first_error() ) );
	}

	public function test_list_badges_returns_items_and_columns(): void {
		$this->make_badge( 'A Badge', 'bronze' );
		$this->make_badge( 'B Badge', 'gold' );

		$result = $this->journey->list_badges();
		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertCount( 2, $result->data['items'] );
		$this->assertContains( 'tier', $result->data['columns'] );
		$this->assertContains( 'name', $result->data['columns'] );
	}

	public function test_award_badge_records_assignment(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$result = $this->journey->award_badge( $badge_id, $this->user_a, $this->admin );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertFalse( $result->data['already_awarded'] );
		$this->assertGreaterThan( 0, (int) $result->data['award_id'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT user_id, badge_id, metadata FROM {$wpdb->prefix}jt_pro_user_badges WHERE id = %d",
				(int) $result->data['award_id']
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( $this->user_a, (int) $row->user_id );
		$this->assertSame( $badge_id, (int) $row->badge_id );

		$meta = json_decode( (string) $row->metadata, true );
		$this->assertIsArray( $meta );
		$this->assertSame( $this->admin, (int) $meta['awarded_by'] );
	}

	public function test_award_badge_is_idempotent_when_already_awarded(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$first  = $this->journey->award_badge( $badge_id, $this->user_a, $this->admin );
		$second = $this->journey->award_badge( $badge_id, $this->user_a, $this->admin );

		$this->assertTrue( $first->is_success() );
		$this->assertTrue( $second->is_success() );
		$this->assertFalse( $first->data['already_awarded'] );
		$this->assertTrue( $second->data['already_awarded'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_user_badges WHERE badge_id = %d AND user_id = %d",
				$badge_id,
				$this->user_a
			)
		);
		$this->assertSame( 1, $count );
	}

	public function test_revoke_badge_removes_award(): void {
		global $wpdb;
		$badge_id = $this->make_badge();

		$award = $this->journey->award_badge( $badge_id, $this->user_a, $this->admin );
		$this->assertTrue( $award->is_success() );

		$revoke = $this->journey->revoke_badge( $badge_id, $this->user_a );
		$this->assertTrue( $revoke->is_success() );
		$this->assertSame( 1, (int) $revoke->data['removed'] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_user_badges WHERE badge_id = %d AND user_id = %d",
				$badge_id,
				$this->user_a
			)
		);
		$this->assertSame( 0, $count );

		$missing = $this->journey->revoke_badge( $badge_id, $this->user_b );
		$this->assertFalse( $missing->is_success() );
	}

	public function test_list_user_badges_includes_award_metadata(): void {
		$badge_one = $this->make_badge( 'Alpha', 'bronze' );
		$badge_two = $this->make_badge( 'Beta', 'silver' );

		$this->journey->award_badge( $badge_one, $this->user_a, $this->admin );
		$this->journey->award_badge( $badge_two, $this->user_a, $this->admin );
		// user_b only gets one — should not appear in user_a's list.
		$this->journey->award_badge( $badge_one, $this->user_b, $this->admin );

		$result = $this->journey->list_user_badges( $this->user_a );
		$this->assertTrue( $result->is_success() );
		$this->assertArrayHasKey( 'items', $result->data );
		$this->assertArrayHasKey( 'columns', $result->data );
		$this->assertCount( 2, $result->data['items'] );

		foreach ( $result->data['items'] as $item ) {
			$this->assertArrayHasKey( 'badge_id', $item );
			$this->assertArrayHasKey( 'earned_at', $item );
			$this->assertArrayHasKey( 'awarded_by', $item );
			$this->assertSame( $this->admin, (int) $item['awarded_by'] );
			$this->assertNotEmpty( $item['earned_at'] );
		}

		$badge_ids = array_column( $result->data['items'], 'badge_id' );
		$this->assertContains( $badge_one, $badge_ids );
		$this->assertContains( $badge_two, $badge_ids );
	}
}
