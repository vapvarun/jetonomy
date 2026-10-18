<?php
/**
 * Every Jetonomy (Free + Pro) personal-data exporter and eraser runs without a
 * database error.
 *
 * REGRESSION (2.0.2 journey E.pro-privacy-and-abilities): the Pro custom-fields
 * and badges exporters selected columns that do not exist (`f.label`,
 * `ub.awarded_at`), so Tools > Export Personal Data silently dropped both groups.
 * A query error inside an exporter returns an empty group, never a failure, so
 * the only reliable guard is to run every callback and read `$wpdb->last_error`.
 *
 * @package Jetonomy\Tests\Pro
 */
namespace Jetonomy\Tests\Pro;

use WP_UnitTestCase;

class PrivacyExportersTest extends WP_UnitTestCase {

	private \WP_User $user;

	public function set_up(): void {
		parent::set_up();
		if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) {
			$this->markTestSkipped( 'Jetonomy Pro is not active.' );
		}
		$this->user = self::factory()->user->create_and_get( array( 'user_email' => 'privacy-qa@example.org' ) );
	}

	public function test_every_exporter_runs_without_a_db_error(): void {
		global $wpdb;
		$p = $wpdb->prefix . 'jt_pro_';

		// One row in each joined Pro table, so the exporters return data to read back.
		$wpdb->insert( $p . 'fields', array( 'name' => 'Favourite game', 'slug' => 'qa-fav-game', 'field_type' => 'text', 'context' => 'profile', 'created_at' => current_time( 'mysql', true ) ) );
		$wpdb->insert( $p . 'field_values', array( 'field_id' => $wpdb->insert_id, 'object_type' => 'user', 'object_id' => $this->user->ID, 'value' => 'chess' ) );
		$wpdb->insert( $p . 'badges', array( 'name' => 'QA Badge', 'slug' => 'qa-badge', 'tier' => 'gold', 'created_at' => current_time( 'mysql', true ) ) );
		$wpdb->insert( $p . 'user_badges', array( 'user_id' => $this->user->ID, 'badge_id' => $wpdb->insert_id, 'earned_at' => '2026-10-01 10:00:00' ) );
		$wpdb->insert( $p . 'push_subscriptions', array( 'user_id' => $this->user->ID, 'endpoint' => 'https://push.example.org/qa', 'p256dh' => 'k', 'auth' => 'a', 'created_at' => '2026-10-01 10:00:00' ) );

		$exporters = array_filter(
			apply_filters( 'wp_privacy_personal_data_exporters', array() ),
			static fn( $key ) => str_starts_with( (string) $key, 'jetonomy' ),
			ARRAY_FILTER_USE_KEY
		);
		$this->assertArrayHasKey( 'jetonomy-pro-custom-fields', $exporters );

		$values = array();
		foreach ( $exporters as $key => $exporter ) {
			$wpdb->last_error = '';
			$result           = call_user_func( $exporter['callback'], $this->user->user_email, 1 );
			$this->assertSame( '', $wpdb->last_error, "{$key} exporter hit a DB error" );
			foreach ( $result['data'] as $item ) {
				foreach ( $item['data'] as $pair ) {
					$values[ $key ][] = $pair['name'] . '=' . $pair['value'];
				}
			}
		}

		$this->assertContains( 'Favourite game=chess', $values['jetonomy-pro-custom-fields'] ?? array() );
		$this->assertContains( 'Awarded=2026-10-01 10:00:00', $values['jetonomy-pro-badges'] ?? array() );
		$this->assertContains( 'Browser subscription=https://push.example.org/qa', $values['jetonomy-pro-push-devices'] ?? array() );
	}

	public function test_every_eraser_runs_without_a_db_error(): void {
		global $wpdb;
		$erasers = array_filter(
			apply_filters( 'wp_privacy_personal_data_erasers', array() ),
			static fn( $key ) => str_starts_with( (string) $key, 'jetonomy' ),
			ARRAY_FILTER_USE_KEY
		);
		$this->assertNotEmpty( $erasers );

		foreach ( $erasers as $key => $eraser ) {
			$wpdb->last_error = '';
			call_user_func( $eraser['callback'], $this->user->user_email, 1 );
			$this->assertSame( '', $wpdb->last_error, "{$key} eraser hit a DB error" );
		}
	}
}
