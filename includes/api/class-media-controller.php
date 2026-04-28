<?php
/**
 * Media REST API controller.
 *
 * Handles image uploads from the composer (paste / drag-drop / toolbar pick).
 * Replaces the legacy `wp_ajax_jetonomy_upload_image` handler shipped with
 * `Jetonomy\Media` so the front-end stops touching `admin-ajax.php` — see
 * `feedback_frontend_rest_only_backend_ajax_ok.md` and v1.4.0 Phase A.
 *
 * @package Jetonomy
 */

namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class Media_Controller extends Base_Controller {

	protected $rest_base = 'media';

	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'upload_image' ],
					'permission_callback' => [ $this, 'upload_permissions_check' ],
				],
			]
		);
	}

	/**
	 * Permission check.
	 *
	 * Mirrors the cap matrix from the legacy `Jetonomy\Media::handle_upload`
	 * so existing roles keep the same behaviour:
	 *   - `upload_files` (Author+) — wp-core grant
	 *   - `jetonomy_upload_media` (Contributor+) — Jetonomy role map
	 *   - `jetonomy_create_posts` (Subscriber+) — anyone who can post
	 *   - `jetonomy_create_replies` (Subscriber+) — anyone who can reply
	 *
	 * Trust-level promotion already grants `jetonomy_upload_media` at TL 1, so
	 * the trust path is covered by the cap check — no separate trust gate.
	 *
	 * @param WP_REST_Request $request Unused but required by the contract.
	 * @return bool|WP_Error
	 */
	public function upload_permissions_check( WP_REST_Request $request ) {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'jetonomy_unauthenticated',
				__( 'You must be logged in to upload images.', 'jetonomy' ),
				[ 'status' => 401 ]
			);
		}

		$can_upload = current_user_can( 'upload_files' )
			|| current_user_can( 'jetonomy_upload_media' )
			|| current_user_can( 'jetonomy_create_posts' )
			|| current_user_can( 'jetonomy_create_replies' );

		if ( ! $can_upload ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * POST /jetonomy/v1/media — accept a multipart `file` field, store it as a
	 * WordPress attachment, return `{ id, url, alt, mime, width, height }`.
	 *
	 * Front-end senders:
	 *   - `assets/js/composer.js` → uploadImage() — paste / drag-drop / toolbar
	 *   - G5 cover image picker (planned, lands with `space-edit.js`)
	 *
	 * @param WP_REST_Request $request Multipart request with a `file` field.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_image( WP_REST_Request $request ) {
		if ( empty( $_FILES['file'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing — REST nonce already verified by core.
			return new WP_Error(
				'jetonomy_no_file',
				__( 'No file provided.', 'jetonomy' ),
				[ 'status' => 400 ]
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'file', 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return new WP_Error(
				'jetonomy_upload_failed',
				$attachment_id->get_error_message(),
				[ 'status' => 400 ]
			);
		}

		// 1.4.0 D.8 — accessibility + SEO: every uploaded image must carry
		// an alt. Caller can pass an explicit `alt` form field (preferred);
		// otherwise we synthesise a readable default from the file name so
		// screen-readers don't see "image" and search engines have something
		// indexable. Customers who want strict empty-alt can pass alt="".
		$explicit_alt = $request->get_param( 'alt' );
		if ( null !== $explicit_alt ) {
			$alt_text = sanitize_text_field( (string) $explicit_alt );
		} else {
			$post     = get_post( $attachment_id );
			$base     = $post ? pathinfo( get_attached_file( $attachment_id ) ?: $post->post_title, PATHINFO_FILENAME ) : '';
			$base     = (string) preg_replace( '/[-_]+/', ' ', (string) $base );
			$base     = trim( (string) preg_replace( '/\s+/', ' ', $base ) );
			$alt_text = '' !== $base ? ucfirst( $base ) : __( 'Uploaded image', 'jetonomy' );
		}
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$meta = wp_get_attachment_metadata( $attachment_id );

		return rest_ensure_response(
			[
				'id'     => (int) $attachment_id,
				'url'    => (string) wp_get_attachment_url( $attachment_id ),
				'alt'    => (string) ( get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ?: '' ),
				'mime'   => (string) get_post_mime_type( $attachment_id ),
				'width'  => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
				'height' => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			]
		);
	}
}
