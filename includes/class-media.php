<?php
/**
 * Media upload handler.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Media {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_upload_image', [ $this, 'handle_upload' ] );
	}

	public function handle_upload(): void {
		check_ajax_referer( 'jetonomy_upload', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( __( 'You must be logged in to upload images.', 'jetonomy' ) );
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( __( 'You do not have permission to upload files.', 'jetonomy' ) );
		}

		if ( empty( $_FILES['file'] ) ) {
			wp_send_json_error( __( 'No file provided.', 'jetonomy' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( $attachment_id->get_error_message() );
		}

		wp_send_json_success(
			[
				'id'  => $attachment_id,
				'url' => wp_get_attachment_url( $attachment_id ),
				'alt' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '',
			]
		);
	}
}
