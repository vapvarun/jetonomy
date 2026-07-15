<?php
/**
 * Full-text search adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Search;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Adapters\Search_Adapter;
use function Jetonomy\table;

class Fulltext_Search implements Search_Adapter {

	public function is_active(): bool {
		return true; // Always available — MySQL FULLTEXT is built-in
	}

	public function index( string $object_type, int $object_id, array $data ): void {
		// FULLTEXT is automatic — content_plain column is already indexed
		// This method exists for external adapters (Meilisearch, etc.)
	}

	public function search( string $query, string $type = 'post', ?int $space_id = null, int $limit = 20, int $offset = 0 ): array {
		$query = trim( $query );
		if ( strlen( $query ) < 2 ) {
			return [];
		}

		// Prepare boolean mode query (AND-required prefix tokens >= 4 chars).
		$search_term = self::build_boolean_query( $query );

		switch ( $type ) {
			case 'post':
				return $this->search_posts( $search_term, $space_id, $limit, $offset );
			case 'reply':
				return $this->search_replies( $search_term, $space_id, $limit, $offset );
			case 'space':
				return $this->search_spaces( $query, $limit, $offset );
			default:
				return [];
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
	 * for search visibility — the adapter (posts + replies), the search template's
	 * filtered query, and the REST controller all call it so no path can leak a
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
			return [ '', [] ];
		}

		if ( $viewer_id > 0 ) {
			return [ "({$col}is_private = 0 OR {$col}author_id = %d)", [ $viewer_id ] ];
		}

		return [ "{$col}is_private = 0", [] ];
	}

	private function search_posts( string $term, ?int $space_id, int $limit, int $offset ): array {
		global $wpdb;
		$t  = table( 'posts' );
		$st = table( 'spaces' );

		// JOIN spaces so the space-visibility gate can run; alias posts as `p`
		// (spaces shares an `author_id` column, so an unaliased query would be
		// ambiguous once joined).
		$sql = "SELECT p.*, MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE) AS relevance
				FROM {$t} p
				INNER JOIN {$st} s ON s.id = p.space_id
				WHERE MATCH(p.title, p.content_plain) AGAINST(%s IN BOOLEAN MODE)
				AND p.status = 'publish'";

		$params = [ $term, $term ];

		if ( $space_id ) {
			$sql     .= ' AND p.space_id = %d';
			$params[] = $space_id;
		}

		// Exclude private posts the viewer can't see (per-post is_private axis).
		[ $vis_sql, $vis_params ] = self::visibility_clause( $space_id, 'p' );
		if ( '' !== $vis_sql ) {
			$sql   .= ' AND ' . $vis_sql;
			$params = array_merge( $params, $vis_params );
		}

		// Space-level content gate — exclude posts whose parent space the viewer
		// cannot read (private/hidden unless member). Single source of truth.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $space_vis_sql ) {
			$sql   .= ' AND ' . $space_vis_sql;
			$params = array_merge( $params, $space_vis_params );
		}

		// Hide posts from users the viewer has blocked. no-op for guests/no-blocks.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
		if ( '' !== $block_sql ) {
			$sql .= ' AND ' . $block_sql;
		}

		$sql     .= ' ORDER BY relevance DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: [];
	}

	private function search_replies( string $term, ?int $space_id, int $limit, int $offset ): array {
		global $wpdb;
		$rt = table( 'replies' );
		$pt = table( 'posts' );
		$st = table( 'spaces' );

		// Always JOIN posts so we can enforce parent-post visibility — a reply on
		// a private post must not surface in search to anyone but its author.
		// JOIN spaces too so the parent space's visibility can be enforced.
		$sql = "SELECT r.*, MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AS relevance
				FROM {$rt} r
				INNER JOIN {$pt} p ON r.post_id = p.id
				INNER JOIN {$st} s ON s.id = p.space_id";

		$params = [ $term ];

		$where    = [ 'MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE)', "r.status = 'publish'", "p.status = 'publish'" ];
		$params[] = $term;

		if ( $space_id ) {
			$where[]  = 'p.space_id = %d';
			$params[] = $space_id;
		}

		// Exclude replies on private posts the viewer can't see (alias 'p').
		[ $vis_sql, $vis_params ] = self::visibility_clause( $space_id, 'p' );
		if ( '' !== $vis_sql ) {
			$where[] = $vis_sql;
			$params  = array_merge( $params, $vis_params );
		}

		// Space-level content gate — exclude replies on posts whose parent space
		// the viewer cannot read. Single source of truth.
		[ $space_vis_sql, $space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 's' );
		if ( '1=1' !== $space_vis_sql ) {
			$where[] = $space_vis_sql;
			$params  = array_merge( $params, $space_vis_params );
		}

		// Hide replies AUTHORED BY a blocked user. Deliberately not filtering on
		// the parent post's author — that would over-block other people's
		// useful replies inside a blocked user's thread. no-op for guests/no-blocks.
		[ $block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'r', 'author_id' );
		if ( '' !== $block_sql ) {
			$where[] = $block_sql;
		}

		$sql     .= ' WHERE ' . implode( ' AND ', $where );
		$sql     .= ' ORDER BY relevance DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) ) ?: [];
	}

	private function search_spaces( string $query, int $limit, int $offset ): array {
		global $wpdb;
		$t    = table( 'spaces' );
		$like = '%' . $wpdb->esc_like( $query ) . '%';

		// Listing gate — discoverable spaces (public + private); hidden withheld
		// from non-members. Mirrors the directory so search and browse agree.
		[ $vis_sql, $vis_params ] = \Jetonomy\Models\Space::listing_visibility_sql( get_current_user_id() );
		$args                     = array_merge( [ $like, $like ], $vis_params, [ $limit, $offset ] );

		return $wpdb->get_results(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$t} WHERE (title LIKE %s OR description LIKE %s) AND {$vis_sql} AND status = 'active' ORDER BY member_count DESC LIMIT %d OFFSET %d",
				...$args
			)
		) ?: [];
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
		$tokens  = preg_split( '/\s+/', (string) $cleaned, -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		$required = [];
		foreach ( $tokens as $t ) {
			$len = function_exists( 'mb_strlen' ) ? mb_strlen( $t ) : strlen( $t );
			if ( $len < 4 ) {
				continue;
			}
			$required[] = '+' . $t . '*';
		}

		return $required ? implode( ' ', $required ) : $query;
	}
}
