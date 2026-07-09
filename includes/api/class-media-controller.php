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
					// Login + nonce + the upload-capability matrix in one call:
					// auth_mutation() passes only when the user holds ANY of these
					// caps, so a read-only subscriber can no longer push files into
					// the media library. upload_image() adds the restriction
					// (ban/silence) re-check that capabilities alone don't express.
					'permission_callback' => REST_Auth::auth_mutation(
						array( 'upload_files', 'jetonomy_upload_media', 'jetonomy_create_posts', 'jetonomy_create_replies' )
					),
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
		// Capabilities are enforced by the route's auth_mutation matrix; here we
		// add the restriction layer caps don't cover. A silenced member (can log
		// in, cannot create content) or a banned account whose session outlived
		// the ban must not be able to push files into the media library.
		$uid = get_current_user_id();
		if ( \Jetonomy\Models\Restriction::is_banned( $uid ) || \Jetonomy\Models\Restriction::is_silenced( $uid ) ) {
			return new WP_Error(
				'jetonomy_upload_forbidden',
				__( 'You are not allowed to upload media.', 'jetonomy' ),
				[ 'status' => 403 ]
			);
		}

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

		// Explicit allow-list + size + MIME double-check before core stores it.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- REST nonce verified by core.
		$jt_file  = array(
			'name'     => isset( $_FILES['file']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['file']['name'] ) ) : '',
			'tmp_name' => isset( $_FILES['file']['tmp_name'] ) ? $_FILES['file']['tmp_name'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'size'     => isset( $_FILES['file']['size'] ) ? (int) $_FILES['file']['size'] : 0,
		);
		$jt_valid = self::validate_upload( $jt_file );
		if ( is_wp_error( $jt_valid ) ) {
			return $jt_valid;
		}
		$_FILES['file']['name'] = $jt_file['name']; // Persist the sanitized name for core.

		$attachment_id = media_handle_upload(
			'file',
			0,
			array(),
			array(
				'test_form' => false,
				'mimes'     => (array) apply_filters(
					'jetonomy_upload_allowed_types',
					array(
						'jpg|jpeg' => 'image/jpeg',
						'png'      => 'image/png',
						'gif'      => 'image/gif',
						'webp'     => 'image/webp',
					)
				),
			)
		);

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

	/**
	 * Validate an uploaded file against the explicit extension+MIME allow-list
	 * and the size cap BEFORE it reaches media_handle_upload(). Allow-list, not
	 * blocklist: the extension AND the sniffed MIME must both resolve to the
	 * same allowed type. SVG is never in the default list (script vector).
	 *
	 * Free defaults to images only, so existing behaviour is unchanged; the Pro
	 * attachments extension widens `jetonomy_upload_allowed_types` to add
	 * pdf/office. This closes the prior gap where media_handle_upload() ran with
	 * no explicit mime guard (inheriting the user's role mime map silently).
	 *
	 * @param array $file One entry from $_FILES (name, tmp_name, size).
	 * @return true|\WP_Error
	 */
	public static function validate_upload( array $file ) {
		$max = (int) apply_filters( 'jetonomy_upload_max_size', min( (int) wp_max_upload_size(), 10 * MB_IN_BYTES ) );
		if ( (int) ( $file['size'] ?? 0 ) > $max ) {
			return new WP_Error(
				'jetonomy_upload_size',
				/* translators: %s: human-readable maximum file size. */
				sprintf( __( 'File is too large. Maximum size is %s.', 'jetonomy' ), size_format( $max ) ),
				array( 'status' => 400 )
			);
		}

		$allowed = (array) apply_filters(
			'jetonomy_upload_allowed_types',
			array(
				'jpg|jpeg' => 'image/jpeg',
				'png'      => 'image/png',
				'gif'      => 'image/gif',
				'webp'     => 'image/webp',
			)
		);
		unset( $allowed['svg'], $allowed['svgz'] ); // Hard exclusion regardless of filters.

		$name  = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		$check = wp_check_filetype_and_ext( (string) ( $file['tmp_name'] ?? '' ), $name, $allowed );
		if ( empty( $check['ext'] ) || empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			return new WP_Error( 'jetonomy_upload_type', __( 'This file type is not allowed.', 'jetonomy' ), array( 'status' => 400 ) );
		}

		if ( function_exists( 'finfo_open' ) && is_readable( (string) $file['tmp_name'] ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$real  = $finfo ? finfo_file( $finfo, (string) $file['tmp_name'] ) : '';
			if ( $finfo ) {
				finfo_close( $finfo );
			}
			if ( $real && ! in_array( $real, $allowed, true ) ) {
				return new WP_Error( 'jetonomy_upload_type', __( 'File contents do not match its extension.', 'jetonomy' ), array( 'status' => 400 ) );
			}
		}

		return true;
	}
}
