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

	private const MAX_BYTES     = 512 * 1024; // 512 KB — enough for <head>, short-circuits on 10 MB pages.
	private const TIMEOUT       = 6;
	private const MAX_REDIRECTS = 5;

	/**
	 * User-Agent: TikTok, Instagram, Twitter, LinkedIn all refuse empty or
	 * default WP user-agents with 403 or an SPA shell that has no OG tags.
	 * `facebookexternalhit/1.1` is the canonical share-crawler UA that every
	 * one of these sites pre-renders OG/Twitter-card metadata for — pretending
	 * to be a real Chrome sometimes works but leaves us fetching the client-
	 * rendered shell on TikTok specifically. Combine with a bot ident for
	 * log traceability; share bots (Slack/Discord) do the same.
	 */
	public const USER_AGENT = 'facebookexternalhit/1.1 (+https://wbcomdesigns.com/jetonomy/) JetonomyBot/1.0';

	/**
	 * @return array{body:string,final_url:string,status:int,content_type:string}|\WP_Error
	 */
	public function fetch( string $url ) {
		$ua = apply_filters( 'jetonomy_link_preview_user_agent', self::USER_AGENT, $url );

		// Follow redirects MANUALLY (redirection => 0) so the SSRF guard runs on
		// every hop. wp_safe_remote_get only re-validates redirects with the same
		// incomplete wp_http_validate_url(), so a public URL could otherwise 30x
		// into 169.254.169.254 (cloud metadata) or another internal address.
		$current = $url;
		$seen    = array();

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$guard = Url_Guard::check_remote_url( $current );
			if ( is_wp_error( $guard ) ) {
				return $guard;
			}

			$response = wp_safe_remote_get(
				$current,
				array(
					'timeout'             => self::TIMEOUT,
					'redirection'         => 0,
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

			// Redirect — resolve the target, guard it on the next iteration.
			if ( $status >= 300 && $status < 400 ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					break;
				}
				$next = \WP_Http::make_absolute_url( $location, $current );
				if ( '' === $next || isset( $seen[ $next ] ) ) {
					return new \WP_Error( 'jetonomy_link_preview_redirect', 'Redirect loop or invalid redirect.', array( 'status' => 400 ) );
				}
				$seen[ $current ] = true;
				$current          = $next;
				continue;
			}

			if ( $status < 200 || $status >= 400 ) {
				return new \WP_Error(
					'jetonomy_link_preview_http',
					sprintf( 'Upstream returned HTTP %d', $status ),
					array( 'status' => $status )
				);
			}

			return array(
				'body'         => (string) wp_remote_retrieve_body( $response ),
				'final_url'    => $current,
				'status'       => $status,
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			);
		}

		return new \WP_Error( 'jetonomy_link_preview_redirect', 'Too many redirects.', array( 'status' => 400 ) );
	}
}
