<?php
namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

class Settings_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_test_email',  [ $this, 'ajax_test_email' ] );
		add_action( 'wp_ajax_jetonomy_flush_rules', [ $this, 'ajax_flush_rules' ] );
	}

	public function ajax_test_email(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$admin_email = get_option( 'admin_email' );
		$result = wp_mail(
			$admin_email,
			__( 'Jetonomy Test Email', 'jetonomy' ),
			__( 'This is a test email from your Jetonomy forum plugin. If you received this, email is working correctly.', 'jetonomy' )
		);

		if ( $result ) {
			wp_send_json_success( [
				'message' => sprintf(
					__( 'Test email sent to %s.', 'jetonomy' ),
					$admin_email
				),
			] );
		} else {
			wp_send_json_error( __( 'Failed to send test email. Check your WordPress email configuration.', 'jetonomy' ) );
		}
	}

	public function ajax_flush_rules(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		flush_rewrite_rules();
		wp_send_json_success( [ 'message' => __( 'Rewrite rules flushed.', 'jetonomy' ) ] );
	}
}
