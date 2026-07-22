<?php
/**
 * Space purge — every row a space owns, in one declared map.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

/**
 * Removes a space and everything hanging off it.
 *
 * One map, three consumers
 * ------------------------
 * {@see relations()} is the single declaration of how a space is referenced
 * across the schema. The purge, the orphan discovery in {@see Space_Backfill},
 * and the "what would this remove" report all derive from it. There is
 * deliberately no second list: a hand-maintained twin is how `ai_log` came to
 * be missing from the user purge for two releases ({@see Privacy_Backfill}),
 * and the space graph is three times the size, so the same mistake here would
 * be three times as quiet.
 *
 * Why a purge exists before anything calls it
 * -------------------------------------------
 * Today `Space::delete()` deletes the row and nothing else, so every space
 * ever deleted left its posts, replies, members, join requests and
 * notifications behind, pointing at an id that no longer resolves. This class
 * is how that damage gets swept up (via Space_Backfill), and it is deliberately
 * NOT wired into `Space::delete()` yet.
 *
 * That ordering is the point. Deletion is due to default to TRANSFERRING a
 * space to its next moderator rather than destroying it, with a permanent
 * purge as an opt-in the site owner controls. Wiring the cascade in first
 * would turn today's recoverable orphans into real data loss — other members'
 * topics and replies — with no safe default in place to choose instead. So the
 * engine lands first and stays unarmed; the delete flow adopts it once transfer
 * exists.
 *
 * Resolution order
 * ----------------
 * Post and reply ids are resolved BEFORE anything is deleted, because the rows
 * that identify them are themselves in the purge set. This works identically
 * for a live space and an already-orphaned one: `posts.space_id` still holds
 * the id whether or not the parent `spaces` row survives, which is what lets
 * the backfill replay this same body against damage done years ago.
 */
final class Space_Purge {

	/** Chunk size for `IN (...)` deletes, so a 50k-topic space never builds one enormous statement. */
	private const CHUNK = 500;

	/**
	 * Listen for orphan remediation.
	 *
	 * The backfill fires `jetonomy_purge_orphan_space` per id rather than
	 * calling this class directly, so Pro (and third-party code holding
	 * space-scoped rows) cleans up through the same signal instead of shipping
	 * a parallel sweeper.
	 */
	public static function register(): void {
		add_action( 'jetonomy_purge_orphan_space', [ self::class, 'purge' ] );
	}

