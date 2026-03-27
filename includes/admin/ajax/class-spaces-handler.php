<?php
/**
 * Admin AJAX handler — spaces.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\SpaceMember;
use Jetonomy\Models\AccessRule;
use function Jetonomy\now;

class Spaces_Handler {

	public function __construct() {
		// Space AJAX
		add_action( 'wp_ajax_jetonomy_create_space', [ $this, 'ajax_create_space' ] );
		add_action( 'wp_ajax_jetonomy_update_space', [ $this, 'ajax_update_space' ] );
		add_action( 'wp_ajax_jetonomy_delete_space', [ $this, 'ajax_delete_space' ] );
		// Space Members AJAX
		add_action( 'wp_ajax_jetonomy_add_space_member', [ $this, 'ajax_add_space_member' ] );
		add_action( 'wp_ajax_jetonomy_remove_space_member', [ $this, 'ajax_remove_space_member' ] );
		add_action( 'wp_ajax_jetonomy_change_member_role', [ $this, 'ajax_change_member_role' ] );
		// Access Rules AJAX
		add_action( 'wp_ajax_jetonomy_add_access_rule', [ $this, 'ajax_add_access_rule' ] );
		add_action( 'wp_ajax_jetonomy_delete_access_rule', [ $this, 'ajax_delete_access_rule' ] );
	}

	public function ajax_create_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$title       = sanitize_text_field( $_POST['title'] ?? '' );
		$slug        = sanitize_title( $_POST['slug'] ?? $title );
		$description = wp_kses_post( $_POST['description'] ?? '' );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$type        = sanitize_text_field( $_POST['type'] ?? 'forum' );
		$visibility  = sanitize_text_field( $_POST['visibility'] ?? 'public' );
		$join_policy = sanitize_text_field( $_POST['join_policy'] ?? 'open' );
		$icon        = sanitize_text_field( $_POST['icon'] ?? '' );
		$cover_image = esc_url_raw( $_POST['cover_image'] ?? '' );
		$status      = sanitize_text_field( $_POST['status'] ?? 'active' );

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
			$type = 'forum';
		}
		if ( ! in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
			$visibility = 'public';
		}
		if ( ! in_array( $join_policy, [ 'open', 'approval', 'invite' ], true ) ) {
			$join_policy = 'open';
		}
		if ( ! in_array( $status, [ 'active', 'archived', 'locked' ], true ) ) {
			$status = 'active';
		}

		$id = Space::create(
			[
				'title'       => $title,
				'slug'        => $slug,
				'description' => $description,
				'category_id' => $category_id,
				'author_id'   => get_current_user_id(),
				'type'        => $type,
				'visibility'  => $visibility,
				'join_policy' => $join_policy,
				'icon'        => $icon ?: null,
				'cover_image' => $cover_image ?: null,
				'status'      => $status,
			]
		);

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create space.', 'jetonomy' ) );
		}

		wp_send_json_success(
			[
				'id'      => $id,
				'message' => __( 'Space created.', 'jetonomy' ),
			]
		);
	}

	public function ajax_update_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid space ID.', 'jetonomy' ) );
		}

		$data           = [];
		$allowed_fields = [
			'title'       => 'sanitize_text_field',
			'description' => 'wp_kses_post',
			'icon'        => 'sanitize_text_field',
		];

		foreach ( $allowed_fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = $sanitizer( $_POST[ $field ] );
			}
		}

		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( $_POST['slug'] );
		}
		if ( isset( $_POST['category_id'] ) ) {
			$data['category_id'] = absint( $_POST['category_id'] );
		}
		if ( isset( $_POST['type'] ) ) {
			$type = sanitize_text_field( $_POST['type'] );
			if ( in_array( $type, [ 'forum', 'qa', 'ideas', 'feed' ], true ) ) {
				$data['type'] = $type;
			}
		}
		if ( isset( $_POST['visibility'] ) ) {
			$visibility = sanitize_text_field( $_POST['visibility'] );
			if ( in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
				$data['visibility'] = $visibility;
			}
		}
		if ( isset( $_POST['join_policy'] ) ) {
			$join_policy = sanitize_text_field( $_POST['join_policy'] );
			if ( in_array( $join_policy, [ 'open', 'approval', 'invite' ], true ) ) {
				$data['join_policy'] = $join_policy;
			}
		}
		if ( isset( $_POST['status'] ) ) {
			$status = sanitize_text_field( $_POST['status'] );
			if ( in_array( $status, [ 'active', 'archived', 'locked' ], true ) ) {
				$data['status'] = $status;
			}
		}
		if ( isset( $_POST['cover_image'] ) ) {
			$data['cover_image'] = esc_url_raw( $_POST['cover_image'] ) ?: null;
		}
		if ( isset( $_POST['settings'] ) ) {
			$settings_raw = $_POST['settings'];
			if ( is_string( $settings_raw ) ) {
				$decoded = json_decode( wp_unslash( $settings_raw ), true );
				if ( is_array( $decoded ) ) {
					$data['settings'] = wp_json_encode( $decoded );
				}
			}
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'No data to update.', 'jetonomy' ) );
		}

		$data['updated_at'] = now();

		$result = Space::update( $id, $data );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update space.', 'jetonomy' ) );
		}

		wp_send_json_success(
			[
				'message' => __( 'Space updated.', 'jetonomy' ),
			]
		);
	}

	public function ajax_delete_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid space ID.', 'jetonomy' ) );
		}

		$space = Space::find( $id );
		if ( ! $space ) {
			wp_send_json_error( __( 'Space not found.', 'jetonomy' ) );
		}

		// Decrement category space count
		if ( $space->category_id ) {
			Category::increment_space_count( (int) $space->category_id, -1 );
		}

		$result = Space::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete space.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Space deleted.', 'jetonomy' ) ] );
	}

	public function ajax_add_space_member(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( $_POST['role'] ?? 'member' );

		if ( ! $space_id || ! $user_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			$role = 'member';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( __( 'User not found.', 'jetonomy' ) );
		}

		SpaceMember::add( $space_id, $user_id, $role );

		wp_send_json_success(
			[
				'message'      => sprintf( __( '%1$s added as %2$s.', 'jetonomy' ), $user->display_name, $role ),
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'role'         => $role,
			]
		);
	}

	public function ajax_remove_space_member(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );

		if ( ! $space_id || ! $user_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		SpaceMember::remove( $space_id, $user_id );

		wp_send_json_success( [ 'message' => __( 'Member removed.', 'jetonomy' ) ] );
	}

	public function ajax_change_member_role(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( $_POST['role'] ?? '' );

		if ( ! $space_id || ! $user_id || ! $role ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			wp_send_json_error( __( 'Invalid role.', 'jetonomy' ) );
		}

		SpaceMember::add( $space_id, $user_id, $role );

		wp_send_json_success( [ 'message' => __( 'Role updated.', 'jetonomy' ) ] );
	}

	public function ajax_add_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id   = absint( $_POST['space_id'] ?? 0 );
		$rule_type  = sanitize_text_field( $_POST['rule_type'] ?? '' );
		$rule_value = sanitize_text_field( $_POST['rule_value'] ?? '' );
		$grants     = sanitize_text_field( $_POST['grants'] ?? 'read' );
		$space_role = sanitize_text_field( $_POST['space_role'] ?? 'viewer' );
		$priority   = absint( $_POST['priority'] ?? 0 );

		if ( ! $space_id ) {
			wp_send_json_error( __( 'Missing space ID.', 'jetonomy' ) );
		}

		$valid_types = [ 'membership', 'role', 'capability', 'trust_level', 'logged_in', 'everyone' ];
		if ( ! in_array( $rule_type, $valid_types, true ) ) {
			wp_send_json_error( __( 'Invalid rule type.', 'jetonomy' ) );
		}

		if ( ! in_array( $grants, [ 'read', 'participate', 'full' ], true ) ) {
			$grants = 'read';
		}

		if ( ! in_array( $space_role, [ 'viewer', 'member', 'moderator', 'admin' ], true ) ) {
			$space_role = 'viewer';
		}

		$id = AccessRule::create(
			[
				'space_id'   => $space_id,
				'rule_type'  => $rule_type,
				'rule_value' => $rule_value ?: null,
				'grants'     => $grants,
				'space_role' => $space_role,
				'priority'   => $priority,
			]
		);

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create access rule.', 'jetonomy' ) );
		}

		$rule = AccessRule::find( $id );
		wp_send_json_success(
			[
				'id'      => $id,
				'rule'    => $rule,
				'message' => __( 'Access rule added.', 'jetonomy' ),
			]
		);
	}

	public function ajax_delete_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid rule ID.', 'jetonomy' ) );
		}

		$result = AccessRule::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete rule.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Access rule deleted.', 'jetonomy' ) ] );
	}
}
