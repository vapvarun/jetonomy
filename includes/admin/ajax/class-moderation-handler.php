<?php
/**
 * Admin AJAX handler — moderation.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Flag;

class Moderation_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_approve_content', [ $this, 'ajax_approve_content' ] );
		add_action( 'wp_ajax_jetonomy_spam_content', [ $this, 'ajax_spam_content' ] );
		add_action( 'wp_ajax_jetonomy_trash_content', [ $this, 'ajax_trash_content' ] );
		add_action( 'wp_ajax_jetonomy_resolve_flag', [ $this, 'ajax_resolve_flag' ] );
	}

	public function ajax_approve_content(): void {
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

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'publish' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'publish' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Content approved.', 'jetonomy' ) ] );
	}

	public function ajax_spam_content(): void {
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

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'spam' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'spam' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Marked as spam.', 'jetonomy' ) ] );
	}

	public function ajax_trash_content(): void {
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

		if ( 'post' === $object_type ) {
			Post::update( $object_id, [ 'status' => 'trash' ] );
		} else {
			Reply::update( $object_id, [ 'status' => 'trash' ] );
		}

		wp_send_json_success( [ 'message' => __( 'Content trashed.', 'jetonomy' ) ] );
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

		$flag = Flag::find( $flag_id );
		if ( ! $flag ) {
			wp_send_json_error( __( 'Flag not found.', 'jetonomy' ) );
		}

		Flag::resolve( $flag_id, get_current_user_id(), $resolution );

		// If valid, also trash the reported content
		if ( 'valid' === $resolution ) {
			if ( 'post' === $flag->object_type ) {
				Post::update( (int) $flag->object_id, [ 'status' => 'trash' ] );
			} elseif ( 'reply' === $flag->object_type ) {
				Reply::update( (int) $flag->object_id, [ 'status' => 'trash' ] );
			}
		}

		wp_send_json_success( [ 'message' => __( 'Flag resolved.', 'jetonomy' ) ] );
	}
}
