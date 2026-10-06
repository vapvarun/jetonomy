<?php
/**
 * Admin AJAX handler — moderation.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Moderation\Moderation_Service;

class Moderation_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_approve_content', [ $this, 'ajax_approve_content' ] );
		add_action( 'wp_ajax_jetonomy_spam_content', [ $this, 'ajax_spam_content' ] );
		add_action( 'wp_ajax_jetonomy_trash_content', [ $this, 'ajax_trash_content' ] );
		add_action( 'wp_ajax_jetonomy_resolve_flag', [ $this, 'ajax_resolve_flag' ] );
	}

	public function ajax_approve_content(): void {
		$this->set_status( 'approve', __( 'Content approved.', 'jetonomy' ) );
	}

	public function ajax_spam_content(): void {
		$this->set_status( 'spam', __( 'Marked as spam.', 'jetonomy' ) );
	}

	public function ajax_trash_content(): void {
		$this->set_status( 'trash', __( 'Content trashed.', 'jetonomy' ) );
	}

	/**
	 * Shared body of the approve / spam / trash buttons. Routes through the
	 * moderation choke-point, like the REST and abilities paths, so approval
	 * announces held content, pending flags resolve, and
	 * jetonomy_content_moderated fires. The capability + nonce here are the
	 * authorization, hence the trusted entry with this admin as the actor.
	 *
	 * @param string $action  'approve' | 'spam' | 'trash'.
	 * @param string $message Success message.
	 */
	private function set_status( string $action, string $message ): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		// Accept both the legacy `object_type`/`object_id` names (used by the
		// moderation queue UI) and the shorter `type`/`id` names posted by the
		// Replies admin list. Either pair is valid input from trusted admin JS.
		$object_type = sanitize_text_field( wp_unslash( $_POST['object_type'] ?? $_POST['type'] ?? '' ) );
		$object_id   = absint( $_POST['object_id'] ?? $_POST['id'] ?? 0 );

		if ( ! $object_id || ! in_array( $object_type, [ 'post', 'reply' ], true ) ) {
			wp_send_json_error( __( 'Invalid content.', 'jetonomy' ) );
		}

		$result = Moderation_Service::system_set_object_status( $object_type, $object_id, $action, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [ 'message' => $message ] );
	}

	public function ajax_resolve_flag(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$flag_id    = absint( $_POST['flag_id'] ?? 0 );
		$resolution = sanitize_text_field( wp_unslash( $_POST['resolution'] ?? '' ) );

		if ( ! $flag_id || ! in_array( $resolution, [ 'valid', 'dismissed' ], true ) ) {
			wp_send_json_error( __( 'Invalid flag data.', 'jetonomy' ) );
		}

		// Delegate to the canonical service so this admin path picks up the
		// per-object permission gate, the sibling cascade, and the
		// jetonomy_flag_resolved / jetonomy_after_resolve_flag actions.
		// The frontend moderation queue and the CLI journey already use
		// this code path; before this refactor only this admin AJAX hop
		// went through inline Flag::resolve + Post/Reply trash, leaving
		// stale "pending" siblings on the same content and skipping any
		// downstream listeners (analytics, audit log, reputation).
		$result = Moderation_Service::resolve_flag( get_current_user_id(), $flag_id, $resolution );
		if ( is_wp_error( $result ) ) {
			$status = 0;
			$data   = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) ) {
				$status = (int) $data['status'];
			}
			wp_send_json_error( $result->get_error_message(), $status > 0 ? $status : null );
		}

		wp_send_json_success( [ 'message' => __( 'Flag resolved.', 'jetonomy' ) ] );
	}
}
