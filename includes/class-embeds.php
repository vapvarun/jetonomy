<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Embeds {

	/**
	 * Process content and convert standalone URLs to embeds.
	 *
	 * Matches URLs on their own line (optionally wrapped in <p> tags)
	 * and replaces them with oEmbed HTML when WordPress can embed the URL.
	 *
	 * @param string $content HTML content to process.
	 * @return string Content with standalone URLs converted to embeds.
	 */
	public static function process( string $content ): string {
		return preg_replace_callback(
			'#(?:<p>)?\s*(https?://[^\s<>"]+?)\s*(?:</p>)?(?=\s|$)#i',
			function ( $matches ) {
				$url = trim( $matches[1] );

				// Check if WP can embed this URL.
				$embed = wp_oembed_get( $url, [ 'width' => 680 ] );
				if ( $embed ) {
					return '<div class="jt-embed">' . $embed . '</div>';
				}

				// If not embeddable, return original URL as a link.
				return '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $url ) . '</a>';
			},
			$content
		);
	}
}
