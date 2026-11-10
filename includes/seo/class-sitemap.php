<?php
/**
 * Sitemap wiring.
 *
 * Jetonomy content is served by a CUSTOM sitemap (Sitemap_Emitter, via the
 * router rewrite) rather than WP-core providers, so it can carry
 * <priority>/<changefreq>. This class registers no WP-core providers; it
 * just advertises the custom sitemap in robots.txt.
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function __construct() {
		add_filter( 'robots_txt', array( $this, 'add_sitemap_line' ), 10, 2 );
		// A purged space's URLs leave the sitemap at once (Sitemap_Emitter::flush()).
		add_action( 'jetonomy_space_purged', array( Sitemap_Emitter::class, 'flush' ), 10, 0 );
		// A topic that becomes public joins it at once too (leaving is flushed by Post::update()).
		add_action(
			'jetonomy_post_publish_transition',
			static function ( $post_id, $delta ): void {
				if ( (int) $delta > 0 ) {
					Sitemap_Emitter::flush();
				}
			},
			10,
			2
		);
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
