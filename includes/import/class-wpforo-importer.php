<?php
/**
 * wpForo importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post as JtPost;
use Jetonomy\Models\Reply as JtReply;
use Jetonomy\Models\Vote;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;

class WPForo_Importer extends Importer {

	public function get_source_name(): string {
		return 'wpForo';
	}

	public function is_source_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforo_forums';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * NOTE on the table names below: these were `"{$p}posts"`, which resolves to
	 * `wp_posts` — the WordPress core posts table, not `wp_wpforo_posts`. The
	 * `wpforo_` prefix was missing, so the importer counted every WP post, page,
	 * revision and media attachment on the site as if it were a forum post. The
	 * progress bar divides by this number, so on a real site the percentage was
	 * nonsense (and on a site with no forum content at all it still reported
	 * thousands of rows to import).
	 */
	public function get_source_stats(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return [
			'forums' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_forums" ),
			'topics' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_topics" ),
			'posts'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_posts" ),
		];
	}

	public function get_total_count(): int {
		global $wpdb;
		$p      = $wpdb->prefix;
		$forums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_forums" );
		$topics = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_topics" );
		$posts  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_posts" );
		return $forums + $topics + $posts;
	}

	/** Option holding the resolved board list for the run in progress. */
	private const BOARDS_OPTION = 'jetonomy_import_wpforo_boards';

	/** Option holding the index of the board currently being imported. */
	private const BOARD_IDX_OPTION = 'jetonomy_import_wpforo_board_idx';

	/**
	 * Uploads folder of the board currently being imported (wpforo, wpforo_2, ...).
	 *
	 * @var string
	 */
	private string $media_dir = 'wpforo';

	/**
	 * Import one batch of one phase.
	 *
	 * This used to call run() — the entire import, every board — inside a single
	 * AJAX request, so any forum with real content hit max_execution_time and died
	 * with no partial-progress recovery. Same defect the Asgaros importer carried.
	 *
	 * wpForo is MULTI-BOARD: each board has its own table prefix (wpforo_,
	 * wpforo1_, wpforo2_...). The phase machine therefore walks boards as an outer
	 * loop and phases as an inner one, persisting which board it is on between
	 * requests (the handler only hands us phase + offset, so the board index has to
	 * live in an option).
	 *
	 * Per board: forums -> topics -> replies -> likes -> profiles. When a board is
	 * finished we advance to the next one and start at 'forums' again. After the
	 * last board, recount once and finish.
	 *
	 * Forums are NOT paged: wpForo forums nest, so a child forum needs its parent
	 * to exist first, and the set is bounded (a board's forum list, tens of rows).
	 * The volume is topics/posts, which do page. Same reasoning as Asgaros.
	 *
	 * @param string $phase      Current phase.
	 * @param int    $offset     Row offset within the phase.
	 * @param int    $batch_size Rows to process this call.
	 * @return array{phase:string, offset:int, done:bool, processed:int}
	 */
	public function run_batch( string $phase, int $offset, int $batch_size ): array {
		$batch_size = max( 1, $batch_size );
		$this->start_budget();
		$boards = $this->resolve_boards( $phase, $offset );

		if ( empty( $boards ) ) {
			return $this->finish();
		}

		$idx = (int) get_option( self::BOARD_IDX_OPTION, 0 );
		if ( $idx >= count( $boards ) ) {
			return $this->finish();
		}

		$board  = $boards[ $idx ];
		$prefix = (string) $board['prefix'];

		// Board-scoped uploads folder (wpforo, wpforo_2, ...). Boards cached by an
		// older build have no 'media' key, so fall back to the default board's.
		$this->media_dir = (string) ( $board['media'] ?? 'wpforo' );

		switch ( $phase ) {
			case 'forums':
				$cat_id = Category::create(
					[
						'name' => $board['cat_name'],
						'slug' => $board['cat_slug'] . '-' . time(),
					]
				);

				$before = $this->imported;
				$this->import_forums( (int) $cat_id, $prefix );
				$this->persist_id_map();

				return $this->next( 'topics', 0, $this->imported - $before );

			case 'topics':
				$r = $this->import_topics_batch( $prefix, $offset, $batch_size );
				$this->persist_id_map();

				// Ran out of time mid-page: resume at the exact row we stopped on.
				if ( $r['processed'] < $r['fetched'] ) {
					return $this->next( 'topics', $offset + $r['processed'], $r['processed'] );
				}

				return $r['fetched'] >= $batch_size
					? $this->next( 'topics', $offset + $r['processed'], $r['processed'] )
					: $this->next( 'replies', 0, $r['processed'] );

			case 'replies':
				$r = $this->import_replies_batch( $prefix, $offset, $batch_size );
				$this->persist_id_map();

				if ( $r['processed'] < $r['fetched'] ) {
					return $this->next( 'replies', $offset + $r['processed'], $r['processed'] );
				}

				return $r['fetched'] >= $batch_size
					? $this->next( 'replies', $offset + $r['processed'], $r['processed'] )
					: $this->next( 'likes', 0, $r['processed'] );

			case 'likes':
				// Likes are a thin join table; one pass per board is bounded and cheap.
				$this->import_likes( $prefix );
				return $this->next( 'profiles', 0, 0 );

			case 'profiles':
				$processed = $this->create_profiles_batch( $prefix, $offset, $batch_size );

				if ( $processed >= $batch_size ) {
					return $this->next( 'profiles', $offset + $batch_size, $processed );
				}

				// Board done — move to the next one, or finish.
				$next_idx = $idx + 1;
				update_option( self::BOARD_IDX_OPTION, $next_idx, false );

				if ( $next_idx < count( $boards ) ) {
					return $this->next( 'forums', 0, $processed );
				}

				return $this->finish( $processed );

			default:
				return $this->finish();
		}
	}

	/**
	 * Resolve (and cache for the run) the list of wpForo boards to import.
	 *
	 * Computed once, on the first batch, then reused — the board list must not be
	 * re-derived mid-run or the board index would point at a different board.
	 *
	 * @param string $phase  Current phase.
	 * @param int    $offset Current offset.
	 * @return array[] Boards, each with prefix + category naming.
	 */
	private function resolve_boards( string $phase, int $offset ): array {
		$cached = get_option( self::BOARDS_OPTION, [] );
		$first  = ( 'forums' === $phase && 0 === $offset && ! get_option( self::BOARD_IDX_OPTION, 0 ) );

		if ( ! empty( $cached ) && ! $first ) {
			return $cached;
		}
		if ( ! empty( $cached ) && $first ) {
			return $cached;
		}

		global $wpdb;
		$boards_table = $wpdb->prefix . 'wpforo_boards';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT boardid, title FROM {$boards_table} WHERE status = 1 ORDER BY boardid ASC" );

		if ( empty( $rows ) ) {
			$rows = [
				(object) [
					'boardid' => 0,
					'title'   => 'Forums',
				],
			];
		}

		$multi  = count( $rows ) > 1;
		$boards = [];

		foreach ( $rows as $row ) {
			$board_id = (int) $row->boardid;
			$prefix   = $wpdb->prefix . 'wpforo' . ( $board_id ? $board_id . '_' : '_' );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'forums' ) ) ) {
				continue;
			}

			$boards[] = [
				'prefix'   => $prefix,
				// Each board uploads to its OWN folder: wpforo, wpforo_2, wpforo_3...
				// (wpforo.php:503). Hardcoding 'wpforo' silently skipped every file on
				// every board but the first — no error, just missing media.
				'media'    => 'wpforo' . ( $board_id ? '_' . $board_id : '' ),
				'cat_name' => $multi
					/* translators: %s: wpForo board name */
					? sprintf( __( 'Imported from wpForo -- %s', 'jetonomy' ), $row->title )
					: __( 'Imported from wpForo', 'jetonomy' ),
				'cat_slug' => $multi
					? 'imported-wpforo-' . sanitize_title( $row->title )
					: 'imported-wpforo',
			];
		}

		update_option( self::BOARDS_OPTION, $boards, false );
		update_option( self::BOARD_IDX_OPTION, 0, false );

		return $boards;
	}

	/**
	 * Build a "keep going" batch result.
	 *
	 * @param string $phase     Next phase.
	 * @param int    $offset    Next offset.
	 * @param int    $processed Rows consumed this call.
	 * @return array{phase:string, offset:int, done:bool, processed:int}
	 */
	private function next( string $phase, int $offset, int $processed ): array {
		return [
			'phase'     => $phase,
			'offset'    => $offset,
			'done'      => false,
			'processed' => $processed,
		];
	}

	/**
	 * Recount denormalized counters once, clean up run state, and report done.
	 *
	 * @param int $processed Rows consumed on the final call.
	 * @return array{phase:string, offset:int, done:bool, processed:int}
	 */
	/**
	 * Also drop the cached board list + board index on a fresh run.
	 *
	 * These persist across batches (a run is multi-board), so a previous run that
	 * was aborted mid-board would otherwise leave a non-zero board index behind and
	 * a fresh import would silently skip every board before it. parent handles the
	 * shared id_map + processed counter.
	 */
	public function reset_run_state(): void {
		parent::reset_run_state();
		delete_option( self::BOARDS_OPTION );
		delete_option( self::BOARD_IDX_OPTION );
	}

	private function finish( int $processed = 0 ): array {
		$this->recount();

		delete_option( self::BOARDS_OPTION );
		delete_option( self::BOARD_IDX_OPTION );

		return [
			'phase'     => 'complete',
			'offset'    => 0,
			'done'      => true,
			'processed' => $processed,
		];
	}

	/**
	 * Persist the id_map so the next batch (a separate request) can resolve
	 * parents. Import_Handler restores it into $this->id_map before each call.
	 */
	private function persist_id_map(): void {
		update_option( 'jetonomy_import_id_map', $this->id_map, false );
	}

	public function run( array $options = [] ): array {
		global $wpdb;

		$boards_table = $wpdb->prefix . 'wpforo_boards';
		$boards       = $wpdb->get_results( "SELECT boardid, title FROM {$boards_table} WHERE status = 1 ORDER BY boardid ASC" );

		if ( empty( $boards ) ) {
			$boards = [
				(object) [
					'boardid' => 0,
					'title'   => 'Forums',
				],
			];
		}

		foreach ( $boards as $board ) {
			$board_id = (int) $board->boardid;
			$prefix   = $wpdb->prefix . 'wpforo' . ( $board_id ? $board_id . '_' : '_' );

			if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $prefix . 'forums' ) ) ) {
				continue;
			}

			$cat_name = count( $boards ) > 1
				/* translators: %s: wpForo board name */
				? sprintf( __( 'Imported from wpForo -- %s', 'jetonomy' ), $board->title )
				: __( 'Imported from wpForo', 'jetonomy' );

			$cat_slug = count( $boards ) > 1
				? 'imported-wpforo-' . sanitize_title( $board->title )
				: 'imported-wpforo';

			$cat_id = Category::create(
				[
					'name' => $cat_name,
					'slug' => $cat_slug,
				]
			);

			$this->import_forums( $cat_id, $prefix );
			$this->import_topics( $prefix );
			$this->import_replies( $prefix );
			$this->import_likes( $prefix );
			$this->create_profiles( $prefix );
		}

		$this->recount();

		return $this->results();
	}

	/**
	 * Order forums so every parent appears before its children.
	 *
	 * Breadth-first from the roots, then anything orphaned (a parent that no longer
	 * exists) is appended so it still imports rather than being silently dropped.
	 *
	 * @param object[] $forums wpForo forum rows.
	 * @return object[] Same rows, parents first.
	 */
	private function sort_forums_by_dependency( array $forums ): array {
		$indexed  = [];
		$children = [];

		foreach ( $forums as $forum ) {
			$indexed[ (int) $forum->forumid ] = $forum;
			$parent                           = (int) ( $forum->parentid ?? 0 );
			$children[ $parent ][]            = (int) $forum->forumid;
		}

		$ordered = [];
		$queue   = $children[0] ?? [];

		while ( ! empty( $queue ) ) {
			$id = array_shift( $queue );
			if ( ! isset( $indexed[ $id ] ) ) {
				continue;
			}
			$ordered[] = $indexed[ $id ];
			unset( $indexed[ $id ] );
			if ( ! empty( $children[ $id ] ) ) {
				array_splice( $queue, 0, 0, $children[ $id ] );
			}
		}

		// Orphans: parent id points at a forum that isn't in the set. Import them
		// anyway (flat) rather than losing the content entirely.
		foreach ( $indexed as $leftover ) {
			$ordered[] = $leftover;
		}

		return $ordered;
	}

	/**
	 * Parse wpForo's default (free) attachments out of a post body.
	 *
	 * Core wpForo stores an attachment as markup appended to `wpforo_posts.body`
	 * (see includes/hooks.php::wpforo_move_uploded_default_attach):
	 *
	 *   <div id="wpfa-{ID}" class="wpforo-attached-file">
	 *     <a class="wpforo-default-attachment" href="{url}" title="{name}">…</a>
	 *   </div>
	 *
	 * {ID} is a WordPress media ID — wpForo puts the file in the media library via
	 * wpforo_insert_to_media_library(). BUT that only happens when the site's
	 * `attachs_to_medialib` setting is on (it ships on, but owners can turn it off),
	 * and when it is off the ID written into the markup is literally 0 and the file
	 * exists on disk only. Both cases have to be handled, which is why we keep the
	 * href as well as the id: the href is what lets us recover a wpfa-0 file.
	 *
	 * `wpfa-deleted` blocks are wpForo's "Attachment removed" tombstones — skipped.
	 *
	 * @param string $body Post body HTML.
	 * @return array[] Each: ['media_id' => int, 'url' => string, 'name' => string].
	 */
	private function parse_wpforo_attachments( string $body ): array {
		if ( false === strpos( $body, 'wpforo-attached-file' ) ) {
			return [];
		}

		$found = [];

		if ( ! preg_match_all(
			'#<div[^>]*id=[\'"]wpfa-(\d+)[\'"][^>]*>(.*?)</div>#is',
			$body,
			$blocks,
			PREG_SET_ORDER
		) ) {
			return [];
		}

		foreach ( $blocks as $block ) {
			$media_id = (int) $block[1];
			$inner    = $block[2];

			$url  = preg_match( '#href=[\'"]([^\'"]+)[\'"]#i', $inner, $m ) ? $m[1] : '';
			$name = preg_match( '#title=[\'"]([^\'"]*)[\'"]#i', $inner, $t ) ? $t[1] : '';

			if ( ! $url ) {
				continue; // A tombstone, or markup we don't recognise — leave it be.
			}

			$found[] = [
				'media_id' => $media_id,
				'url'      => html_entity_decode( $url, ENT_QUOTES ),
				'name'     => html_entity_decode( $name, ENT_QUOTES ),
				// The exact markup this attachment came from. We only ever remove a
				// block we have PROVEN we replaced.
				'block'    => $block[0],
			];
		}

		return $found;
	}

	/**
	 * Migrate a body's attachments, and strip ONLY the ones that actually landed.
	 *
	 * The subtle, dangerous version of this method stripped the whole body up front
	 * (any wpfa block, on the strength of "Pro is active") and migrated afterwards.
	 * If migration then recovered nothing — which is exactly what happened on a site
	 * with attachs_to_medialib off — the body lost its links AND no attachment row was
	 * written, so the file was referenced from nowhere. That is the customer's original
	 * "missing media" bug, recreated by the fix meant to close it.
	 *
	 * So the rule is: a block is removed only after that specific file is linked. If
	 * anything fails, wpForo's markup stays exactly where it is and the file remains
	 * reachable from the post.
	 *
	 * @param string  $object_type 'post' or 'reply'.
	 * @param int     $object_id   Jetonomy post/reply id.
	 * @param array[] $attachments Rows from parse_wpforo_attachments().
	 * @param string  $body        The body as stored.
	 * @return array{linked: int, body: string} Linked count and the body to keep.
	 */
	private function migrate_attachments( string $object_type, int $object_id, array $attachments, string $body ): array {
		$linked = 0;
		$sort   = 0;

		foreach ( $attachments as $att ) {
			$media_id = $this->ensure_media_id( (int) $att['media_id'], (string) $att['url'], (string) $att['name'] );
			if ( ! $media_id ) {
				$this->log_error( 'attachment', (string) $att['url'], 'Could not recover the file — left the link in the post' );
				continue;
			}

			if ( ! $this->link_attachment( $object_type, $object_id, $media_id, $sort ) ) {
				$this->log_error( 'attachment', (string) $att['url'], 'Could not attach the file — left the link in the post' );
				continue;
			}

			// Linked. Now, and only now, take wpForo's markup out — otherwise the
			// reader sees it twice: once in Jetonomy's attachment UI, once as
			// wpForo's leftover paperclip link.
			$body = str_replace( (string) $att['block'], '', $body );
			++$linked;
			++$sort;
		}

		return [
			'linked' => $linked,
			'body'   => $linked ? trim( $body ) : $body,
		];
	}

	private function import_forums( int $cat_id, string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$forums = $wpdb->get_results( "SELECT * FROM {$p}forums ORDER BY `order` ASC" );

		// wpForo NESTS forums (its categories are just forums with children), and
		// this loop used to ignore `parentid` entirely — every sub-forum landed as a
		// top-level space and the whole board structure was flattened on import.
		// Walk parents before children so a child can resolve its parent's new id.
		$forums = $this->sort_forums_by_dependency( (array) $forums );

		foreach ( $forums as $forum ) {
			$parent_space_id = 0;
			if ( ! empty( $forum->parentid ) && (int) $forum->parentid > 0 ) {
				$mapped = $this->get_mapped_id( 'forum', (int) $forum->parentid );
				if ( $mapped ) {
					$parent_space_id = $mapped;
				}
			}

			// Preserve source access level — a members-only wpForo board must
			// NOT land as a public Jetonomy space. See self::map_access().
			$access   = self::map_access( $forum );
			$space_id = Space::create(
				[
					'category_id' => $cat_id,
					'parent_id'   => $parent_space_id,
					'author_id'   => 1,
					'type'        => 'forum',
					'title'       => $forum->title,
					'slug'        => $forum->slug ?: sanitize_title( $forum->title ),
					'description' => wp_strip_all_tags( $forum->description ?? '' ),
					'visibility'  => $access['visibility'],
					'join_policy' => $access['join_policy'],
					'sort_order'  => (int) $forum->order,
				]
			);

			if ( $space_id ) {
				$this->map_id( 'forum', $forum->forumid, $space_id );
				++$this->imported;
			} else {
				++$this->skipped;
			}
		}
	}

	/**
	 * Map a wpForo forum's read access to a Jetonomy [visibility, join_policy].
	 *
	 * The wpForo plugin stores per-forum read access in the serialized `groups_can_view`
	 * column (array of usergroup IDs). The default Guest usergroup id is 4; when
	 * guests are not in the allow-list the board was members-only and must NOT
	 * be flattened to a public Jetonomy space — it maps to private + approval so
	 * the content stays gated and new members are vetted. When the column is
	 * absent/empty the access is genuinely unknown, so the historical 'public'
	 * default is preserved (fail-open only when there is no restriction signal).
	 *
	 * Owners can remap any imported board via the `jetonomy_import_space_visibility`
	 * filter (e.g. force everything private, or whitelist a board to public).
	 *
	 * @param object $forum Source wpForo forum row.
	 * @return array{visibility:string,join_policy:string}
	 */
	private static function map_access( object $forum ): array {
		$restricted = false;
		if ( ! empty( $forum->groups_can_view ) ) {
			$groups = maybe_unserialize( $forum->groups_can_view );
			if ( is_array( $groups ) ) {
				$guest_group = (int) apply_filters( 'jetonomy_import_wpforo_guest_group', 4 );
				$restricted  = ! in_array( $guest_group, array_map( 'intval', $groups ), true );
			}
		}

		$access = [
			'visibility'  => $restricted ? 'private' : 'public',
			'join_policy' => $restricted ? 'approval' : 'open',
		];

		/**
		 * Filter the visibility + join policy applied to an imported forum.
		 *
		 * @param array  $access [ visibility, join_policy ].
		 * @param string $source Importer slug ('wpforo').
		 * @param object $forum  Source forum row.
		 */
		$access = apply_filters( 'jetonomy_import_space_visibility', $access, 'wpforo', $forum );

		// Validate against the schema enums so a stray filter return can never
		// persist an invalid visibility/join_policy.
		return [
			'visibility'  => in_array( $access['visibility'] ?? '', [ 'public', 'private', 'hidden' ], true ) ? $access['visibility'] : ( $restricted ? 'private' : 'public' ),
			'join_policy' => in_array( $access['join_policy'] ?? '', [ 'open', 'approval', 'invite' ], true ) ? $access['join_policy'] : ( $restricted ? 'approval' : 'open' ),
		];
	}

	private function import_topics( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$topics = $wpdb->get_results( "SELECT * FROM {$p}topics ORDER BY topicid ASC" );

		$this->import_topic_rows( (array) $topics, $p );
	}

	/**
	 * Import one page of topics. Returns rows SEEN (not imported) — returning
	 * "imported" would stall the phase forever on a page where every row skipped.
	 *
	 * @param string $p      Board table prefix.
	 * @param int    $offset Row offset.
	 * @param int    $limit  Page size.
	 * @return array{fetched:int, processed:int} `processed` < `fetched` means the batch
	 *         stopped early on its time budget; resume from offset + processed.
	 */
	private function import_topics_batch( string $p, int $offset, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$topics = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$p}topics ORDER BY topicid ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		$fetched   = count( (array) $topics );
		$processed = $this->import_topic_rows( (array) $topics, $p );

		return [
			'fetched'   => $fetched,
			'processed' => $processed,
		];
	}

	/**
	 * Create Jetonomy posts from a set of wpForo topic rows.
	 *
	 * Shared by the batched and single-shot paths so the two cannot drift.
	 *
	 * @param object[] $topics wpForo topic rows.
	 * @param string   $p      Board table prefix.
	 * @return int Rows CONSUMED (including skipped ones) — what the offset advances by.
	 */
	private function import_topic_rows( array $topics, string $p ): int {
		global $wpdb;

		if ( empty( $topics ) ) {
			return 0;
		}

		$topic_ids    = wp_list_pluck( $topics, 'topicid' );
		$placeholders = implode( ',', array_fill( 0, count( $topic_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$first_posts_raw = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT fp.* FROM {$p}posts fp
				 INNER JOIN (
				     SELECT topicid, MIN(postid) AS first_postid
				     FROM {$p}posts
				     WHERE topicid IN ({$placeholders})
				     GROUP BY topicid
				 ) f ON fp.postid = f.first_postid",
				...$topic_ids
			)
		);
		$first_posts_map = [];
		foreach ( $first_posts_raw as $fp ) {
			$first_posts_map[ (int) $fp->topicid ] = $fp;
		}

		$consumed = 0;
		$total    = count( $topics );

		foreach ( $topics as $topic ) {
			// Count the row as consumed BEFORE any `continue` below: a skipped row is
			// still a row the offset moved past. Miscounting here would replay it and
			// duplicate the customer's content.
			++$consumed;

			$space_id = $this->get_mapped_id( 'forum', $topic->forumid );
			if ( ! $space_id ) {
				++$this->skipped;
				continue;
			}

			$is_sticky = 0;
			if ( isset( $topic->type ) && 1 === (int) $topic->type ) {
				$is_sticky = 1;
			}

			$first_post = $first_posts_map[ (int) $topic->topicid ] ?? null;
			$content    = (string) ( $first_post ? $first_post->body : '' );

			// Images pasted into the text are a separate loss from the attachment
			// box: they render fine after import, so the migration looks clean, but
			// the file is not a media item and deleting uploads/wpforo/ 404s it.
			$this->register_body_media( $content, $this->media_dir );

			// The post is created with the body INTACT. Attachment markup is removed
			// only after each file is proven linked (see migrate_attachments), so a
			// failed migration can never leave the reader with no way to the file.
			$attachments = $this->parse_wpforo_attachments( $content );

			$post_id = JtPost::create(
				[
					'space_id'      => $space_id,
					'author_id'     => (int) $topic->userid,
					'type'          => 'topic',
					'title'         => $topic->title,
					'slug'          => $topic->slug ?: sanitize_title( $topic->title ),
					'content'       => wp_kses_post( $content ),
					'content_plain' => wp_strip_all_tags( $content ),
					'status'        => ( 0 === (int) ( $topic->status ?? 0 ) ) ? 'publish' : 'pending',
					'is_sticky'     => $is_sticky,
					'is_closed'     => (int) ( $topic->closed ?? 0 ),
					'created_at'    => $topic->created ?? now(),
				]
			);

			if ( is_wp_error( $post_id ) ) {
				++$this->skipped;
				continue;
			}

			if ( $post_id ) {
				$this->map_id( 'topic', $topic->topicid, $post_id );

				if ( $attachments ) {
					$result = $this->migrate_attachments( 'post', (int) $post_id, $attachments, $content );

					// Only rewrite the stored body for the blocks that actually linked.
					if ( $result['linked'] > 0 ) {
						JtPost::update(
							(int) $post_id,
							[
								'content'       => wp_kses_post( $result['body'] ),
								'content_plain' => wp_strip_all_tags( $result['body'] ),
							]
						);
					}
				}

				if ( $first_post ) {
					$this->map_id( 'wpforo_post', $first_post->postid, 0 );
				}
				++$this->imported;
			} else {
				++$this->skipped;
			}

			// Out of time, but not out of rows: stop cleanly here. The caller resumes
			// at exactly $consumed, so nothing is lost and nothing is done twice.
			if ( $consumed < $total && $this->budget_spent() ) {
				break;
			}
		}

		return $consumed;
	}

	private function import_replies( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			"SELECT p.* FROM {$p}posts p
			 INNER JOIN (
			     SELECT topicid, MIN(postid) as first_postid FROM {$p}posts GROUP BY topicid
			 ) fp ON p.topicid = fp.topicid
			 WHERE p.postid != fp.first_postid
			 ORDER BY p.postid ASC"
		);

		$this->import_reply_rows( (array) $posts );
	}

	/**
	 * Import one page of replies. Returns rows SEEN (see import_topics_batch).
	 *
	 * Ordered by postid ASC, which keeps a threaded reply's parent (always a lower
	 * postid) ahead of its children across batch boundaries.
	 *
	 * @param string $p      Board table prefix.
	 * @param int    $offset Row offset.
	 * @param int    $limit  Page size.
	 * @return array{fetched:int, processed:int}
	 */
	private function import_replies_batch( string $p, int $offset, int $limit ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$posts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT p.* FROM {$p}posts p
				 INNER JOIN (
				     SELECT topicid, MIN(postid) as first_postid FROM {$p}posts GROUP BY topicid
				 ) fp ON p.topicid = fp.topicid
				 WHERE p.postid != fp.first_postid
				 ORDER BY p.postid ASC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		$fetched   = count( (array) $posts );
		$processed = $this->import_reply_rows( (array) $posts );

		return [
			'fetched'   => $fetched,
			'processed' => $processed,
		];
	}

	/**
	 * Create Jetonomy replies from a set of wpForo post rows.
	 *
	 * Shared by the batched and single-shot paths so the two cannot drift.
	 *
	 * @param object[] $posts wpForo post rows (excluding each topic's first post).
	 * @return int Rows CONSUMED (including skipped ones).
	 */
	private function import_reply_rows( array $posts ): int {
		$consumed = 0;
		$total    = count( $posts );

		foreach ( $posts as $wf_post ) {
			// Counted before any `continue` — see import_topic_rows().
			++$consumed;

			$post_id = $this->get_mapped_id( 'topic', $wf_post->topicid );
			if ( ! $post_id ) {
				++$this->skipped;
				continue;
			}

			$parent_id = null;
			if ( ! empty( $wf_post->parentid ) && (int) $wf_post->parentid > 0 ) {
				$parent_id = $this->get_mapped_id( 'wpforo_reply', $wf_post->parentid );
			}

			// Replies carry attachments and inline images too — same body as topics.
			$body = (string) $wf_post->body;
			$this->register_body_media( $body, $this->media_dir );

			$attachments = $this->parse_wpforo_attachments( $body );

			$reply_id = JtReply::create(
				[
					'post_id'       => $post_id,
					'parent_id'     => $parent_id,
					'author_id'     => (int) $wf_post->userid,
					'content'       => wp_kses_post( $body ),
					'content_plain' => wp_strip_all_tags( $body ),
					'status'        => 'publish',
					'created_at'    => $wf_post->created ?? now(),
				]
			);

			if ( is_wp_error( $reply_id ) ) {
				++$this->skipped;
				continue;
			}

			if ( $reply_id ) {
				$this->map_id( 'wpforo_reply', $wf_post->postid, $reply_id );

				if ( $attachments ) {
					$result = $this->migrate_attachments( 'reply', (int) $reply_id, $attachments, $body );

					if ( $result['linked'] > 0 ) {
						JtReply::update(
							(int) $reply_id,
							[
								'content'       => wp_kses_post( $result['body'] ),
								'content_plain' => wp_strip_all_tags( $result['body'] ),
							]
						);
					}
				}

				++$this->imported;
			} else {
				++$this->skipped;
			}

			if ( $consumed < $total && $this->budget_spent() ) {
				break;
			}
		}

		return $consumed;
	}

	private function import_likes( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$likes_table = $p . 'likes';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $likes_table ) ) ) {
			return;
		}

		$likes = $wpdb->get_results( "SELECT * FROM {$likes_table}" );

		foreach ( $likes as $like ) {
			$reply_id = $this->get_mapped_id( 'wpforo_reply', $like->postid );
			if ( ! $reply_id ) {
				continue;
			}

			Vote::cast( (int) $like->userid, 'reply', $reply_id, 1 );
			++$this->imported;
		}
	}

	private function create_profiles( string $p = '' ): void {
		global $wpdb;
		if ( ! $p ) {
			$p = $wpdb->prefix . 'wpforo_';
		}

		$profiles_table = $p . 'profiles';
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles_table ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$profiles = $wpdb->get_results( "SELECT * FROM {$profiles_table}" );

		foreach ( $profiles as $prof ) {
			$this->ensure_profile( (int) $prof->userid );
		}
	}

	/**
	 * Ensure profiles for one page of wpForo members. Returns rows SEEN.
	 *
	 * @param string $p      Board table prefix.
	 * @param int    $offset Row offset.
	 * @param int    $limit  Page size.
	 * @return int Rows consumed.
	 */
	private function create_profiles_batch( string $p, int $offset, int $limit ): int {
		global $wpdb;

		$profiles_table = $p . 'profiles';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $profiles_table ) ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT userid FROM {$profiles_table} ORDER BY userid ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			)
		);

		foreach ( $ids as $uid ) {
			$this->ensure_profile( (int) $uid );
		}

		return count( (array) $ids );
	}

	private function recount(): void {
		global $wpdb;
		$posts_t   = \Jetonomy\table( 'posts' );
		$replies_t = \Jetonomy\table( 'replies' );
		$spaces_t  = \Jetonomy\table( 'spaces' );

		$wpdb->query( "UPDATE {$posts_t} p SET p.reply_count = (SELECT COUNT(*) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );
		$wpdb->query( "UPDATE {$spaces_t} s SET s.post_count = (SELECT COUNT(*) FROM {$posts_t} p WHERE p.space_id = s.id AND p.status = 'publish')" );
		$wpdb->query( "UPDATE {$posts_t} p SET p.last_reply_at = (SELECT MAX(r.created_at) FROM {$replies_t} r WHERE r.post_id = p.id AND r.status = 'publish')" );

		// spaces.post_count above backs space:{id}; a set-based UPDATE names no ids
		// (Caching Standard §4d). This is a one-shot import, so flush the group.
		\Jetonomy\Cache::flush();
	}
}
