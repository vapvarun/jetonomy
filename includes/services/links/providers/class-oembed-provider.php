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

	public function id(): string {
		return 'oembed';
	}

	public function supports( string $url, string $host ): bool {
		$oembed = _wp_oembed_get_object();
		if ( ! method_exists( $oembed, 'get_provider' ) ) {
			return false;
		}
		return false !== $oembed->get_provider( $url, array( 'discover' => false ) );
	}

	public function hydrate( string $url, Preview_Data $out ): Preview_Data {
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
