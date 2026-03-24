<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Restriction;
use Jetonomy\Models\UserProfile;

class Users_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_ban_user',           [ $this, 'ajax_ban_user' ] );
		add_action( 'wp_ajax_jetonomy_unban_user',         [ $this, 'ajax_unban_user' ] );
		add_action( 'wp_ajax_jetonomy_change_trust_level', [ $this, 'ajax_change_trust_level' ] );
		add_action( 'wp_ajax_jetonomy_search_users',       [ $this, 'ajax_search_users' ] );
	}

	public function ajax_ban_user(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id   = absint( $_POST['user_id'] ?? 0 );
		$type      = sanitize_text_field( $_POST['type'] ?? 'global_ban' );
		$reason    = sanitize_text_field( $_POST['reason'] ?? '' );
		$duration  = sanitize_text_field( $_POST['duration'] ?? 'permanent' );
		$space_id  = absint( $_POST['space_id'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, [ 'global_ban', 'space_ban', 'silence' ], true ) ) {
			$type = 'global_ban';
		}

		$expires_at = null;
		switch ( $duration ) {
			case '1d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
				break;
			case '7d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
				break;
			case '30d':
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
				break;
			case 'permanent':
			default:
				$expires_at = null;
				break;
		}

		if ( 'permanent' !== $duration && ! $expires_at ) {
			// Custom duration in days
			$custom_days = absint( $duration );
			if ( $custom_days > 0 ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + $custom_days * DAY_IN_SECONDS );
			}
		}

		$restriction_id = Restriction::ban(
			$user_id,
			$type,
			get_current_user_id(),
			$space_id ?: null,
			$reason ?: null,
			$expires_at
		);

		if ( ! $restriction_id ) {
			wp_send_json_error( __( 'Failed to ban user.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'User banned.', 'jetonomy' ) ] );
	}

	public function ajax_unban_user(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$restriction_id = absint( $_POST['restriction_id'] ?? 0 );
		if ( ! $restriction_id ) {
			wp_send_json_error( __( 'Invalid restriction ID.', 'jetonomy' ) );
		}

		$result = Restriction::remove_ban( $restriction_id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to remove ban.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Ban removed.', 'jetonomy' ) ] );
	}

	public function ajax_change_trust_level(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id     = absint( $_POST['user_id'] ?? 0 );
		$trust_level = absint( $_POST['trust_level'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		if ( $trust_level > 5 ) {
			$trust_level = 5;
		}

		UserProfile::find_or_create( $user_id );
		$result = UserProfile::update_profile( $user_id, [ 'trust_level' => $trust_level ] );

		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update trust level.', 'jetonomy' ) );
		}

		wp_send_json_success( [
			'message'     => __( 'Trust level updated.', 'jetonomy' ),
			'trust_level' => $trust_level,
		] );
	}

	public function ajax_search_users(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$search = sanitize_text_field( $_POST['search'] ?? '' );
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( [ 'users' => [] ] );
		}

		$users = get_users( [
			'search'         => '*' . $search . '*',
			'search_columns' => [ 'user_login', 'display_name', 'user_email' ],
			'number'         => 10,
		] );

		$results = [];
		foreach ( $users as $user ) {
			$results[] = [
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'avatar'       => get_avatar_url( $user->ID, [ 'size' => 32 ] ),
			];
		}

		wp_send_json_success( [ 'users' => $results ] );
	}
}
