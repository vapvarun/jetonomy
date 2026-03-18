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

		$sitemaps->registry->add_provider( 'jetonomy-spaces', new Spaces_Sitemap_Provider() );
		$sitemaps->registry->add_provider( 'jetonomy-posts', new Posts_Sitemap_Provider() );
	}
}
