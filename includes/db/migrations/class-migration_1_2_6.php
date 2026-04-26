<?php // phpcs:ignore WordPress.Files.FileName.NotHyphenatedLowercase, WordPress.Files.FileName.InvalidClassFileName
/**
 * Migration 1.2.6 - decode legacy HTML-entity-encoded titles.
 *
 * One-time sweep for rows whose human-readable title was stored already
 * HTML-encoded (e.g. "Welcome &amp; Introductions" instead of
 * "Welcome & Introductions"). Such rows reached customer sites from older
 * import/seed paths that are no longer in use. Rendering uses textContent
 * so the encoded form survives every escape layer and is visible in the UI.
 *
 * Tables swept: jt_spaces.title, jt_posts.title, jt_replies.content_plain
 * (content_plain only - content is rich HTML and entities there are expected).
 *
 * Idempotent: only touches rows whose current value still contains an HTML
 * entity pattern. Safe to re-run.
 *
 * @package Jetonomy
 */

namespace Jetonomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

class Migration_1_2_6 {

	/**
	 * Regex matching the HTML entity forms worth decoding. Narrow on purpose:
	 * we only touch values that legitimately round-trip through `html_entity_decode`.
	 */
	private const ENTITY_REGEX = '&(amp|quot|apos|#0?39|lt|gt|nbsp|ldquo|rdquo|lsquo|rsquo|ndash|mdash);';

	public function up(): void {
		global $wpdb;

		$this->decode_column( $wpdb, table( 'spaces' ), 'title' );
		$this->decode_column( $wpdb, table( 'posts' ), 'title' );
	}

	/**
	 * Decode every row of $column in $table whose value still contains an
	 * HTML entity. Uses a single prepared SELECT to get the affected ids,
	 * then per-row UPDATE so the decoded value is computed in PHP (MySQL
	 * has no portable html_entity_decode).
	 */
	private function decode_column( \wpdb $wpdb, string $table, string $column ): void {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, {$column} AS val FROM {$table} WHERE {$column} REGEXP %s",
				self::ENTITY_REGEX
			)
		);

		if ( ! $rows ) {
			return;
		}

		foreach ( $rows as $row ) {
			$decoded = html_entity_decode( (string) $row->val, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( $decoded === $row->val ) {
				continue;
			}
			$wpdb->update(
				$table,
				[ $column => $decoded ],
				[ 'id' => (int) $row->id ]
			);
		}
		// phpcs:enable
	}
}
