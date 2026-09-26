<?php
/**
 * wpForo importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

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

	protected function map_source(): string {
		return 'wpforo';
	}

	/**
	 * wpForo board currently being imported (0 = the default board).
	 *
	 * @var int
	 */
	private int $board_id = 0;

	/**
	 * jt_import_map source id for a row of the current board.
	 *
	 * Every board has its own tables and its own id sequence, so forum 3 on
	 * board 2 is not forum 3 on the default board. The default board keeps the
	 * bare id; other boards are prefixed.
	 *
	 * @param int|string $id Source row id.
	 * @return string
	 */
	private function sid( $id ): string {
		return $this->board_id ? $this->board_id . ':' . $id : (string) $id;
	}

	public function is_source_available(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'wpforo_forums';
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	}

	/**
	 * wpForo itself loaded? The import works either way - see Importer::is_source_active().
	 */
	public function is_source_active(): bool {
		return defined( 'WPFORO_VERSION' );
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

	/**
	 * The rows the batches report as processed, so progress ends at 100%:
	 * forums, topics, the posts that become replies (each topic's first post
	 * is its body, not a reply), and the profiles phase's rows. Counts the
	 * default board, like get_source_stats().
	 */
	public function get_total_count(): int {
		global $wpdb;
		$p = $wpdb->prefix;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first    = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT topicid) FROM {$p}wpforo_posts" );
		$profiles = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'wpforo_profiles' ) )
			? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_profiles" )
			: 0;
		// phpcs:enable

		return array_sum( $this->get_source_stats() ) - $first + $profiles;
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
		$this->board_id  = (int) ( $board['board'] ?? 0 );

		switch ( $phase ) {
			case 'forums':
				$this->import_forums( $board, $prefix );

				return $this->next( 'topics', 0, $this->imported + $this->already );

			case 'topics':
				$r = $this->import_topics_batch( $prefix, $offset, $batch_size );

				// Ran out of time mid-page: resume at the exact row we stopped on.
				if ( $r['processed'] < $r['fetched'] ) {
					return $this->next( 'topics', $offset + $r['processed'], $r['processed'] );
				}

				return $r['fetched'] >= $batch_size
					? $this->next( 'topics', $offset + $r['processed'], $r['processed'] )
					: $this->next( 'replies', 0, $r['processed'] );

			case 'replies':
				$r = $this->import_replies_batch( $prefix, $offset, $batch_size );

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
				'board'    => $board_id,
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

			// Every board numbers its rows from 1, so board 1's forum 3 must not
			// resolve as board 2's forum 3.
			$this->id_map   = [];
			$this->board_id = $board_id;
			$this->import_forums(
				[
					'board'    => $board_id,
					'cat_name' => $cat_name,
					'cat_slug' => $cat_slug,
				],
				$prefix
			);
			$this->import_topics( $prefix );
			$this->import_replies( $prefix );
			$this->import_likes( $prefix );
			$this->create_profiles( $prefix );
		}

		$this->recount();

		return $this->results();
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

	/**
	 * Create a space per wpForo forum on one board, skipping any an earlier run imported.
	 *
	 * @param array  $board Board entry: board id, category name and slug.
	 * @param string $p     Board table prefix.
	 * @return void
	 */
	private function import_forums( array $board, string $p ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$forums = $wpdb->get_results( "SELECT * FROM {$p}forums ORDER BY `order` ASC" );

		// wpForo NESTS forums (its categories are just forums with children), and
		// this loop used to ignore `parentid` entirely — every sub-forum landed as a
		// top-level space and the whole board structure was flattened on import.
		// Walk parents before children so a child can resolve its parent's new id.
		$forums = $this->sort_rows_parents_first( (array) $forums, 'forumid', 'parentid' );

		$fingerprints = [];
		foreach ( $forums as $forum ) {
			$fingerprints[ $this->sid( $forum->forumid ) ] = [ 'slug' => self::forum_slug( $forum ) ];
		}
		$existing = $this->find_imported( 'space', $fingerprints );
		$cat_id   = 0;

		foreach ( $forums as $forum ) {
			// Imported by an earlier run: map it so new topics under it import.
			// Re-creating it used to fail on the slug and silently strand every
			// new topic in the forum.
			$sid = $this->sid( $forum->forumid );
			if ( isset( $existing[ $sid ] ) ) {
				$this->map_id( 'forum', $forum->forumid, $existing[ $sid ] );
				++$this->already;
				continue;
			}

			$parent_space_id = 0;
			if ( ! empty( $forum->parentid ) && (int) $forum->parentid > 0 ) {
				$mapped = $this->get_mapped_id( 'forum', (int) $forum->parentid );
				if ( $mapped ) {
					$parent_space_id = $mapped;
				}
			}

			$cat_id = $cat_id ?: $this->import_category(
				$board['board'] ? 'board-' . $board['board'] : 'default',
				(string) $board['cat_slug'],
				[ 'name' => (string) $board['cat_name'] ]
			);

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
					// Identity lives in jt_import_map, so a slug the owner already
					// uses imports beside it as "<slug>-1" instead of failing.
					'slug'        => Space::unique_slug( self::clean_slug( self::forum_slug( $forum ) ) ?: 'forum-' . $forum->forumid ),
					'description' => wp_strip_all_tags( $forum->description ?? '' ),
					'visibility'  => $access['visibility'],
					'join_policy' => $access['join_policy'],
					'sort_order'  => (int) $forum->order,
				]
			);

			if ( $space_id ) {
				$this->map_id( 'forum', $forum->forumid, $space_id );
				$this->remember( 'space', $sid, (int) $space_id );
				++$this->imported;
			} else {
				$this->log_error( 'forum', $sid, 'Failed to create space' );
				++$this->skipped;
			}
		}
	}

	/**
	 * The slug a wpForo forum imports under - and the one every import before
	 * 2.0.1 wrote verbatim, which is what legacy recognition matches on.
	 *
	 * @param object $forum wpForo forum row.
	 * @return string
	 */
	private static function forum_slug( object $forum ): string {
		return $forum->slug ?: sanitize_title( $forum->title );
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

		// This page's forums, from memory or from jt_import_map (see load_mapped()).
		$forum_ids = array_unique( array_map( 'intval', array_column( $topics, 'forumid' ) ) );
		$this->load_mapped( 'forum', 'space', array_combine( $forum_ids, array_map( fn( $id ) => $this->sid( $id ), $forum_ids ) ) );

		// One "already imported?" lookup for the whole page (see find_imported()).
		$fingerprints = [];
		foreach ( $topics as $topic ) {
			$space_id = $this->get_mapped_id( 'forum', $topic->forumid );
			if ( $space_id ) {
				$fingerprints[ $this->sid( $topic->topicid ) ] = [
					'parent'  => $space_id,
					'author'  => (int) $topic->userid,
					'created' => (string) ( $topic->created ?? '' ),
				];
			}
		}
		$existing = $this->find_imported( 'post', $fingerprints );

		$consumed = 0;
		$total    = count( $topics );

		foreach ( $topics as $topic ) {
			// Count the row as consumed BEFORE any `continue` below: a skipped row is
			// still a row the offset moved past. Miscounting here would replay it and
			// duplicate the customer's content.
			++$consumed;

			$space_id = $this->get_mapped_id( 'forum', $topic->forumid );
			if ( ! $space_id ) {
				$this->skip_orphan( 'topic' );
				continue;
			}

			// Imported by an earlier run: map it so its new replies resolve.
			$sid = $this->sid( $topic->topicid );
			if ( isset( $existing[ $sid ] ) ) {
				$this->map_id( 'topic', $topic->topicid, $existing[ $sid ] );
				++$this->already;
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
					'type'          => \Jetonomy\compose_post_type( 'forum' ),
					'title'         => $topic->title,
					'slug'          => self::clean_slug( $topic->slug ?: $topic->title ) ?: 'topic-' . $topic->topicid,
					'content'       => wp_kses_post( $content ),
					'content_plain' => \jetonomy_content_to_plain( $content ),
					'status'        => ( 0 === (int) ( $topic->status ?? 0 ) ) ? 'publish' : 'pending',
					'is_sticky'     => $is_sticky,
					'is_closed'     => (int) ( $topic->closed ?? 0 ),
					'created_at'    => $topic->created ?? now(),
				]
			);

			if ( is_wp_error( $post_id ) ) {
				$this->log_error( 'topic', $sid, $post_id->get_error_message() );
				++$this->skipped;
				continue;
			}

			if ( $post_id ) {
				$this->map_id( 'topic', $topic->topicid, $post_id );
				$this->remember( 'post', $sid, (int) $post_id );

				if ( $attachments ) {
					$result = $this->migrate_attachments( 'post', (int) $post_id, $attachments, $content );

					// Only rewrite the stored body for the blocks that actually linked.
					if ( $result['linked'] > 0 ) {
						JtPost::update(
							(int) $post_id,
							[
								'content'       => wp_kses_post( $result['body'] ),
								'content_plain' => \jetonomy_content_to_plain( $result['body'] ),
							]
						);
					}
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
	/**
	 * Caveats the owner should read BEFORE running this import.
	 *
	 * Rendered on the import screen. The private-reply note exists because the
	 * mapping is a compromise the owner cannot infer: jt_replies has no `hidden`
	 * status, so a private wpForo reply lands in the moderation queue rather
	 * than staying private. Better they read that here than discover a queue
	 * full of content they never held back.
	 *
	 * @return string[]
	 */
	public function get_import_notes(): array {
		global $wpdb;

		$notes = array();
		$p     = $wpdb->prefix;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$held = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_posts WHERE status <> 0" );
		if ( $held > 0 ) {
			$notes[] = sprintf(
				/* translators: %s: number of unapproved replies. */
				_n(
					'%s unapproved reply will be imported as Pending, not published.',
					'%s unapproved replies will be imported as Pending, not published.',
					$held,
					'jetonomy'
				),
				number_format_i18n( $held )
			);
		}

		// The `private` column does not exist on every wpForo schema.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$has_private = (bool) $wpdb->get_var( "SHOW COLUMNS FROM {$p}wpforo_posts LIKE 'private'" );
		if ( $has_private ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$private = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}wpforo_posts WHERE private = 1" );
			if ( $private > 0 ) {
				$notes[] = sprintf(
					/* translators: %s: number of private replies. */
					_n(
						'%s private reply will be imported as Pending for review - replies have no private state in Jetonomy, so it will not be published.',
						'%s private replies will be imported as Pending for review - replies have no private state in Jetonomy, so they will not be published.',
						$private,
						'jetonomy'
					),
					number_format_i18n( $private )
				);
			}
		}

		return $notes;
	}

	/**
	 * Jetonomy status for one wpForo post (reply) row.
	 *
	 * The source stores 0 = approved and any other value = held for moderation,
	 * and carries a separate `private` flag.
	 *
	 * Every value returned here MUST exist in jt_replies.status, which is
	 * ENUM('publish','pending','spam','trash'). An earlier version of this
	 * method returned 'hidden' for a private reply - a value that exists on
	 * SPACES, not replies. MySQL without strict mode stored it as the empty
	 * string, matching none of the four statuses: invisible to members, absent
	 * from the moderation queue, unrecoverable from any screen. On a host with
	 * STRICT_TRANS_TABLES (the MySQL 5.7+/8 default) the insert failed outright
	 * and the reply was lost. A status column is a contract, and a mapper is
	 * exactly where that contract has to be honoured.
	 *
	 * A private reply therefore imports as `pending`: not public, still in the
	 * owner's moderation queue where it can be read and acted on. That is the
	 * closest thing the schema can express, and it keeps the content rather than
	 * dropping it. The import notes say so, so the owner is not guessing why
	 * private replies are awaiting review.
	 *
	 * @param object $row wpForo post row.
	 * @return string 'publish' | 'pending'
	 */
	private static function map_reply_status( object $row ): string {
		if ( ! empty( $row->private ) ) {
			return 'pending';
		}

		return 0 === (int) ( $row->status ?? 0 ) ? 'publish' : 'pending';
	}

	private function import_reply_rows( array $posts ): int {
		$consumed = 0;
		$total    = count( $posts );

		// This page's topics and threaded parents, from memory or from
		// jt_import_map (see load_mapped()). A parent created earlier in this
		// page is mapped as it is created.
		$topic_ids  = array_unique( array_map( 'intval', array_column( $posts, 'topicid' ) ) );
		$parent_ids = array_filter( array_unique( array_map( 'intval', array_column( $posts, 'parentid' ) ) ) );
		$this->load_mapped( 'topic', 'post', array_combine( $topic_ids, array_map( fn( $id ) => $this->sid( $id ), $topic_ids ) ) );
		$this->load_mapped( 'wpforo_reply', 'reply', array_combine( $parent_ids, array_map( fn( $id ) => $this->sid( $id ), $parent_ids ) ) );

		$fingerprints = [];
		foreach ( $posts as $wf_post ) {
			$post_id = $this->get_mapped_id( 'topic', $wf_post->topicid );
			if ( $post_id ) {
				$fingerprints[ $this->sid( $wf_post->postid ) ] = [
					'parent'  => $post_id,
					'author'  => (int) $wf_post->userid,
					'created' => (string) ( $wf_post->created ?? '' ),
				];
			}
		}
		$existing = $this->find_imported( 'reply', $fingerprints );

		foreach ( $posts as $wf_post ) {
			// Counted before any `continue` — see import_topic_rows().
			++$consumed;

			$post_id = $this->get_mapped_id( 'topic', $wf_post->topicid );
			if ( ! $post_id ) {
				$this->skip_orphan( 'reply' );
				continue;
			}

			// Imported by an earlier run. Still mapped, so a new threaded reply
			// beneath it resolves its parent.
			$sid = $this->sid( $wf_post->postid );
			if ( isset( $existing[ $sid ] ) ) {
				$this->map_id( 'wpforo_reply', $wf_post->postid, $existing[ $sid ] );
				++$this->already;
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
					'content_plain' => \jetonomy_content_to_plain( $body ),
					// Map the SOURCE status, exactly as the topic import above
					// does. Hard-coding 'publish' pushed every unapproved or
					// private wpForo reply live on import: content a moderator
					// had held back, or a member had marked private, became
					// public the moment the owner migrated. wpForo uses
					// status 0 = approved, anything else = held.
					'status'        => self::map_reply_status( $wf_post ),
					'created_at'    => $wf_post->created ?? now(),
				]
			);

			if ( is_wp_error( $reply_id ) ) {
				$this->log_error( 'reply', $sid, $reply_id->get_error_message() );
				++$this->skipped;
				continue;
			}

			if ( $reply_id ) {
				$this->map_id( 'wpforo_reply', $wf_post->postid, $reply_id );
				$this->remember( 'reply', $sid, (int) $reply_id );

				if ( $attachments ) {
					$result = $this->migrate_attachments( 'reply', (int) $reply_id, $attachments, $body );

					if ( $result['linked'] > 0 ) {
						JtReply::update(
							(int) $reply_id,
							[
								'content'       => wp_kses_post( $result['body'] ),
								'content_plain' => \jetonomy_content_to_plain( $result['body'] ),
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

		$likes = (array) $wpdb->get_results( "SELECT * FROM {$likes_table}" );

		// The liked replies, from jt_import_map, a page at a time.
		foreach ( array_chunk( array_unique( array_map( 'intval', array_column( $likes, 'postid' ) ) ), 500 ) as $chunk ) {
			$this->load_mapped( 'wpforo_reply', 'reply', array_combine( $chunk, array_map( fn( $id ) => $this->sid( $id ), $chunk ) ) );
		}

		foreach ( $likes as $like ) {
			$reply_id = $this->get_mapped_id( 'wpforo_reply', $like->postid );
			if ( ! $reply_id ) {
				continue;
			}

			// A re-run maps replies an earlier run imported, and Vote::cast()
			// TOGGLES a repeated vote - so casting again would retract the like.
			// ponytail: one lookup per like; batch it if likes ever run to 100k+.
			if ( null !== Vote::get_user_vote( (int) $like->userid, 'reply', (int) $reply_id ) ) {
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
