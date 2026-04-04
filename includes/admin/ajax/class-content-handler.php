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

class Content_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_update_post', [ $this, 'ajax_update_post' ] );
		add_action( 'wp_ajax_jetonomy_delete_post', [ $this, 'ajax_delete_post' ] );
		add_action( 'wp_ajax_jetonomy_update_reply', [ $this, 'ajax_update_reply' ] );
		add_action( 'wp_ajax_jetonomy_delete_reply', [ $this, 'ajax_delete_reply' ] );
		add_action( 'wp_ajax_jetonomy_get_replies', [ $this, 'ajax_get_replies' ] );
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

		Post::update( $id, [ 'status' => $status ] );

		do_action( 'jetonomy_content_moderated', $status, 'post', $id, get_current_user_id() );

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

		Reply::update( $id, [ 'status' => $status ] );
		wp_send_json_success();
	}

	public function ajax_get_replies(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'jetonomy' ) );
		}

		$replies = Reply::list_by_post( $post_id, 'oldest', 100, 0, 0 );
		$items   = [];
		foreach ( $replies as $r ) {
			$author  = get_userdata( (int) $r->author_id );
			$items[] = [
				'id'          => (int) $r->id,
				'author_name' => $author ? $author->display_name : __( 'Unknown', 'jetonomy' ),
				'content'     => $r->content ?? '',
				'status'      => $r->status ?? 'publish',
				'created_at'  => $r->created_at ?? '',
			];
		}

		wp_send_json_success( $items );
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

		foreach ( $ids as $id ) {
			if ( 'post' === $type ) {
				Post::update( $id, [ 'status' => $action ] );
			} else {
				Reply::update( $id, [ 'status' => $action ] );
			}
		}

		wp_send_json_success( [ 'updated' => count( $ids ) ] );
	}
}
