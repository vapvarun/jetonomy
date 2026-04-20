<?php
/**
 * Thin wrapper over wp_remote_get for link previews.
 *
 * Exists to keep host-specific quirks (user-agent spoofing, redirect caps,
 * response size caps) out of the parsing layer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links;

defined( 'ABSPATH' ) || exit;

final class HTML_Fetcher {

	private const MAX_BYTES = 512 * 1024; // 512 KB — enough for <head>, short-circuits on 10 MB pages.
	private const TIMEOUT   = 6;

	/**
	 * User-Agent: TikTok / Instagram / Twitter / LinkedIn all refuse empty or
	 * default WP user-agents with 403. Presenting as a recent Chrome fixes all
	 * four. The Jetonomy suffix is for logs; share-bots (Slack/Discord) present
	 * similar strings.
	 */
	public const USER_AGENT = 'Mozilla/5.0 (compatible; JetonomyBot/1.0; +https://wbcomdesigns.com/jetonomy/) Chrome/120.0.0.0 Safari/537.36';

	/**
	 * @return array{body:string,final_url:string,status:int,content_type:string}|\WP_Error
	 */
	public function fetch( string $url ) {
		$ua = apply_filters( 'jetonomy_link_preview_user_agent', self::USER_AGENT, $url );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 5,
				'sslverify'           => true,
				'user-agent'          => $ua,
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'en-US,en;q=0.9',
				),
				'limit_response_size' => self::MAX_BYTES,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 400 ) {
			return new \WP_Error(
				'jetonomy_link_preview_http',
				sprintf( 'Upstream returned HTTP %d', $status ),
				array( 'status' => $status )
			);
		}

		// Final URL after redirects — some sites cloak the canonical URL so we
		// use the final URL for provider detection + domain display.
		$final_url     = $url;
		$http_response = $response['http_response'];
		if ( $http_response instanceof \WP_HTTP_Requests_Response
			&& method_exists( $http_response, 'get_response_object' ) ) {
			$final_url = (string) $http_response->get_response_object()->url;
		}

		return array(
			'body'         => (string) wp_remote_retrieve_body( $response ),
			'final_url'    => '' !== $final_url ? $final_url : $url,
			'status'       => $status,
			'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
		);
	}
}
