<?php
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

		// Prepare boolean mode query (add + prefix for required terms)
		$search_term = $this->prepare_boolean_query( $query );

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

	private function search_posts( string $term, ?int $space_id, int $limit, int $offset ): array {
		global $wpdb;
		$t = table( 'posts' );

		$sql = "SELECT *, MATCH(title, content_plain) AGAINST(%s IN BOOLEAN MODE) AS relevance
				FROM {$t}
				WHERE MATCH(title, content_plain) AGAINST(%s IN BOOLEAN MODE)
				AND status = 'publish'";

		$params = [ $term, $term ];

		if ( $space_id ) {
			$sql     .= ' AND space_id = %d';
			$params[] = $space_id;
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

		$sql = "SELECT r.*, MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AS relevance
				FROM {$rt} r";

		$params = [ $term ];

		if ( $space_id ) {
			$sql     .= " INNER JOIN {$pt} p ON r.post_id = p.id AND p.space_id = %d";
			$params[] = $space_id;
		}

		$sql     .= " WHERE MATCH(r.content_plain) AGAINST(%s IN BOOLEAN MODE) AND r.status = 'publish'";
		$params[] = $term;

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

		return $wpdb->get_results(
			$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$t} WHERE (title LIKE %s OR description LIKE %s) AND visibility = 'public' AND status = 'active' ORDER BY member_count DESC LIMIT %d OFFSET %d",
				$like,
				$like,
				$limit,
				$offset
			)
		) ?: [];
	}

	/**
	 * Prepare a boolean mode query from user input.
	 * Adds + prefix for required terms, handles quoted phrases.
	 */
	private function prepare_boolean_query( string $query ): string {
		// If user already used boolean operators, pass through
		if ( preg_match( '/[+\-~<>*"]/', $query ) ) {
			return $query;
		}

		// Split into words, make each required with +
		$words = preg_split( '/\s+/', $query, -1, PREG_SPLIT_NO_EMPTY );
		return implode( ' ', array_map( fn( $w ) => '+' . $w . '*', $words ) );
	}
}
