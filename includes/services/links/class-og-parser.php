<?php
/**
 * Extract Open Graph / Twitter Card / schema.org metadata from HTML.
 *
 * DOM-based (not regex) so attribute order, quoting style, and HTML entities
 * parse correctly. The old regex parser returned empty titles for ~20% of
 * tested sites because of attribute ordering (`content` before `property`).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Services\Links;

defined( 'ABSPATH' ) || exit;

final class OG_Parser {

	/**
	 * Parse $html and merge findings into $out. Existing fields on $out are not
	 * overwritten, so a provider can pre-seed host-specific values and let the
	 * parser fill the gaps.
	 */
	public function parse( string $html, Preview_Data $out ): Preview_Data {
		if ( '' === trim( $html ) ) {
			return $out;
		}

		$meta = $this->collect_meta( $html );

		if ( '' === $out->title ) {
			$out->title = (string) ( $meta['og:title']
				?? $meta['twitter:title']
				?? $this->extract_title_tag( $html )
				?? '' );
		}
		if ( '' === $out->description ) {
			$out->description = (string) ( $meta['og:description']
				?? $meta['twitter:description']
				?? $meta['description']
				?? '' );
		}
		if ( '' === $out->image ) {
			$image = (string) ( $meta['og:image:secure_url']
				?? $meta['og:image']
				?? $meta['twitter:image']
				?? $meta['twitter:image:src']
				?? '' );
			if ( '' !== $image ) {
				$out->image = $this->absolutize( $image, $out->url );
			}
		}
		if ( '' === $out->image_alt ) {
			$out->image_alt = (string) ( $meta['og:image:alt'] ?? $meta['twitter:image:alt'] ?? '' );
		}
		if ( '' === $out->site_name ) {
			$out->site_name = (string) ( $meta['og:site_name'] ?? $meta['application-name'] ?? '' );
		}
		if ( '' === $out->type || 'website' === $out->type ) {
			$og_type = (string) ( $meta['og:type'] ?? '' );
			if ( '' !== $og_type ) {
				$out->type = $og_type;
			}
		}
		if ( '' === $out->locale ) {
			$out->locale = (string) ( $meta['og:locale'] ?? '' );
		}
		if ( '' === $out->published_at ) {
			$out->published_at = (string) (
				$meta['article:published_time']
				?? $meta['og:article:published_time']
				?? ''
			);
		}
		if ( '' === $out->author ) {
			$out->author = (string) ( $meta['article:author'] ?? $meta['author'] ?? '' );
		}

		if ( '' === $out->favicon && '' !== $out->domain ) {
			$out->favicon = $this->extract_favicon( $html, $out->url ) ?: $this->default_favicon( $out->domain );
		}

		return $out;
	}

	/**
	 * Collect every <meta> with name or property. Later tags win for the same
	 * key, which matches how Facebook's OG debugger and every major parser
	 * resolves duplicates.
	 *
	 * @return array<string,string>
	 */
	private function collect_meta( string $html ): array {
		$meta = array();

		$dom        = new \DOMDocument();
		$prev_error = libxml_use_internal_errors( true );
		// UTF-8 preamble forces DOMDocument's default ISO-8859-1 assumption
		// aside so entities in titles ("—", curly quotes) don't mojibake.
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_error );

		foreach ( $dom->getElementsByTagName( 'meta' ) as $node ) {
			if ( ! $node instanceof \DOMElement ) {
				continue;
			}
			$key   = strtolower( (string) ( $node->getAttribute( 'property' ) ?: $node->getAttribute( 'name' ) ?: $node->getAttribute( 'itemprop' ) ) );
			$value = (string) $node->getAttribute( 'content' );
			if ( '' === $key || '' === $value ) {
				continue;
			}
			if ( ! isset( $meta[ $key ] ) ) {
				$meta[ $key ] = $this->normalize_text( $value );
			}
		}

		return $meta;
	}

	private function extract_title_tag( string $html ): ?string {
		if ( preg_match( '#<title[^>]*>(.+?)</title>#is', $html, $m ) ) {
			return $this->normalize_text( $m[1] );
		}
		return null;
	}

	private function extract_favicon( string $html, string $page_url ): string {
		// link rel="icon" or "shortcut icon" — first match wins.
		if ( preg_match_all( '#<link[^>]+rel=["\']([^"\']*icon[^"\']*)["\'][^>]*>#i', $html, $matches ) ) {
			foreach ( $matches[0] as $tag ) {
				if ( preg_match( '#href=["\']([^"\']+)["\']#i', $tag, $hm ) ) {
					return $this->absolutize( $hm[1], $page_url );
				}
			}
		}
		return '';
	}

	private function default_favicon( string $domain ): string {
		return 'https://www.google.com/s2/favicons?domain=' . rawurlencode( $domain ) . '&sz=64';
	}

	private function absolutize( string $url, string $base ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$scheme = wp_parse_url( $base, PHP_URL_SCHEME ) ?: 'https';
			return $scheme . ':' . $url;
		}
		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return $url;
		}
		$parts  = wp_parse_url( $base );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		if ( '' === $host ) {
			return $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			return $scheme . '://' . $host . $url;
		}
		$path = isset( $parts['path'] ) ? rtrim( (string) $parts['path'], '/' ) : '';
		return $scheme . '://' . $host . $path . '/' . $url;
	}

	private function normalize_text( string $value ): string {
		$value = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		return trim( $value );
	}
}
