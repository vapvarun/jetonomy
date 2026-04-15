<?php
namespace Jetonomy\Tests\Pro\CLI\Journeys;

use WP_UnitTestCase;
use Jetonomy\CLI\Journey_Result;
use Jetonomy_Pro\CLI\Journeys\Custom_Fields_Journey;

/**
 * Integration tests for Custom_Fields_Journey against the live Pro
 * `wp_jt_pro_fields` and `wp_jt_pro_field_values` tables.
 *
 * The Pro plugin must be active during the test run so the tables exist
 * (they're created in Custom_Fields\Extension::activate() via dbDelta).
 * Each test creates its own fixtures and tear_down() removes every row so
 * runs stay independent.
 */
class CustomFieldsJourneyTest extends WP_UnitTestCase {

	private Custom_Fields_Journey $journey;

	private int $user_id;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		if ( ! class_exists( Custom_Fields_Journey::class ) || ! class_exists( \Jetonomy_Pro::class ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not loaded — cannot exercise Custom_Fields_Journey.' );
		}

		$this->journey = new Custom_Fields_Journey();

		$this->user_id = (int) self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->post_id = 4242;

		$this->truncate_tables();
	}

	public function tear_down(): void {
		$this->truncate_tables();
		parent::tear_down();
	}

	private function truncate_tables(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_field_values" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$wpdb->prefix}jt_pro_fields" );
	}

	private function make_post_field( string $key = 'priority', string $type = 'text' ): int {
		$result = $this->journey->create_field(
			array(
				'key'        => $key,
				'label'      => ucfirst( $key ),
				'type'       => $type,
				'applies_to' => 'post',
			)
		);
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		return (int) $result->data['field_id'];
	}

	private function make_user_field( string $key = 'company', string $type = 'text' ): int {
		$result = $this->journey->create_field(
			array(
				'key'        => $key,
				'label'      => ucfirst( $key ),
				'type'       => $type,
				'applies_to' => 'user',
			)
		);
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		return (int) $result->data['field_id'];
	}

	public function test_create_field_stores_definition(): void {
		global $wpdb;

		$result = $this->journey->create_field(
			array(
				'key'         => 'priority',
				'label'       => 'Priority',
				'type'        => 'select',
				'applies_to'  => 'post',
				'description' => 'How urgent is this post?',
				'options'     => 'low,medium,high',
				'required'    => true,
				'default'     => 'medium',
			)
		);

		$this->assertInstanceOf( Journey_Result::class, $result );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertGreaterThan( 0, (int) $result->data['field_id'] );
		$this->assertSame( 'priority', $result->data['key'] );
		$this->assertSame( 'select', $result->data['type'] );
		$this->assertSame( 'post', $result->data['applies_to'] );
		$this->assertTrue( $result->data['required'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}jt_pro_fields WHERE slug = %s",
				'priority'
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'post', $row->context );
		$this->assertSame( 'select', $row->field_type );
		$this->assertSame( 1, (int) $row->is_required );
		$this->assertSame( 'medium', (string) $row->default_value );
		$this->assertNotEmpty( $row->options );
	}

	public function test_create_field_requires_core_fields(): void {
		$missing_key = $this->journey->create_field(
			array(
				'label'      => 'X',
				'type'       => 'text',
				'applies_to' => 'post',
			)
		);
		$this->assertFalse( $missing_key->is_success() );
		$this->assertStringContainsString( 'key', strtolower( (string) $missing_key->first_error() ) );

		$missing_label = $this->journey->create_field(
			array(
				'key'        => 'x',
				'type'       => 'text',
				'applies_to' => 'post',
			)
		);
		$this->assertFalse( $missing_label->is_success() );
		$this->assertStringContainsString( 'label', strtolower( (string) $missing_label->first_error() ) );

		$missing_type = $this->journey->create_field(
			array(
				'key'        => 'x',
				'label'      => 'X',
				'applies_to' => 'post',
			)
		);
		$this->assertFalse( $missing_type->is_success() );
		$this->assertStringContainsString( 'type', strtolower( (string) $missing_type->first_error() ) );

		$missing_applies_to = $this->journey->create_field(
			array(
				'key'   => 'x',
				'label' => 'X',
				'type'  => 'text',
			)
		);
		$this->assertFalse( $missing_applies_to->is_success() );
		$this->assertStringContainsString( 'applies_to', strtolower( (string) $missing_applies_to->first_error() ) );
	}

	public function test_create_field_rejects_invalid_type(): void {
		$result = $this->journey->create_field(
			array(
				'key'        => 'broken',
				'label'      => 'Broken',
				'type'       => 'banana',
				'applies_to' => 'post',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'type', strtolower( (string) $result->first_error() ) );
	}

	public function test_create_field_rejects_invalid_applies_to(): void {
		$result = $this->journey->create_field(
			array(
				'key'        => 'broken',
				'label'      => 'Broken',
				'type'       => 'text',
				'applies_to' => 'space',
			)
		);

		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'applies_to', strtolower( (string) $result->first_error() ) );
	}

	public function test_update_field_whitelists_fields(): void {
		global $wpdb;

		$this->make_post_field( 'priority', 'text' );

		$result = $this->journey->update_field(
			'priority',
			array(
				'label'       => 'Priority Level',
				'description' => 'How urgent',
				'type'        => 'select',
				'options'     => 'low,medium,high',
				'required'    => true,
				'default'     => 'medium',
				// These should be ignored (not in the whitelist).
				'key'         => 'hacked',
				'applies_to'  => 'user',
				'context'     => 'profile',
			)
		);

		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}jt_pro_fields WHERE slug = %s",
				'priority'
			)
		);
		$this->assertNotNull( $row );
		$this->assertSame( 'Priority Level', $row->name );
		$this->assertSame( 'How urgent', $row->description );
		$this->assertSame( 'select', $row->field_type );
		$this->assertSame( 1, (int) $row->is_required );
		$this->assertSame( 'medium', (string) $row->default_value );
		// The slug and context must be untouched.
		$this->assertSame( 'priority', $row->slug );
		$this->assertSame( 'post', $row->context );
	}

	public function test_delete_field_removes_definition(): void {
		global $wpdb;

		$field_id = $this->make_post_field( 'priority', 'text' );
		$this->journey->set_post_field( $this->post_id, 'priority', 'urgent' );

		$result = $this->journey->delete_field( 'priority' );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertTrue( $result->data['deleted'] );
		$this->assertSame( $field_id, (int) $result->data['field_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$def_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_fields WHERE id = %d",
				$field_id
			)
		);
		$this->assertSame( 0, $def_count );

		// Cascade: stored values for the deleted field are gone too.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$val_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_field_values WHERE field_id = %d",
				$field_id
			)
		);
		$this->assertSame( 0, $val_count );
	}

	public function test_list_fields_all(): void {
		$this->make_post_field( 'priority', 'text' );
		$this->make_user_field( 'company', 'text' );

		$result = $this->journey->list_fields();

		$this->assertTrue( $result->is_success() );
		$this->assertIsArray( $result->data['items'] );
		$this->assertCount( 2, $result->data['items'] );

		$keys = array_column( $result->data['items'], 'key' );
		$this->assertContains( 'priority', $keys );
		$this->assertContains( 'company', $keys );

		$this->assertContains( 'field_id', $result->data['columns'] );
		$this->assertContains( 'key', $result->data['columns'] );
		$this->assertContains( 'applies_to', $result->data['columns'] );
	}

	public function test_list_fields_filtered_by_applies_to(): void {
		$this->make_post_field( 'priority', 'text' );
		$this->make_user_field( 'company', 'text' );
		$this->make_user_field( 'website', 'url' );

		$user_only = $this->journey->list_fields( 'user' );
		$this->assertTrue( $user_only->is_success() );
		$this->assertCount( 2, $user_only->data['items'] );
		foreach ( $user_only->data['items'] as $row ) {
			$this->assertSame( 'user', $row['applies_to'] );
		}

		$post_only = $this->journey->list_fields( 'post' );
		$this->assertTrue( $post_only->is_success() );
		$this->assertCount( 1, $post_only->data['items'] );
		$this->assertSame( 'priority', $post_only->data['items'][0]['key'] );
	}

	public function test_get_field_returns_single(): void {
		$this->make_post_field( 'priority', 'text' );

		$result = $this->journey->get_field( 'priority' );
		$this->assertTrue( $result->is_success() );
		$this->assertSame( 'priority', $result->data['key'] );
		$this->assertSame( 'Priority', $result->data['label'] );
		$this->assertSame( 'text', $result->data['type'] );
		$this->assertSame( 'post', $result->data['applies_to'] );

		$missing = $this->journey->get_field( 'no-such-field' );
		$this->assertFalse( $missing->is_success() );
	}

	public function test_set_post_field_persists_value(): void {
		global $wpdb;

		$field_id = $this->make_post_field( 'priority', 'text' );

		$result = $this->journey->set_post_field( $this->post_id, 'priority', 'urgent' );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 'urgent', $result->data['value'] );
		$this->assertSame( $this->post_id, (int) $result->data['post_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}jt_pro_field_values
				 WHERE field_id = %d AND object_type = %s AND object_id = %d",
				$field_id,
				'post',
				$this->post_id
			)
		);
		$this->assertSame( 'urgent', $stored );

		// Upsert: writing again overwrites rather than duplicating.
		$this->journey->set_post_field( $this->post_id, 'priority', 'low' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}jt_pro_field_values
				 WHERE field_id = %d AND object_type = %s AND object_id = %d",
				$field_id,
				'post',
				$this->post_id
			)
		);
		$this->assertSame( 1, $row_count );
	}

	public function test_set_post_field_rejects_unknown_key(): void {
		$result = $this->journey->set_post_field( $this->post_id, 'nope', 'x' );
		$this->assertFalse( $result->is_success() );
		$this->assertStringContainsString( 'not found', strtolower( (string) $result->first_error() ) );
	}

	public function test_get_post_fields_returns_values(): void {
		$this->make_post_field( 'priority', 'text' );
		$this->make_post_field( 'category', 'text' );

		$this->journey->set_post_field( $this->post_id, 'priority', 'high' );
		$this->journey->set_post_field( $this->post_id, 'category', 'bug' );

		$result = $this->journey->get_post_fields( $this->post_id );
		$this->assertTrue( $result->is_success() );
		$this->assertCount( 2, $result->data['items'] );

		$map = array();
		foreach ( $result->data['items'] as $row ) {
			$map[ $row['key'] ] = $row['value'];
		}
		$this->assertSame( 'high', $map['priority'] );
		$this->assertSame( 'bug', $map['category'] );

		$this->assertContains( 'key', $result->data['columns'] );
		$this->assertContains( 'value', $result->data['columns'] );
	}

	public function test_set_user_field_persists_value(): void {
		global $wpdb;

		$field_id = $this->make_user_field( 'company', 'text' );

		$result = $this->journey->set_user_field( $this->user_id, 'company', 'Acme Inc' );
		$this->assertTrue( $result->is_success(), implode( '; ', $result->errors ) );
		$this->assertSame( 'Acme Inc', $result->data['value'] );
		$this->assertSame( $this->user_id, (int) $result->data['user_id'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored = (string) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT value FROM {$wpdb->prefix}jt_pro_field_values
				 WHERE field_id = %d AND object_type = %s AND object_id = %d",
				$field_id,
				'user',
				$this->user_id
			)
		);
		$this->assertSame( 'Acme Inc', $stored );

		// Writing a post value to a user field must be rejected.
		$post_field_id = $this->make_post_field( 'priority', 'text' );
		$wrong         = $this->journey->set_user_field( $this->user_id, 'priority', 'high' );
		$this->assertFalse( $wrong->is_success() );
		$this->assertStringContainsString( 'post', strtolower( (string) $wrong->first_error() ) );
		$this->assertGreaterThan( 0, $post_field_id );
	}

	public function test_get_user_fields_returns_values(): void {
		$this->make_user_field( 'company', 'text' );
		$this->make_user_field( 'website', 'url' );

		$this->journey->set_user_field( $this->user_id, 'company', 'Acme' );
		$this->journey->set_user_field( $this->user_id, 'website', 'https://example.com' );

		$result = $this->journey->get_user_fields( $this->user_id );
		$this->assertTrue( $result->is_success() );
		$this->assertCount( 2, $result->data['items'] );

		$map = array();
		foreach ( $result->data['items'] as $row ) {
			$map[ $row['key'] ] = $row['value'];
		}
		$this->assertSame( 'Acme', $map['company'] );
		$this->assertSame( 'https://example.com', $map['website'] );
	}
}
