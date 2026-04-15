<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Advanced_Moderation_Journey;

/**
 * Integration tests for Advanced_Moderation_Journey against the live
 * `wp_jt_pro_mod_rules` table.
 *
 * The Pro plugin must be active during the test run so the mod_rules table
 * exists (it's created in Advanced_Moderation\Extension::activate() via
 * dbDelta). Each test creates its own fixtures and tear_down() removes every
 * row the journey inserted so runs remain independent.
 */
class AdvancedModerationJourneyTest extends WP_UnitTestCase {

	private Advanced_Moderation_Journey $journey;

	/**
	 * Rule ids we inserted during this test — wiped in tear_down.
	 *
	 * @var int[]
	 */
	private array $created_rule_ids = array();

	public function set_up(): void {
		parent::set_up();

		// The journey class is autoloaded by Jetonomy Pro's PSR-4 map, but
		// when Pro is not active on the site we include the file directly so
		// these tests still fail-skip gracefully.
		if ( ! class_exists( Advanced_Moderation_Journey::class ) ) {
			$path = dirname( __DIR__, 5 ) . '/jetonomy-pro/includes/cli/journeys/class-advanced-moderation-journey.php';
			if ( file_exists( $path ) ) {
				require_once $path;
			}
		}

		if ( ! class_exists( Advanced_Moderation_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Advanced_Moderation_Journey.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . 'jt_pro_mod_rules';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);
		if ( $exists !== $table ) {
			$this->markTestSkipped( 'jt_pro_mod_rules table is missing — Advanced Moderation extension not activated.' );
		}

		$this->journey          = new Advanced_Moderation_Journey();
		$this->created_rule_ids = array();
	}

	public function tear_down(): void {
		global $wpdb;

		if ( ! empty( $this->created_rule_ids ) ) {
			$table = $wpdb->prefix . 'jt_pro_mod_rules';
			$ids   = array_map( 'intval', $this->created_rule_ids );
			// One-shot DELETE keyed on our own insert_ids so nothing else in
			// the table gets touched even if other tests run in parallel.
			foreach ( $ids as $id ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			}
		}

		parent::tear_down();
	}

	/**
	 * Helper: create a rule and track its id for tear_down.
	 *
	 * @param array<string,mixed> $overrides Overrides merged on top of a
	 *                                       minimal valid payload.
	 */
	private function make_rule( array $overrides = array() ): Journey_Result {
		$payload = array_merge(
			array(
				'name'    => 'Test Rule ' . uniqid( '', true ),
				'type'    => 'keyword',
				'pattern' => 'spam,viagra',
				'action'  => 'flag',
			),
			$overrides
		);

		$result = $this->journey->create_rule( $payload );
		if ( $result->is_success() && ! empty( $result->data['id'] ) ) {
			$this->created_rule_ids[] = (int) $result->data['id'];
		}
		return $result;
	}

	public function test_create_rule_persists(): void {
		global $wpdb;

		$result = $this->make_rule();

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertGreaterThan( 0, (int) $result->data['id'] );
		$this->assertSame( 'keyword', $result->data['type'] );
		$this->assertSame( 'flag', $result->data['action'] );
		$this->assertTrue( $result->data['is_active'] );

		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_mod_rules WHERE id = %d",
				(int) $result->data['id']
			)
		);
		$this->assertSame( 1, $row_count );
	}

	public function test_create_rule_requires_core_fields(): void {
		$missing_name = $this->journey->create_rule(
			array(
				'type'    => 'keyword',
				'pattern' => 'spam',
				'action'  => 'flag',
			)
		);
		$this->assertFalse( $missing_name->is_success() );
		$this->assertStringContainsString( 'name', strtolower( (string) $missing_name->first_error() ) );

		$missing_type = $this->journey->create_rule(
			array(
				'name'    => 'no type',
				'pattern' => 'spam',
				'action'  => 'flag',
			)
		);
		$this->assertFalse( $missing_type->is_success() );
		$this->assertStringContainsString( 'type', strtolower( (string) $missing_type->first_error() ) );

		$missing_pattern = $this->journey->create_rule(
			array(
				'name'    => 'no pattern',
				'type'    => 'keyword',
				'pattern' => '',
				'action'  => 'flag',
			)
		);
		$this->assertFalse( $missing_pattern->is_success() );
		$this->assertStringContainsString( 'pattern', strtolower( (string) $missing_pattern->first_error() ) );

		$missing_action = $this->journey->create_rule(
			array(
				'name'    => 'no action',
				'type'    => 'keyword',
				'pattern' => 'spam',
			)
		);
		$this->assertFalse( $missing_action->is_success() );
		$this->assertStringContainsString( 'action', strtolower( (string) $missing_action->first_error() ) );
	}

	public function test_create_rule_rejects_invalid_type(): void {
		$result = $this->journey->create_rule(
			array(
				'name'    => 'bad type',
				'type'    => 'banana',
				'pattern' => 'spam',
				'action'  => 'flag',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'type', strtolower( (string) $result->first_error() ) );
	}

	public function test_create_rule_rejects_invalid_action(): void {
		$result = $this->journey->create_rule(
			array(
				'name'    => 'bad action',
				'type'    => 'keyword',
				'pattern' => 'spam',
				'action'  => 'nuke',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'action', strtolower( (string) $result->first_error() ) );
	}

	public function test_update_rule_whitelists_fields(): void {
		$created = $this->make_rule();
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$patched = $this->journey->update_rule(
			$rule_id,
			array(
				'name'        => 'Renamed rule',
				'action'      => 'hold',
				'nonexistent' => 'ignored',
			)
		);

		$this->assertTrue( $patched->is_success(), implode( '; ', $patched->errors ) );
		$this->assertSame( 'Renamed rule', $patched->data['name'] );
		$this->assertSame( 'hold', $patched->data['action'] );
		$this->assertArrayNotHasKey( 'nonexistent', $patched->data );
	}

	public function test_update_rule_rejects_empty_patch(): void {
		$created = $this->make_rule();
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$empty = $this->journey->update_rule( $rule_id, array() );
		$this->assertFalse( $empty->is_success() );
		$this->assertStringContainsString( 'no updatable', strtolower( (string) $empty->first_error() ) );

		// description/priority are dropped in the journey (forward-compat)
		// so a patch containing only those two should also reject.
		$drop_only = $this->journey->update_rule(
			$rule_id,
			array(
				'description' => 'ignored',
				'priority'    => 5,
			)
		);
		$this->assertFalse( $drop_only->is_success() );
	}

	public function test_delete_rule_removes_row(): void {
		global $wpdb;

		$created = $this->make_rule();
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$deleted = $this->journey->delete_rule( $rule_id );
		$this->assertTrue( $deleted->is_success() );
		$this->assertTrue( $deleted->data['deleted'] );

		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_mod_rules WHERE id = %d",
				$rule_id
			)
		);
		$this->assertSame( 0, $row_count );

		// Deleting again fails with "not found".
		$again = $this->journey->delete_rule( $rule_id );
		$this->assertFalse( $again->is_success() );
	}

	public function test_list_rules_returns_items_and_columns(): void {
		$this->make_rule( array( 'name' => 'alpha' ) );
		$this->make_rule(
			array(
				'name'    => 'beta',
				'type'    => 'regex',
				'pattern' => '\\bhello\\b',
				'action'  => 'hold',
			)
		);

		$result = $this->journey->list_rules();
		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$this->assertGreaterThanOrEqual( 2, count( $result->data['items'] ) );
		$this->assertIsArray( $result->data['columns'] );
		$this->assertContains( 'id', $result->data['columns'] );
		$this->assertContains( 'type', $result->data['columns'] );
		$this->assertContains( 'action', $result->data['columns'] );
	}

	public function test_list_rules_filtered_by_enabled(): void {
		$enabled_row  = $this->make_rule( array( 'name' => 'on-rule' ) );
		$disabled_row = $this->make_rule(
			array(
				'name'    => 'off-rule',
				'enabled' => false,
			)
		);

		$this->assertTrue( $enabled_row->is_success() );
		$this->assertTrue( $disabled_row->is_success() );

		$on_only = $this->journey->list_rules( true );
		$this->assertTrue( $on_only->is_success() );
		$ids_on = array_column( $on_only->data['items'], 'id' );
		$this->assertContains( (int) $enabled_row->data['id'], $ids_on );
		$this->assertNotContains( (int) $disabled_row->data['id'], $ids_on );

		$off_only = $this->journey->list_rules( false );
		$this->assertTrue( $off_only->is_success() );
		$ids_off = array_column( $off_only->data['items'], 'id' );
		$this->assertContains( (int) $disabled_row->data['id'], $ids_off );
		$this->assertNotContains( (int) $enabled_row->data['id'], $ids_off );
	}

	public function test_get_rule_returns_single(): void {
		$created = $this->make_rule( array( 'name' => 'lookup-target' ) );
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$fetched = $this->journey->get_rule( $rule_id );
		$this->assertTrue( $fetched->is_success() );
		$this->assertSame( 'lookup-target', $fetched->data['name'] );

		$missing = $this->journey->get_rule( 999999999 );
		$this->assertFalse( $missing->is_success() );
	}

	public function test_enable_rule_and_disable_rule_toggle(): void {
		$created = $this->make_rule();
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$disable = $this->journey->disable_rule( $rule_id );
		$this->assertTrue( $disable->is_success() );
		$this->assertFalse( $disable->data['is_active'] );

		$after_disable = $this->journey->get_rule( $rule_id );
		$this->assertFalse( $after_disable->data['is_active'] );

		$enable = $this->journey->enable_rule( $rule_id );
		$this->assertTrue( $enable->is_success() );
		$this->assertTrue( $enable->data['is_active'] );

		$after_enable = $this->journey->get_rule( $rule_id );
		$this->assertTrue( $after_enable->data['is_active'] );
	}

	public function test_test_rule_against_matching_content_returns_match(): void {
		$created = $this->make_rule(
			array(
				'name'    => 'match spam',
				'type'    => 'keyword',
				'pattern' => 'viagra',
				'action'  => 'block',
			)
		);
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$result = $this->journey->test_rule_against_content( $rule_id, 'Cheap viagra deals inside.' );
		$this->assertTrue( $result->is_success() );
		$this->assertTrue( $result->data['matched'] );
		$this->assertSame( 'block', $result->data['would_fire'] );
	}

	public function test_test_rule_against_non_matching_content_returns_no_match(): void {
		$created = $this->make_rule(
			array(
				'name'    => 'no match spam',
				'type'    => 'keyword',
				'pattern' => 'viagra',
				'action'  => 'block',
			)
		);
		$this->assertTrue( $created->is_success() );

		$rule_id = (int) $created->data['id'];

		$result = $this->journey->test_rule_against_content( $rule_id, 'A perfectly fine post about gardening.' );
		$this->assertTrue( $result->is_success() );
		$this->assertFalse( $result->data['matched'] );
		$this->assertNull( $result->data['would_fire'] );
	}

	public function test_evaluate_content_returns_first_match(): void {
		$rule_a = $this->make_rule(
			array(
				'name'    => 'evaluate-a',
				'type'    => 'keyword',
				'pattern' => 'viagra',
				'action'  => 'flag',
			)
		);
		$rule_b = $this->make_rule(
			array(
				'name'    => 'evaluate-b',
				'type'    => 'keyword',
				'pattern' => 'casino',
				'action'  => 'block',
			)
		);
		$this->assertTrue( $rule_a->is_success() );
		$this->assertTrue( $rule_b->is_success() );

		$hit = $this->journey->evaluate_content( 'Cheap viagra and casino deals!', 0, 0 );
		$this->assertTrue( $hit->is_success() );
		$this->assertTrue( $hit->data['matched'] );
		$this->assertNotNull( $hit->data['first_match'] );
		$this->assertSame( 'block', $hit->data['highest_action'] );

		$miss = $this->journey->evaluate_content( 'A post about hiking and trail maps.', 0, 0 );
		$this->assertTrue( $miss->is_success() );
		$this->assertFalse( $miss->data['matched'] );
		$this->assertNull( $miss->data['first_match'] );
	}
}
