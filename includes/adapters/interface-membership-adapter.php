<?php
/**
 * Membership adapter interface.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

interface Membership_Adapter {
	public function is_active(): bool;
	public function get_user_levels( int $user_id ): array;
	public function user_has_level( int $user_id, string $level_id ): bool;
	public function get_all_levels(): array;
	public function get_level_label( string $level_id ): string;
	public function register_hooks(): void;
}
