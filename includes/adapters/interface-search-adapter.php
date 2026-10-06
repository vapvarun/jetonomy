<?php
/**
 * Search adapter interface.
 *
 * The narrow contract: keyword search of one type, used by the jetonomy/search
 * WP Ability. Search_Query_Adapter extends it with the filtered query that
 * REST /search, the search page and the app need; implement that one to take
 * over all of them. The default implementation is
 * Jetonomy\Search\Fulltext_Search (MySQL FULLTEXT). Plugins register theirs
 * via Adapter_Registry::register_search(); Search_Indexer calls index() and
 * delete() as topics and replies are published, edited and removed.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Search_Adapter {
	public function is_active(): bool;
	public function index( string $object_type, int $object_id, array $data ): void;
	public function search( string $query, string $type, ?int $space_id, int $limit, int $offset ): array;
	public function delete( string $object_type, int $object_id ): void;
}
