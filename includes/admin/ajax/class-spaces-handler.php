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
use Jetonomy\Models\JoinRequest;
use Jetonomy\Models\InviteLink;
use function Jetonomy\now;

class Spaces_Handler {

	public function __construct() {
		// Space AJAX
		add_action( 'wp_ajax_jetonomy_create_space', array( $this, 'ajax_create_space' ) );
		add_action( 'wp_ajax_jetonomy_update_space', array( $this, 'ajax_update_space' ) );
		add_action( 'wp_ajax_jetonomy_delete_space', array( $this, 'ajax_delete_space' ) );
		// Space Members AJAX
		add_action( 'wp_ajax_jetonomy_add_space_member', array( $this, 'ajax_add_space_member' ) );
		add_action( 'wp_ajax_jetonomy_remove_space_member', array( $this, 'ajax_remove_space_member' ) );
		add_action( 'wp_ajax_jetonomy_change_member_role', array( $this, 'ajax_change_member_role' ) );
		// Access Rules AJAX
		add_action( 'wp_ajax_jetonomy_add_access_rule', array( $this, 'ajax_add_access_rule' ) );
		add_action( 'wp_ajax_jetonomy_delete_access_rule', array( $this, 'ajax_delete_access_rule' ) );
		add_action( 'wp_ajax_jetonomy_sync_access_rule', array( $this, 'ajax_sync_access_rule' ) );
		// Join Requests AJAX
		add_action( 'wp_ajax_jetonomy_approve_join_request', array( $this, 'ajax_approve_join_request' ) );
		add_action( 'wp_ajax_jetonomy_deny_join_request', array( $this, 'ajax_deny_join_request' ) );
		// Invite Links AJAX
		add_action( 'wp_ajax_jetonomy_generate_invite', array( $this, 'ajax_generate_invite' ) );
		add_action( 'wp_ajax_jetonomy_list_invites', array( $this, 'ajax_list_invites' ) );
		add_action( 'wp_ajax_jetonomy_revoke_invite', array( $this, 'ajax_revoke_invite' ) );
	}

	/**
	 * Build the public invite URL for a token, mirroring
	 * Spaces_Controller::generate_invite() exactly.
	 */
	private function invite_url( string $token ): string {
		$settings  = get_option( 'jetonomy_settings', array() );
		$base_slug = $settings['base_slug'] ?? 'community';
		return home_url( '/' . $base_slug . '/invite/' . $token . '/' );
	}

