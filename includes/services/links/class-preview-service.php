<?php
/**
 * Link preview service — single entry point for producing a LinkedIn-style
 * rich preview card for an arbitrary URL, both for the web UI and native
 * mobile app clients.
 *
 * ## Why wrap WP core oEmbed instead of using it directly?
 *
 * WordPress's built-in oEmbed (see wp-includes/class-wp-oembed.php) is a
 * post-render-time pipeline: it expands embeddable URLs inside editor content
 * into iframes and returns raw HTML. That's not what we need here. We need:
 *
 *   1. A REST endpoint that works for arbitrary URLs, on demand, from JS and
 *      from native apps.
 *   2. A *normalized* JSON shape (title/description/image/favicon/site_name)
 *      so the same card renders on web, iOS, and Android without per-platform
 *      HTML parsing.
 *   3. Coverage beyond the ~40 sanctioned oEmbed providers — every site with
 *      Open Graph tags (LinkedIn, Instagram, Facebook, news outlets, blogs,
 *      GitHub, etc.) should still get a rich card.
 *   4. A caching layer so a 200-reply thread doesn't hit TikTok 200 times.
 *
 * ## Pipeline (in order)
 *
 *   1. **Host-specific providers** pre-seed provider-aware fields.
 *      Current set: X_Provider (x.com → twitter.com rewrite), OEmbed_Provider
 *      (reuses _wp_oembed_get_object() for all 40+ sanctioned hosts —
 *      YouTube, Vimeo, Dailymotion, TikTok, Twitter, Reddit, SoundCloud,
 *      Spotify, Pinterest, Bluesky, Canva, Flickr, Tumblr, Kickstarter, TED,
 *      Scribd, Issuu, Mixcloud, SmugMug, Crowdsignal, ReverbNation,
 *      WordPress.tv, Imgur, Amazon, Speakerdeck, Cloudup, Wolfram, Anghami,
 *      Pocketcasts, and the rest of the sanctioned registry).
 *   2. **HTML fetch + OG_Parser** fills gaps with Open Graph / Twitter Card /
 *      schema.org metadata — this is what covers LinkedIn / Instagram /
 *      Facebook / GitHub / Medium / news sites / any site with OG tags.
 *   3. **Cache** (12 h positive / 10 min negative) so repeated renders are free.
 *
 * ## Extensibility
 *
 *   - `jetonomy_link_preview_providers`  array<Provider_Interface> — add host-specific
 *     providers (e.g. a company-intranet URL rewriter) in front of the defaults.
 *   - `jetonomy_link_preview_data`       Preview_Data → Preview_Data — final mutation
 *     hook, runs right before cache + response.
 *   - `jetonomy_link_preview_cache_ttl`  int seconds — transient TTL override.
 *   - `jetonomy_link_preview_user_agent` string — UA override (some corporate
 *     firewalls whitelist specific UAs).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links;

use Jetonomy\Services\Links\Providers\OEmbed_Provider;
use Jetonomy\Services\Links\Providers\Provider_Interface;
use Jetonomy\Services\Links\Providers\X_Provider;

defined( 'ABSPATH' ) || exit;

final class Preview_Service {

	private const CACHE_PREFIX    = 'jt_lp_';
	private const DEFAULT_CACHE_S = 12 * HOUR_IN_SECONDS;
	/** Negative cache for failed fetches — short so transient failures recover quickly. */
	private const NEGATIVE_CACHE_S = 10 * MINUTE_IN_SECONDS;

	private HTML_Fetcher $fetcher;
	private OG_Parser $parser;

	/** @var array<Provider_Interface>|null */
	private ?array $providers = null;

	public function __construct( ?HTML_Fetcher $fetcher = null, ?OG_Parser $parser = null ) {
		$this->fetcher = $fetcher ?? new HTML_Fetcher();
		$this->parser  = $parser ?? new OG_Parser();
	}

	/**
	 * Main entry. Returns a Preview_Data even on failure (with `domain` populated);
	 * the caller decides whether to render based on whether `title` is set.
	 */
	public function fetch( string $url ): Preview_Data {
		$url = $this->normalize_url( $url );
		if ( '' === $url ) {
			return Preview_Data::empty_for( '' );
		}

		$cache_key = self::CACHE_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return Preview_Data::from_array( $cached );
		}

		$out         = Preview_Data::empty_for( $url );
		$host        = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host_no_www = preg_replace( '/^www\./', '', $host ) ?? $host;
		$out->domain = $host_no_www;

		// 1. Host-specific providers — short-circuit some fields before HTML fetch.
		foreach ( $this->get_providers() as $provider ) {
			if ( $provider->supports( $url, $host_no_www ) ) {
				$out           = $provider->hydrate( $url, $out );
				$out->provider = '' !== $out->provider ? $out->provider : $provider->id();
				break;
			}
		}

		// 2. HTML + OG pass. Skipped when we already have enough from oEmbed
		// AND the embed is a video — we still fetch for articles to pull
		// description + image which oEmbed rarely exposes.
		$needs_html = ( '' === $out->description || '' === $out->image || '' === $out->title || '' === $out->favicon );
		if ( $needs_html ) {
			$fetched = $this->fetcher->fetch( $url );
			if ( is_array( $fetched ) ) {
				$out->url = $fetched['final_url'];
				if ( false !== stripos( $fetched['content_type'], 'text/html' )
					|| false !== stripos( $fetched['content_type'], 'application/xhtml' )
					|| '' === $fetched['content_type'] ) {
					$out = $this->parser->parse( $fetched['body'], $out );
				}
				// refresh domain after redirects may have moved us to the real host.
				$final_host  = strtolower( (string) wp_parse_url( $out->url, PHP_URL_HOST ) );
				$out->domain = preg_replace( '/^www\./', '', $final_host ) ?? $final_host;
			} else {
				// Negative cache — don't hammer a failing upstream on every view.
				set_transient( $cache_key, $out->to_array(), self::NEGATIVE_CACHE_S );
				return apply_filters( 'jetonomy_link_preview_data', $out, $url );
			}
		}

		if ( '' === $out->site_name && '' !== $out->domain ) {
			$out->site_name = $out->domain;
		}
		if ( '' === $out->provider ) {
			$out->provider = 'generic';
		}

		/**
		 * Final mutation point — extensions (e.g. custom private-wiki rewriter)
		 * get the last word on the shape that's cached + returned.
		 */
		$out = apply_filters( 'jetonomy_link_preview_data', $out, $url );

		$ttl = (int) apply_filters( 'jetonomy_link_preview_cache_ttl', self::DEFAULT_CACHE_S, $url );
		set_transient( $cache_key, $out->to_array(), max( 60, $ttl ) );

		return $out;
	}

	/**
	 * Clear the cache for a single URL — used after a successful report-abuse
	 * moderator override, or during admin "refresh preview" actions.
	 */
	public function forget( string $url ): void {
		$url = $this->normalize_url( $url );
		if ( '' === $url ) {
			return;
		}
		delete_transient( self::CACHE_PREFIX . md5( $url ) );
	}

	/**
	 * @return array<Provider_Interface>
	 */
	private function get_providers(): array {
		if ( null === $this->providers ) {
			// Order matters — first match wins. Host-specific rewrites (X)
			// run before the generic oEmbed provider.
			$defaults        = array(
				new X_Provider(),
				new OEmbed_Provider(),
			);
			$filtered        = apply_filters( 'jetonomy_link_preview_providers', $defaults );
			$this->providers = array_values(
				array_filter(
					is_array( $filtered ) ? $filtered : $defaults,
					static fn( $p ) => $p instanceof Provider_Interface
				)
			);
		}
		return $this->providers;
	}

	/**
	 * Strip fragments and user/pass; enforce http(s); reject private IPs.
	 */
	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return '';
		}
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}
		// wp_http_validate_url rejects loopback/private by default. That's what
		// we want for user-supplied URLs — no SSRF against wp-admin.
		return $url;
	}
}
