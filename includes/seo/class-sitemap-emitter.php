<?php
/**
 * Custom XML sitemap emitter.
 *
 * Replaces the WP-core sitemap providers for Jetonomy content so we can emit
 * <priority> and <changefreq> (WP core's renderer hardcodes only loc + lastmod).
 *
 * Scale model (target: 100k+ posts, 10k spaces):
 *   - Sitemap index at /{base}-sitemap.xml linking paginated children.
 *   - Children /{base}-sitemap-{spaces|posts}-{n}.xml, PAGE_SIZE URLs each.
 *   - KEYSET pagination (id > cursor), never LIMIT/OFFSET — page N is a pure
 *     primary-key range scan on the (status,id)/(visibility,status,id) indexes.
 *   - Per-type page cursors + rows + a per-space settings map are cached in
 *     transients (persistent without an object-cache drop-in) on a short TTL, so
 *     a crawl never re-runs the heavy queries and new content appears within TTL.
 *   - priority / changefreq come from filters (free defaults; Pro overrides via
 *     the same free-emits / pro-hooks contract as jetonomy_sitemap_exclude_space).
 *
 * @package Jetonomy
 */

namespace Jetonomy\SEO;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;
use function Jetonomy\base_url;
use function Jetonomy\seo_settings;

class Sitemap_Emitter {

	/** URLs per child sitemap. Well under the 50,000-URL / 50MB hard limit. */
	private const PAGE_SIZE = 5000;

	/** Cache TTL. Sitemaps tolerate mild staleness; this avoids per-write bust. */
	private const CACHE_TTL = HOUR_IN_SECONDS;

	private const TYPES = array( 'spaces', 'posts' );

	/**
	 * Entry point from the router. Emits XML and exits.
	 *
	 * @param string $type Child type ('spaces'|'posts') or '' for the index.
	 * @param int    $page 1-based child page ( >0 for a child ).
	 */
	public static function render( string $type, int $page ): void {
		$seo = seo_settings();
		if ( empty( $seo['seo_sitemap'] ) ) {
			status_header( 404 );
			exit;
		}

		// Crawler-facing XML; keep the sitemap files themselves out of the index.
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );

