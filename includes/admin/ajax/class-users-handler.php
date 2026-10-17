<?php
/**
 * Admin AJAX handler — users.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Restriction;

class Users_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_ban_user', array( $this, 'ajax_ban_user' ) );
		add_action( 'wp_ajax_jetonomy_unban_user', array( $this, 'ajax_unban_user' ) );
		add_action( 'wp_ajax_jetonomy_change_trust_level', array( $this, 'ajax_change_trust_level' ) );
		add_action( 'wp_ajax_jetonomy_search_users', array( $this, 'ajax_search_users' ) );
	}

	public function ajax_ban_user(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_moderate' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id  = absint( $_POST['user_id'] ?? 0 );
		$type     = sanitize_text_field( wp_unslash( $_POST['type'] ?? 'global_ban' ) );
		$reason   = sanitize_text_field( wp_unslash( $_POST['reason'] ?? '' ) );
		$duration = sanitize_text_field( wp_unslash( $_POST['duration'] ?? 'permanent' ) );
		$space_id = absint( $_POST['space_id'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		if ( ! in_array( $type, array( 'global_ban', 'space_ban', 'silence', 'ip_ban' ), true ) ) {
			$type = 'global_ban';
		}

		if ( 'space_ban' === $type && ! $space_id ) {
			/* translators: 1: space label, lowercase (e.g. "space"). 2: member label, lowercase (e.g. "member"). 3: member label, plural (e.g. "Members"). */
			wp_send_json_error( sprintf( __( 'This ban needs a specific %1$s. Ban the %2$s from that %1$s\'s %3$s screen.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ), \Jetonomy\jetonomy_label( 'member', false, true ), \Jetonomy\jetonomy_label( 'member', true ) ) );
		}

		/*
		 * WHO may be targeted, not just who may moderate.
		 *
		 * The cap check above is satisfied by the editor role out of the box, so
		 * without this an editor could global_ban user 1 and lock the owner out
		 * of their own site. Restriction::ban() enforces
		 * the same rule as a backstop; this call is here so the moderator gets
		 * the reason instead of a bare failure.
		 */
		$allowed = Restriction::actor_may_restrict( $user_id, get_current_user_id() );
		if ( is_wp_error( $allowed ) ) {
			wp_send_json_error( $allowed->get_error_message() );
		}

		// An ip_ban stores the address in `reason` (the column is_ip_banned() matches).
		if ( 'ip_ban' === $type ) {
			$ip = Restriction::validate_ip_ban( sanitize_text_field( wp_unslash( $_POST['ip'] ?? '' ) ), get_current_user_id() );
			if ( is_wp_error( $ip ) ) {
				wp_send_json_error( $ip->get_error_message() );
			}
			$reason   = $ip;
			$space_id = 0;
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

		$messages = array(
			'silence' => __( 'User silenced.', 'jetonomy' ),
			'ip_ban'  => __( 'IP address banned.', 'jetonomy' ),
		);

		wp_send_json_success(
			array(
				'message'        => $messages[ $type ] ?? __( 'User banned.', 'jetonomy' ),
				'restriction_id' => $restriction_id,
			)
		);
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

		// Read the type first: the answer names what was lifted, not always "ban".
		$restriction = Restriction::find( $restriction_id );
		if ( ! $restriction ) {
			wp_send_json_error( __( 'This restriction was already lifted.', 'jetonomy' ) );
		}

		$result = Restriction::remove_ban( $restriction_id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to remove ban.', 'jetonomy' ) );
		}

		$messages = array(
			'silence'   => __( 'Silence lifted.', 'jetonomy' ),
			/* translators: %s: space label (e.g. "Space"). */
			'space_ban' => sprintf( __( '%s ban lifted.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space' ) ),
			'ip_ban'    => __( 'IP address ban lifted.', 'jetonomy' ),
		);

		wp_send_json_success( array( 'message' => $messages[ $restriction->type ] ?? __( 'Ban lifted.', 'jetonomy' ) ) );
	}

	public function ajax_change_trust_level(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_users' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$user_id     = absint( $_POST['user_id'] ?? 0 );
		$trust_level = absint( $_POST['trust_level'] ?? 0 );

		if ( ! $user_id ) {
			wp_send_json_error( __( 'Invalid user ID.', 'jetonomy' ) );
		}

		$trust_level = \Jetonomy\Trust\Trust_Levels::set_manually( $user_id, $trust_level, get_current_user_id() );
		if ( is_wp_error( $trust_level ) ) {
			wp_send_json_error( $trust_level->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message'     => __( 'Trust level updated.', 'jetonomy' ),
				'trust_level' => $trust_level,
			)
		);
	}

	public function ajax_search_users(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_spaces' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$search = sanitize_text_field( wp_unslash( $_POST['search'] ?? '' ) );
		if ( strlen( $search ) < 2 ) {
			wp_send_json_success( array( 'users' => array() ) );
		}

		$users = get_users(
			array(
				'search'         => '*' . $search . '*',
				'search_columns' => array( 'user_login', 'display_name', 'user_email' ),
				'number'         => 10,
			)
		);

		$results = array();
		foreach ( $users as $user ) {
			$results[] = array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'user_login'   => $user->user_login,
				'avatar'       => get_avatar_url( $user->ID, array( 'size' => 32 ) ),
			);
		}

		wp_send_json_success( array( 'users' => $results ) );
	}
}
