<?php
/**
 * MemberPress membership adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\SpaceMember;

class MemberPress_Adapter implements Membership_Adapter {

	public function is_active(): bool {
		return defined( 'MEPR_VERSION' ) && class_exists( 'MeprUser' );
	}

	public function get_user_levels( int $user_id ): array {
		if ( ! $this->is_active() ) {
			return [];
		}

		$mepr_user = new \MeprUser( $user_id );
		$active    = $mepr_user->active_product_subscriptions( 'ids' );

		return array_map(
			function ( $product_id ) {
				return 'mepr_' . $product_id;
			},
			$active
		);
	}

	public function user_has_level( int $user_id, string $level_id ): bool {
		$levels = $this->get_user_levels( $user_id );
		return in_array( $level_id, $levels, true );
	}

	public function get_all_levels(): array {
		if ( ! $this->is_active() ) {
			return [];
		}

		$products = \MeprCptModel::all( 'MeprProduct' );
		$levels   = [];
		foreach ( $products as $product ) {
			$levels[] = [
				'id'    => 'mepr_' . $product->ID,
				'label' => $product->post_title,
			];
		}
		return $levels;
	}

	public function get_level_label( string $level_id ): string {
		$product_id = (int) str_replace( 'mepr_', '', $level_id );
		$product    = get_post( $product_id );
		return $product ? $product->post_title : $level_id;
	}

	public function register_hooks(): void {
		if ( ! $this->is_active() ) {
			return;
		}

		// When a MemberPress transaction is completed
		add_action( 'mepr-txn-status-complete', [ $this, 'on_membership_activated' ] );

		// When a subscription is paused/cancelled/expired
		add_action( 'mepr-txn-status-refunded', [ $this, 'on_membership_deactivated' ] );
		add_action( 'mepr-txn-expired', [ $this, 'on_membership_deactivated' ] );
		add_action( 'mepr_subscription_transition_status', [ $this, 'on_subscription_status_change' ], 10, 3 );
	}

	/**
	 * When membership activates, auto-join matching spaces.
	 */
	public function on_membership_activated( $txn ): void {
		if ( ! is_object( $txn ) || empty( $txn->user_id ) || empty( $txn->product_id ) ) {
			return;
		}

		$user_id  = (int) $txn->user_id;
		$level_id = 'mepr_' . $txn->product_id;

		$this->sync_user_spaces( $user_id, $level_id, true );

		do_action( 'jetonomy_membership_activated', $user_id, $level_id, 'memberpress' );
	}

	/**
	 * When membership deactivates, downgrade in matching spaces.
	 */
	public function on_membership_deactivated( $txn ): void {
		if ( ! is_object( $txn ) || empty( $txn->user_id ) || empty( $txn->product_id ) ) {
			return;
		}

		$user_id  = (int) $txn->user_id;
		$level_id = 'mepr_' . $txn->product_id;

		// Check if user still has active membership for this product
		$mepr_user = new \MeprUser( $user_id );
		$active    = $mepr_user->active_product_subscriptions( 'ids' );

		if ( ! in_array( $txn->product_id, $active, true ) ) {
			$this->sync_user_spaces( $user_id, $level_id, false );
			do_action( 'jetonomy_membership_deactivated', $user_id, $level_id, 'memberpress' );
		}
	}

	public function on_subscription_status_change( $old_status, $new_status, $subscription ): void {
		if ( in_array( $new_status, [ 'suspended', 'cancelled' ], true ) ) {
			$txn = $subscription->latest_txn();
			if ( $txn ) {
				$this->on_membership_deactivated( $txn );
			}
		}
	}

	/**
	 * Sync user's space memberships based on access rules.
	 */
	private function sync_user_spaces( int $user_id, string $level_id, bool $activate ): void {
		global $wpdb;

		$table = \Jetonomy\table( 'access_rules' );
		$rules = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE rule_type = 'membership' AND rule_value = %s",
				$level_id
			)
		);

		foreach ( $rules as $rule ) {
			if ( $activate ) {
				$result = SpaceMember::add( (int) $rule->space_id, $user_id, $rule->space_role ?? 'member' );
				if ( is_wp_error( $result ) ) {
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( '[Jetonomy] MemberPress adapter: failed to add user ' . $user_id . ' to space ' . $rule->space_id . ' — ' . $result->get_error_message() );
					continue;
				}
			} else {
				SpaceMember::remove( (int) $rule->space_id, $user_id );
			}
		}
	}
}
