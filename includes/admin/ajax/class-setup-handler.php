<?php
/**
 * Admin AJAX handler — setup wizard.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\UserProfile;

class Setup_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_setup_save', [ $this, 'ajax_setup_save' ] );
		add_action( 'wp_ajax_jetonomy_setup_create_sample', [ $this, 'ajax_setup_create_sample' ] );
		add_action( 'wp_ajax_jetonomy_cleanup_sample_data', [ $this, 'ajax_cleanup_sample_data' ] );
	}

	public function ajax_setup_save(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$settings                 = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( wp_unslash( $_POST['base_slug'] ?? 'community' ) );
		$settings['default_type'] = sanitize_text_field( wp_unslash( $_POST['default_type'] ?? 'forum' ) );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		$cat_name   = sanitize_text_field( wp_unslash( $_POST['category_name'] ?? 'General' ) );
		$space_name = sanitize_text_field( wp_unslash( $_POST['space_name'] ?? 'Community Discussion' ) );
		$space_desc = sanitize_textarea_field( wp_unslash( $_POST['space_description'] ?? '' ) );

		$cat_id = Category::create(
			[
				'name'       => $cat_name,
				'slug'       => sanitize_title( $cat_name ),
				'visibility' => 'public',
			]
		);

		$space_id = Space::create(
			[
				'category_id' => $cat_id,
				'author_id'   => get_current_user_id(),
				'type'        => $settings['default_type'],
				'title'       => $space_name,
				'slug'        => sanitize_title( $space_name ),
				'description' => $space_desc,
				'visibility'  => 'public',
				'join_policy' => 'open',
			]
		);

		$add_result = SpaceMember::add( $space_id, get_current_user_id(), 'admin' );
		if ( is_wp_error( $add_result ) ) {
			wp_send_json_error( $add_result->get_error_message() );
		}
		UserProfile::find_or_create( get_current_user_id() );

		flush_rewrite_rules();
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success(
			[
				'category_id' => $cat_id,
				'space_id'    => $space_id,
			]
		);
	}

	public function ajax_setup_create_sample(): void {
		check_ajax_referer( 'jetonomy_setup', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$uid = get_current_user_id();
		UserProfile::find_or_create( $uid );

		$settings                 = get_option( 'jetonomy_settings', [] );
		$settings['base_slug']    = sanitize_title( wp_unslash( $_POST['base_slug'] ?? 'community' ) );
		$settings['default_type'] = sanitize_text_field( wp_unslash( $_POST['default_type'] ?? 'forum' ) );
		$settings['guest_read']   = true;
		update_option( 'jetonomy_settings', $settings );

		// Auto-cleanup any existing demo data before re-seeding.
		$existing = get_option( 'jetonomy_demo_data', [] );
		if ( ! empty( $existing ) ) {
			Demo_Seeder::cleanup( $existing );
		}

		$demo = Demo_Seeder::seed( $uid );
		update_option( 'jetonomy_demo_data', $demo, false );

		flush_rewrite_rules();
		update_option( 'jetonomy_setup_complete', true );

		wp_send_json_success( [ 'message' => __( 'Sample community created with realistic multi-user content.', 'jetonomy' ) ] );
	}

	public function ajax_cleanup_sample_data(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$demo = get_option( 'jetonomy_demo_data', [] );
		if ( empty( $demo ) ) {
			wp_send_json_error( __( 'No demo data found to clean up.', 'jetonomy' ) );
		}

		Demo_Seeder::cleanup( $demo );
		delete_option( 'jetonomy_demo_data' );

		wp_send_json_success( [ 'message' => __( 'All demo data has been removed.', 'jetonomy' ) ] );
	}
}
