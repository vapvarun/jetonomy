<?php
/**
 * Sitemap registration.
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		// Respect seo_sitemap toggle (defaults On, matching the admin UI — via
		// the shared seo_settings() defaults union so render == consume).
		$settings = \Jetonomy\seo_settings();
		if ( empty( $settings['seo_sitemap'] ) ) {
			return;
		}

		$sitemaps = wp_sitemaps_get_server();
		if ( ! $sitemaps ) {
			return;
		}

		$sitemaps->registry->add_provider( 'jetonomyspaces', new Spaces_Sitemap_Provider() );
		$sitemaps->registry->add_provider( 'jetonomyposts', new Posts_Sitemap_Provider() );
	}
}
