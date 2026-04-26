<?php
/**
 * Embed processor.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Embeds {

	/**
	 * Register runtime hooks — call from plugin bootstrap.
	 *
	 * Meta deprecated anonymous Instagram/Facebook oEmbed in October 2020. To
	 * embed those URLs we must attach an app access token (`{app_id}|{app_secret}`)
	 * to every outbound oEmbed HTTP request. Without the token WordPress's
	 * `wp_oembed_get()` silently returns empty and the URL falls back to a plain
	 * link. The admin pastes their credentials in Settings → SEO → Social Embeds.
	 */
	public static function register(): void {
		add_filter( 'oembed_remote_get_args', array( __CLASS__, 'inject_fb_token' ), 10, 2 );
		add_filter( 'oembed_providers', array( __CLASS__, 'register_fb_providers' ) );
	}

	/**
	 * Register Instagram/Facebook as known oEmbed providers.
	 *
	 * Core WordPress dropped these from the default provider list after Meta's
	 * deprecation. Re-registering them here makes `wp_oembed_get()` treat
	 * Instagram + Facebook URLs as oembeddable again — the actual HTTP call is
	 * still gated on whether a token is configured (see inject_fb_token).
	 *
	 * @param array<string, array{0:string,1:bool}> $providers Provider map.
	 * @return array<string, array{0:string,1:bool}>
	 */
	public static function register_fb_providers( array $providers ): array {
		$settings = (array) get_option( 'jetonomy_settings', array() );
		if ( empty( $settings['fb_app_id'] ) || empty( $settings['fb_app_secret'] ) ) {
			return $providers;
		}

		// Instagram — posts, reels, IGTV.
		$providers['#https?://(www\.)?instagram\.com/(p|reel|tv)/.*#i'] = array( 'https://graph.facebook.com/v16.0/instagram_oembed', true );
		// Facebook — posts, videos, photos, reels.
		$providers['#https?://(www\.)?facebook\.com/.*/(posts|videos|photos)/.*#i'] = array( 'https://graph.facebook.com/v16.0/oembed_post', true );
		$providers['#https?://(www\.)?facebook\.com/watch/?\?v=\d+#i']              = array( 'https://graph.facebook.com/v16.0/oembed_video', true );
		$providers['#https?://(www\.)?facebook\.com/reel/\d+#i']                    = array( 'https://graph.facebook.com/v16.0/oembed_video', true );

		return $providers;
	}

	/**
	 * Append the Meta app access token to Instagram/Facebook oEmbed requests.
	 *
	 * `oembed_remote_get_args` fires for every oEmbed HTTP call. We only mutate
	 * it when the provider URL belongs to graph.facebook.com — other providers
	 * (YouTube, Vimeo, TikTok) pass through untouched.
	 *
	 * @param array<string, mixed> $args        wp_remote_get args.
	 * @param string               $provider_url Provider endpoint the request is going to.
	 * @return array<string, mixed>
	 */
	public static function inject_fb_token( $args, $provider_url ) {
		if ( false === strpos( (string) $provider_url, 'graph.facebook.com' ) ) {
			return $args;
		}

		$settings = (array) get_option( 'jetonomy_settings', array() );
		$app_id   = trim( (string) ( $settings['fb_app_id'] ?? '' ) );
		$secret   = trim( (string) ( $settings['fb_app_secret'] ?? '' ) );
		if ( '' === $app_id || '' === $secret ) {
			return $args;
		}

		$args['headers']                  = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $app_id . '|' . $secret;

		return $args;
	}

	/**
	 * Process content and convert standalone URLs to embeds.
	 *
	 * Normalises contenteditable artifacts (`&nbsp;`, empty `<div><br></div>`
	 * wrappers the browser injects after a trailing Enter) before running the
	 * URL matcher — otherwise a pasted link followed by Enter never matches
	 * because the regex boundary requires whitespace/EOL.
	 *
	 * Matches URLs that sit on their own (optionally wrapped in `<p>` or
	 * adjacent to tag boundaries) and replaces them with oEmbed HTML when
	 * WordPress can embed the URL. URLs inside an existing `<a>` tag are
	 * left alone.
	 *
	 * @param string $content HTML content to process.
	 * @return string Content with standalone URLs converted to embeds.
	 */
	public static function process( string $content ): string {
		// Normalise contenteditable junk before matching.
		$content = preg_replace( '/&nbsp;|&#160;|&#xa0;/i', ' ', $content );
		$content = preg_replace( '#\s*<div>\s*<br\s*/?>\s*</div>\s*#i', ' ', $content );
		$content = preg_replace( '#<p>\s*</p>#i', '', $content );

		// TikTok embeds need special handling. TikTok's oEmbed returns a
		// <blockquote class="tiktok-embed"> plus a <script src="embed.js">.
		// Jetonomy strips <script> tags (correctly), which leaves only the
		// caption text rendering on the post. Replace the blockquote with a
		// script-free <iframe src="https://www.tiktok.com/embed/v2/<id>">
		// that the existing kses allowlist already permits. Covers three
		// paste/storage shapes:
		// - real HTML <blockquote class="tiktok-embed" cite=".../video/<id>">
		// - the same markup encoded as entity text (&lt;blockquote ...&gt;),
		// which is what contenteditable produces when a user pastes raw
		// HTML into the composer
		// - a bare tiktok.com/@user/video/<id> URL that would otherwise
		// hit wp_oembed_get() and come back as the same script-laden HTML
		$content = self::replace_tiktok_blockquote( $content );
		$content = self::replace_tiktok_encoded_blockquote( $content );

		// Split on HTML tags so we only match URLs in text nodes — this
		// prevents the regex from touching href attributes or nested anchor
		// content. Mirrors the approach in jetonomy_format_content().
		$parts = preg_split( '/(<[^>]*>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE );
		if ( false === $parts ) {
			return $content;
		}

		$inside_a = 0;
		foreach ( $parts as $i => $part ) {
			if ( preg_match( '/<a[\s>]/i', $part ) ) {
				++$inside_a;
				continue;
			}
			if ( preg_match( '/<\/a>/i', $part ) ) {
				--$inside_a;
				continue;
			}
			// Skip HTML tags entirely.
			if ( isset( $part[0] ) && '<' === $part[0] ) {
				continue;
			}
			if ( $inside_a > 0 ) {
				continue;
			}

			$parts[ $i ] = preg_replace_callback(
				'#(https?://[^\s<>"\']+)#i',
				static function ( $matches ) {
					$url = html_entity_decode( trim( $matches[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

					// Strip trailing punctuation that's almost certainly
					// sentence-terminator, not part of the URL: . , ; : ! ? ) ] }
					// Keep stripping so "foo.html.)" → "foo.html".
					$trailing = '';
					while ( '' !== $url && false !== strpos( '.,;:!?)]}', substr( $url, -1 ) ) ) {
						$trailing = substr( $url, -1 ) . $trailing;
						$url      = substr( $url, 0, -1 );
					}

					// TikTok video URL: skip wp_oembed_get (which returns the
					// script-laden blockquote) and emit the iframe directly.
					$tiktok_id = self::extract_tiktok_video_id( $url );
					if ( '' !== $tiktok_id ) {
						return '<div class="jt-embed">' . self::tiktok_iframe( $tiktok_id ) . '</div>' . $trailing;
					}

					$embed = wp_oembed_get( $url, array( 'width' => 680 ) );
					if ( $embed ) {
						return '<div class="jt-embed">' . $embed . '</div>' . $trailing;
					}

					return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>' . $trailing;
				},
				$part
			);
		}

		$output = implode( '', $parts );

		// Unwrap any `<p>…<div class="jt-embed">…</div>…</p>` patterns: a
		// block-level `<div>` inside a `<p>` produces invalid HTML which
		// browsers recover from by silently closing the `<p>`. Pull the embed
		// (and any sibling text) out so the DOM stays valid.
		$output = preg_replace(
			'#<p>\s*(<div class="jt-embed">.*?</div>)\s*</p>#is',
			'$1',
			$output
		);

		return $output;
	}

	/**
	 * Build a script-free iframe embed for a TikTok video. Matches what
	 * TikTok's own embed.js would have produced for the canonical URL, but
	 * skips the script loader entirely so the embed survives our kses pass.
	 *
	 * @param string $video_id TikTok video ID (digits only).
	 * @param int    $width    Player width in px. Height is derived 9:16-ish.
	 */
	private static function tiktok_iframe( string $video_id, int $width = 680 ): string {
		$video_id = preg_replace( '/[^0-9]/', '', $video_id );
		if ( '' === $video_id ) {
			return '';
		}
		$height = (int) round( $width * 1.35 );
		return sprintf(
			'<iframe src="https://www.tiktok.com/embed/v2/%s" width="%d" height="%d" style="max-width:100%%;border:none;" allow="encrypted-media;" allowfullscreen loading="lazy"></iframe>',
			esc_attr( $video_id ),
			$width,
			$height
		);
	}

	/**
	 * Pull a TikTok video ID from any of the URL shapes the platform uses.
	 * Returns '' when the URL isn't a TikTok video URL we can embed.
	 */
	private static function extract_tiktok_video_id( string $url ): string {
		if ( false === stripos( $url, 'tiktok.com' ) ) {
			return '';
		}
		// Canonical: https://www.tiktok.com/@user/video/<id>
		if ( preg_match( '#tiktok\.com/@[^/]+/video/(\d+)#i', $url, $m ) ) {
			return $m[1];
		}
		// Already-embed URL: https://www.tiktok.com/embed/v2/<id>
		if ( preg_match( '#tiktok\.com/embed/(?:v2/)?(\d+)#i', $url, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Replace any `<blockquote class="tiktok-embed">` tags with the
	 * script-free iframe form. Handles both the canonical data-video-id
	 * attribute and the fallback of extracting the ID from `cite="..."`.
	 */
	private static function replace_tiktok_blockquote( string $content ): string {
		if ( false === stripos( $content, 'tiktok-embed' ) ) {
			return $content;
		}
		// Strip any adjacent TikTok <script> tag (the loader that we don't
		// want in post content anyway). The blockquote replacement below
		// would have left this orphaned otherwise.
		$content = (string) preg_replace(
			'#<script[^>]*src=["\']https?://(?:www\.)?tiktok\.com/embed\.js["\'][^>]*>\s*</script>#i',
			'',
			$content
		);
		return (string) preg_replace_callback(
			'#<blockquote\b[^>]*\bclass=["\'][^"\']*tiktok-embed[^"\']*["\'][^>]*>.*?</blockquote>#is',
			static function ( $matches ) {
				$tag = $matches[0];
				$id  = '';
				if ( preg_match( '#\bdata-video-id=["\'](\d+)["\']#i', $tag, $m ) ) {
					$id = $m[1];
				} elseif ( preg_match( '#\bcite=["\'][^"\']*/video/(\d+)["\']#i', $tag, $m ) ) {
					$id = $m[1];
				} elseif ( preg_match( '#/video/(\d+)#i', $tag, $m ) ) {
					$id = $m[1];
				}
				if ( '' === $id ) {
					return $matches[0];
				}
				return '<div class="jt-embed">' . self::tiktok_iframe( $id ) . '</div>';
			},
			$content
		);
	}

	/**
	 * Paste paths that store the TikTok embed as entity-encoded text
	 * (`&lt;blockquote ...&gt;`) need a second pass. contenteditable serialises
	 * any pasted HTML source this way, so the real-tag replacer above never
	 * sees the markup. Match the encoded form and emit the same iframe.
	 */
	private static function replace_tiktok_encoded_blockquote( string $content ): string {
		if ( false === stripos( $content, 'tiktok-embed' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'#&lt;blockquote\b[^&]*?tiktok-embed.*?&lt;/blockquote&gt;(\s*&lt;script[^&]*?&gt;\s*&lt;/script&gt;)?#is',
			static function ( $matches ) {
				$decoded = html_entity_decode( $matches[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$id      = '';
				if ( preg_match( '#\bdata-video-id="(\d+)"#i', $decoded, $m ) ) {
					$id = $m[1];
				} elseif ( preg_match( '#\bcite="[^"]*/video/(\d+)"#i', $decoded, $m ) ) {
					$id = $m[1];
				} elseif ( preg_match( '#/video/(\d+)#i', $decoded, $m ) ) {
					$id = $m[1];
				}
				if ( '' === $id ) {
					return $matches[0];
				}
				return '<div class="jt-embed">' . self::tiktok_iframe( $id ) . '</div>';
			},
			$content
		);
	}
}
