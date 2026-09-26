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

		$table = self::target_table( $object_type );
		if ( '' === $table ) {
			// Unknown type: assume it exists rather than deleting a mapping we
			// do not understand.
			return true;
		}

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
	 * Prefixed Jetonomy table an object type maps onto, or '' if unknown.
	 *
	 * @param string $object_type 'space', 'post', 'reply', 'category'.
	 * @return string
	 */
	private static function target_table( string $object_type ): string {
		$tables = array(
			'space'    => 'spaces',
			'post'     => 'posts',
			'reply'    => 'replies',
			'category' => 'categories',
		);

		return isset( $tables[ $object_type ] ) ? \Jetonomy\table( $tables[ $object_type ] ) : '';
	}

	/**
	 * Record many source rows at once - one INSERT instead of one per row.
	 *
	 * Used when a re-run recognises a whole batch of rows an earlier import
	 * created, which on a large board is hundreds of rows per batch.
	 *
	 * @param string            $source      Importer slug.
	 * @param string            $object_type Object type.
	 * @param array<string,int> $pairs       source_id => object_id.
	 * @return void
	 */
	public static function record_many( string $source, string $object_type, array $pairs ): void {
		global $wpdb;

		$table = self::table();
		$now   = now();

		foreach ( array_chunk( $pairs, 200, true ) as $chunk ) {
			$values = array();
			foreach ( $chunk as $source_id => $object_id ) {
				if ( (int) $object_id <= 0 ) {
					continue;
				}
				$values[] = $wpdb->prepare( '(%s, %s, %s, %d, %s)', $source, $object_type, (string) $source_id, (int) $object_id, $now );
			}
			if ( ! $values ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- every tuple is prepared above; table() is a trusted prefixed name.
			$wpdb->query(
				"INSERT INTO {$table} (source, object_type, source_id, object_id, created_at) VALUES " . implode( ',', $values ) . ' ON DUPLICATE KEY UPDATE object_id = VALUES(object_id)'
			);
		}
	}

	/**
	 * Batched find(): which of these source rows did this importer create?
	 *
	 * One query per chunk instead of one (plus an existence check) per row, so
	 * a 500-row batch costs one round trip. Mappings whose target has since
	 * been deleted are dropped, exactly as find() does, so the content is
	 * recreated rather than skipped forever.
	 *
	 * @param string       $source      Importer slug.
	 * @param string       $object_type 'space', 'post', 'reply', 'category'.
	 * @param array<mixed> $source_ids  Source primary keys.
	 * @return array<string,int> source_id => live Jetonomy id. Unmapped ids are absent.
	 */
	public static function find_many( string $source, string $object_type, array $source_ids ): array {
		global $wpdb;

		$target = self::target_table( $object_type );
		$table  = self::table();
		$found  = array();

		foreach ( array_chunk( array_map( 'strval', array_values( array_unique( $source_ids ) ) ), 500 ) as $chunk ) {
			$in = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			$live_col = '' === $target ? 'm.object_id' : 't.id';
			$join     = '' === $target ? '' : "LEFT JOIN {$target} t ON t.id = m.object_id";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table names; $in is a placeholder list.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.source_id, m.object_id, {$live_col} AS live FROM {$table} m {$join}
					 WHERE m.source = %s AND m.object_type = %s AND m.source_id IN ({$in})",
					$source,
					$object_type,
					...$chunk
				)
			);

			$stale = array();
			foreach ( (array) $rows as $row ) {
				if ( $row->live ) {
					$found[ (string) $row->source_id ] = (int) $row->object_id;
				} else {
					$stale[] = (string) $row->source_id;
				}
			}

			if ( $stale ) {
				$in_stale = implode( ',', array_fill( 0, count( $stale ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table name; placeholder list.
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE source = %s AND object_type = %s AND source_id IN ({$in_stale})", $source, $object_type, ...$stale ) );
			}
		}

		return $found;
	}

	/**
	 * Recognise rows an importer created BEFORE it kept this map.
	 *
	 * The jt_import_map table arrived in 2.0.0. bbPress imports from 1.9.x, and every
	 * wpForo / Asgaros import up to 2.0.0, wrote no map rows at all, so on those
	 * sites the map is empty and a re-import would duplicate everything. The
	 * source ids were never stored anywhere else, so identity has to be inferred
	 * from what those versions did write. The heuristic, per type:
	 *
	 *  - space: same slug as the source forum AND sitting in an importer-created
	 *    category (slug `imported-<source>...`). Those versions wrote the source
	 *    slug verbatim (a collision failed the insert rather than suffixing), and
	 *    the category condition is what keeps the owner's own "general" space
	 *    from being adopted - the exact failure that retired slug matching in
	 *    2.0.0.
	 *  - post: same space + author + created_at (to the second).
	 *  - reply: same topic + author + created_at (to the second).
	 *
	 * Posts and replies are paired in id order within each identical
	 * fingerprint, so two replies one member posted in the same second map to
	 * two distinct rows instead of both claiming the first. Only rows no mapping
	 * already claims are candidates, so a row is never adopted twice and nothing
	 * a 2.0+ import created can be taken for legacy content.
	 *
	 * Known ceiling: a legacy row whose source date was empty was stamped with
	 * the import time, so it cannot be recognised and would import again.
	 *
	 * @param string $source      Importer slug, e.g. 'bbpress'.
	 * @param string $object_type 'space', 'post' or 'reply'.
	 * @param array  $rows        source_id => fingerprint. space: ['slug'];
	 *                            post/reply: ['parent', 'author', 'created'].
	 * @return array<string,int> source_id => Jetonomy id, for rows recognised.
	 */
	public static function match_legacy( string $source, string $object_type, array $rows ): array {
		global $wpdb;

		if ( ! $rows ) {
			return array();
		}

		$map     = self::table();
		$matched = array();

		if ( 'space' === $object_type ) {
			$slugs = array_values( array_unique( array_filter( array_map( 'strval', array_column( $rows, 'slug' ) ) ) ) );
			if ( ! $slugs ) {
				return array();
			}

			$spaces = \Jetonomy\table( 'spaces' );
			$cats   = \Jetonomy\table( 'categories' );
			$in     = implode( ',', array_fill( 0, count( $slugs ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table names; placeholder list.
			$by_slug = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT s.id, s.slug FROM {$spaces} s
					 INNER JOIN {$cats} c ON c.id = s.category_id
					 LEFT JOIN {$map} m ON m.object_type = 'space' AND m.object_id = s.id
					 WHERE m.id IS NULL AND c.slug LIKE %s AND s.slug IN ({$in})",
					$wpdb->esc_like( 'imported-' . $source ) . '%',
					...$slugs
				)
			);

			$space_ids = array();
			foreach ( (array) $by_slug as $space ) {
				$space_ids[ (string) $space->slug ] = (int) $space->id;
			}

			foreach ( $rows as $source_id => $fp ) {
				$slug = (string) ( $fp['slug'] ?? '' );
				if ( isset( $space_ids[ $slug ] ) ) {
					$matched[ (string) $source_id ] = $space_ids[ $slug ];
					unset( $space_ids[ $slug ] ); // One space per source forum.
				}
			}

			return $matched;
		}

		$parent_col = array(
			'post'  => 'space_id',
			'reply' => 'post_id',
		);
		if ( ! isset( $parent_col[ $object_type ] ) ) {
			return array();
		}
		$col    = $parent_col[ $object_type ];
		$target = self::target_table( $object_type );

		$parents = array_values( array_unique( array_map( 'intval', array_column( $rows, 'parent' ) ) ) );
		$dates   = array_values( array_unique( array_filter( array_map( 'strval', array_column( $rows, 'created' ) ) ) ) );
		if ( ! $dates ) {
			return array();
		}

		$in_p = implode( ',', array_fill( 0, count( $parents ), '%d' ) );
		$in_d = implode( ',', array_fill( 0, count( $dates ), '%s' ) );

		// Bounded by the batch: only this batch's parents AND this batch's exact
		// timestamps, served by the (space_id|post_id, created_at)-leading keys,
		// so a topic with 10k replies does not pull all 10k rows.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- trusted table/column names; placeholder lists.
		$candidates = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.id, t.{$col} AS parent, t.author_id AS author, t.created_at AS created FROM {$target} t
				 LEFT JOIN {$map} m ON m.object_type = %s AND m.object_id = t.id
				 WHERE m.id IS NULL AND t.{$col} IN ({$in_p}) AND t.created_at IN ({$in_d})
				 ORDER BY t.id ASC",
				$object_type,
				...array_merge( $parents, $dates )
			)
		);

		$buckets = array();
		foreach ( (array) $candidates as $c ) {
			$buckets[ (int) $c->parent . '|' . (int) $c->author . '|' . $c->created ][] = (int) $c->id;
		}

		foreach ( $rows as $source_id => $fp ) {
			$key = (int) ( $fp['parent'] ?? 0 ) . '|' . (int) ( $fp['author'] ?? 0 ) . '|' . (string) ( $fp['created'] ?? '' );
			if ( ! empty( $buckets[ $key ] ) ) {
				$matched[ (string) $source_id ] = array_shift( $buckets[ $key ] );
			}
		}

		return $matched;
	}

	/**
	 * The category an earlier import of this source filed its spaces under.
	 *
	 * Lets a re-run on a legacy site put the forums it fills in beside the ones
	 * already there, instead of opening another "Imported from ..." category.
	 * The oldest such category that actually holds a space wins, which skips
	 * the empty categories earlier re-runs left behind.
	 *
	 * @param string $slug_prefix Category slug prefix, e.g. 'imported-bbpress'.
	 * @return int Category id, or 0.
	 */
	public static function legacy_category( string $slug_prefix ): int {
		global $wpdb;

		$cats   = \Jetonomy\table( 'categories' );
		$spaces = \Jetonomy\table( 'spaces' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted table names.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT c.id FROM {$cats} c
				 WHERE c.slug LIKE %s AND EXISTS ( SELECT 1 FROM {$spaces} s WHERE s.category_id = c.id )
				 ORDER BY c.id ASC LIMIT 1",
				$wpdb->esc_like( $slug_prefix ) . '%'
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
