<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Search_Adapter {
	public function is_active(): bool;
	public function index( string $object_type, int $object_id, array $data ): void;
	public function search( string $query, string $type, ?int $space_id, int $limit, int $offset ): array;
	public function delete( string $object_type, int $object_id ): void;
}
