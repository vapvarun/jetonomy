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
				'permission_callback' => [ \Jetonomy\Visibility::class, 'rest_check' ],
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
					'author'    => [
						'type'              => 'string',
						'description'       => 'Author name or username; resolved to author_id server-side (headless parity with the search UI).',
						'sanitize_callback' => 'sanitize_text_field',
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
					'limit'     => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Page size (1-50, default 20). No default is declared here so an unsent value can fall back to the per_page alias.',
					],
					'offset'    => [
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Row offset for pagination.',
					],
					'per_page'  => [
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => 50,
						'description' => 'Alias for limit — kept for existing web callers (assets/js/header.js) that already send per_page.',
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
		// Headless parity with the search UI: accept an author name/username and
		// resolve it to an id (exact login, then display-name/nicename match).
		// `author_id` wins if both are sent.
		$author_name = $request->get_param( 'author' ) ? sanitize_text_field( $request->get_param( 'author' ) ) : '';
		if ( ! $author_id && '' !== $author_name ) {
			$author_user = get_user_by( 'login', $author_name );
			if ( ! $author_user ) {
				$author_matches = get_users(
					array(
						'search'         => '*' . $author_name . '*',
						'search_columns' => array( 'display_name', 'user_login', 'user_nicename' ),
						'number'         => 1,
						'fields'         => array( 'ID' ),
					)
				);
				$author_user    = $author_matches ? $author_matches[0] : null;
			}
			if ( $author_user ) {
				$author_id = (int) $author_user->ID;
			}
		}
		$tag_slug = $request->get_param( 'tag' ) ? sanitize_text_field( $request->get_param( 'tag' ) ) : null;
		$sort     = $request->get_param( 'sort' ) ?? 'relevance';

		if ( strlen( $q ) < 2 ) {
			return $this->validation_error( __( 'Search query must be at least 2 characters.', 'jetonomy' ) );
		}

		// Pagination — mirrors Feed_Controller::list_items() (get_pagination() +
		// clamp to 1..50). `per_page` is accepted as an alias for `limit`: the
		// header search overlay (assets/js/header.js) already sends per_page,
		// and the composer typeahead (assets/js/composer.js) already sends
		// limit — both were previously silently dropped by the route schema.
		//
		// Big-site note: limit is capped at 50; a deep OFFSET on a FULLTEXT+JOIN
		// query is O(offset), which is fine at ~2000 rows. A keyset cursor would
		// only work for sort=newest (relevance has no monotonic key to page on),
		// so we deliberately do not half-build one here.
		$pagination = $this->get_pagination( $request );
		$limit_raw  = $request->get_param( 'limit' );
		if ( null === $limit_raw || '' === $limit_raw ) {
			$per_page = $request->get_param( 'per_page' );
			if ( null !== $per_page && '' !== $per_page ) {
				$limit_raw = $per_page;
			}
		}
		$limit  = max( 1, min( 50, (int) ( $limit_raw ?? $pagination['limit'] ) ) );
		$offset = max( 0, (int) $pagination['offset'] );

		global $wpdb;

		// Combined "all" mode returns posts, spaces, and tags grouped. Limit/offset
		// apply to the posts group only — spaces/tags are a header slice (a fixed
		// preview list), not a paginated collection in this mode.
		if ( 'all' === $type || empty( $type ) ) {
			$posts        = $this->search_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort, $limit, $offset );
			$posts_total  = $this->count_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug );
			$spaces       = $this->search_spaces( $wpdb, $q );
			$spaces_total = $this->count_spaces( $wpdb, $q );
			$tags         = $this->search_tags( $wpdb, $q );
			$tags_total   = $this->count_tags( $wpdb, $q );

			$response = new WP_REST_Response(
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
					'meta' => [
						// Back-compat: `total` previously meant "rows returned across
						// all three groups combined." It now means the posts group's
						// real total (matching every other search mode + the new
						// X-WP-Total/X-WP-TotalPages headers). `totals` carries the
						// real per-group counts so callers than need the old combined
						// number can add them up themselves.
						'total'    => $posts_total,
						'totals'   => [
							'posts'  => $posts_total,
							'spaces' => $spaces_total,
							'tags'   => $tags_total,
						],
						'offset'   => $offset,
						'has_more' => ( $offset + count( $posts ) ) < $posts_total,
					],
				],
				200
			);

			$response->header( 'X-WP-Total', (string) $posts_total );
			$response->header( 'X-WP-TotalPages', (string) (int) ceil( $posts_total / max( 1, $limit ) ) );

			return $response;
		}

		$results = [];
		$total   = 0;

		if ( 'post' === $type ) {
			$results = $this->search_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort, $limit, $offset );
			$total   = $this->count_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug );
		} elseif ( 'reply' === $type ) {
			$results = $this->search_replies( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $limit, $offset );
			$total   = $this->count_replies( $wpdb, $q, $space_id, $date_from, $date_to, $author_id );
		} elseif ( 'space' === $type ) {
			$results = $this->search_spaces( $wpdb, $q, $limit, $offset );
			$total   = $this->count_spaces( $wpdb, $q );
		} elseif ( 'tag' === $type ) {
			$results = $this->search_tags( $wpdb, $q, $limit, $offset );
			$total   = $this->count_tags( $wpdb, $q );
		}

		$items = array_map(
			function ( $row ) use ( $type ) {
				$item         = (array) $row;
				$item['type'] = $type;
				return $item;
			},
			$results
		);

		// COUNT mirrors the WHERE clause of each search_* method. paginated_response()
		// computes has_more from offset + count(items) vs total (base-controller.php),
		// which is correct on every page — the previous hand-rolled
		// `$total > count($items)` stayed true forever past page 1. When A3 lands
		// the per-type SQL collapses into a single adapter call and these count_*
		// siblings disappear too.
		$response = $this->paginated_response(
			$items,
			[
				'total'  => $total,
				'offset' => $offset,
			]
		);

		$response->header( 'X-WP-TotalPages', (string) (int) ceil( $total / max( 1, $limit ) ) );

		return $response;
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
	 * @param int         $limit      Page size (default 20, callers clamp to 1..50).
	 * @param int         $offset     Row offset.
	 * @return object[]
	 */
	private function search_posts( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, ?string $tag_slug = null, string $sort = 'relevance', int $limit = 20, int $offset = 0 ): array {
		$posts_table = table( 'posts' );

		// Build a BOOLEAN-MODE query string that treats each meaningful token
		// as required with a prefix wildcard. Without the leading `+` each
		// token is OR'd, which matches any post sharing a single word with the
		// query ("test" bringing back every post with "test" anywhere). The
		// user-visible symptom was the new-post similar-topics typeahead
		// returning unrelated rows; fixing it here also fixes the general
		// search page, where the old natural-mode fallback was equally loose.
		//
		// Tokens shorter than 4 chars are dropped: they are below typical
		// innodb_ft_min_token_size AND dominated by stop words. If all tokens
		// drop out the raw query is passed through, preserving the old
		// behavior for short queries that would otherwise return nothing.
		$boolean_q = \Jetonomy\Search\Fulltext_Search::build_boolean_query( $q );

		$where  = [ 'MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE)', "p.status = 'publish'" ];
		$params = [ $boolean_q ];

		// Private post visibility: exclude private posts unless viewer is author or
		// privileged. Shared guard (single source of truth) — see Fulltext_Search.
		[ $vis_sql, $vis_params ] = \Jetonomy\Search\Fulltext_Search::visibility_clause( $space_id, 'p' );
		if ( '' !== $vis_sql ) {
			$where[] = $vis_sql;
			$params  = array_merge( $params, $vis_params );
		}

		// Space-level content gate: never surface a post whose parent space the
		// viewer cannot read (private/hidden unless member). Composes with the
		// per-post is_private guard above. Single source of truth:
		// Space::content_visibility_sql (mirrors Permission_Engine::can read).
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $space_vis_sql ) {
			$where[] = $space_vis_sql;
			$params  = array_merge( $params, $space_vis_params );
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
			$where[]  = 'p.author_id = %d AND p.is_anonymous = 0';
			$params[] = $author_id;
		}

		// Hide posts from users the viewer has blocked. no-op for guests/no-blocks.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$where[] = $block_sql;
		}

		// Order:
		// - relevance: the MATCH score against the same boolean query (previously
		// defaulted to created_at DESC, which meant relevance sort was never
		// actually sorting by relevance).
		// - newest:    created_at DESC.
		// - votes:     vote_score DESC.
		switch ( $sort ) {
			case 'votes':
				$order_by = 'p.vote_score DESC';
				break;
			case 'newest':
				$order_by = 'p.created_at DESC';
				break;
			case 'relevance':
			default:
				$order_by    = 'match_score DESC, p.created_at DESC';
				$order_match = true;
				break;
		}

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

		// When ordering by relevance we need the MATCH score as a selectable
		// column. Repeat the same AGAINST(...) expression so MySQL can reuse
		// the index; the extra params slot is prepended before $params.
		$select_extra  = '';
		$select_params = [];
		if ( ! empty( $order_match ) ) {
			$select_extra  = ', MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE) AS match_score';
			$select_params = [ $boolean_q ];
		}

		if ( $tag_slug ) {
			$tags_table      = table( 'tags' );
			$post_tags_table = table( 'post_tags' );
			// Param order matters: select_params, tag_slug, where params, then
			// limit/offset LAST — the new placeholders are last in the SQL below.
			$all_params = array_merge( $select_params, [ $tag_slug ], $params, [ $limit, $offset ] );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id INNER JOIN {$post_tags_table} pt ON pt.post_id = p.id INNER JOIN {$tags_table} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				...$all_params
			);
		} else {
			$all_params = array_merge( $select_params, $params, [ $limit, $offset ] );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				...$all_params
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
	 * @param int         $limit      Page size (default 20, callers clamp to 1..50).
	 * @param int         $offset     Row offset.
	 * @return object[]
	 */
	private function search_replies( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, int $limit = 20, int $offset = 0 ): array {
		$replies_table = table( 'replies' );
		$posts_table   = table( 'posts' );
		$spaces_table  = table( 'spaces' );

		// Same AND-required prefix boolean as search_posts so the reply
		// search widget and the Abilities API adapter rank replies by
		// topical overlap instead of OR-matching every shared word.
		$boolean_q = \Jetonomy\Search\Fulltext_Search::build_boolean_query( $q );

		// Always JOIN posts to filter out replies on private posts.
		$r_where  = [ 'MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE)', "r.status = 'publish'", "p.status = 'publish'" ];
		$r_params = [ $boolean_q ];

		// Private post visibility for replies — shared guard (single source of truth).
		[ $r_vis_sql, $r_vis_params ] = \Jetonomy\Search\Fulltext_Search::visibility_clause( $space_id, 'p' );
		if ( '' !== $r_vis_sql ) {
			$r_where[] = $r_vis_sql;
			$r_params  = array_merge( $r_params, $r_vis_params );
		}

		// Space-level content gate (parent post's space). See search_posts().
		[ $r_space_vis_sql, $r_space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $r_space_vis_sql ) {
			$r_where[] = $r_space_vis_sql;
			$r_params  = array_merge( $r_params, $r_space_vis_params );
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
			$r_where[]  = 'r.author_id = %d AND r.is_anonymous = 0';
			$r_params[] = $author_id;
		}

		if ( $space_id ) {
			$r_where[]  = 'p.space_id = %d';
			$r_params[] = $space_id;
		}

		// Hide replies AUTHORED BY a blocked user. Deliberately not filtering on
		// the parent post's author — that would over-block other people's useful
		// replies inside a blocked user's thread. no-op for guests/no-blocks.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'r', 'author_id' );
		if ( '' !== $block_sql ) {
			$r_where[] = $block_sql;
		}

		$where_sql = implode( ' AND ', $r_where );
		// Order by the same boolean MATCH score so the best topical matches
		// surface first instead of the most recent replies regardless of
		// overlap.
		$score_params = [ $boolean_q ];
		$all_params   = array_merge( $score_params, $r_params, [ $limit, $offset ] );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT r.*, MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AS match_score FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY match_score DESC, r.created_at DESC LIMIT %d OFFSET %d",
			...$all_params
		);

		return $wpdb->get_results( $sql ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * LIKE search on jt_spaces (public only).
	 *
	 * @param \wpdb  $wpdb
	 * @param string $q
	 * @param int    $limit  Page size (default 20, callers clamp to 1..50).
	 * @param int    $offset Row offset.
	 * @return object[]
	 */
	private function search_spaces( \wpdb $wpdb, string $q, int $limit = 20, int $offset = 0 ): array {
		$spaces_table = table( 'spaces' );
		$like         = '%' . $wpdb->esc_like( $q ) . '%';

		// Listing gate: space SEARCH mirrors the directory — public + discoverable
		// private spaces (content stays gated), hidden withheld from non-members.
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::listing_visibility_sql( get_current_user_id() );

		$all_params = array_merge( [ $like, $like ], $vis_params, [ $limit, $offset ] );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$spaces_table} WHERE (title LIKE %s OR description LIKE %s) AND {$vis_sql} LIMIT %d OFFSET %d",
				...$all_params
			)
		) ?: [];
	}

	/**
	 * LIKE search on jt_tags, ordered by post_count desc.
	 *
	 * @param \wpdb  $wpdb
	 * @param string $q
	 * @param int    $limit  Page size (default 10, callers clamp to 1..50).
	 * @param int    $offset Row offset.
	 * @return object[]
	 */
	private function search_tags( \wpdb $wpdb, string $q, int $limit = 10, int $offset = 0 ): array {
		$tags_table = table( 'tags' );
		$like       = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tags_table} WHERE name LIKE %s ORDER BY post_count DESC LIMIT %d OFFSET %d",
				$like,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Count companion to search_posts() — same WHERE clause, no LIMIT/ORDER.
	 * Mirrors viewer-aware visibility + tag/date/author filters so meta.total
	 * matches what the user would page through.
	 *
	 * @param \wpdb       $wpdb
	 * @param string      $q
	 * @param int|null    $space_id
	 * @param string|null $date_from
	 * @param string|null $date_to
	 * @param int|null    $author_id
	 * @param string|null $tag_slug
	 * @return int
	 */
	private function count_posts( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, ?string $tag_slug = null ): int {
		$posts_table  = table( 'posts' );
		$spaces_table = table( 'spaces' );
		$boolean_q    = \Jetonomy\Search\Fulltext_Search::build_boolean_query( $q );

		$where  = [ 'MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE)', "p.status = 'publish'" ];
		$params = [ $boolean_q ];

		// Shared visibility guard (single source of truth) — see Fulltext_Search.
		[ $vis_sql, $vis_params ] = \Jetonomy\Search\Fulltext_Search::visibility_clause( $space_id, 'p' );
		if ( '' !== $vis_sql ) {
			$where[] = $vis_sql;
			$params  = array_merge( $params, $vis_params );
		}

		// Space-level content gate — mirror search_posts() so the total never
		// counts posts in spaces the viewer cannot read.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $space_vis_sql ) {
			$where[] = $space_vis_sql;
			$params  = array_merge( $params, $space_vis_params );
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
			$where[]  = 'p.author_id = %d AND p.is_anonymous = 0';
			$params[] = $author_id;
		}

		// Must mirror search_posts() exactly or meta.total disagrees with the rows.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$where[] = $block_sql;
		}

		$where_sql = implode( ' AND ', $where );

		if ( $tag_slug ) {
			$tags_table      = table( 'tags' );
			$post_tags_table = table( 'post_tags' );
			$all_params      = array_merge( [ $tag_slug ], $params );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id INNER JOIN {$post_tags_table} pt ON pt.post_id = p.id INNER JOIN {$tags_table} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql}",
					...$all_params
				)
			);
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql}",
				...$params
			)
		);
	}

	/**
	 * Count companion to search_replies() — viewer-aware private-post filter
	 * preserved so the total never exposes private content the viewer can't read.
	 */
	private function count_replies( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null ): int {
		$replies_table = table( 'replies' );
		$posts_table   = table( 'posts' );
		$spaces_table  = table( 'spaces' );
		$boolean_q     = \Jetonomy\Search\Fulltext_Search::build_boolean_query( $q );

		$r_where  = [ 'MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE)', "r.status = 'publish'", "p.status = 'publish'" ];
		$r_params = [ $boolean_q ];

		// Shared visibility guard (single source of truth) — see Fulltext_Search.
		[ $r_vis_sql, $r_vis_params ] = \Jetonomy\Search\Fulltext_Search::visibility_clause( $space_id, 'p' );
		if ( '' !== $r_vis_sql ) {
			$r_where[] = $r_vis_sql;
			$r_params  = array_merge( $r_params, $r_vis_params );
		}

		// Space-level content gate — mirror search_replies() so the total never
		// counts replies on posts in spaces the viewer cannot read.
		[ $r_space_vis_sql, $r_space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $r_space_vis_sql ) {
			$r_where[] = $r_space_vis_sql;
			$r_params  = array_merge( $r_params, $r_space_vis_params );
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
			$r_where[]  = 'r.author_id = %d AND r.is_anonymous = 0';
			$r_params[] = $author_id;
		}
		if ( $space_id ) {
			$r_where[]  = 'p.space_id = %d';
			$r_params[] = $space_id;
		}

		// Must mirror search_replies() exactly or meta.total disagrees with the rows.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'r', 'author_id' );
		if ( '' !== $block_sql ) {
			$r_where[] = $block_sql;
		}

		$where_sql = implode( ' AND ', $r_where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql}",
				...$r_params
			)
		);
	}

	/**
	 * Count companion to search_spaces() — public-only, same LIKE filter.
	 */
	private function count_spaces( \wpdb $wpdb, string $q ): int {
		$spaces_table = table( 'spaces' );
		$like         = '%' . $wpdb->esc_like( $q ) . '%';

		// Listing gate — mirror search_spaces() so the total matches the rows.
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::listing_visibility_sql( get_current_user_id() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$spaces_table} WHERE (title LIKE %s OR description LIKE %s) AND {$vis_sql}",
				$like,
				$like,
				...$vis_params
			)
		);
	}

	/**
	 * Count companion to search_tags() — same LIKE filter.
	 */
	private function count_tags( \wpdb $wpdb, string $q ): int {
		$tags_table = table( 'tags' );
		$like       = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tags_table} WHERE name LIKE %s",
				$like
			)
		);
	}
}
