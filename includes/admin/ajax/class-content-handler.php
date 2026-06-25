<?php
/**
 * Admin AJAX handler — content management.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Moderation\Moderation_Service;

class Content_Handler {

	/**
	 * Map a raw target status to the canonical moderation action vocabulary the
	 * Moderation_Service choke-point expects.
	 */
	private const STATUS_TO_ACTION = [
		'trash'   => 'trash',
		'spam'    => 'spam',
		'publish' => 'approve',
		'pending' => 'hold',
	];

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_update_post', [ $this, 'ajax_update_post' ] );
		add_action( 'wp_ajax_jetonomy_delete_post', [ $this, 'ajax_delete_post' ] );
		add_action( 'wp_ajax_jetonomy_update_reply', [ $this, 'ajax_update_reply' ] );
		add_action( 'wp_ajax_jetonomy_delete_reply', [ $this, 'ajax_delete_reply' ] );
		add_action( 'wp_ajax_jetonomy_bulk_content_action', [ $this, 'ajax_bulk_content_action' ] );
	}

	public function ajax_update_post(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['title'] ) ) {
			$data['title'] = sanitize_text_field( wp_unslash( $_POST['title'] ) );
		}
		if ( isset( $_POST['content'] ) ) {
			$data['content']       = wp_kses_post( wp_unslash( $_POST['content'] ) );
			$data['content_plain'] = wp_strip_all_tags( $data['content'] );
		}
		if ( isset( $_POST['status'] ) ) {
			$data['status'] = sanitize_text_field( wp_unslash( $_POST['status'] ) );
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'Nothing to update.', 'jetonomy' ) );
		}

		$data['edited_at'] = current_time( 'mysql' );
		$data['edited_by'] = get_current_user_id();

		Post::update( $id, $data );
		wp_send_json_success( [ 'message' => __( 'Post updated.', 'jetonomy' ) ] );
	}

	public function ajax_delete_post(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id     = absint( $_POST['post_id'] ?? 0 );
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'trash' ) );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		// Route through the moderation choke-point so the status write, pending
		// flag resolution, reputation, and the canonical jetonomy_content_moderated
		// action all happen — identical to the REST and abilities paths. The admin
		// capability + nonce above is the authorization, so use the trusted system
		// entry with this admin recorded as the actor.
		$action = self::STATUS_TO_ACTION[ $status ] ?? 'trash';
		$result = Moderation_Service::system_set_object_status( 'post', $id, $action, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	public function ajax_update_reply(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['reply_id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid reply ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['content'] ) ) {
			$data['content']       = wp_kses_post( wp_unslash( $_POST['content'] ) );
			$data['content_plain'] = wp_strip_all_tags( $data['content'] );
		}
		if ( isset( $_POST['status'] ) ) {
			$data['status'] = sanitize_text_field( wp_unslash( $_POST['status'] ) );
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'Nothing to update.', 'jetonomy' ) );
		}

		$data['edited_at'] = current_time( 'mysql' );
		$data['edited_by'] = get_current_user_id();

		Reply::update( $id, $data );
		wp_send_json_success( [ 'message' => __( 'Reply updated.', 'jetonomy' ) ] );
	}

	public function ajax_delete_reply(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id     = absint( $_POST['reply_id'] ?? 0 );
		$status = sanitize_text_field( wp_unslash( $_POST['status'] ?? 'trash' ) );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid reply ID.', 'jetonomy' ) );
		}

		// Route through the moderation choke-point (flag resolution + canonical
		// action), authorized by the admin cap + nonce above.
		$action = self::STATUS_TO_ACTION[ $status ] ?? 'trash';
		$result = Moderation_Service::system_set_object_status( 'reply', $id, $action, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success();
	}

	public function ajax_bulk_content_action(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$action = sanitize_text_field( wp_unslash( $_POST['bulk_action'] ?? '' ) );
		$ids    = array_map( 'absint', wp_unslash( $_POST['ids'] ?? [] ) );
		$type   = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'post' ) );

		if ( empty( $ids ) || ! in_array( $action, [ 'trash', 'spam', 'publish' ], true ) ) {
			wp_send_json_error( __( 'Invalid bulk action.', 'jetonomy' ) );
		}

		// Each item goes through the moderation choke-point so bulk actions resolve
		// pending flags and fire the canonical action just like single-item ones.
		// $action is validated against the STATUS_TO_ACTION keys above, so the
		// lookup always resolves.
		$obj_type   = 'post' === $type ? 'post' : 'reply';
		$mod_action = self::STATUS_TO_ACTION[ $action ];
		$actor_id   = get_current_user_id();
		$updated    = 0;
		foreach ( $ids as $id ) {
			$result = Moderation_Service::system_set_object_status( $obj_type, $id, $mod_action, $actor_id );
			if ( ! is_wp_error( $result ) ) {
				++$updated;
			}
		}

		wp_send_json_success( [ 'updated' => $updated ] );
	}
}
