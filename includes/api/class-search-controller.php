<?php
/**
 * Search REST API controller.
 *
 * @package Jetonomy
 */

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

		register_rest_route(
			$ns,
			'/search',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'search' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'q'         => [
						'type'              => 'string',
						'required'          => true,
						'minLength'         => 2,
						'sanitize_callback' => 'sanitize_text_field',
					],
					'type'      => [
						'type'    => 'string',
						'default' => 'post',
						'enum'    => [ 'post', 'reply', 'space', 'tag', 'all' ],
					],
					'space_id'  => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'date_from' => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'date_to'   => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'author_id' => [
						'type'    => 'integer',
						'minimum' => 1,
					],
					'tag'       => [
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					],
					'sort'      => [
						'type'    => 'string',
						'default' => 'relevance',
						'enum'    => [ 'relevance', 'newest', 'votes' ],
					],
				],
			]
		);
	}

	/**
	 * GET /jetonomy/v1/search — Full-text search across posts, replies, or spaces.
	 */
	public function search( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$q         = trim( (string) $request->get_param( 'q' ) );
		$type      = $request->get_param( 'type' ) ?? 'post';
		$space_id  = $request->get_param( 'space_id' ) ? absint( $request->get_param( 'space_id' ) ) : null;
		$date_from = $request->get_param( 'date_from' ) ? sanitize_text_field( $request->get_param( 'date_from' ) ) : null;
		$date_to   = $request->get_param( 'date_to' ) ? sanitize_text_field( $request->get_param( 'date_to' ) ) : null;
		$author_id = $request->get_param( 'author_id' ) ? absint( $request->get_param( 'author_id' ) ) : null;
		$tag_slug  = $request->get_param( 'tag' ) ? sanitize_text_field( $request->get_param( 'tag' ) ) : null;
		$sort      = $request->get_param( 'sort' ) ?? 'relevance';

		if ( strlen( $q ) < 2 ) {
			return $this->validation_error( __( 'Search query must be at least 2 characters.', 'jetonomy' ) );
		}

		global $wpdb;

		// Combined "all" mode returns posts, spaces, and tags grouped.
		if ( 'all' === $type || empty( $type ) ) {
			$posts  = $this->search_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort );
			$spaces = $this->search_spaces( $wpdb, $q );
			$tags   = $this->search_tags( $wpdb, $q );

			return new WP_REST_Response(
				[
					'data' => [
						'posts'  => array_map(
							function ( $row ) {
								$item         = (array) $row;
								$item['type'] = 'post';
								return $item; },
							$posts
						),
						'spaces' => array_map(
							function ( $row ) {
								$item         = (array) $row;
								$item['type'] = 'space';
								return $item; },
							$spaces
						),
						'tags'   => array_map(
							function ( $row ) {
								return (array) $row; },
							$tags
						),
					],
					'meta' => [ 'total' => count( $posts ) + count( $spaces ) + count( $tags ) ],
				],
				200
			);
		}

		$results = [];

		if ( 'post' === $type ) {
			$results = $this->search_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort );
		} elseif ( 'reply' === $type ) {
			$results = $this->search_replies( $wpdb, $q, $space_id, $date_from, $date_to, $author_id );
		} elseif ( 'space' === $type ) {
			$results = $this->search_spaces( $wpdb, $q );
		} elseif ( 'tag' === $type ) {
			$results = $this->search_tags( $wpdb, $q );
		}

		$items = array_map(
			function ( $row ) use ( $type ) {
				$item         = (array) $row;
				$item['type'] = $type;
				return $item;
			},
			$results
		);

		// TODO: implement proper search pagination with COUNT query for accurate total.
		return $this->paginated_response(
			$items,
			[
				'total'    => count( $items ),
				'has_more' => count( $items ) >= 20,
			]
		);
	}

	/**
	 * Full-text search on jt_posts with optional date, author, tag, and sort filters.
	 *
	 * @param \wpdb       $wpdb
	 * @param string      $q
	 * @param int|null    $space_id
	 * @param string|null $date_from  Date string in Y-m-d format.
	 * @param string|null $date_to    Date string in Y-m-d format.
	 * @param int|null    $author_id
	 * @param string|null $tag_slug
	 * @param string      $sort       One of 'relevance', 'newest', 'votes'.
	 * @return object[]
	 */
	private function search_posts( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, ?string $tag_slug = null, string $sort = 'relevance' ): array {
		$posts_table = table( 'posts' );

		$where  = [ 'MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE)', "p.status = 'publish'" ];
		$params = [ $q ];

		// Private post visibility: exclude private posts unless viewer is author or privileged.
		$viewer_id      = get_current_user_id();
		$is_privileged  = $space_id && \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $viewer_id, $space_id );
		if ( ! $is_privileged ) {
			if ( $viewer_id > 0 ) {
				$where[]  = '(p.is_private = 0 OR p.author_id = %d)';
				$params[] = $viewer_id;
			} else {
				$where[] = 'p.is_private = 0';
			}
		}

		if ( $space_id ) {
			$where[]  = 'p.space_id = %d';
			$params[] = $space_id;
		}
		if ( $date_from ) {
			$where[]  = 'p.created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'p.created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		if ( $author_id ) {
			$where[]  = 'p.author_id = %d';
			$params[] = $author_id;
		}

		$order_by = 'votes' === $sort ? 'p.vote_score DESC' : 'p.created_at DESC';

		/**
		 * Filters the search query args before the query is built.
		 *
		 * @since 1.0.0
		 *
		 * @param array $filter_args Keys: q, space_id, date_from, date_to, author_id, tag_slug, sort.
		 */
		$filter_args = apply_filters( 'jetonomy_search_query_args', compact( 'q', 'space_id', 'date_from', 'date_to', 'author_id', 'tag_slug', 'sort' ) );

		$where_sql = implode( ' AND ', $where );

		$spaces_table = table( 'spaces' );

		if ( $tag_slug ) {
			$tags_table      = table( 'tags' );
			$post_tags_table = table( 'post_tags' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug FROM {$posts_table} p LEFT JOIN {$spaces_table} s ON s.id = p.space_id INNER JOIN {$post_tags_table} pt ON pt.post_id = p.id INNER JOIN {$tags_table} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql} ORDER BY {$order_by} LIMIT 20",
				$tag_slug,
				...$params
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug FROM {$posts_table} p LEFT JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY {$order_by} LIMIT 20",
				...$params
			);
		}

		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Full-text search on jt_replies with optional date, author, and space filters.
	 *
	 * @param \wpdb       $wpdb
	 * @param string      $q
	 * @param int|null    $space_id
	 * @param string|null $date_from  Date string in Y-m-d format.
	 * @param string|null $date_to    Date string in Y-m-d format.
	 * @param int|null    $author_id
	 * @return object[]
	 */
	private function search_replies( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null ): array {
		$replies_table = table( 'replies' );
		$posts_table   = table( 'posts' );

		// Always JOIN posts to filter out replies on private posts.
		$r_where  = [ 'MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE)', "r.status = 'publish'", "p.status = 'publish'" ];
		$r_params = [ $q ];

		// Private post visibility for replies.
		$viewer_id     = get_current_user_id();
		$r_privileged  = $space_id && \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $viewer_id, $space_id );
		if ( ! $r_privileged ) {
			if ( $viewer_id > 0 ) {
				$r_where[]  = '(p.is_private = 0 OR p.author_id = %d)';
				$r_params[] = $viewer_id;
			} else {
				$r_where[] = 'p.is_private = 0';
			}
		}

		if ( $date_from ) {
			$r_where[]  = 'r.created_at >= %s';
			$r_params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$r_where[]  = 'r.created_at <= %s';
			$r_params[] = $date_to . ' 23:59:59';
		}
		if ( $author_id ) {
			$r_where[]  = 'r.author_id = %d';
			$r_params[] = $author_id;
		}

		if ( $space_id ) {
			$r_where[]  = 'p.space_id = %d';
			$r_params[] = $space_id;
		}

		$where_sql = implode( ' AND ', $r_where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT r.* FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id WHERE {$where_sql} LIMIT 20",
			...$r_params
		);

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

	/**
	 * LIKE search on jt_tags, ordered by post_count desc.
	 *
	 * @param \wpdb  $wpdb
	 * @param string $q
	 * @return object[]
	 */
	private function search_tags( \wpdb $wpdb, string $q ): array {
		$tags_table = table( 'tags' );
		$like       = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tags_table} WHERE name LIKE %s ORDER BY post_count DESC LIMIT 10",
				$like
			)
		) ?: [];
	}
}
