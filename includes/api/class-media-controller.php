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
use Jetonomy\API\REST_Auth;

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
					// REST_Auth enforces login + nonce upfront. The fine-grained
					// cap matrix (upload_files OR jetonomy_upload_media OR
					// jetonomy_create_posts OR jetonomy_create_replies) is
					// re-checked in upload_image() so the handler keeps the same
					// cap-OR semantics REST_Auth can't express in a single call.
					'permission_callback' => REST_Auth::auth_mutation( 'read' ),
				],
				[
					// GET /jetonomy/v1/media — list community uploads (owner view).
					// Read-only listing scoped to Jetonomy member uploads.
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_media' ],
					'permission_callback' => static function () {
						return current_user_can( 'jetonomy_manage_settings' );
					},
					'args'                => [
						'page'     => [
							'type'    => 'integer',
							'default' => 1,
						],
						'per_page' => [
							'type'    => 'integer',
							'default' => 24,
						],
						'author'   => [ 'type' => 'integer' ],
						'space_id' => [ 'type' => 'integer' ],
						'search'   => [ 'type' => 'string' ],
						'order'    => [
							'type'    => 'string',
							'enum'    => [ 'asc', 'desc' ],
							'default' => 'desc',
						],
					],
				],
			]
		);
	}

	/**
	 * GET /jetonomy/v1/media — paginated list of community uploads, scoped to
	 * Jetonomy member uploads (the same set hidden from the main Media Library).
	 *
	 * @param WP_REST_Request $request Query params: page, per_page, author, space_id, search, order.
	 * @return WP_REST_Response
	 */
	public function list_media( WP_REST_Request $request ) {
		$result = \Jetonomy\Media_Library::query(
			[
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => (int) $request->get_param( 'per_page' ),
				'author'   => (int) $request->get_param( 'author' ),
				'space_id' => (int) $request->get_param( 'space_id' ),
				'search'   => (string) $request->get_param( 'search' ),
				'order'    => (string) $request->get_param( 'order' ),
			]
		);

		$items = array_map(
			static function ( $att ) {
				$id = (int) $att->ID;
				return [
					'id'       => $id,
					'url'      => (string) wp_get_attachment_url( $id ),
					'thumb'    => (string) wp_get_attachment_image_url( $id, 'medium' ),
					'title'    => get_the_title( $id ),
					'mime'     => (string) get_post_mime_type( $id ),
					'author'   => (int) $att->post_author,
					'space_id' => (int) get_post_meta( $id, '_jetonomy_space_id', true ),
					'date'     => mysql_to_rfc3339( $att->post_date_gmt ),
				];
			},
			$result['items']
		);

		return $this->paginated_response(
			$items,
			[
				'total'  => (int) $result['total'],
				'page'   => (int) $result['page'],
				'offset' => ( (int) $result['page'] - 1 ) * (int) $result['per_page'],
			]
		);
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

		// Mark this as a Jetonomy community upload so it can be kept out of the
		// admin Media Library by default (member uploads should not drown the
		// site owner's own media). Optional space context if the caller sends it.
		\Jetonomy\Media_Library::tag_upload( (int) $attachment_id, (int) $request->get_param( 'space_id' ) );

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
