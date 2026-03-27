<?php
/**
 * WordPress roles membership adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class WP_Roles_Adapter implements Membership_Adapter {

	public function is_active(): bool {
		return true; // Always active as fallback
	}

	public function get_user_levels( int $user_id ): array {
		$user = get_userdata( $user_id );
		return $user ? $user->roles : [];
	}

	public function user_has_level( int $user_id, string $level_id ): bool {
		$user = get_userdata( $user_id );
		return $user && in_array( $level_id, $user->roles, true );
	}

	public function get_all_levels(): array {
		$roles  = wp_roles()->roles;
		$levels = [];
		foreach ( $roles as $slug => $role ) {
			$levels[] = [
				'id'    => $slug,
				'label' => $role['name'],
			];
		}
		return $levels;
	}

	public function get_level_label( string $level_id ): string {
		$roles = wp_roles()->roles;
		return $roles[ $level_id ]['name'] ?? $level_id;
	}

	public function register_hooks(): void {
		// No special hooks needed for WP roles
	}
}
