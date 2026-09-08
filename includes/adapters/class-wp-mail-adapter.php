<?php
/**
 * wp_mail email adapter.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Adapters;

defined( 'ABSPATH' ) || exit;

class WP_Mail_Adapter implements Email_Adapter {

	public function is_active(): bool {
		return true;
	}

	public function send( string $to, string $subject, string $html, string $plain, array $extra_headers = [] ): bool {
		$headers = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $this->get_from_name() . ' <' . $this->get_from_email() . '>',
		];

		// Merge per-email headers (e.g. List-Unsubscribe with specific unsubscribe URL).
		if ( ! empty( $extra_headers ) ) {
			$headers = array_merge( $headers, $extra_headers );
		} else {
			// Fallback generic List-Unsubscribe.
			$headers[] = 'List-Unsubscribe: <' . \Jetonomy\base_url() . '/notifications/' . '>';
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		$headers = apply_filters( 'jetonomy_notification_email_headers', $headers, $to, $subject );

		return $this->send_with_our_sender( $to, $subject, $html, $headers );
	}

	/**
	 * Run one wp_mail() with our own From asserted, then get out of the way.
	 *
	 * A `From:` header alone does not survive. wp_mail() parses the header,
	 * then passes the address it found through the `wp_mail_from` filter, so
	 * any plugin filtering site-wide gets the last word - and a plugin that
	 * returns its own sender without inspecting the incoming value discards
	 * ours. Learnomy's Notification_Service::filter_mail_from() does exactly
	 * that at priority 20, which left a community that had configured
	 * community@example.com sending as the WordPress admin address, breaking
	 * SPF/DKIM alignment (Basecamp 10280327270).
	 *
	 * The filters are registered immediately before the send and removed
	 * immediately after, at a priority high enough to be the final word, so
	 * this asserts our sender for our own mail ONLY. Every other plugin's mail
	 * is left exactly as it was - the bug we are fixing, inflicted outward, is
	 * still the same bug.
	 *
	 * @param string        $to      Recipient.
	 * @param string        $subject Subject line.
	 * @param string        $html    HTML body.
	 * @param array<string> $headers Assembled headers.
	 */
	private function send_with_our_sender( string $to, string $subject, string $html, array $headers ): bool {
		$from_email = $this->get_from_email();
		$from_name  = $this->get_from_name();

		$email_filter = static function () use ( $from_email ) {
			return $from_email;
		};
		$name_filter  = static function () use ( $from_name ) {
			return $from_name;
		};

		add_filter( 'wp_mail_from', $email_filter, PHP_INT_MAX );
		add_filter( 'wp_mail_from_name', $name_filter, PHP_INT_MAX );

		try {
			return wp_mail( $to, $subject, $html, $headers );
		} finally {
			// finally, not a plain trailing pair: wp_mail() can throw (a PHPMailer
			// exception surfacing through a mail plugin), and leaking these
			// filters would make us the site-wide stomper.
			remove_filter( 'wp_mail_from', $email_filter, PHP_INT_MAX );
			remove_filter( 'wp_mail_from_name', $name_filter, PHP_INT_MAX );
		}
	}

	public function register_hooks(): void {
		// Set HTML content type for wp_mail when sending Jetonomy emails
	}

	private function get_from_name(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		$name     = $settings['email_from_name'] ?? '';
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	private function get_from_email(): string {
		$settings = get_option( 'jetonomy_settings', [] );
		$email    = $settings['email_from_email'] ?? '';
		return '' !== $email ? $email : get_option( 'admin_email' );
	}
}