		if ( '' === $type ) {
			echo self::render_index(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} elseif ( in_array( $type, self::TYPES, true ) && $page >= 1 ) {
			echo self::render_urlset( $type, $page ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} else {
			status_header( 404 );
		}
		exit;
	}

	/**
	 * The <sitemapindex> listing every child page for both types.
	 */
	private static function render_index(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( self::TYPES as $type ) {
			$meta  = self::cursors( $type );
			$pages = max( 1, count( $meta['cursors'] ) );
			// A type with zero URLs still lists page 1 (an empty urlset) so the
			// index shape is stable; crawlers tolerate an empty child.
			if ( 0 === $meta['total'] ) {
				$pages = 0;
			}
			for ( $p = 1; $p <= $pages; $p++ ) {
				$xml .= "\t<sitemap>\n";
				$xml .= "\t\t<loc>" . esc_url( self::child_url( $type, $p ) ) . "</loc>\n";
				if ( ! empty( $meta['lastmod'] ) ) {
					$xml .= "\t\t<lastmod>" . esc_html( self::w3c( $meta['lastmod'] ) ) . "</lastmod>\n";
				}
				$xml .= "\t</sitemap>\n";
			}
		}

		$xml .= '</sitemapindex>' . "\n";
		return $xml;
	}

	/**
	 * A child <urlset> for one type + page. Cached per (type,page).
	 */
	private static function render_urlset( string $type, int $page ): string {
		$cache_key = 'jt_sitemap_' . $type . '_' . $page;
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$meta = self::cursors( $type );
		if ( $page > count( $meta['cursors'] ) && $meta['total'] > 0 ) {
			status_header( 404 );
			return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>';
		}

		$start_after = ( $page <= 1 ) ? 0 : (int) ( $meta['cursors'][ $page - 2 ] ?? 0 );
		$rows        = ( 'spaces' === $type )
			? self::fetch_spaces( $start_after )
			: self::fetch_posts( $start_after );

		// Prefetch the per-space SEO map ONCE for this page (exclude + priority +
		// changefreq) so the Pro filters below never hit the DB per row (the N+1
		// the old per-provider design had at scale). Space rows expose their own
		// id; post rows expose space_id.
		$space_ids = array();
		foreach ( $rows as $r ) {
			$space_ids[] = ( 'spaces' === $type ) ? (int) $r->id : (int) $r->space_id;
		}
		/**
		 * Let consumers (Pro seo) warm a request-static settings cache for these
		 * spaces in one query before the per-row priority/exclude filters fire.
		 *
		 * @param int[] $space_ids Space IDs on this page.
		 */
		do_action( 'jetonomy_sitemap_prime_spaces', array_values( array_unique( $space_ids ) ) );

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

		foreach ( $rows as $row ) {
			$space_id = ( 'spaces' === $type ) ? (int) $row->id : (int) $row->space_id;

			/** Reuse the exclusion filter the WP-core providers already fired. */
			if ( apply_filters( 'jetonomy_sitemap_exclude_space', false, $space_id ) ) {
				continue;
			}

			if ( 'spaces' === $type ) {
				$loc     = base_url() . '/s/' . $row->slug . '/';
				$lastmod = $row->last_activity_at ?: $row->updated_at;
				$def_pri = 0.7;
				$def_frq = self::freq_from_age( $lastmod );
			} else {
				$loc     = base_url() . '/s/' . $row->space_slug . '/t/' . $row->post_slug . '/';
				$lastmod = $row->last_reply_at ?: ( $row->updated_at ?: $row->created_at );
				$def_pri = 0.5;
				$def_frq = self::freq_from_age( $lastmod );
			}

			/**
			 * Per-URL priority (0.0-1.0). Pro's seo returns the per-space
			 * sitemap_priority here. $object is the DB row.
			 *
			 * @param float  $priority Default priority.
			 * @param string $type     'spaces'|'posts'.
			 * @param object $object   The row.
			 * @param int    $space_id Owning space id.
			 */
			$priority   = (float) apply_filters( 'jetonomy_sitemap_priority', $def_pri, $type, $row, $space_id );
			$priority   = max( 0.0, min( 1.0, $priority ) );
			$changefreq = (string) apply_filters( 'jetonomy_sitemap_changefreq', $def_frq, $type, $row, $space_id );

			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . esc_url( $loc ) . "</loc>\n";
			if ( $lastmod ) {
				$xml .= "\t\t<lastmod>" . esc_html( self::w3c( $lastmod ) ) . "</lastmod>\n";
			}
			if ( in_array( $changefreq, array( 'always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never' ), true ) ) {
				$xml .= "\t\t<changefreq>" . esc_html( $changefreq ) . "</changefreq>\n";
			}
			$xml .= "\t\t<priority>" . esc_html( number_format( $priority, 1 ) ) . "</priority>\n";
			$xml .= "\t</url>\n";
		}

		$xml .= '</urlset>' . "\n";

		set_transient( $cache_key, $xml, self::CACHE_TTL );
		return $xml;
	}

	/**
	 * Compute (and cache) the page cursors for a type: the last id of each page,
	 * the total URL count, and the newest lastmod. One covering-index scan of ids.
	 *
	 * @return array{cursors:int[], total:int, lastmod:string}
	 */
	private static function cursors( string $type ): array {
		$cache_key = 'jt_sitemap_cursors_' . $type;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['cursors'], $cached['total'] ) ) {
			return $cached;
		}

		global $wpdb;
		$sp = table( 'spaces' );

