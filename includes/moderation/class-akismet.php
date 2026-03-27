<?php
/**
 * Akismet spam integration.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Moderation;

defined( 'ABSPATH' ) || exit;

class Akismet {

	/**
	 * Check whether the Akismet plugin is active and has a valid API key.
	 */
	public static function is_available(): bool {
		return class_exists( 'Akismet' )
			&& method_exists( 'Akismet', 'get_api_key' )
			&& \Akismet::get_api_key();
	}

	/**
	 * Check content against Akismet's spam detection API.
	 *
	 * @param string $content Post/reply content.
	 * @param string $author  Author display name.
	 * @param string $email   Author email.
	 * @param string $ip      Author IP address.
	 * @return bool True if Akismet flags the content as spam.
	 */
	public static function check_spam( string $content, string $author, string $email, string $ip ): bool {
		if ( ! self::is_available() ) {
			return false;
		}

		$data = [
			'blog'                 => home_url(),
			'user_ip'              => $ip,
			'user_agent'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
			'comment_type'         => 'forum-post',
			'comment_author'       => $author,
			'comment_author_email' => $email,
			'comment_content'      => $content,
		];

		$response = \Akismet::http_post( http_build_query( $data ), 'comment-check' );

		return isset( $response[1] ) && 'true' === trim( $response[1] );
	}
}