	/**
	 * How every table in the schema refers to a space.
	 *
	 * `ref` names which id set the column's values belong to — a space id, a
	 * post id, or a reply id — which is what lets one declaration drive both
	 * the purge (delete WHERE column IN <set>) and orphan discovery (find
	 * values with no surviving parent).
	 *
	 * `where` narrows polymorphic tables to the rows of the relevant type.
	 * `self` marks the space row itself, which is deleted last.
	 *
	 * @return array<int,array{table:string,column:string,ref:string,where:string,self:bool}>
	 */
	public static function relations(): array {
		$rel = [
			// Direct space references.
			[ 'table' => table( 'posts' ), 'column' => 'space_id', 'ref' => 'space' ],
			[ 'table' => table( 'space_members' ), 'column' => 'space_id', 'ref' => 'space' ],
			[ 'table' => table( 'join_requests' ), 'column' => 'space_id', 'ref' => 'space' ],
			[ 'table' => table( 'access_rules' ), 'column' => 'space_id', 'ref' => 'space' ],
			[ 'table' => table( 'invite_links' ), 'column' => 'space_id', 'ref' => 'space' ],
			[ 'table' => table( 'restrictions' ), 'column' => 'space_id', 'ref' => 'space' ],

			// Polymorphic rows pointing AT the space.
			[
				'table'  => table( 'notifications' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],
			[
				'table'  => table( 'subscriptions' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],
			[
				'table'  => table( 'activity_log' ),
				'column' => 'object_id',
				'ref'    => 'space',
				'where'  => "object_type = 'space'",
			],

			// One hop out: rows hanging off the space's topics.
			[ 'table' => table( 'replies' ), 'column' => 'post_id', 'ref' => 'post' ],
			[ 'table' => table( 'post_tags' ), 'column' => 'post_id', 'ref' => 'post' ],
			[ 'table' => table( 'read_status' ), 'column' => 'post_id', 'ref' => 'post' ],
			[ 'table' => table( 'bookmarks' ), 'column' => 'post_id', 'ref' => 'post' ],
		];

		// Polymorphic rows pointing at those topics, and at their replies. Same
		// tables, two id sets — declared as a loop so a table added to one type
		// can never be forgotten for the other, which is the drift this whole
		// class is built to avoid.
		foreach ( [ 'post', 'reply' ] as $ref ) {
			foreach ( [ 'notifications', 'subscriptions', 'activity_log', 'votes', 'flags', 'attachments', 'revisions' ] as $t ) {
				// subscriptions is ENUM('space','post') — it cannot hold a reply.
				if ( 'reply' === $ref && 'subscriptions' === $t ) {
					continue;
				}
				$rel[] = [
					'table'  => table( $t ),
					'column' => 'object_id',
					'ref'    => $ref,
					'where'  => "object_type = '" . $ref . "'",
				];
			}
		}

		// The space row itself, deleted last.
		$rel[] = [
			'table'  => table( 'spaces' ),
			'column' => 'id',
			'ref'    => 'space',
			'self'   => true,
		];

		/**
		 * Every table that holds a reference to a space, its topics, or their
		 * replies.
		 *
		 * Pro adds its own `jt_pro_*` tables here rather than shipping a
		 * parallel purge — free owns the engine, Pro contributes its tables.
		 * Mirrors `jetonomy_privacy_orphan_columns`.
		 *
		 * @param array<int,array{table:string,column:string,ref:string,where?:string,self?:bool}> $rel Relations.
		 */
		$rel = apply_filters( 'jetonomy_space_relations', $rel );

		$clean = [];
		foreach ( (array) $rel as $r ) {
			if ( empty( $r['table'] ) || empty( $r['column'] ) || empty( $r['ref'] ) ) {
				continue;
			}
			if ( ! in_array( $r['ref'], [ 'space', 'post', 'reply' ], true ) ) {
				continue; // Unknown id set — we have no way to resolve it.
			}
			$clean[] = [
				'table'  => (string) $r['table'],
				'column' => sanitize_key( (string) $r['column'] ),
				'ref'    => (string) $r['ref'],
				'where'  => (string) ( $r['where'] ?? '' ),
				'self'   => (bool) ( $r['self'] ?? false ),
			];
		}
		return $clean;
	}

	/**
	 * Delete a space and every row that references it.
	 *
	 * Idempotent: a second call finds nothing left to remove and reports zeros.
	 * Safe on an already-orphaned space (the `spaces` row gone, its children
	 * still present), which is exactly the backfill's case.
	 *
	 * @param int $space_id Space id.
	 * @return array<string,int> Rows removed, keyed "table.column" (only non-zero entries).
	 */
	public static function purge( int $space_id ): array {
		$space_id = (int) $space_id;
		if ( $space_id <= 0 ) {
			return [];
		}

		global $wpdb;

		// Capture the slug and category BEFORE the row goes, so cache busting
		// and the category counter can still be resolved afterwards.
		$space       = Models\Space::find( $space_id );
		$slug        = $space->slug ?? null;
		$category_id = (int) ( $space->category_id ?? 0 );

		$ids = [
			'space' => [ $space_id ],
			'post'  => self::post_ids( $space_id ),
		];
		$ids['reply'] = self::reply_ids( $ids['post'] );

		$removed = [];
		foreach ( self::relations() as $r ) {
			if ( $r['self'] ) {
				continue; // Handled after the children.
			}
			$n = self::delete_where_in( $r, $ids[ $r['ref'] ] );
			if ( $n > 0 ) {
				$removed[ $r['table'] . '.' . $r['column'] . ':' . $r['ref'] ] = $n;
			}
		}

		// The space row last, so an interrupted purge leaves the space
		// discoverable and the next pass finishes the job. Dropping the parent
		// first would strand whatever remained with nothing to find it by.
		foreach ( self::relations() as $r ) {
			if ( ! $r['self'] ) {
				continue;
			}
			$n = self::delete_where_in( $r, [ $space_id ] );
			if ( $n > 0 ) {
				$removed[ $r['table'] . '.' . $r['column'] ] = $n;
			}
		}

		// Only when the space row was actually still there — an orphan sweep
		// must not decrement a counter the original delete already adjusted.
		if ( $category_id > 0 && $space ) {
			Models\Category::increment_space_count( $category_id, -1 );
		}

		Models\Space::bust_cache( $space_id, $slug );

		/**
		 * A space and everything it owned has been removed.
		 *
		 * @param int                $space_id Space id.
		 * @param array<string,int>  $removed  Rows removed per table.column:ref.
		 */
		do_action( 'jetonomy_space_purged', $space_id, $removed );

		return $removed;
	}

	/**
	 * Topic ids in a space.
	 *
	 * Read straight from `posts.space_id` rather than through the model, so it
	 * still resolves when the parent `spaces` row is already gone.
	 *
	 * @param int $space_id Space id.
	 * @return int[]
	 */
	private static function post_ids( int $space_id ): array {
		global $wpdb;
		$posts = table( 'posts' );
		if ( ! self::table_exists( $posts ) ) {
			return [];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$posts} WHERE space_id = %d", $space_id ) ) );
	}

	/**
	 * Reply ids belonging to the given topics.
	 *
	 * @param int[] $post_ids Topic ids.
	 * @return int[]
	 */
	private static function reply_ids( array $post_ids ): array {
		global $wpdb;
		$replies = table( 'replies' );
		if ( ! $post_ids || ! self::table_exists( $replies ) ) {
			return [];
		}

		$out = [];
		foreach ( array_chunk( $post_ids, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$out = array_merge( $out, (array) $wpdb->get_col( "SELECT id FROM {$replies} WHERE post_id IN ({$in})" ) );
		}
		return array_map( 'intval', $out );
	}

	/**
	 * Delete a relation's rows for a set of ids, in chunks.
	 *
	 * @param array{table:string,column:string,where:string} $rel Relation.
	 * @param int[]                                          $ids Id set.
	 * @return int Rows removed.
	 */
	private static function delete_where_in( array $rel, array $ids ): int {
		global $wpdb;

		if ( ! $ids || ! self::table_exists( $rel['table'] ) ) {
			return 0;
		}

		$tbl   = $rel['table'];
		$col   = $rel['column'];
		$extra = '' !== $rel['where'] ? ' AND ( ' . $rel['where'] . ' )' : '';
		$n     = 0;

		foreach ( array_chunk( $ids, self::CHUNK ) as $chunk ) {
			$in = implode( ',', array_map( 'intval', $chunk ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$n += (int) $wpdb->query( "DELETE FROM {$tbl} WHERE {$col} IN ({$in}){$extra}" );
		}
		return $n;
	}

	/** Does a table exist? Pro tables are absent when the extension never ran. */
	private static function table_exists( string $table ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}
}
