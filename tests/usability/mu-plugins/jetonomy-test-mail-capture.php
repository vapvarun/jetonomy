<?php
/**
 * Plugin Name: Jetonomy Test Mail Capture
 * Description: Intercepts every wp_mail() call and writes the payload to a
 *              JSON-lines file so usability tests can assert on delivery
 *              without actually sending mail. Only active when the constant
 *              JETONOMY_TEST_MAIL_CAPTURE is defined — production safe.
 * Version:     1.0.0
 *
 * Install: symlink or copy this file into wp-content/mu-plugins/ on the
 * test environment and add the following to wp-config.php ABOVE the
 * "stop editing" line:
 *
 *     define( 'JETONOMY_TEST_MAIL_CAPTURE', true );
 *
 * The capture file lives at wp-content/debug-mail.jsonl and is overwritten
 * at the start of each usability test run via EmailCapture::clear().
 *
 * @package Jetonomy_Test_Helpers
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'JETONOMY_TEST_MAIL_CAPTURE' ) || ! JETONOMY_TEST_MAIL_CAPTURE ) {
	return;
}

add_filter(
	'pre_wp_mail',
	/**
	 * Capture every wp_mail call and return true to short-circuit the
	 * real SMTP dispatch so tests never hit the network.
	 *
	 * @param mixed $short_circuit Whatever a previous filter returned.
	 * @param array $atts {
	 *     @type string|array $to
	 *     @type string       $subject
	 *     @type string       $message
	 *     @type string|array $headers
	 *     @type string|array $attachments
	 * }
	 * @return bool Always true — prevents the real dispatch.
	 */
	function ( $short_circuit, $atts ) {
		$file = WP_CONTENT_DIR . '/debug-mail.jsonl';

		$record = array(
			'to'           => $atts['to'] ?? '',
			'subject'      => $atts['subject'] ?? '',
			'message'      => $atts['message'] ?? '',
			'headers'      => $atts['headers'] ?? array(),
			'attachments'  => $atts['attachments'] ?? array(),
			'captured_at'  => gmdate( 'c' ),
		);

		$line = wp_json_encode( $record ) . "\n";

		// Use error_log style append so multiple concurrent mails don't
		// clobber each other. Tests run serially by default so contention
		// is unlikely, but the flag is cheap.
		file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		// Return true → wp_mail treats the mail as "sent" and skips SMTP.
		return true;
	},
	10,
	2
);
