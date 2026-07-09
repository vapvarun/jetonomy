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
		$total   = 0;

		if ( 'post' === $type ) {
			$results = $this->search_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort );
			$total   = $this->count_posts( $wpdb, $q, $space_id, $date_from, $date_to, $author_id, $tag_slug );
		} elseif ( 'reply' === $type ) {
			$results = $this->search_replies( $wpdb, $q, $space_id, $date_from, $date_to, $author_id );
			$total   = $this->count_replies( $wpdb, $q, $space_id, $date_from, $date_to, $author_id );
		} elseif ( 'space' === $type ) {
			$results = $this->search_spaces( $wpdb, $q );
			$total   = $this->count_spaces( $wpdb, $q );
		} elseif ( 'tag' === $type ) {
			$results = $this->search_tags( $wpdb, $q );
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

		// COUNT mirrors the WHERE clause of each search_* method. Result-set
		// page size is 20 (or 10 for tags); has_more is "total exceeds what
		// we returned." When A3 lands the per-type SQL collapses into a
		// single adapter call and these count_* siblings disappear too.
		return $this->paginated_response(
			$items,
			[
				'total'    => $total,
				'has_more' => $total > count( $items ),
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
			$all_params      = array_merge( $select_params, [ $tag_slug ], $params );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id INNER JOIN {$post_tags_table} pt ON pt.post_id = p.id INNER JOIN {$tags_table} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql} ORDER BY {$order_by} LIMIT 20",
				...$all_params
			);
		} else {
			$all_params = array_merge( $select_params, $params );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY {$order_by} LIMIT 20",
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
	 * @return object[]
	 */
	private function search_replies( \wpdb $wpdb, string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null ): array {
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

		$where_sql = implode( ' AND ', $r_where );
		// Order by the same boolean MATCH score so the best topical matches
		// surface first instead of the most recent replies regardless of
		// overlap.
		$score_params = [ $boolean_q ];
		$all_params   = array_merge( $score_params, $r_params );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT r.*, MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AS match_score FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY match_score DESC, r.created_at DESC LIMIT 20",
			...$all_params
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

		// Listing gate: space SEARCH mirrors the directory — public + discoverable
		// private spaces (content stays gated), hidden withheld from non-members.
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::listing_visibility_sql( get_current_user_id() );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$spaces_table} WHERE (title LIKE %s OR description LIKE %s) AND {$vis_sql} LIMIT 20",
				$like,
				$like,
				...$vis_params
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
