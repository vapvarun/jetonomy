<?php
/**
 * Posts sitemap provider.
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

use WP_Sitemaps_Provider;
use function Jetonomy\table;

class Posts_Sitemap_Provider extends WP_Sitemaps_Provider {

	public $name        = 'jetonomyposts';
	public $object_type = 'jetonomypost';

	public function get_url_list( $page_num, $object_subtype = '' ) {
		global $wpdb;
		$pt     = table( 'posts' );
		$st     = table( 'spaces' );
		$offset = ( $page_num - 1 ) * 2000;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.slug AS post_slug, s.slug AS space_slug, p.last_reply_at, p.updated_at, p.created_at
             FROM {$pt} p
             INNER JOIN {$st} s ON p.space_id = s.id
             WHERE p.status = 'publish' AND s.visibility = 'public' AND s.status = 'active'
             ORDER BY p.id ASC
             LIMIT 2000 OFFSET %d",
				$offset
			)
		);

		$urls = [];
		$base = \Jetonomy\base_url() . '/s/';

		foreach ( $posts as $post ) {
			$urls[] = [
				'loc'     => $base . $post->space_slug . '/t/' . $post->post_slug . '/',
				'lastmod' => $post->last_reply_at ?: $post->updated_at ?: $post->created_at,
			];
		}

		return $urls;
	}

	public function get_max_num_pages( $object_subtype = '' ) {
		global $wpdb;
		$pt = table( 'posts' );
		$st = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$pt} p INNER JOIN {$st} s ON p.space_id = s.id WHERE p.status = 'publish' AND s.visibility = 'public'"
		);
		return (int) ceil( $total / 2000 );
	}
}
