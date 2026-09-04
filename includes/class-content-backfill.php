<?php
/**
 * One-time remediation for `content_plain` values derived by the old bare strip.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Re-derives the stored plain-text copy of post and reply bodies.
 *
 * Why this class exists
 * ---------------------
 * Until 1.9.5 every writer of `content_plain` ran its own inline
 * `wp_strip_all_tags( $content )`. That leaves HTML entities encoded, so a body
 * reading "R&D" stored a plain copy reading the five literal characters
 * "&amp;", and it closes block boundaries, so "…link.</p><blockquote>nested…"
 * stored "link.nested". {@see \jetonomy_content_to_plain()} is now the single
 * derivation and every writer routes through it — but fixing the writer does
 * not fix the rows already written, and `content_plain` is what feed excerpts,
 * notification text, search snippets, FULLTEXT search and the mobile app's
 * Quote action all read (Basecamp 10207964048).
 *
 * On-demand, not automatic
 * ------------------------
 * Runs ONLY when a site owner asks, via `wp jetonomy content scan-plain` and
 * `wp jetonomy content backfill-plain`. It is the same call as
 * {@see Privacy_Backfill} and {@see Space_Backfill}: migrations run inline on
 * the first request after an update, including an anonymous frontend hit, and
 * a full rewrite of two of the largest tables on the site is not something an
 * update should do to somebody unannounced.
 *
 * Unlike those two it is NOT destructive — it recomputes a derived column from
 * the `content` column that stays untouched, so a re-run is a no-op and an
 * interrupted run simply resumes.
 *
 * Scale
 * -----
 * Keyset pagination on the primary key (`WHERE id > cursor ORDER BY id`), not
 * LIMIT/OFFSET, so page N costs the same as page 1 on a 500k-row table. Each
 * pass runs under a wall-clock budget and stores its cursor, so a 2M-row site
 * drains across as many invocations as it needs without ever holding a long
 * transaction. Only rows whose derived value actually CHANGED are written.
 */
final class Content_Backfill {

	/** Durable per-table cursor so a run resumes where the last one stopped. */
	private const CURSOR_OPTION = 'jetonomy_content_plain_backfill_cursor';

	/** Rows read per query. Small enough to stay well inside memory on wide bodies. */
	private const BATCH = 500;

	/**
	 * Tables carrying a derived `content_plain`, in sweep order.
	 *
	 * @return string[] Table suffixes (unprefixed).
	 */
	public static function tables(): array {
		return array( 'jt_posts', 'jt_replies' );
	}

	/**
	 * Total rows to consider, per table. COUNT(*), never a materialised list.
	 *
	 * @return array<string,int>
	 */
	public static function count_rows(): array {
		global $wpdb;

		$out = array();
		foreach ( self::tables() as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $suffix ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}
		return $out;
	}

	/**
	 * Walk a slice of rows, reporting (and optionally fixing) stale plain copies.
	 *
	 * @param bool  $apply   False to report only; true to write the corrected value.
	 * @param float $seconds Wall-clock budget for this pass.
	 * @return array{stale:int,fixed:int,scanned:int,done:bool,cursor:array<string,int>}
	 */
	public static function run_batch( bool $apply, float $seconds = 15.0 ): array {
		global $wpdb;

		/**
		 * Filter the wall-clock budget for one content_plain backfill pass.
		 *
		 * @param float $seconds Default 15.
		 */
		$seconds  = (float) apply_filters( 'jetonomy_content_plain_backfill_batch_seconds', $seconds );
		$deadline = microtime( true ) + max( 1.0, $seconds );

		$cursor  = self::cursor();
		$stale   = 0;
		$fixed   = 0;
		$scanned = 0;
		$done    = true;

		foreach ( self::tables() as $suffix ) {
			$table = $wpdb->prefix . $suffix;
			if ( ! self::table_exists( $table ) ) {
				continue;
			}

			$last = (int) ( $cursor[ $suffix ] ?? 0 );

			while ( microtime( true ) < $deadline ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT id, content, content_plain FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d",
						$last,
						self::BATCH
					)
				);

				if ( empty( $rows ) ) {
					$last = 0; // Table drained — reset so a re-run starts clean.
					break;
				}

				foreach ( $rows as $row ) {
					++$scanned;
					$last    = (int) $row->id;
					$derived = \jetonomy_content_to_plain( (string) ( $row->content ?? '' ) );

					if ( $derived === (string) ( $row->content_plain ?? '' ) ) {
						continue;
					}

					++$stale;
					if ( $apply ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update( $table, array( 'content_plain' => $derived ), array( 'id' => (int) $row->id ) );
						++$fixed;
					}
				}
			}

			$cursor[ $suffix ] = $last;
			if ( 0 !== $last ) {
				$done = false; // Ran out of clock mid-table.
				break;
			}
		}

		if ( $done ) {
			delete_option( self::CURSOR_OPTION );
		} else {
			update_option( self::CURSOR_OPTION, $cursor, false );
		}

		if ( $fixed > 0 ) {
			// Set-based writes that cannot name every id they touched — exactly
			// the one-shot recompute case Cache::flush() documents (§4d).
			Cache::flush();
		}

		return array(
			'stale'   => $stale,
			'fixed'   => $fixed,
			'scanned' => $scanned,
			'done'    => $done,
			'cursor'  => $cursor,
		);
	}

	/**
	 * Resume point from the last interrupted pass.
	 *
	 * @return array<string,int>
	 */
	private static function cursor(): array {
		$stored = get_option( self::CURSOR_OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}
}
