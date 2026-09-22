<?php
/**
 * Import map model — which source row produced which Jetonomy row.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Models;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\now;

/**
 * Durable source-row to Jetonomy-row mapping, shared by every importer.
 *
 * WHY THIS EXISTS. An importer has to answer "did I create this?" whenever it
 * runs a second time - to recover content an earlier version skipped without
 * duplicating what is already there. The first attempt answered by SLUG, which
 * is a different question and a dangerous one: a bbPress forum called "general"
 * matches the owner's own existing "general" space, so a re-run adopted it and
 * poured the source forum's topics into the owner's space. Where the source
 * forum was private, adoption also skipped the visibility mapping, so private
 * content could land in a public space.
 *
 * Identity has to come from the source, not from a name that anything else can
 * also hold. A row here is written at create time and read on every later run.
 *
 * It also makes reply de-duplication exact. The slug era matched replies on
 * post + author + timestamp-to-the-second, which silently dropped the second of
 * two replies posted by one member within the same second - on a FIRST run, not
 * just a re-run.
 */
class Import_Map {

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	private static function table(): string {
		return \Jetonomy\table( 'import_map' );
	}

	/**
	 * Record that a source row produced a Jetonomy row.
	 *
	 * Idempotent: re-recording the same source row updates the target rather
	 * than erroring, so a partially-completed batch can be re-run safely.
	 *
	 * @param string     $source      Importer slug, e.g. 'bbpress'.
	 * @param string     $object_type 'space', 'post', 'reply', 'category'.
	 * @param int|string $source_id   Primary key in the source system.
	 * @param int        $object_id   The Jetonomy row created.
	 * @return void
	 */
	public static function record( string $source, string $object_type, $source_id, int $object_id ): void {
		global $wpdb;

		if ( $object_id <= 0 ) {
			return;
		}

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source, object_type, source_id, object_id, created_at)
				 VALUES (%s, %s, %s, %d, %s)
				 ON DUPLICATE KEY UPDATE object_id = VALUES(object_id)",
				$source,
				$object_type,
				(string) $source_id,
				$object_id,
				now()
			)
		);
	}

	/**
	 * The Jetonomy row a source row produced, if this importer created one.
	 *
	 * Verifies the target still EXISTS before reporting it. An owner who
	 * deleted an imported space and re-ran the import should get it back, not
	 * have every topic skipped because a mapping row outlived its target.
	 *
	 * @param string     $source      Importer slug.
	 * @param string     $object_type 'space', 'post', 'reply', 'category'.
	 * @param int|string $source_id   Primary key in the source system.
	 * @return int Jetonomy row id, or 0.
	 */
	public static function find( string $source, string $object_type, $source_id ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name.
		$object_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT object_id FROM {$table} WHERE source = %s AND object_type = %s AND source_id = %s",
				$source,
				$object_type,
				(string) $source_id
			)
		);

		if ( $object_id <= 0 ) {
			return 0;
		}

		if ( ! self::target_exists( $object_type, $object_id ) ) {
			// Stale mapping: the row it pointed at is gone. Drop it so this run
			// recreates the content instead of skipping it forever.
			self::forget( $source, $object_type, $source_id );
			return 0;
		}

		return $object_id;
	}

	/**
	 * Does the mapped Jetonomy row still exist?
	 *
	 * @param string $object_type 'space', 'post', 'reply', 'category'.
	 * @param int    $object_id   Row id.
	 * @return bool
	 */
	private static function target_exists( string $object_type, int $object_id ): bool {
		global $wpdb;

		$tables = array(
			'space'    => 'spaces',
			'post'     => 'posts',
			'reply'    => 'replies',
			'category' => 'categories',
		);

		if ( ! isset( $tables[ $object_type ] ) ) {
			// Unknown type: assume it exists rather than deleting a mapping we
			// do not understand.
			return true;
		}

		$table = \Jetonomy\table( $tables[ $object_type ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $object_id ) );
	}

	/**
	 * Drop one mapping.
	 *
	 * @param string     $source      Importer slug.
	 * @param string     $object_type Object type.
	 * @param int|string $source_id   Source primary key.
	 * @return void
	 */
	public static function forget( string $source, string $object_type, $source_id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'source'      => $source,
				'object_type' => $object_type,
				'source_id'   => (string) $source_id,
			)
		);
	}

	/**
	 * How many rows one importer has recorded.
	 *
	 * Lets the import screen say "this source has been imported before" rather
	 * than leaving the owner to guess what a re-run will do.
	 *
	 * @param string $source Importer slug.
	 * @return int
	 */
	public static function count_for_source( string $source ): int {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE source = %s", $source ) );
	}
}
