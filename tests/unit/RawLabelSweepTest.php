<?php
/**
 * QA I-P2-2: four admin labels were built with ucfirst() of a stored key, so they could not be translated.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Tests\Unit;

use WP_UnitTestCase;

class RawLabelSweepTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( '\WP_List_Table' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		}
		require_once JETONOMY_DIR . 'includes/admin/class-activity-list-table.php';
	}

	/** Each label goes through a translated map, not ucfirst() of the stored value. */
	public function test_the_four_labels_use_translated_maps(): void {
		$sites = array(
			'includes/admin/views/import.php'             => '$jt_stat_labels[ $key ]',
			'includes/admin/views/space-edit.php'         => '\Jetonomy\access_rule_type_labels()[ $rule->rule_type ]',
			'includes/admin/views/moderation.php'         => "'global_ban' => __( 'Global Ban', 'jetonomy' )",
			'includes/admin/class-activity-list-table.php' => '\Jetonomy\idea_status_labels()',
		);
		foreach ( $sites as $file => $map ) {
			$code = (string) file_get_contents( JETONOMY_DIR . $file );
			$this->assertStringContainsString( $map, $code, $file );
		}
		$this->assertStringNotContainsString( 'ucfirst( $key )', (string) file_get_contents( JETONOMY_DIR . 'includes/admin/views/import.php' ) );
		$this->assertStringNotContainsString( "ucfirst( str_replace( '_', ' ', \$rule->rule_type ) )", (string) file_get_contents( JETONOMY_DIR . 'includes/admin/views/space-edit.php' ) );
		$this->assertStringNotContainsString( "ucfirst( str_replace( '_', ' ', (string) \$ban->type ) )", (string) file_get_contents( JETONOMY_DIR . 'includes/admin/views/moderation.php' ) );
	}

	public function test_the_activity_log_names_idea_statuses_and_roles_in_the_site_language(): void {
		$translate = static fn( $translation, $text, $domain ) => 'jetonomy' === $domain && in_array( $text, array( 'In Progress', 'Shipped', 'Moderator' ), true ) ? 'XX ' . $text : $translation;
		add_filter( 'gettext', $translate, 10, 3 );
		$idea = \Jetonomy\Admin\Activity_List_Table::details_text( (object) array( 'action' => 'idea_status_changed', 'metadata' => wp_json_encode( array( 'old' => 'in_progress', 'new' => 'shipped' ) ) ), array() );
		$role = \Jetonomy\Admin\Activity_List_Table::details_text( (object) array( 'action' => 'joined_space', 'metadata' => wp_json_encode( array( 'role' => 'moderator' ) ) ), array() );
		remove_filter( 'gettext', $translate, 10 );
		$this->assertSame( 'XX In Progress to XX Shipped', $idea );
		$this->assertSame( 'Role: XX Moderator', $role );
		// An add-on's action still lists its keys.
		$this->assertSame( 'Badge name: Helper', \Jetonomy\Admin\Activity_List_Table::details_text( (object) array( 'action' => 'acme_badge', 'metadata' => wp_json_encode( array( 'badge_name' => 'Helper' ) ) ), array() ) );
	}

	public function test_the_roadmap_columns_and_the_status_map_are_one_list(): void {
		$this->assertSame( \Jetonomy\Models\Post::valid_idea_statuses(), array_keys( \Jetonomy\idea_status_labels() ) );
		$this->assertSame( array( 'role', 'capability', 'trust_level', 'membership' ), array_keys( \Jetonomy\access_rule_type_labels() ) );
	}
}
