<?php
namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

class Sitemap {

	public function __construct() {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		$sitemaps = wp_sitemaps_get_server();
		if ( ! $sitemaps ) {
			return;
		}

		$sitemaps->registry->add_provider( 'jetonomyspaces', new Spaces_Sitemap_Provider() );
		$sitemaps->registry->add_provider( 'jetonomyposts', new Posts_Sitemap_Provider() );
	}
}
