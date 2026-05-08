<?php
/**
 * Admin AJAX handler — settings.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

use Jetonomy\Notifications\Notifier;

defined( 'ABSPATH' ) || exit;

class Settings_Handler {

	/**
	 * Sample copy shown when admins preview or send-test for a specific
	 * notification type. Keeps the preview concrete without pulling real
	 * community data.
	 *
	 * @return array<string, array{message: string, url_path: string}>
	 */
	private static function sample_fixtures(): array {
		return array(
			'user_welcome'          => array(
				'message'  => __( 'Welcome to the community. Your account is ready. Jump in and introduce yourself.', 'jetonomy' ),
				'url_path' => '/',
			),
			'reply_to_post'         => array(
				'message'  => __( 'Alice replied to your post "Getting started with Jetonomy".', 'jetonomy' ),
				'url_path' => '/s/general/t/getting-started-with-jetonomy/',
			),
			'reply_to_reply'        => array(
				'message'  => __( 'Bob replied to your comment on "Hosting recommendations".', 'jetonomy' ),
				'url_path' => '/s/general/t/hosting-recommendations/',
			),
			'mention'               => array(
				'message'  => __( '@alice mentioned you in "Release notes discussion".', 'jetonomy' ),
				'url_path' => '/s/announcements/t/release-notes-discussion/',
			),
			'accepted_answer'       => array(
				'message'  => __( 'Your answer was accepted as the best reply on "How do I enable dark mode?".', 'jetonomy' ),
				'url_path' => '/s/help/t/how-do-i-enable-dark-mode/',
			),
			'idea_status_changed'   => array(
				'message'  => __( 'Your idea "Dark mode toggle" is now Planned.', 'jetonomy' ),
				'url_path' => '/s/feature-requests/roadmap/',
			),
			'new_post_in_sub'       => array(
				'message'  => __( 'A new discussion was posted in a space you follow.', 'jetonomy' ),
				'url_path' => '/',
			),
			'badge_earned'          => array(
				'message'  => __( 'You earned the "First Post" badge. Nice work.', 'jetonomy' ),
				'url_path' => '/u/me/',
			),
			'vote_on_post'          => array(
				'message'  => __( 'Your post received a new vote.', 'jetonomy' ),
				'url_path' => '/',
			),
			'moderation'            => array(
				'message'  => __( 'A moderator reviewed your recent content.', 'jetonomy' ),
				'url_path' => '/mod/',
			),
			'join_request'          => array(
				'message'  => __( 'A member has asked to join one of your spaces.', 'jetonomy' ),
				'url_path' => '/mod/',
			),
			'verification_reminder' => array(
				'message'  => __( "We noticed you haven't confirmed your email yet at {site}. Click the link below to verify your account and start participating.", 'jetonomy' ),
				'url_path' => '/',
			),
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_test_email', [ $this, 'ajax_test_email' ] );
		add_action( 'wp_ajax_jetonomy_flush_rules', [ $this, 'ajax_flush_rules' ] );
		add_action( 'wp_ajax_jetonomy_email_preview', [ $this, 'ajax_email_preview' ] );
		add_action( 'wp_ajax_jetonomy_email_reset', [ $this, 'ajax_email_reset' ] );
	}

	public function ajax_test_email(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$type        = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		$admin_email = get_option( 'admin_email' );

		// Generic test when no type specified.
		if ( '' === $type || ! isset( self::sample_fixtures()[ $type ] ) ) {
			// Route through the registered Email_Adapter so the admin tests
			// the same path production traffic uses. Earlier versions called
			// wp_mail() directly, which bypassed any Pro Mailgun / SES /
			// Postmark adapter and defeated the whole point of "test email".
			$adapter = \Jetonomy\Adapters\Adapter_Registry::get_email();
			$body    = __( 'This is a test email from your Jetonomy forum plugin. If you received this, email is working correctly.', 'jetonomy' );
			$subject = __( 'Jetonomy Test Email', 'jetonomy' );
			$result  = $adapter
				? $adapter->send( $admin_email, $subject, $body, $body, array() )
				: wp_mail( $admin_email, $subject, $body );

			if ( $result ) {
				wp_send_json_success(
					[
						/* translators: %s: admin email */
						'message' => sprintf( __( 'Test email sent to %s.', 'jetonomy' ), $admin_email ),
					]
				);
			}
			wp_send_json_error( __( 'Failed to send test email. Check your WordPress email configuration.', 'jetonomy' ) );
		}

		// Type-specific preview — route through the Notifier so the admin's
		// template overrides, branded HTML, and List-Unsubscribe headers all
		// apply exactly as they would in production. wp_get_current_user()
		// inside a logged-in admin-ajax request always returns a WP_User,
		// so no null-check needed after the fallback.
		$admin_user = get_user_by( 'email', $admin_email ) ?: wp_get_current_user();
		if ( '' === (string) $admin_user->user_email ) {
			wp_send_json_error( __( 'No admin recipient to send to.', 'jetonomy' ) );
		}

		$fixture = self::sample_fixtures()[ $type ];

		// Notifier::send_email_notification is private; invoke via reflection
		// so we don't duplicate the template-rendering logic.
		$notifier = new Notifier();
		$ref      = new \ReflectionMethod( Notifier::class, 'send_email_notification' );
		$ref->setAccessible( true );
		$ref->invoke(
			$notifier,
			(int) $admin_user->ID,
			$type,
			$fixture['message'],
			'preview',
			0,
			\Jetonomy\base_url() . $fixture['url_path']
		);

		wp_send_json_success(
			[
				/* translators: 1: type label, 2: recipient email */
				'message' => sprintf( __( 'Preview (%1$s) sent to %2$s.', 'jetonomy' ), $type, $admin_user->user_email ),
			]
		);
	}

	/**
	 * Return the rendered branded HTML for a given notification type, using
	 * either the live saved templates OR a caller-supplied subject/body
	 * preview (so admins can preview unsaved changes).
	 */
	public function ajax_email_preview(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		if ( ! isset( self::sample_fixtures()[ $type ] ) ) {
			wp_send_json_error( __( 'Unknown notification type.', 'jetonomy' ) );
		}

		$subject_override = isset( $_POST['subject'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['subject'] ) ) : '';
		$body_override    = isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( (string) $_POST['body'] ) ) : '';
		$fixture          = self::sample_fixtures()[ $type ];

		$user = wp_get_current_user();

		$placeholders = array(
			'{site}'    => get_bloginfo( 'name' ),
			'{user}'    => $user->display_name,
			'{message}' => $fixture['message'],
			'{type}'    => $type,
			'{url}'     => \Jetonomy\base_url() . $fixture['url_path'],
		);

		$subject = '' !== $subject_override ? strtr( $subject_override, $placeholders ) : strtr( '[{site}] {message}', $placeholders );
		$body    = '' !== $body_override ? strtr( $body_override, $placeholders ) : $fixture['message'];

		$html = Notifier::render_email_template(
			$type,
			$body,
			$user,
			'',
			\Jetonomy\base_url() . $fixture['url_path']
		);

		wp_send_json_success(
			array(
				'subject' => $subject,
				'html'    => $html,
			)
		);
	}

	/**
	 * Reset a single email template row to its shipped default.
	 *
	 * Removes the matching key from `jetonomy_email_templates` (so the next
	 * send falls back to `Notifier::get_default_template()` at render time)
	 * and returns the default subject/body in the response so the JS can
	 * repopulate the row's inputs without a full page reload.
	 *
	 * Per safety-check § A8: writing into the option is gated by
	 * `jetonomy_manage_settings` and the standard `jetonomy_admin` nonce —
	 * same surface as ajax_email_preview / ajax_test_email.
	 */
	public function ajax_email_reset(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$type = sanitize_key( wp_unslash( $_POST['type'] ?? '' ) );
		if ( '' === $type ) {
			wp_send_json_error( __( 'Unknown notification type.', 'jetonomy' ) );
		}

		$defaults = Notifier::get_default_template( $type );
		// `get_default_template()` returns ['subject' => '', 'body' => '']
		// for unknown types — guard so we don't drop arbitrary attacker-
		// supplied keys from the option.
		if ( '' === $defaults['subject'] && '' === $defaults['body'] ) {
			wp_send_json_error( __( 'Unknown notification type.', 'jetonomy' ) );
		}

		$templates = get_option( 'jetonomy_email_templates', array() );
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}
		if ( isset( $templates[ $type ] ) ) {
			unset( $templates[ $type ] );
			update_option( 'jetonomy_email_templates', $templates );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Reset to default.', 'jetonomy' ),
				'subject' => $defaults['subject'],
				'body'    => $defaults['body'],
			)
		);
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