		if ( 'spaces' === $type ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( "SELECT id FROM {$sp} WHERE visibility = 'public' AND status = 'active' ORDER BY id ASC" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lastmod = (string) $wpdb->get_var( "SELECT MAX(GREATEST(COALESCE(last_activity_at,'1970-01-01'), COALESCE(updated_at,'1970-01-01'))) FROM {$sp} WHERE visibility = 'public' AND status = 'active'" );
		} else {
			$pt = table( 'posts' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col( "SELECT p.id FROM {$pt} p INNER JOIN {$sp} s ON p.space_id = s.id WHERE p.status = 'publish' AND p.is_private = 0 AND s.visibility = 'public' AND s.status = 'active' ORDER BY p.id ASC" );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$lastmod = (string) $wpdb->get_var( "SELECT MAX(GREATEST(COALESCE(p.last_reply_at,'1970-01-01'), COALESCE(p.updated_at,'1970-01-01'), COALESCE(p.created_at,'1970-01-01'))) FROM {$pt} p INNER JOIN {$sp} s ON p.space_id = s.id WHERE p.status = 'publish' AND p.is_private = 0 AND s.visibility = 'public' AND s.status = 'active'" );
		}

		$ids     = array_map( 'intval', (array) $ids );
		$total   = count( $ids );
		$cursors = array();
		for ( $i = self::PAGE_SIZE - 1; $i < $total; $i += self::PAGE_SIZE ) {
			$cursors[] = $ids[ $i ]; // last id of each full page
		}
		if ( $total > 0 && ( 0 === count( $cursors ) || end( $cursors ) !== $ids[ $total - 1 ] ) ) {
			$cursors[] = $ids[ $total - 1 ]; // trailing partial page
		}

		$meta = array(
			'cursors' => $cursors,
			'total'   => $total,
			'lastmod' => $lastmod,
		);
		set_transient( $cache_key, $meta, self::CACHE_TTL );
		return $meta;
	}

	/** Keyset page of spaces after $start_after. */
	private static function fetch_spaces( int $start_after ): array {
		global $wpdb;
		$sp = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, slug, last_activity_at, updated_at FROM {$sp} WHERE visibility = 'public' AND status = 'active' AND id > %d ORDER BY id ASC LIMIT %d",
				$start_after,
				self::PAGE_SIZE
			)
		);
	}

	/** Keyset page of posts after $start_after. */
	private static function fetch_posts( int $start_after ): array {
		global $wpdb;
		$pt = table( 'posts' );
		$sp = table( 'spaces' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.id, p.slug AS post_slug, s.slug AS space_slug, s.id AS space_id, p.last_reply_at, p.updated_at, p.created_at
				 FROM {$pt} p INNER JOIN {$sp} s ON p.space_id = s.id
				 WHERE p.status = 'publish' AND p.is_private = 0 AND s.visibility = 'public' AND s.status = 'active' AND p.id > %d
				 ORDER BY p.id ASC LIMIT %d",
				$start_after,
				self::PAGE_SIZE
			)
		);
	}

	/** Derive a sensible changefreq default from how recently a URL changed. */
	private static function freq_from_age( ?string $lastmod ): string {
		if ( ! $lastmod ) {
			return 'monthly';
		}
		$age = time() - (int) strtotime( $lastmod . ' UTC' );
		if ( $age < 2 * DAY_IN_SECONDS ) {
			return 'daily';
		}
		if ( $age < 30 * DAY_IN_SECONDS ) {
			return 'weekly';
		}
		if ( $age < 365 * DAY_IN_SECONDS ) {
			return 'monthly';
		}
		return 'yearly';
	}

	/** Child sitemap URL for a type + page. */
	private static function child_url( string $type, int $page ): string {
		return home_url( '/' . self::base_prefix() . '-sitemap-' . $type . '-' . $page . '.xml' );
	}

	/**
	 * The base slug used for the sitemap URLs. Matches the router's
	 * get_base_slug() exactly so the emitted child URLs line up with the rewrite.
	 */
	public static function base_prefix(): string {
		$settings = get_option( 'jetonomy_settings', array() );
		return (string) ( $settings['base_slug'] ?? 'community' );
	}

	/** Stored UTC datetime → W3C/ISO8601 (sitemaps expect W3C). */
	private static function w3c( string $mysql_utc ): string {
		$ts = (int) strtotime( $mysql_utc . ' UTC' );
		return $ts > 0 ? gmdate( 'c', $ts ) : gmdate( 'c' );
	}
}