	public function ajax_create_space(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$title       = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		$slug        = sanitize_title( wp_unslash( $_POST['slug'] ?? $title ) );
		$description = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$category_id = absint( $_POST['category_id'] ?? 0 );
		$type        = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'forum' ) );
		$visibility  = sanitize_text_field( wp_unslash( $_POST['visibility'] ?? 'public' ) );
		$join_policy = sanitize_text_field( wp_unslash( $_POST['join_policy'] ?? 'open' ) );
		$icon        = sanitize_text_field( wp_unslash( $_POST['icon'] ?? '' ) );
		$cover_image = esc_url_raw( wp_unslash( $_POST['cover_image'] ?? '' ) );
		$status      = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'active' ) );

		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ) {
			$type = 'forum';
		}
		if ( ! in_array( $visibility, Space::visibility_values(), true ) ) {
			$visibility = 'public';
		}
		if ( ! in_array( $join_policy, array( 'open', 'approval', 'invite' ), true ) ) {
			$join_policy = 'open';
		}
		if ( ! in_array( $status, array( 'active', 'archived', 'locked' ), true ) ) {
			$status = 'active';
		}

		$combo = Space::validate_visibility_join_policy( $visibility, $join_policy );
		if ( is_wp_error( $combo ) ) {
			wp_send_json_error( $combo->get_error_message() );
		}

		$id = Space::create(
			array(
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
			)
		);

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create space.', 'jetonomy' ) );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Space created.', 'jetonomy' ),
			)
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

		$data           = array();
		$allowed_fields = array(
			'title'       => 'sanitize_text_field',
			'description' => 'wp_kses_post',
			'icon'        => 'sanitize_text_field',
		);

		foreach ( $allowed_fields as $field => $sanitizer ) {
			if ( isset( $_POST[ $field ] ) ) {
				$data[ $field ] = $sanitizer( wp_unslash( $_POST[ $field ] ) );
			}
		}

		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( wp_unslash( $_POST['slug'] ) );
		}
		if ( isset( $_POST['category_id'] ) ) {
			$data['category_id'] = absint( $_POST['category_id'] );
		}
		if ( isset( $_POST['type'] ) ) {
			$type = sanitize_text_field( wp_unslash( $_POST['type'] ) );
			if ( in_array( $type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ) {
				$data['type'] = $type;
			}
		}
		if ( isset( $_POST['visibility'] ) ) {
			$visibility = sanitize_text_field( wp_unslash( $_POST['visibility'] ) );
			if ( in_array( $visibility, Space::visibility_values(), true ) ) {
				$data['visibility'] = $visibility;
			}
		}
		if ( isset( $_POST['join_policy'] ) ) {
			$join_policy = sanitize_text_field( wp_unslash( $_POST['join_policy'] ) );
			if ( in_array( $join_policy, array( 'open', 'approval', 'invite' ), true ) ) {
				$data['join_policy'] = $join_policy;
			}
		}
		if ( isset( $_POST['status'] ) ) {
			$status = sanitize_text_field( wp_unslash( $_POST['status'] ) );
			if ( in_array( $status, array( 'active', 'archived', 'locked' ), true ) ) {
				$data['status'] = $status;
			}
		}

		// Cross-validate visibility + join_policy after overlaying the patch
		// onto the existing space row. Either field may have been left out of
		// the form post and we still need to honour the rule against the
		// stored value (e.g. flipping just visibility to "hidden" on a space
		// whose stored join_policy is "open").
		if ( isset( $data['visibility'] ) || isset( $data['join_policy'] ) ) {
			$existing              = Space::find( $id );
			$effective_visibility  = $data['visibility'] ?? ( $existing->visibility ?? 'public' );
			$effective_join_policy = $data['join_policy'] ?? ( $existing->join_policy ?? 'open' );
			$combo                 = Space::validate_visibility_join_policy(
				(string) $effective_visibility,
				(string) $effective_join_policy
			);
			if ( is_wp_error( $combo ) ) {
				wp_send_json_error( $combo->get_error_message() );
			}
		}
		if ( isset( $_POST['cover_image'] ) ) {
			$data['cover_image'] = esc_url_raw( wp_unslash( $_POST['cover_image'] ) ) ?: null;
		}
		if ( isset( $_POST['settings'] ) ) {
			$settings_raw = $_POST['settings'];
			if ( is_string( $settings_raw ) ) {
				$decoded = json_decode( wp_unslash( $settings_raw ), true );
				if ( is_array( $decoded ) ) {
					// Sanitize topic prefixes if present.
					if ( isset( $decoded['prefixes'] ) && is_array( $decoded['prefixes'] ) ) {
						$sanitized_prefixes = array();
						foreach ( $decoded['prefixes'] as $pfx ) {
							$name  = sanitize_text_field( $pfx['name'] ?? '' );
							$color = sanitize_hex_color( $pfx['color'] ?? '' );
							if ( $name && $color ) {
								$sanitized_prefixes[] = array(
									'name'  => $name,
									'color' => $color,
								);
							}
						}
						$decoded['prefixes'] = $sanitized_prefixes;
					}
					// Handle BuddyPress group linking (stored in group meta, not space settings).
					if ( isset( $decoded['bp_group_id'] ) && function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) {
						$bp_gid = absint( $decoded['bp_group_id'] );
						// Unlink any previously linked group.
						$old_gid = \Jetonomy\Integrations\BuddyPress::find_group_by_space( $id );
						if ( $old_gid && $old_gid !== $bp_gid ) {
							\Jetonomy\Integrations\BuddyPress::unlink_group( $old_gid );
						}
						// Link new group.
						if ( $bp_gid ) {
							\Jetonomy\Integrations\BuddyPress::link_group_to_space( $bp_gid, $id );
						}
						unset( $decoded['bp_group_id'] );
					}

					// Sanitize posts_per_page: clamp to [1, 100] when set, drop key when
					// null/empty/<=0 so Space::get_posts_per_page() can fall through to
					// global → 20 instead of being overridden by a phantom per-space value.
					if ( array_key_exists( 'posts_per_page', $decoded ) ) {
						$pp = $decoded['posts_per_page'];
						if ( null === $pp || '' === $pp || (int) $pp <= 0 ) {
							unset( $decoded['posts_per_page'] );
							$drop_posts_per_page = true;
						} else {
							$decoded['posts_per_page'] = max( 1, min( 100, (int) $pp ) );
							$drop_posts_per_page       = false;
						}
					} else {
						$drop_posts_per_page = false;
					}

					// Merge with existing settings so other keys are not wiped.
					$existing = Space::get_settings( $id );
					$merged   = array_merge( $existing, $decoded );
					// If the user cleared the field, also strip the key from the merged
					// result (otherwise the previous value would persist after merge).
					if ( $drop_posts_per_page ) {
						unset( $merged['posts_per_page'] );
					}
					$data['settings'] = wp_json_encode( $merged );
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
			array(
				'message' => __( 'Space updated.', 'jetonomy' ),
			)
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
			wp_send_json_error( sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		// Decrement category space count
		if ( $space->category_id ) {
			Category::increment_space_count( (int) $space->category_id, -1 );
		}

		$result = Space::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete space.', 'jetonomy' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Space deleted.', 'jetonomy' ) ) );
	}

	public function ajax_add_space_member(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( wp_unslash( $_POST['role'] ?? 'member' ) );

		if ( ! $space_id || ! $user_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, array( 'viewer', 'member', 'moderator', 'admin' ), true ) ) {
			$role = 'member';
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( __( 'User not found.', 'jetonomy' ) );
		}

		$result = SpaceMember::add( $space_id, $user_id, $role );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'      => sprintf( __( '%1$s added as %2$s.', 'jetonomy' ), $user->display_name, $role ),
				'user_id'      => $user_id,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'role'         => $role,
			)
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

		wp_send_json_success( array( 'message' => __( 'Member removed.', 'jetonomy' ) ) );
	}

	public function ajax_change_member_role(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$role     = sanitize_text_field( wp_unslash( $_POST['role'] ?? '' ) );

		if ( ! $space_id || ! $user_id || ! $role ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		if ( ! in_array( $role, SpaceMember::VALID_ROLES, true ) ) {
			wp_send_json_error( __( 'Invalid role.', 'jetonomy' ) );
		}

		// Route existing-member role changes through set_role() so they fire the
		// role-changed event (add()'s REPLACE INTO updated the row silently);
		// still add() a brand-new member so this admin control keeps working for
		// both cases.
		$result = SpaceMember::is_member( $space_id, $user_id )
			? SpaceMember::set_role( $space_id, $user_id, $role )
			: SpaceMember::add( $space_id, $user_id, $role );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( array( 'message' => __( 'Role updated.', 'jetonomy' ) ) );
	}

	public function ajax_add_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id   = absint( $_POST['space_id'] ?? 0 );
		$rule_type  = sanitize_text_field( wp_unslash( $_POST['rule_type'] ?? '' ) );
		$rule_value = sanitize_text_field( wp_unslash( $_POST['rule_value'] ?? '' ) );
		$grants     = sanitize_text_field( wp_unslash( $_POST['grants'] ?? 'read' ) );
		$space_role = sanitize_text_field( wp_unslash( $_POST['space_role'] ?? 'viewer' ) );
		$priority   = absint( $_POST['priority'] ?? 0 );

		if ( ! $space_id ) {
			wp_send_json_error( __( 'Missing space ID.', 'jetonomy' ) );
		}

		$valid_types = array( 'membership', 'role', 'capability', 'trust_level', 'logged_in', 'everyone' );
		if ( ! in_array( $rule_type, $valid_types, true ) ) {
			wp_send_json_error( __( 'Invalid rule type.', 'jetonomy' ) );
		}

		if ( ! in_array( $grants, array( 'read', 'participate', 'full' ), true ) ) {
			$grants = 'read';
		}

		if ( ! in_array( $space_role, array( 'viewer', 'member', 'moderator', 'admin' ), true ) ) {
			$space_role = 'viewer';
		}

		$id = AccessRule::create(
			array(
				'space_id'   => $space_id,
				'rule_type'  => $rule_type,
				'rule_value' => $rule_value ?: null,
				'grants'     => $grants,
				'space_role' => $space_role,
				'priority'   => $priority,
			)
		);

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create access rule.', 'jetonomy' ) );
		}

		$rule = AccessRule::find( $id );

		// Guard: a restrictive access rule cannot gate a PUBLIC space. Public
		// spaces are always readable, and the Permission_Engine blocks non-members
		// on private/hidden spaces *before* rules run — so a membership/role/
		// capability/trust-level rule on a public space silently does nothing (the
		// "configured but content still accessible" report, #10000074550). When
		// such a rule is attached to a public space, switch the space to Private
		// so the rule actually restricts access, and tell the admin what happened.
		$restrictive_types = array( 'membership', 'role', 'capability', 'trust_level' );
		$made_private      = false;
		$space             = Space::find( $space_id );
		if ( $space && 'public' === $space->visibility && in_array( $rule_type, $restrictive_types, true ) ) {
			$made_private = Space::update( $space_id, array( 'visibility' => 'private' ) );
		}

		$message = $made_private
			? __( 'Access rule added. This space was switched to Private so the rule can restrict access — a rule cannot gate a public space.', 'jetonomy' )
			: __( 'Access rule added.', 'jetonomy' );

		wp_send_json_success(
			array(
				'id'           => $id,
				'rule'         => $rule,
				'made_private' => $made_private,
				'message'      => $message,
			)
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

		wp_send_json_success( array( 'message' => __( 'Access rule deleted.', 'jetonomy' ) ) );
	}

	/**
	 * Sync existing memberships for a specific access rule.
	 *
	 * Finds all users who currently have the membership level defined in the rule
	 * and adds them to the space with the configured role.
	 */
	public function ajax_sync_access_rule(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id   = absint( $_POST['space_id'] ?? 0 );
		$rule_value = sanitize_text_field( wp_unslash( $_POST['rule_value'] ?? '' ) );
		$space_role = sanitize_text_field( wp_unslash( $_POST['space_role'] ?? 'member' ) );

		if ( ! $space_id || ! $rule_value ) {
			wp_send_json_error( __( 'Missing parameters.', 'jetonomy' ) );
		}

		// Find the adapter that owns this level.
		$adapters = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
		$synced   = 0;

		// Get all users and check each against the adapter.
		$users = get_users( array( 'fields' => 'ID' ) );

		foreach ( $users as $user_id ) {
			$user_id = (int) $user_id;
			foreach ( $adapters as $adapter ) {
				if ( $adapter->is_active() && $adapter->user_has_level( $user_id, $rule_value ) ) {
					if ( ! SpaceMember::is_member( $space_id, $user_id ) ) {
						$add_result = SpaceMember::add( $space_id, $user_id, $space_role );
						if ( is_wp_error( $add_result ) ) {
							continue;
						}
						++$synced;
					}
					break;
				}
			}
		}

		wp_send_json_success(
			array(
				/* translators: %d: number of members synced */
				'message' => sprintf( __( 'Synced %d existing members.', 'jetonomy' ), $synced ),
				'synced'  => $synced,
			)
		);
	}

	/**
	 * Approve a pending join request and add the user as a space member.
	 */
	public function ajax_approve_join_request(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$request_id = absint( $_POST['id'] ?? 0 );
		$space_id   = absint( $_POST['space_id'] ?? 0 );

		if ( ! $request_id || ! $space_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		$request = JoinRequest::find( $request_id );
		if ( ! $request || 'pending' !== $request->status ) {
			wp_send_json_error( __( 'Join request not found or already processed.', 'jetonomy' ) );
		}

		JoinRequest::approve( $request_id, get_current_user_id() );
		$add_result = SpaceMember::add( $space_id, (int) $request->user_id, 'member' );
		if ( is_wp_error( $add_result ) ) {
			wp_send_json_error( $add_result->get_error_message() );
		}

		wp_send_json_success( array( 'message' => __( 'Join request approved.', 'jetonomy' ) ) );
	}

	/**
	 * Deny a pending join request.
	 */
	public function ajax_deny_join_request(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$request_id = absint( $_POST['id'] ?? 0 );

		if ( ! $request_id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		$request = JoinRequest::find( $request_id );
		if ( ! $request || 'pending' !== $request->status ) {
			wp_send_json_error( __( 'Join request not found or already processed.', 'jetonomy' ) );
		}

		JoinRequest::deny( $request_id, get_current_user_id() );

		wp_send_json_success( array( 'message' => __( 'Join request denied.', 'jetonomy' ) ) );
	}

	/**
	 * Generate a new invite link for a space.
	 */
	public function ajax_generate_invite(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		if ( ! $space_id ) {
			wp_send_json_error( __( 'Missing space ID.', 'jetonomy' ) );
		}

		$max_uses   = absint( $_POST['max_uses'] ?? 0 );
		$expires_at = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );

		$token  = InviteLink::generate( $space_id, get_current_user_id(), $max_uses, $expires_at ?: null );
		$invite = InviteLink::find_by_token( $token );

		wp_send_json_success(
			array(
				'id'         => $invite ? (int) $invite->id : 0,
				'token'      => $token,
				'invite_url' => $this->invite_url( $token ),
				'max_uses'   => $max_uses,
				'expires_at' => $expires_at ?: null,
				'message'    => __( 'Invite link generated.', 'jetonomy' ),
			)
		);
	}

	/**
	 * List active invite links for a space.
	 */
	public function ajax_list_invites(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		if ( ! $space_id ) {
			wp_send_json_error( __( 'Missing space ID.', 'jetonomy' ) );
		}

		$invites = array();
		foreach ( InviteLink::list_by_space( $space_id ) as $invite ) {
			$invites[] = array(
				'id'         => (int) $invite->id,
				'invite_url' => $this->invite_url( (string) $invite->token ),
				'max_uses'   => (int) $invite->max_uses,
				'used_count' => (int) $invite->use_count,
				'expires_at' => $invite->expires_at,
				'is_valid'   => InviteLink::is_valid( $invite ),
			);
		}

		wp_send_json_success( array( 'invites' => $invites ) );
	}

	/**
	 * Revoke (delete) an invite link, scoped to its owning space.
	 */
	public function ajax_revoke_invite(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$space_id = absint( $_POST['space_id'] ?? 0 );
		$id       = absint( $_POST['id'] ?? 0 );

		if ( ! $space_id || ! $id ) {
			wp_send_json_error( __( 'Missing required fields.', 'jetonomy' ) );
		}

		$result = InviteLink::revoke( $id, $space_id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to revoke invite link.', 'jetonomy' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Invite link revoked.', 'jetonomy' ) ) );
	}
}
