<?php
/**
 * oEmbed-backed provider — YouTube, Vimeo, Twitter/X, TikTok, Reddit, SoundCloud,
 * Spotify, Instagram, Flickr, Dailymotion, etc. WordPress core ships a provider
 * list and discovery pipeline; we reuse it so we don't maintain our own.
 *
 * Precedence: this provider pre-seeds embed_html + title + author. The OG parser
 * still runs afterwards to fill description/image/favicon.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links\Providers;

use Jetonomy\Services\Links\Preview_Data;

defined( 'ABSPATH' ) || exit;

final class OEmbed_Provider implements Provider_Interface {

	/**
	 * Transient prefix for resolved short-URL → canonical URL cache. Keyed
	 * by md5(short_url). 1-day TTL on hits, 1-hour negative cache on misses
	 * so a transient TikTok outage doesn't stick.
	 */
	private const SHORT_URL_CACHE_PREFIX = 'jt_oembed_short_';

	public function id(): string {
		return 'oembed';
	}

	public function supports( string $url, string $host ): bool {
		$oembed = _wp_oembed_get_object();
		if ( ! method_exists( $oembed, 'get_provider' ) ) {
			return false;
		}
		// TikTok ships a `/t/<code>/` short URL form (Share button output)
		// which WP's hardcoded provider regex `tiktok.com/.*/video/.*` does
		// not match. Resolve to canonical via a cached HEAD before asking WP
		// whether it can handle this URL — TikTok's oEmbed endpoint accepts
		// the short form, but supports() blocks us before we ever call it.
		$resolved = $this->maybe_resolve_short_url( $url );
		return false !== $oembed->get_provider( $resolved, array( 'discover' => false ) );
	}

	public function hydrate( string $url, Preview_Data $out ): Preview_Data {
		// Resolve before tagging or fetching so the entire pipeline operates
		// on the canonical URL — same cache hit as supports(), no extra HTTP.
		$url = $this->maybe_resolve_short_url( $url );

		// Always tag the host first. Some sanctioned providers (Twitter/X,
		// Instagram, Facebook) return empty oEmbed responses when their
		// external API is rate-limited or deprecated — we still want the card
		// to render with the right provider branding.
		$this->tag_known_host( $out, $url );

		$oembed = _wp_oembed_get_object();
		$data   = $oembed->get_data( $url, array( 'discover' => false ) );
		if ( ! is_object( $data ) ) {
			return $out;
		}

		if ( '' === $out->title && ! empty( $data->title ) ) {
			$out->title = (string) $data->title;
		}
		if ( '' === $out->author && ! empty( $data->author_name ) ) {
			$out->author = (string) $data->author_name;
		}
		if ( '' === $out->image && ! empty( $data->thumbnail_url ) ) {
			$out->image = (string) $data->thumbnail_url;
		}
		if ( '' === $out->site_name && ! empty( $data->provider_name ) ) {
			$out->site_name = (string) $data->provider_name;
		}
		$embed_type = (string) ( $data->type ?? '' );
		if ( '' !== $embed_type ) {
			$out->type = $embed_type;
		}

		// Only accept iframe/video embeds — never render arbitrary third-party
		// HTML inline. kses with an iframe allowlist gates the output.
		$raw_html = (string) ( $data->html ?? '' );
		if ( '' !== $raw_html && in_array( $embed_type, array( 'video', 'rich' ), true ) ) {
			$out->embed_html = $this->sanitize_embed( $raw_html );
		}

		return $out;
	}

	/**
	 * Narrow the iframe allowlist hard — the HTML is coming from a third party
	 * and we ship it straight into post markup.
	 */
	private function sanitize_embed( string $html ): string {
		$allowed = array(
			'iframe'     => array(
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'frameborder'     => true,
				'allow'           => true,
				'allowfullscreen' => true,
				'referrerpolicy'  => true,
				'loading'         => true,
				'title'           => true,
				'sandbox'         => true,
			),
			'blockquote' => array(
				'class'         => true,
				'cite'          => true,
				'data-lang'     => true,
				'data-video-id' => true,
			),
			'p'          => array(),
			'a'          => array(
				'href'   => true,
				'target' => true,
				'rel'    => true,
			),
			'br'         => array(),
		);
		return wp_kses( $html, $allowed );
	}

	/**
	 * Normalise a share URL into the canonical form WP's oEmbed provider
	 * regex understands. Two transformations:
	 *
	 *   1. Host alias rewrite (no HTTP).
	 *      - `x.com/user/status/123` → `twitter.com/user/status/123`
	 *        Twitter rebranded to X but the oEmbed endpoint and the WP
	 *        provider regex still key on twitter.com only.
	 *
	 *   2. Short-URL redirect resolution (cached HEAD, one hop).
	 *      Hosts that hand out share-shorteners and redirect to the
	 *      canonical permalink:
	 *        - `tiktok.com/t/<code>`            → tiktok.com/@user/video/<id>
	 *        - `t.co/<code>`                     → twitter.com/...
	 *        - `redd.it/<id>`                    → reddit.com/r/.../comments/...
	 *        - `reddit.com/r/<sub>/s/<code>`    → reddit.com/r/<sub>/comments/...
	 *        - `fb.watch/<code>`                 → facebook.com/...
	 *        - `spoti.fi/<code>`                 → open.spotify.com/...
	 *
	 * Each redirect is followed once and cached for a day on success, an
	 * hour on failure. Cross-host redirects to unrelated domains are
	 * treated as suspicious and discarded.
	 *
	 * Customer report: Basecamp 9809230457 / Zoho 39463 — paying customer
	 * could not embed any TikTok share URL because TikTok's mobile Share
	 * button hands out the `/t/<code>/` short form, which WP's hardcoded
	 * tiktok.com canonical-video provider regex doesn't match.
	 */
	private function maybe_resolve_short_url( string $url ): string {
		$url  = $this->rewrite_host_alias( $url );
		$host = $this->host_of( $url );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		$is_short = (
			( 'tiktok.com' === $host && (bool) preg_match( '#^/t/[A-Za-z0-9]+/?$#', $path ) ) ||
			( 't.co' === $host && (bool) preg_match( '#^/[A-Za-z0-9]+/?$#', $path ) ) ||
			( 'redd.it' === $host && (bool) preg_match( '#^/[A-Za-z0-9]+/?$#', $path ) ) ||
			( 'reddit.com' === $host && (bool) preg_match( '#^/r/[A-Za-z0-9_]+/s/[A-Za-z0-9]+/?$#', $path ) ) ||
			( 'fb.watch' === $host && (bool) preg_match( '#^/[A-Za-z0-9_-]+/?$#', $path ) ) ||
			( 'spoti.fi' === $host )
		);
		if ( ! $is_short ) {
			return $url;
		}
		return $this->follow_one_redirect( $url );
	}

	/**
	 * Rewrite alias hosts that point to the same content as a canonical host
	 * the WP oEmbed regex already matches. No HTTP — pure string transform.
	 */
	private function rewrite_host_alias( string $url ): string {
		// x.com → twitter.com. Twitter's oEmbed endpoint accepts both, but
		// WP core's hardcoded provider regex only matches twitter.com.
		if ( (bool) preg_match( '#^(https?://)(www\.)?x\.com(/.*)?$#i', $url, $m ) ) {
			$tail = isset( $m[3] ) ? $m[3] : '/';
			return $m[1] . 'twitter.com' . $tail;
		}
		return $url;
	}

	private function host_of( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Map of short-URL host → host the redirect must land on. Anything else
	 * is treated as a suspicious cross-host redirect and discarded.
	 */
	private function expected_redirect_host( string $short_host ): string {
		$map = array(
			'tiktok.com' => 'tiktok.com',
			't.co'       => 'twitter.com',
			'redd.it'    => 'reddit.com',
			'reddit.com' => 'reddit.com',
			'fb.watch'   => 'facebook.com',
			'spoti.fi'   => 'spotify.com',
		);
		return $map[ $short_host ] ?? '';
	}

	private function follow_one_redirect( string $url ): string {
		$cache_key = self::SHORT_URL_CACHE_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached && is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$resp = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			set_transient( $cache_key, $url, HOUR_IN_SECONDS );
			return $url;
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		if ( ! in_array( $code, array( 301, 302, 307, 308 ), true ) ) {
			set_transient( $cache_key, $url, HOUR_IN_SECONDS );
			return $url;
		}
		$location = (string) wp_remote_retrieve_header( $resp, 'location' );
		if ( '' === $location ) {
			set_transient( $cache_key, $url, HOUR_IN_SECONDS );
			return $url;
		}

		// Normalise the resolved URL — strip share-tracking query keys so
		// the cache key doesn't fragment per-share and the canonical URL
		// matches WP's provider regex without the noise tail.
		$location = $this->strip_tracking_query( $location );

		$expected      = $this->expected_redirect_host( $this->host_of( $url ) );
		$resolved_host = $this->host_of( $location );
		// Allow the resolved host to be the expected host or a sub-host of
		// it (vm.tiktok.com → tiktok.com is fine; redd.it → reddit.com fine).
		$is_expected = ( '' === $expected ) || ( $resolved_host === $expected ) || str_ends_with( $resolved_host, '.' . $expected );
		if ( ! $is_expected ) {
			set_transient( $cache_key, $url, HOUR_IN_SECONDS );
			return $url;
		}

		// Apply alias rewrite to the resolved URL too — e.g. t.co could
		// redirect to x.com; we want the canonical twitter.com form.
		$location = $this->rewrite_host_alias( $location );

		set_transient( $cache_key, $location, DAY_IN_SECONDS );
		return $location;
	}

	/**
	 * Strip share-tracking query parameters from a URL while preserving the
	 * meaningful path + the rest of the query. Removes provider-specific
	 * tracking tails (TikTok `_r/_t`, Spotify `si`, Instagram `igsh`) and
	 * the standard `utm_*`, `fbclid`, `gclid` set.
	 */
	private function strip_tracking_query( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
		}
		$strip = array( '_r', '_t', 'si', 'igsh', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'fbclid', 'gclid', 'mc_cid', 'mc_eid' );
		foreach ( $strip as $k ) {
			unset( $query[ $k ] );
		}
		$rebuilt = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' );
		if ( ! empty( $parts['port'] ) ) {
			$rebuilt .= ':' . $parts['port'];
		}
		$rebuilt .= $parts['path'] ?? '';
		if ( ! empty( $query ) ) {
			$rebuilt .= '?' . http_build_query( $query );
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$rebuilt .= '#' . $parts['fragment'];
		}
		return $rebuilt;
	}

	private function tag_known_host( Preview_Data $out, string $url ): void {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );

		$map = array(
			'youtube.com'    => 'youtube',
			'youtu.be'       => 'youtube',
			'm.youtube.com'  => 'youtube',
			'twitter.com'    => 'twitter',
			'x.com'          => 'twitter',
			'tiktok.com'     => 'tiktok',
			'instagram.com'  => 'instagram',
			'vimeo.com'      => 'vimeo',
			'reddit.com'     => 'reddit',
			'linkedin.com'   => 'linkedin',
			'facebook.com'   => 'facebook',
			'fb.watch'       => 'facebook',
			'spotify.com'    => 'spotify',
			'soundcloud.com' => 'soundcloud',
		);
		foreach ( $map as $needle => $provider ) {
			if ( $host === $needle || false !== strpos( $host, '.' . $needle ) ) {
				$out->provider = $provider;
				return;
			}
		}
	}
}
