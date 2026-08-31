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
		add_action( 'wp_ajax_jetonomy_reorder_spaces', array( $this, 'ajax_reorder_spaces' ) );
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
		// Direct entry for the same column ajax_reorder_spaces() writes by drag.
		// The two are not rivals: dragging is for arranging a category you are
		// looking at, this is for setting one space's position without hunting
		// for it in a list. Both land on sort_order, so neither can drift.
		//
		// An empty string means "the field was left blank", which is not the
		// same as 0 - skip it rather than silently sending the space to the top.
		if ( isset( $_POST['sort_order'] ) && '' !== $_POST['sort_order'] ) {
			$data['sort_order'] = absint( wp_unslash( $_POST['sort_order'] ) );
		}
		if ( isset( $_POST['cover_image'] ) ) {
			$data['cover_image'] = esc_url_raw( wp_unslash( $_POST['cover_image'] ) ) ?: null;
		}
		if ( isset( $_POST['settings'] ) ) {
			$settings_raw = $_POST['settings'];
			if ( is_string( $settings_raw ) ) {
				$decoded = json_decode( wp_unslash( $settings_raw ), true );
				if ( is_array( $decoded ) ) {
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

					// Merge + normalize (prefixes, posts_per_page) through the model so
					// this writer and the REST writer store one shape.
					$data['settings'] = wp_json_encode( Space::merge_settings( $id, $decoded ) );
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

	/**
	 * Persist a manual space order inside one category.
	 *
	 * Ordering is per-category because that is the unit the front end renders
	 * (Space::list_by_category orders by `sort_order ASC, title ASC`). The
	 * column and the read path already existed; nothing ever wrote to it, so
	 * every space sat at 0 and the front end silently fell back to alphabetical.
	 *
	 * Positions are absolute via the shared reorder primitive, so a drag on
	 * page 2 cannot renumber over page 1 - the defect this shipped alongside
	 * fixing for categories (Basecamp 10210539659).
	 */
	public function ajax_reorder_spaces(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$order = array_map( 'absint', (array) wp_unslash( $_POST['order'] ?? array() ) );
		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order data.', 'jetonomy' ) );
		}

		$offset = jetonomy_reorder_offset(
			absint( $_POST['paged'] ?? 1 ),
			absint( $_POST['per_page'] ?? 20 )
		);

		jetonomy_apply_manual_order(
			$order,
			$offset,
			static function ( int $space_id, int $position ): void {
				Space::update( $space_id, array( 'sort_order' => $position ) );
			}
		);

		wp_send_json_success( array( 'message' => __( 'Order saved.', 'jetonomy' ) ) );
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
			/* translators: %s: the singular space label. */
			wp_send_json_error( sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ) );
		}

		// Same contract as DELETE /spaces/{id}: transfer unless purge is asked
		// for AND allowed. Space::delete() is deliberately not used here - it
		// removes the row and orphans every child across the 21 relations.
		$mode = sanitize_key( (string) ( $_POST['mode'] ?? 'transfer' ) );

		if ( 'purge' === $mode ) {
			// No allow_space_admin_purge check here. Reaching this method at all
			// requires jetonomy_manage_spaces (asserted at the top), a capability
			// the owner hands out deliberately in the Permissions tab. Asking a
			// second time whether the owner "really" meant it - via a setting
			// that exists to gate FRONT-END space admins, who hold no capability
			// at all - only ever produced one of two outcomes: nothing, for an
			// administrator who passes it regardless, or a refusal for the
			// Community Manager role the owner had just granted Manage all
			// spaces to. The capability is the decision; this screen honours it.
			//
			// The setting still governs the front-end/REST path, where a space
			// admin is a member with a space-level role rather than a WordPress
			// one. See Spaces_Controller::delete_item().
			\Jetonomy\Space_Purge::queue( $id );

			wp_send_json_success(
				array(
					'message' => __( 'Space and all its content are being deleted.', 'jetonomy' ),
					'mode'    => 'purge',
					'removed' => true,
				)
			);
		} else {
			$this->transfer_space( $id );
		}
	}

	/**
	 * Archive a space and hand it to a successor, keeping every topic.
	 *
	 * Split out of ajax_delete_space() so the transfer path is the explicit
	 * ELSE of the purge branch rather than code that merely happens to sit
	 * after it. wp_send_json_success() ends the request, so the two could never
	 * both run - but that relies on a die() several call frames away, and a
	 * reader should not have to know it to see that a space cannot be handed to
	 * a successor moments after it was destroyed.
	 *
	 * @param int $id Space to archive and transfer.
	 */
	private function transfer_space( int $id ): void {
		$successor = Space::resolve_successor( $id, get_current_user_id() );
		if ( ! $successor ) {
			wp_send_json_error( __( 'No one else can take over this space. Delete it permanently instead.', 'jetonomy' ) );
		}

		Space::hand_over( $id, $successor, get_current_user_id(), true );

		wp_send_json_success(
			array(
				'message' => __( 'Space archived and transferred. Its content was kept.', 'jetonomy' ),
				'mode'    => 'transfer',
				'removed' => false,
			)
		);
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
				/* translators: 1: member display name, 2: the space role they were given. */
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
		$made_private = AccessRule::enforce_gate_on_public_space( $space_id, $rule_type );

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
		$rule_id    = absint( $_POST['rule_id'] ?? 0 );
		$space_role = sanitize_text_field( wp_unslash( $_POST['space_role'] ?? 'member' ) );

		if ( ! $space_id || ! $rule_value ) {
			wp_send_json_error( __( 'Missing parameters.', 'jetonomy' ) );
		}

		// The roster role comes from the STORED rule, capped by that rule's own
		// grants - never from the request. Sync used to write whatever role the
		// button carried, so a rule advertising "Read" could deposit space
		// admins, who then get the moderation bypass that reads the roster role
		// and never looks at grants. See AccessRule::cap_space_role().
		$rule = $rule_id ? AccessRule::find( $rule_id ) : null;
		if ( $rule && (int) $rule->space_id === $space_id ) {
			$space_role = AccessRule::cap_space_role( (string) $rule->grants, (string) $rule->space_role, (string) $rule->rule_type );
		}

		// Find the adapter that owns this level.
		$adapters = \Jetonomy\Adapters\Adapter_Registry::get_all_membership();
		$synced   = 0;

		// Only the active adapters, resolved once (not per user).
		$adapters = array_filter( $adapters, static fn( $a ) => $a->is_active() );

		// Page through users in bounded batches — never load the whole user
		// table into memory at once (big-site rule: 50k users OOM'd a single
		// unbounded get_users()). The per-user adapter check is inherent to the
		// membership adapter interface; the recurring roster reconcile
		// (Membership_Roster_Sync::reconcile) is the reliable path at very large
		// scale, this button is the immediate on-demand backfill.
		$per_page = 500;
		$paged    = 1;
		do {
			$users = get_users(
				array(
					'fields'  => 'ID',
					'number'  => $per_page,
					'paged'   => $paged,
					'orderby' => 'ID',
					'order'   => 'ASC',
				)
			);
			$batch = count( $users );
			foreach ( $users as $user_id ) {
				$user_id = (int) $user_id;
				foreach ( $adapters as $adapter ) {
					if ( $adapter->user_has_level( $user_id, $rule_value ) ) {
						if ( ! SpaceMember::is_member( $space_id, $user_id ) ) {
							// Stamp 'tier' so a lapsed plan (or the reconcile
							// sweep) can later remove it. Without this the row
							// defaulted to 'manual' and was permanently exempt
							// from the deactivation sweep - the backfilled members
							// the button exists for never got cleaned up.
							$add_result = SpaceMember::add( $space_id, $user_id, $space_role, 'tier' );
							if ( ! is_wp_error( $add_result ) ) {
								++$synced;
							}
						}
						break;
					}
				}
			}
			++$paged;
		} while ( $batch === $per_page );

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
