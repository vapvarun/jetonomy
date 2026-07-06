<?php
/**
 * Sitemap wiring.
 *
 * Jetonomy content is served by a CUSTOM sitemap (Sitemap_Emitter, via the
 * router rewrite) rather than WP-core providers, so it can carry
 * <priority>/<changefreq>. This class no longer registers WP-core providers; it
 * just advertises the custom sitemap in robots.txt.
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function __construct() {
		add_filter( 'robots_txt', array( $this, 'add_sitemap_line' ), 10, 2 );
	}

	/**
	 * Append a `Sitemap:` line for the custom community sitemap so crawlers
	 * discover it. Gated on the same seo_sitemap toggle as the emitter.
	 *
	 * @param string $output The robots.txt content.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public function add_sitemap_line( string $output, $public ): string {
		if ( ! $public ) {
			return $output;
		}
		$settings = \Jetonomy\seo_settings();
		if ( empty( $settings['seo_sitemap'] ) ) {
			return $output;
		}

		$url = home_url( '/' . Sitemap_Emitter::base_prefix() . '-sitemap.xml' );
		if ( false === strpos( $output, $url ) ) {
			$output .= "\nSitemap: " . esc_url_raw( $url ) . "\n";
		}
		return $output;
	}
}
