<?php
/**
 * MemberPress membership adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;


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

	/**
	 * Where a visitor buys this membership: its own MemberPress page.
	 *
	 * A MemberPress membership is a published CPT whose permalink IS the
	 * registration/checkout page, so this needs no MemberPress API. Optional
	 * across the adapter interface (see
	 * Adapter_Registry::membership_level_url); returns '' for a missing or
	 * unpublished membership so the gate shows the requirement without a dead
	 * button rather than linking somewhere broken.
	 *
	 * @param string $level_id Stored rule value, e.g. `mepr_42`.
	 * @return string
	 */
	public function get_level_url( string $level_id ): string {
		if ( ! str_starts_with( $level_id, 'mepr_' ) ) {
			return '';
		}

		$product_id = (int) str_replace( 'mepr_', '', $level_id );
		if ( $product_id <= 0 ) {
			return '';
		}

		$product = get_post( $product_id );
		if ( ! $product || 'publish' !== $product->post_status ) {
			return '';
		}

		$url = get_permalink( $product_id );

		return is_string( $url ) ? $url : '';
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
}
