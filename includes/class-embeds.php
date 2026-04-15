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
}
