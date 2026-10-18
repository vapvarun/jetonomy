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
	 * Sample values for the enriched placeholders, so a preview or test email
	 * reads like a real one instead of "[site]  replied to your topic".
	 *
	 * @return array{post_title: string, actor_display_name: string, reply_excerpt: string, space_title: string}
	 */
	private static function sample_extra(): array {
		return array(
			'post_title'         => 'Getting started with Jetonomy',
			'actor_display_name' => 'Alice Chen',
			'reply_excerpt'      => __( 'Thanks for the walkthrough, the setup steps worked for me.', 'jetonomy' ),
			'space_title'        => 'General Discussion',
		);
	}

	/**
	 * Sample copy shown when admins preview or send-test for a specific
	 * notification type. Keeps the preview concrete without pulling real
	 * community data. `type` names the real type behind an email variant.
	 *
	 * @return array<string, array{message: string, url: string, type?: string}>
	 */
	private static function sample_fixtures(): array {
		return array(
			'user_welcome'           => array(
				'message' => __( 'Welcome to the community. Your account is ready. Jump in and introduce yourself.', 'jetonomy' ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'reply_to_post'          => array(
				/* translators: %s: singular topic label, lowercase. */
				'message' => sprintf( __( 'Alice replied to your %s "Getting started with Jetonomy".', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
				'url'     => \Jetonomy\route_url( 'post', 'general', 'getting-started-with-jetonomy' ),
			),
			'reply_in_followed'      => array(
				'message' => __( 'Alice Chen replied in "Getting started with Jetonomy"', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'post', 'general', 'getting-started-with-jetonomy' ),
				'type'    => 'reply_to_post',
			),
			'reply_to_reply'         => array(
				'message' => __( 'Bob replied to your comment on "Hosting recommendations".', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'post', 'general', 'hosting-recommendations' ),
			),
			'mention'                => array(
				'message' => __( '@alice mentioned you in "Release notes discussion".', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'post', 'announcements', 'release-notes-discussion' ),
			),
			'accepted_answer'        => array(
				/* translators: %s: reply label, lowercase (e.g. "reply"). */
				'message' => sprintf( __( 'Your answer was accepted as the best %s on "How do I enable dark mode?".', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ) ),
				'url'     => \Jetonomy\route_url( 'post', 'help', 'how-do-i-enable-dark-mode' ),
			),
			'idea_status_changed'    => array(
				'message' => __( 'Your idea "Dark mode toggle" is now Planned.', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'space-roadmap', 'feature-requests' ),
			),
			'new_post_in_sub'        => array(
				/* translators: %s: space label, plural, lowercase (e.g. "spaces"). */
				'message' => sprintf( __( 'A new discussion was posted in one of the %s you follow.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true, true ) ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'badge_earned'           => array(
				'message' => __( 'You earned the "First Post" badge. Nice work.', 'jetonomy' ), // jt-terms-ok: the seeded badge's proper name.
				'url'     => \Jetonomy\route_url( 'profile', 'me' ),
			),
			'vote_on_post'           => array(
				/* translators: %s: singular topic label, lowercase. */
				'message' => sprintf( __( 'Your %s received a new vote.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'reaction'               => array(
				// translators: %s: content title.
				'message' => sprintf( __( 'Someone reacted to %s', 'jetonomy' ), '"Hosting recommendations"' ),
				'url'     => \Jetonomy\route_url( 'post', 'general', 'hosting-recommendations' ),
			),
			'flag_resolved'          => array(
				'message' => __( 'Your report was reviewed. Thanks for helping keep the community healthy.', 'jetonomy' ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'join_request_result'    => array(
				/* translators: %s: space name */
				'message' => sprintf( __( 'Your request to join %s was approved', 'jetonomy' ), 'Feature Requests' ),
				'url'     => \Jetonomy\route_url( 'space', 'feature-requests' ),
			),
			'message'                => array(
				'message' => __( 'Someone sent you a private message.', 'jetonomy' ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'verification'           => array(
				'message' => __( 'Please confirm your email address to finish creating your account.', 'jetonomy' ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'moderation'             => array(
				'message' => __( 'A moderator reviewed your recent content.', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'moderation' ),
			),
			'flag_created'           => array(
				'message' => __( 'New content flag requires review', 'jetonomy' ),
				'url'     => \Jetonomy\route_url( 'moderation' ),
				'type'    => 'moderation',
			),
			'join_request'           => array(
				/* translators: %s: space label, plural, lowercase (e.g. "spaces"). */
				'message' => sprintf( __( 'Someone has asked to join one of your %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true, true ) ),
				'url'     => \Jetonomy\route_url( 'moderation' ),
			),
			'verification_reminder'  => array(
				'message' => __( "We noticed you haven't confirmed your email yet at {site}. Click the link below to verify your account and start participating.", 'jetonomy' ),
				'url'     => \Jetonomy\base_url() . '/',
			),
			'reply_by_email_refused' => array(
				/* translators: 1: reply label, lowercase. 2: topic label, lowercase. 3: reply label, plural lowercase. */
				'message' => sprintf( __( 'Your %1$s sent by email was not posted. This %2$s is closed and cannot receive new %3$s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', false, true ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
				'url'     => \Jetonomy\route_url( 'post', 'general', 'hosting-recommendations' ),
			),
		);
	}

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_test_email', array( $this, 'ajax_test_email' ) );
		add_action( 'wp_ajax_jetonomy_flush_rules', array( $this, 'ajax_flush_rules' ) );
		add_action( 'wp_ajax_jetonomy_email_preview', array( $this, 'ajax_email_preview' ) );
		add_action( 'wp_ajax_jetonomy_email_reset', array( $this, 'ajax_email_reset' ) );
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
			$body    = sprintf( /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ __( 'This is a test email from your %s forum plugin. If you received this, email is working correctly.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() );
			$subject = sprintf( /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ __( '%s Test Email', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() );
			$result  = $adapter
				? $adapter->send( $admin_email, $subject, $body, $body, array() )
				: wp_mail( $admin_email, $subject, $body );

			if ( $result ) {
				wp_send_json_success(
					array(
						/* translators: %s: admin email */
						'message' => sprintf( __( 'Test email sent to %s.', 'jetonomy' ), $admin_email ),
					)
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

		// The confirmation email has its own sender (fixed link + button), so test the real one.
		// The token is a placeholder: the link it carries simply fails verification.
		if ( 'verification' === $type ) {
			Notifier::send_verification_email( (int) $admin_user->ID, 'preview' );
			wp_send_json_success(
				array(
					/* translators: 1: type label, 2: recipient email */
					'message' => sprintf( __( 'Preview (%1$s) sent to %2$s.', 'jetonomy' ), $type, $admin_user->user_email ),
				)
			);
		}

		Notifier::send_email_notification(
			(int) $admin_user->ID,
			$fixture['type'] ?? $type,
			$fixture['message'],
			'preview',
			0,
			$fixture['url'],
			self::sample_extra() + array( 'email_template' => $type )
		);

		wp_send_json_success(
			array(
				/* translators: 1: type label, 2: recipient email */
				'message' => sprintf( __( 'Preview (%1$s) sent to %2$s.', 'jetonomy' ), $type, $admin_user->user_email ),
			)
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

		$placeholders = Notifier::email_placeholders( $user, $fixture['type'] ?? $type, $fixture['message'], $fixture['url'], self::sample_extra() );

		// A blank field previews the default the send path uses for it.
		$default = Notifier::get_default_template( $type );
		$subject = strtr( '' !== $subject_override ? $subject_override : ( '' !== $default['subject'] ? $default['subject'] : '[{site}] {message}' ), $placeholders );
		$body    = '' !== $body_override ? strtr( $body_override, $placeholders ) : ( '' !== $default['body'] ? strtr( $default['body'], $placeholders ) : $fixture['message'] );

		$html = Notifier::render_email_template(
			$fixture['type'] ?? $type,
			$body,
			$user,
			'',
			$fixture['url'],
			self::sample_extra()
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
		wp_send_json_success( array( 'message' => __( 'Rewrite rules flushed.', 'jetonomy' ) ) );
	}
}
