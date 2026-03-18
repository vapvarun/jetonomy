<?php
namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

use WP_Sitemaps_Provider;
use function Jetonomy\table;

class Spaces_Sitemap_Provider extends WP_Sitemaps_Provider {

	public $name        = 'jetonomyspaces';
	public $object_type = 'jetonomyspace';

	public function get_url_list( $page_num, $object_subtype = '' ) {
		global $wpdb;
		$t      = table( 'spaces' );
		$offset = ( $page_num - 1 ) * 2000;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$spaces = $wpdb->get_results( $wpdb->prepare(
			"SELECT slug, last_activity_at, updated_at FROM {$t} WHERE visibility = 'public' AND status = 'active' ORDER BY id ASC LIMIT 2000 OFFSET %d",
			$offset
		) );

		$urls = [];
		$base = home_url( '/community/s/' );

		foreach ( $spaces as $space ) {
			$urls[] = [
				'loc'     => $base . $space->slug . '/',
				'lastmod' => $space->last_activity_at ?: $space->updated_at,
			];
		}

		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		global $wpdb;
		$t     = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE visibility = 'public' AND status = 'active'" );
		return (int) ceil( $total / 2000 );
	}
}
