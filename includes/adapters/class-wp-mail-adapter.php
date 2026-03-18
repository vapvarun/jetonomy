<?php
namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class WP_Mail_Adapter implements Email_Adapter {

	public function is_active(): bool {
		return true;
	}

	public function send( string $to, string $subject, string $html, string $plain ): bool {
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->get_from_name() . ' <' . $this->get_from_email() . '>',
		];

		// Add List-Unsubscribe headers (RFC 8058 compliant).
		$headers[] = 'List-Unsubscribe: <' . home_url( '/community/notifications/' ) . '>';
		$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';

		return wp_mail( $to, $subject, $html, $headers );
	}

	public function send_batch( array $messages ): array {
		$results = [];
		foreach ( $messages as $key => $msg ) {
			$results[ $key ] = $this->send(
				$msg['to'],
				$msg['subject'],
				$msg['html'],
				$msg['plain'] ?? ''
			);
		}
		return $results;
	}

	public function register_hooks(): void {
		// Set HTML content type for wp_mail when sending Jetonomy emails
	}

	private function get_from_name(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		return $settings['email_from_name'] ?? get_bloginfo( 'name' );
	}

	private function get_from_email(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		return $settings['email_from_email'] ?? get_option( 'admin_email' );
	}
}
