<?php
namespace Jetonomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use function Jetonomy\table;

class Search_Controller extends Base_Controller {

	protected $rest_base = 'search';

	/**
	 * Register REST routes for search.
	 */
	public function register_routes() {
		$ns = $this->namespace;

		register_rest_route( $ns, '/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'search' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'q'        => [
					'type'              => 'string',
					'required'          => true,
					'minLength'         => 2,
					'sanitize_callback' => 'sanitize_text_field',
				],
				'type'     => [
					'type'    => 'string',
					'default' => 'post',
					'enum'    => [ 'post', 'reply', 'space' ],
				],
				'space_id' => [
					'type'    => 'integer',
					'minimum' => 1,
				],
			],
		] );
	}

	/**
	 * GET /jetonomy/v1/search — Full-text search across posts, replies, or spaces.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$q        = trim( (string) $request->get_param( 'q' ) );
		$type     = $request->get_param( 'type' ) ?? 'post';
		$space_id = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;

		if ( strlen( $q ) < 2 ) {
			return $this->validation_error( __( 'Search query must be at least 2 characters.', 'jetonomy' ) );
		}

		global $wpdb;

		$results = [];

		if ( 'post' === $type ) {
			$results = $this->search_posts( $wpdb, $q, $space_id );
		} elseif ( 'reply' === $type ) {
			$results = $this->search_replies( $wpdb, $q, $space_id );
		} elseif ( 'space' === $type ) {
			$results = $this->search_spaces( $wpdb, $q );
		}

		$items = array_map(
			function( $row ) use ( $type ) {
				$item         = (array) $row;
				$item['type'] = $type;
				return $item;
			},
			$results
		);

		return $this->paginated_response( $items, [
			'total'    => count( $items ),
			'has_more' => count( $items ) === 20,
		] );
	}

	/**
	 * Full-text search on jt_posts.
	 *
	 * @param \wpdb       $wpdb
	 * @param string      $q
	 * @param int|null    $space_id
	 * @return object[]
	 */
	private function search_posts( \wpdb $wpdb, string $q, ?int $space_id ): array {
		$posts_table = table( 'posts' );

		if ( $space_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$posts_table} WHERE MATCH(title, content_plain) AGAINST(%s IN BOOLEAN MODE) AND status = 'publish' AND space_id = %d LIMIT 20",
				$q,
				$space_id
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$posts_table} WHERE MATCH(title, content_plain) AGAINST(%s IN BOOLEAN MODE) AND status = 'publish' LIMIT 20",
				$q
			);
		}

		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Full-text search on jt_replies.
	 *
	 * @param \wpdb       $wpdb
	 * @param string      $q
	 * @param int|null    $space_id
	 * @return object[]
	 */
	private function search_replies( \wpdb $wpdb, string $q, ?int $space_id ): array {
		$replies_table = table( 'replies' );

		if ( $space_id ) {
			$posts_table = table( 'posts' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT r.* FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id WHERE MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AND r.status = 'publish' AND p.space_id = %d LIMIT 20",
				$q,
				$space_id
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$replies_table} WHERE MATCH(content_plain) AGAINST(%s IN BOOLEAN MODE) AND status = 'publish' LIMIT 20",
				$q
			);
		}

		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * LIKE search on jt_spaces (public only).
	 *
	 * @param \wpdb  $wpdb
	 * @param string $q
	 * @return object[]
	 */
	private function search_spaces( \wpdb $wpdb, string $q ): array {
		$spaces_table = table( 'spaces' );
		$like         = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$spaces_table} WHERE (title LIKE %s OR description LIKE %s) AND visibility = 'public' LIMIT 20",
				$like,
				$like
			)
		) ?: [];
	}
}
