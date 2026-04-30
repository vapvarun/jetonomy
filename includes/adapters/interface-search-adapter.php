<?php
/**
 * Search adapter interface.
 *
 * Implementers back the /jetonomy/v1/search REST endpoint and the
 * jetonomy/search WP Ability. The default implementation is
 * Jetonomy\Search\Fulltext_Search (MySQL FULLTEXT). Plugins may register
 * alternative backends (Elasticsearch, Algolia, Meilisearch) via
 * Adapter_Registry::register_search().
 *
 * Direction (decision recorded 2026-04-30, plan/punch-list-2026-04-30.md
 * Block A2): the next interface change widens search() to accept the
 * filter set Search_Controller already builds (tag_slug, date_from,
 * date_to, author_id, sort, viewer-aware visibility). The controller
 * currently bypasses this interface and runs raw MATCH AGAINST SQL
 * because the signature can't carry those filters. Block A3 / A4 land
 * the widening + the consumer refactor; Block A4 also adds explicit
 * adapter selection so the registry isn't iteration-order-dependent
 * when more than one adapter registers.
 *
 * Until that work lands, do NOT add ES / Algolia adapters — they would
 * inherit the same too-narrow signature and need re-doing on the next
 * widening pass.
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
