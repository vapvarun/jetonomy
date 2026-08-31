<?php
/**
 * Paid Memberships Pro adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;


class PMPro_Adapter implements Membership_Adapter {

	public function is_active(): bool {
		return defined( 'PMPRO_VERSION' ) && function_exists( 'pmpro_getMembershipLevelForUser' );
	}

	public function get_user_levels( int $user_id ): array {
		if ( ! $this->is_active() ) {
			return [];
		}

		// PMPro 3.0+ supports multiple levels
		if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
			$levels = pmpro_getMembershipLevelsForUser( $user_id );
			return array_map(
				function ( $level ) {
					return 'pmpro_' . $level->id;
				},
				$levels ?: []
			);
		}

		// PMPro 2.x — single level
		$level = pmpro_getMembershipLevelForUser( $user_id );
		return $level ? [ 'pmpro_' . $level->id ] : [];
	}

	public function user_has_level( int $user_id, string $level_id ): bool {
		return in_array( $level_id, $this->get_user_levels( $user_id ), true );
	}

	public function get_all_levels(): array {
		if ( ! $this->is_active() ) {
			return [];
		}

		if ( ! function_exists( 'pmpro_getAllLevels' ) ) {
			return [];
		}

		$all    = pmpro_getAllLevels( false, true );
		$levels = [];
		foreach ( $all as $level ) {
			$levels[] = [
				'id'    => 'pmpro_' . $level->id,
				'label' => $level->name,
			];
		}
		return $levels;
	}

	/**
	 * Where a visitor buys this level: the PMPro checkout for it.
	 *
	 * `pmpro_url( 'checkout', '?level=N' )` is PMPro's own way of linking a
	 * specific level's checkout, so a member lands on the right plan already
	 * selected instead of a pricing table they have to re-read. Optional
	 * across the adapter interface (see
	 * Adapter_Registry::membership_level_url); returns '' when PMPro is not
	 * loaded so the gate shows the requirement without a dead button.
	 *
	 * @param string $level_id Stored rule value, e.g. `pmpro_3`.
	 * @return string
	 */
	public function get_level_url( string $level_id ): string {
		if ( ! str_starts_with( $level_id, 'pmpro_' ) || ! function_exists( 'pmpro_url' ) ) {
			return '';
		}

		$id = (int) str_replace( 'pmpro_', '', $level_id );
		if ( $id <= 0 ) {
			return '';
		}

		return (string) pmpro_url( 'checkout', '?level=' . $id );
	}

	public function get_level_label( string $level_id ): string {
		if ( ! $this->is_active() ) {
			return $level_id;
		}

		$id = (int) str_replace( 'pmpro_', '', $level_id );
		if ( function_exists( 'pmpro_getLevel' ) ) {
			$level = pmpro_getLevel( $id );
			return $level ? $level->name : $level_id;
		}
		return $level_id;
	}

	public function register_hooks(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// Level changed (added, changed, or removed)
		add_action( 'pmpro_after_change_membership_level', [ $this, 'on_level_change' ], 10, 3 );
	}

	/**
	 * Handle PMPro level change.
	 *
	 * @param int $level_id New level ID (0 = cancelled)
	 * @param int $user_id
	 * @param int $cancel_level Old level being cancelled
	 */
	public function on_level_change( int $level_id, int $user_id, $cancel_level = 0 ): void {
		// If new level assigned, activate
		if ( $level_id > 0 ) {
			do_action( 'jetonomy_membership_activated', $user_id, 'pmpro_' . $level_id, 'pmpro' );
		}

		// If old level cancelled, deactivate
		if ( $cancel_level > 0 ) {
			do_action( 'jetonomy_membership_deactivated', $user_id, 'pmpro_' . $cancel_level, 'pmpro' );
		}
	}
}
