<?php
/**
 * Full-text search adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Search;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Adapters\Search_Query_Adapter;
use function Jetonomy\table;

class Fulltext_Search implements Search_Query_Adapter {

	public function is_active(): bool {
		return true; // Always available — MySQL FULLTEXT is built-in
	}

	public function index( string $object_type, int $object_id, array $data ): void {
		// FULLTEXT is automatic — content_plain column is already indexed
		// This method exists for external adapters (Meilisearch, etc.)
	}

	/**
	 * Narrow Search_Adapter contract, kept for Abilities and third-party
	 * callers: a keyword search of one type. Same rows as query().
	 */
	public function search( string $query, string $type = 'post', ?int $space_id = null, int $limit = 20, int $offset = 0 ): array {
		if ( strlen( trim( $query ) ) < 2 || ! in_array( $type, array( 'post', 'reply', 'space' ), true ) ) {
			return array();
		}
		return $this->query(
			array(
				'type'       => $type,
				'q'          => $query,
				'space_id'   => $space_id,
				'limit'      => $limit,
				'offset'     => $offset,
				'with_total' => false,
			)
		)['items'];
	}

	/**
	 * The one MySQL search behind REST /search, the search page, the app and
	 * Abilities, so short-term handling, ordering and space rules cannot drift
	 * between copies.
	 *
	 * @param array $args See Search_Query_Adapter::query().
	 * @return array{items: object[], total: int}
	 */
	public function query( array $args ): array {
		$args = wp_parse_args(
			$args,
			array(
				'type'       => 'post',
				'q'          => '',
				'space_id'   => null,
				'date_from'  => null,
				'date_to'    => null,
				'author_id'  => null,
				'tag_slug'   => null,
				'sort'       => 'relevance',
				'limit'      => 20,
				'offset'     => 0,
				'with_total' => true,
			)
		);

		/**
		 * Filters the search query args before the query is built.
		 *
		 * Since 2.0.1 the returned values are used (before that the filter fired
		 * but its result was discarded), and the array also carries type, limit,
		 * offset and with_total.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Keys: type, q, space_id, date_from, date_to, author_id, tag_slug, sort, limit, offset, with_total.
		 */
		$args = (array) apply_filters( 'jetonomy_search_query_args', $args );

		$q         = trim( (string) $args['q'] );
		$space_id  = $args['space_id'] ? (int) $args['space_id'] : null;
		$date_from = $args['date_from'] ? (string) $args['date_from'] : null;
		$date_to   = $args['date_to'] ? (string) $args['date_to'] : null;
		$author_id = $args['author_id'] ? (int) $args['author_id'] : null;
		$tag_slug  = $args['tag_slug'] ? (string) $args['tag_slug'] : null;
		$sort      = in_array( $args['sort'], array( 'relevance', 'newest', 'votes' ), true ) ? $args['sort'] : 'relevance';
		$limit     = max( 1, (int) $args['limit'] );
		$offset    = max( 0, (int) $args['offset'] );
		$count     = ! empty( $args['with_total'] );

		switch ( $args['type'] ) {
			case 'reply':
				return array(
					'items' => $this->search_replies( $q, $space_id, $date_from, $date_to, $author_id, $limit, $offset ),
					'total' => $count ? $this->count_replies( $q, $space_id, $date_from, $date_to, $author_id ) : 0,
				);
			case 'space':
				return array(
					'items' => $this->search_spaces( $q, $limit, $offset ),
					'total' => $count ? $this->count_spaces( $q ) : 0,
				);
			case 'tag':
				return array(
					'items' => $this->search_tags( $q, $limit, $offset ),
					'total' => $count ? $this->count_tags( $q ) : 0,
				);
			case 'post':
			default:
				return array(
					'items' => $this->search_posts( $q, $space_id, $date_from, $date_to, $author_id, $tag_slug, $sort, $limit, $offset ),
					'total' => $count ? $this->count_posts( $q, $space_id, $date_from, $date_to, $author_id, $tag_slug ) : 0,
				);
		}
	}

	public function delete( string $object_type, int $object_id ): void {
		// FULLTEXT is automatic — deletion handled by model
	}

	/**
	 * Private-post visibility guard, shared by every search query path.
	 *
	 * Returns a [ where_fragment, params ] pair that excludes private posts the
	 * current viewer is not allowed to see. This is the single source of truth
	 * for search visibility — query() applies it to posts and replies, and every
	 * entry point (REST, the search page, the app, Abilities) runs query(), so no path can leak a
	 * private post (the bug this closes: a filtered or unfiltered search returned
	 * other members' private posts because only the REST controller carried the
	 * guard).
	 *
	 * @param int|null $space_id Space context, or null for a global search.
	 * @param string   $alias    Table alias for the posts table (e.g. 'p'); '' if unaliased.
	 * @return array{0:string,1:array} Empty fragment when the viewer is privileged in the space.
	 */
	public static function visibility_clause( ?int $space_id, string $alias = '' ): array {
		$col       = '' !== $alias ? $alias . '.' : '';
		$viewer_id = get_current_user_id();

		$is_privileged = $space_id
			&& \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $viewer_id, $space_id );
		if ( $is_privileged ) {
			return array( '', array() );
		}

		if ( $viewer_id > 0 ) {
			return array( "({$col}is_private = 0 OR {$col}author_id = %d)", array( $viewer_id ) );
		}

		return array( "{$col}is_private = 0", array() );
	}

	/**
	 * Private-reply guard for reply search, the SQL twin of
	 * Permission_Engine::can_read_reply(): a private reply is found only by its
	 * author, the topic author, space staff and site admins. Expects aliases
	 * r (replies) and p (posts).
	 *
	 * @param int|null $space_id Space context, or null for a global search.
	 * @return array{0:string,1:array} Empty fragment when the viewer may read every private reply.
	 */
	public static function reply_visibility_clause( ?int $space_id ): array {
		$viewer_id = get_current_user_id();
		if ( user_can( $viewer_id, 'manage_options' )
			|| ( $space_id && \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $viewer_id, $space_id ) ) ) {
			return array( '', array() );
		}
		if ( $viewer_id > 0 ) {
			return array( '(r.is_private = 0 OR r.author_id = %d OR p.author_id = %d)', array( $viewer_id, $viewer_id ) );
		}
		return array( 'r.is_private = 0', array() );
	}

	/**
	 * Full-text search on jt_posts with optional date, author, tag, and sort filters.
	 *
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
	private function search_posts( string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, ?string $tag_slug = null, string $sort = 'relevance', int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$posts_table = table( 'posts' );

		// Build a BOOLEAN-MODE query string that treats each meaningful token
		// as required with a prefix wildcard. Without the leading `+` each
		// token is OR'd, which matches any post sharing a single word with the
		// query ("test" bringing back every post with "test" anywhere), which makes the
		// new-post similar-topics typeahead and the search page return unrelated rows.
		//
		// Tokens shorter than 4 chars are dropped: they are below typical
		// innodb_ft_min_token_size AND dominated by stop words. If all tokens
		// drop out the raw query is passed through, so short queries still return rows.
		// Scope-only listing (empty $q, e.g. a tag page): skip the full-text MATCH
		// entirely and rank by recency — there is nothing to score against, and
		// forcing a MATCH on an empty query would return zero rows.
		$boolean_q = '';
		$used_like = false;
		$where     = array( "p.status = 'publish'" );
		$params    = array();
		if ( '' !== $q ) {
			$boolean_q                                = self::build_boolean_query( $q );
			[ $match_sql, $match_params, $used_like ] = self::match_predicate( array( 'p.title', 'p.content_plain' ), $q );
			$where[]                                  = $match_sql;
			$params                                   = $match_params;
		} elseif ( 'relevance' === $sort ) {
			// No query to rank against — recency is the only sensible order.
			$sort = 'newest';
		}

		// Private post visibility: exclude private posts unless viewer is author or
		// privileged. Shared guard (single source of truth) — see Fulltext_Search.
		[ $vis_sql, $vis_params ] = self::visibility_clause( $space_id, 'p' );
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
		// - relevance: the MATCH score against the same boolean query.
		// - newest:    created_at DESC.
		// - votes:     votes, then replies (Post::popular_order()).
		switch ( $sort ) {
			case 'votes':
				$order_by = \Jetonomy\Models\Post::popular_order( 'p' );
				break;
			case 'newest':
				$order_by = 'p.created_at DESC, p.id DESC';
				break;
			case 'relevance':
			default:
				if ( $used_like ) {
					// The LIKE fallback (short query, below the FULLTEXT token
					// floor) produces no match score, so there is nothing to rank
					// on — fall back to recency rather than selecting a MATCH
					// expression that would return 0 for every row.
					$order_by = 'p.created_at DESC, p.id DESC';
					break;
				}
				$order_by    = 'match_score DESC, p.created_at DESC, p.id DESC';
				$order_match = true;
				break;
		}

		$where_sql = implode( ' AND ', $where );

		$spaces_table = table( 'spaces' );

		// When ordering by relevance we need the MATCH score as a selectable
		// column. Repeat the same AGAINST(...) expression so MySQL can reuse
		// the index; the extra params slot is prepended before $params.
		$select_extra  = '';
		$select_params = array();
		if ( ! empty( $order_match ) ) {
			$select_extra  = ', MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE) AS match_score';
			$select_params = array( $boolean_q );
		}

		if ( $tag_slug ) {
			$tags_table      = table( 'tags' );
			$post_tags_table = table( 'post_tags' );
			// Param order matters: select_params, tag_slug, where params, then
			// limit/offset LAST — the new placeholders are last in the SQL below.
			$all_params = array_merge( $select_params, array( $tag_slug ), $params, array( $limit, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id INNER JOIN {$post_tags_table} pt ON pt.post_id = p.id INNER JOIN {$tags_table} t ON t.id = pt.tag_id AND t.slug = %s WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				...$all_params
			);
		} else {
			$all_params = array_merge( $select_params, $params, array( $limit, $offset ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT p.*, s.title AS space_title, s.slug AS space_slug{$select_extra} FROM {$posts_table} p INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY {$order_by} LIMIT %d OFFSET %d",
				...$all_params
			);
		}

		return $wpdb->get_results( $sql ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Full-text search on jt_replies with optional date, author, and space filters.
	 *
	 * @param string      $q
	 * @param int|null    $space_id
	 * @param string|null $date_from  Date string in Y-m-d format.
	 * @param string|null $date_to    Date string in Y-m-d format.
	 * @param int|null    $author_id
	 * @param int         $limit      Page size (default 20, callers clamp to 1..50).
	 * @param int         $offset     Row offset.
	 * @return object[]
	 */
	private function search_replies( string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$replies_table = table( 'replies' );
		$posts_table   = table( 'posts' );
		$spaces_table  = table( 'spaces' );

		// Same AND-required prefix boolean as search_posts so the reply
		// search widget and the Abilities API adapter rank replies by
		// topical overlap instead of OR-matching every shared word.
		$boolean_q = self::build_boolean_query( $q );

		// Always JOIN posts to filter out replies on private posts.
		[ $r_match_sql, $r_match_params, $r_used_like ] = self::match_predicate( array( 'r.content_plain' ), $q );
		$r_where                                        = array( $r_match_sql, "r.status = 'publish'", "p.status = 'publish'" );
		$r_params                                       = $r_match_params;

		// Private post visibility for replies — shared guard (single source of truth).
		[ $r_vis_sql, $r_vis_params ] = self::visibility_clause( $space_id, 'p' );
		if ( '' !== $r_vis_sql ) {
			$r_where[] = $r_vis_sql;
			$r_params  = array_merge( $r_params, $r_vis_params );
		}
		[ $r_priv_sql, $r_priv_params ] = self::reply_visibility_clause( $space_id );
		if ( '' !== $r_priv_sql ) {
			$r_where[] = $r_priv_sql;
			$r_params  = array_merge( $r_params, $r_priv_params );
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
		$score_params = array( $boolean_q );
		$all_params   = array_merge( $score_params, $r_params, array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT r.*, MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AS match_score FROM {$replies_table} r INNER JOIN {$posts_table} p ON p.id = r.post_id INNER JOIN {$spaces_table} s ON s.id = p.space_id WHERE {$where_sql} ORDER BY match_score DESC, r.created_at DESC LIMIT %d OFFSET %d",
			...$all_params
		);

		return $wpdb->get_results( $sql ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * LIKE search on jt_spaces (public only).
	 *
	 * @param string $q
	 * @param int    $limit  Page size (default 20, callers clamp to 1..50).
	 * @param int    $offset Row offset.
	 * @return object[]
	 */
	private function search_spaces( string $q, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$spaces_table = table( 'spaces' );
		$like         = '%' . $wpdb->esc_like( $q ) . '%';

		// Listing gate: space SEARCH mirrors the directory — public + discoverable
		// private spaces (content stays gated), hidden withheld from non-members.
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::listing_visibility_sql( get_current_user_id() );

		$all_params = array_merge( array( $like, $like ), $vis_params, array( $limit, $offset ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$spaces_table} WHERE (title LIKE %s OR description LIKE %s) AND {$vis_sql} ORDER BY member_count DESC, id DESC LIMIT %d OFFSET %d",
				...$all_params
			)
		) ?: array();
	}

	/**
	 * LIKE search on jt_tags, ordered by post_count desc.
	 *
	 * @param string $q
	 * @param int    $limit  Page size (default 10, callers clamp to 1..50).
	 * @param int    $offset Row offset.
	 * @return object[]
	 */
	private function search_tags( string $q, int $limit = 10, int $offset = 0 ): array {
		global $wpdb;
		$tags_table = table( 'tags' );
		$like       = '%' . $wpdb->esc_like( $q ) . '%';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$tags = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$tags_table} WHERE name LIKE %s ORDER BY post_count DESC LIMIT %d OFFSET %d",
				$like,
				$limit,
				$offset
			)
		) ?: array();

		// Report what this viewer can read, not the stored all-spaces count.
		$counts = \Jetonomy\Models\Post::count_by_tags_visible( wp_list_pluck( $tags, 'id' ) );
		foreach ( $tags as $tag ) {
			$tag->post_count = $counts[ (int) $tag->id ] ?? 0;
		}
		return $tags;
	}

	/**
	 * Count companion to search_posts() — same WHERE clause, no LIMIT/ORDER.
	 * Mirrors viewer-aware visibility + tag/date/author filters so meta.total
	 * matches what the user would page through.
	 *
	 * @param string      $q
	 * @param int|null    $space_id
	 * @param string|null $date_from
	 * @param string|null $date_to
	 * @param int|null    $author_id
	 * @param string|null $tag_slug
	 * @return int
	 */
	private function count_posts( string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null, ?string $tag_slug = null ): int {
		global $wpdb;
		$posts_table  = table( 'posts' );
		$spaces_table = table( 'spaces' );

		// Mirror search_posts(): skip the MATCH for a scope-only listing (empty $q)
		// so meta.total counts the tag/space/author rows, not zero.
		$where  = array( "p.status = 'publish'" );
		$params = array();
		if ( '' !== $q ) {
			[ $match_sql, $match_params ] = self::match_predicate( array( 'p.title', 'p.content_plain' ), $q );
			$where[]                      = $match_sql;
			$params                       = $match_params;
		}

		// Shared visibility guard (single source of truth) — see Fulltext_Search.
		[ $vis_sql, $vis_params ] = self::visibility_clause( $space_id, 'p' );
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
			$all_params      = array_merge( array( $tag_slug ), $params );
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
	private function count_replies( string $q, ?int $space_id, ?string $date_from = null, ?string $date_to = null, ?int $author_id = null ): int {
		global $wpdb;
		$replies_table = table( 'replies' );
		$posts_table   = table( 'posts' );
		$spaces_table  = table( 'spaces' );
		$boolean_q     = self::build_boolean_query( $q );

		[ $r_match_sql, $r_match_params, $r_used_like ] = self::match_predicate( array( 'r.content_plain' ), $q );
		$r_where                                        = array( $r_match_sql, "r.status = 'publish'", "p.status = 'publish'" );
		$r_params                                       = $r_match_params;

		// Shared visibility guard (single source of truth) — see Fulltext_Search.
		[ $r_vis_sql, $r_vis_params ] = self::visibility_clause( $space_id, 'p' );
		if ( '' !== $r_vis_sql ) {
			$r_where[] = $r_vis_sql;
			$r_params  = array_merge( $r_params, $r_vis_params );
		}
		[ $r_priv_sql, $r_priv_params ] = self::reply_visibility_clause( $space_id );
		if ( '' !== $r_priv_sql ) {
			$r_where[] = $r_priv_sql;
			$r_params  = array_merge( $r_params, $r_priv_params );
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
	private function count_spaces( string $q ): int {
		global $wpdb;
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
	private function count_tags( string $q ): int {
		global $wpdb;
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

	/**
	 * Turn a free-text query into a BOOLEAN MODE expression where each
	 * token of length >= 4 is AND-required with a prefix wildcard. Strips
	 * FULLTEXT operators from user input so an accidental "+" / "-" / quote
	 * cannot silently change the semantics. Short or stop-word-only queries
	 * fall back to the raw string to preserve prior behavior rather than
	 * returning an empty set.
	 *
	 * Shared helper: both Fulltext_Search::search() and the REST
	 * Search_Controller use this so posts + replies + Abilities API
	 * adapters all apply the same AND-required ranking semantics.
	 */
	public static function build_boolean_query( string $query ): string {
		$cleaned = preg_replace( '/[+\-<>()~*"@]/', ' ', $query );
		$tokens  = preg_split( '/\s+/', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY ) ?: array();

		$required = array();
		foreach ( $tokens as $t ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t );
			if ( $len < self::MIN_TOKEN_LEN ) {
				continue;
			}
			$required[] = '+' . $t . '*';
		}

		return $required ? implode( ' ', $required ) : $query;
	}

	/**
	 * Shortest token the FULLTEXT index will actually match.
	 *
	 * Conservative on purpose: InnoDB's innodb_ft_min_token_size defaults to 3
	 * and MyISAM's ft_min_word_len to 4, so 4 is the value correct on both.
	 */
	public const MIN_TOKEN_LEN = 4;

	/**
	 * True when at least one token in $query is long enough to be indexed.
	 */
	public static function has_indexable_token( string $query ): bool {
		$cleaned = preg_replace( '/[+\-<>()~*"@]/', ' ', $query );
		foreach ( preg_split( '/\s+/', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $t ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t );
			if ( $len >= self::MIN_TOKEN_LEN ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Build the text-matching predicate for a search query.
	 *
	 * Returns [ sql, params, used_like ].
	 *
	 * build_boolean_query() drops every token below MIN_TOKEN_LEN and, when
	 * nothing survives, hands the RAW query to MATCH ... AGAINST anyway — which
	 * can never match, because the index holds no tokens that short. The result
	 * was a silent empty result set: `q=QA` returned 0 against 26 posts titled
	 * "QA Post N", while `q=Post` returned all 26. Short queries are common in a
	 * forum (acronyms, product codes, "v2"), so members reasonably concluded
	 * search was broken.
	 *
	 * Below the floor we scan with LIKE instead. That cannot use the FULLTEXT
	 * index, so it matches TITLE columns only: body columns (content_plain)
	 * are the large ones, and the composer typeahead sends 2-3 character
	 * queries on every keystroke, which made a LIKE over every topic and reply
	 * body a full scan per keystroke on a large community. An acronym or
	 * product code ("QA", "v2") is still found in titles; a column list with
	 * no title (reply bodies) matches nothing below the floor.
	 *
	 * @param string[] $columns Fully-qualified column names to match against.
	 * @param string   $q       Raw user query.
	 * @return array{0:string,1:array,2:bool}
	 */
	public static function match_predicate( array $columns, string $q ): array {
		if ( self::has_indexable_token( $q ) ) {
			return array(
				'MATCH(' . implode( ', ', $columns ) . ') AGAINST(%s IN BOOLEAN MODE)',
				array( self::build_boolean_query( $q ) ),
				false,
			);
		}

		global $wpdb;
		$columns = array_values( array_filter( $columns, static fn( $c ) => false === strpos( $c, 'content' ) ) );
		if ( ! $columns ) {
			return array( '0 = 1', array(), true );
		}
		$like  = '%' . $wpdb->esc_like( $q ) . '%';
		$parts = array_map( static fn( $c ) => $c . ' LIKE %s', $columns );

		return array(
			'(' . implode( ' OR ', $parts ) . ')',
			array_fill( 0, count( $columns ), $like ),
			true,
		);
	}
}
