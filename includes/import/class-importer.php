<?php
/**
 * Abstract base importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Attachment;
use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;
use function Jetonomy\table;

abstract class Importer {

	public array $id_map    = [];
	protected array $errors = [];
	protected int $imported = 0;
	protected int $skipped  = 0;
	protected bool $dry_run = false;

	/**
	 * Enable or disable dry-run mode.
	 *
	 * In dry-run mode, no records are written to the database.
	 * Import counts are still incremented to simulate the result.
	 */
	public function set_dry_run( bool $dry_run ): void {
		$this->dry_run = $dry_run;
	}

	abstract public function get_source_name(): string;
	abstract public function is_source_available(): bool;
	abstract public function get_source_stats(): array;
	abstract public function run( array $options = [] ): array;

	/**
	 * Get the total count of records to import.
	 * Used to calculate progress percentage.
	 */
	abstract public function get_total_count(): int;

	/**
	 * Run a single batch of the import.
	 *
	 * @param string $phase   Current phase: 'forums', 'topics', 'replies', 'profiles', 'recount'.
	 * @param int    $offset     Where to start this batch.
	 * @param int    $batch_size How many records per batch.
	 * @return array{phase: string, offset: int, done: bool, processed: int}
	 */
	abstract public function run_batch( string $phase, int $offset, int $batch_size ): array;

	/**
	 * Wall-clock deadline for the current batch, or 0.0 when unbounded.
	 *
	 * @var float
	 */
	protected float $deadline = 0.0;

	/**
	 * Start this batch's time budget.
	 *
	 * Rows are cheap; their media is not. Registering one 4000x3000 forum photo costs
	 * ~0.9s, nearly all of it wp_generate_attachment_metadata() regenerating every
	 * thumbnail size. At the default 500-row batch that is minutes of CPU against a
	 * 30s max_execution_time, so an image-heavy forum times out — and a batch that
	 * dies half-way is worse than slow: the id map is only persisted when the batch
	 * completes, so resuming replays rows that were already written and DUPLICATES
	 * the customer's posts.
	 *
	 * So the batch stops itself at a row boundary before the request dies, persists
	 * what it did, and reports the exact number of rows consumed. The next call picks
	 * up from there. Slow imports get more batches, not lost or duplicated content.
	 */
	protected function start_budget(): void {
		/**
		 * Seconds a single import batch may spend before it stops at a row boundary.
		 *
		 * @param float $seconds Default 15.
		 */
		$seconds        = (float) apply_filters( 'jetonomy_import_batch_seconds', 15.0 );
		$this->deadline = microtime( true ) + max( 1.0, $seconds );
	}

	/**
	 * Has this batch used up its time budget?
	 */
	protected function budget_spent(): bool {
		return $this->deadline > 0.0 && microtime( true ) >= $this->deadline;
	}

	/**
	 * Save progress to wp_options for polling.
	 */
	public function save_progress( array $progress ): void {
		update_option( 'jetonomy_import_progress', $progress, false );
	}

	/**
	 * Get current import progress.
	 */
	public static function get_progress(): array {
		return get_option(
			'jetonomy_import_progress',
			[
				'status'    => 'idle',
				'phase'     => '',
				'processed' => 0,
				'total'     => 0,
				'percent'   => 0,
				'message'   => '',
			]
		);
	}

	/**
	 * Clear stored import progress.
	 */
	public static function clear_progress(): void {
		delete_option( 'jetonomy_import_progress' );
	}

	/**
	 * Reset all transient state left by a previous run before a fresh import.
	 *
	 * The batch driver only cleared the id_map + processed counter on a
	 * COMPLETED run, so an import that was closed or timed out left them behind.
	 * The next run then inherited a stale id_map — whose generic 'forum'/'topic'
	 * keys are shared across ALL three sources, so a parent could resolve to the
	 * previous source's object — and a non-zero processed counter that skewed the
	 * progress %. The driver now calls this on a fresh/restart click (never on a
	 * resume or a mid-run board hand-off). Subclasses override to also drop their
	 * own source-specific resume options, calling parent::reset_run_state() first.
	 */
	public function reset_run_state(): void {
		delete_option( 'jetonomy_import_id_map' );
		delete_option( 'jetonomy_import_total_processed' );
		delete_option( 'jetonomy_import_errors' );
		$this->id_map = [];
	}

	/**
	 * Map an old ID to a new ID.
	 */
	protected function map_id( string $type, $old_id, int $new_id ): void {
		$this->id_map[ $type ][ $old_id ] = $new_id;
	}

	protected function get_mapped_id( string $type, $old_id ): ?int {
		return $this->id_map[ $type ][ $old_id ] ?? null;
	}

	/**
	 * Order forum-like rows so every parent appears before its children.
	 *
	 * An importer creates one space per source forum and records old id -> new id
	 * as it goes, so a child can only resolve its parent's new space id if the
	 * parent was created first. Source order never guarantees that — bbPress
	 * forums are WP posts ordered by menu_order/ID, and a sub-forum routinely
	 * carries a LOWER post ID than its parent.
	 *
	 * Breadth-first from the roots. Anything orphaned (its parent id points at a
	 * row that isn't in the set) is appended so it still imports, flat, rather
	 * than being dropped — losing a customer's content is worse than losing its
	 * nesting. Cycles can never reach the queue, so they fall out as orphans too
	 * rather than spinning.
	 *
	 * Every source's forum rows are id + parent-id shaped, but the column names
	 * differ (bbPress: ID/post_parent, wpForo: forumid/parentid, Asgaros:
	 * id/parent_forum), so the keys are passed in. That difference is the only
	 * reason this ever got copied per importer — and copying it is why bbPress
	 * never received the fix wpForo got.
	 *
	 * @param object[] $rows       Source forum rows.
	 * @param string   $id_key     Property holding the row's own id.
	 * @param string   $parent_key Property holding the row's parent id (0 = root).
	 * @return object[] Same rows, parents first.
	 */
	protected function sort_rows_parents_first( array $rows, string $id_key, string $parent_key ): array {
		$indexed  = [];
		$children = [];

		foreach ( $rows as $row ) {
			$id                    = (int) $row->$id_key;
			$indexed[ $id ]        = $row;
			$parent                = (int) ( $row->$parent_key ?? 0 );
			$children[ $parent ][] = $id;
		}

		$ordered = [];
		$queue   = $children[0] ?? [];

		while ( ! empty( $queue ) ) {
			$id = array_shift( $queue );
			if ( ! isset( $indexed[ $id ] ) ) {
				continue;
			}
			$ordered[] = $indexed[ $id ];
			// Drop it from the pool so it can't be emitted twice and so whatever
			// remains at the end is exactly the orphan/cycle set.
			unset( $indexed[ $id ] );
			if ( ! empty( $children[ $id ] ) ) {
				array_splice( $queue, 0, 0, $children[ $id ] );
			}
		}

		foreach ( $indexed as $leftover ) {
			$ordered[] = $leftover;
		}

		return $ordered;
	}

	/**
	 * Ensure a user profile exists.
	 */
	protected function ensure_profile( int $user_id ): void {
		UserProfile::find_or_create( $user_id );
	}

	/**
	 * Log an error.
	 */
	protected function log_error( string $type, $id, string $message ): void {
		$this->errors[] = [
			'type'    => $type,
			'id'      => $id,
			'message' => $message,
		];
	}

	/**
	 * Get import results.
	 */
	protected function results(): array {
		return [
			'source'   => $this->get_source_name(),
			'imported' => $this->imported,
			'skipped'  => $this->skipped,
			'errors'   => $this->errors,
			'dry_run'  => $this->dry_run,
		];
	}

	/**
	 * Non-fatal errors logged during this batch — e.g. an attachment whose file
	 * could not be recovered from the old forum's uploads.
	 *
	 * The run_batch() call logs these into the per-request instance; the AJAX handler
	 * reads this AFTER each batch and accumulates them, so a skipped file is reported
	 * to the customer instead of vanishing behind a green "Import complete!". That
	 * silent success is what let the original missing-media bug ship.
	 *
	 * @return array<int,array{type:string,id:mixed,message:string}>
	 */
	public function get_errors(): array {
		return $this->errors;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Attachments
	//
	// Shared by every importer: forums store an attachment as markup inside the
	// post body, so migrating one means (1) recovering the file, (2) linking it
	// to the new post/reply, and (3) taking the source's markup back out of the
	// body — otherwise the reader sees the attachment twice, once as Jetonomy's
	// own attachment UI and once as the old forum's leftover HTML.
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Attachments are stored in FREE from 1.7.1 on, so an import always has
	 * somewhere to put them.
	 *
	 * This used to ask Pro over a filter, and answer "no" on a free site — which
	 * meant a free-tier migration wrote no attachment rows at all, and we papered
	 * over it by leaving the old forum's raw markup in the post body. Two
	 * presentations, one of which only existed on free, and a body we had to mutate
	 * to switch between them. Kept only so a caller can still ask.
	 */
	protected function attachments_available(): bool {
		return true;
	}

	/**
	 * Resolve a URL from an old forum body to the file's path inside uploads/.
	 *
	 * The single place any importer turns a source URL into a path. There used to be
	 * two implementations that disagreed, and the naive one — a `strpos()` against
	 * `$uploads['baseurl']` — was wrong against real forum markup:
	 *
	 *   - wpForo writes attachment hrefs PROTOCOL-RELATIVE (`//host/path`). Its
	 *     folder map builds a `url//` key with `preg_replace('#^https?:#i','',$url)`
	 *     (wpforo.php:499) and the attachment markup uses it (hooks.php:2432). A
	 *     prefix compare against `https://host/...` fails on every one of them.
	 *   - Bodies written before an http -> https move still carry `http://`.
	 *   - Bodies written before a domain change still carry the old host.
	 *
	 * All three are the same file sitting in this site's uploads dir, so we match on
	 * the PATH and ignore scheme/host entirely.
	 *
	 * Containment is enforced with realpath(): `uploads/wpforo/../../../wp-config.php`
	 * is a valid path under the prefix and file_exists() would say yes. Registering
	 * that as a media item would hand an attacker (any member who could type an <img>
	 * years ago) arbitrary file registration — and, once someone deletes it from the
	 * Media screen, arbitrary file DELETION, since wp_delete_attachment( $id, true )
	 * unlinks whatever _wp_attached_file points at.
	 *
	 * @param string $file_url URL as it appears in the source body.
	 * @return string Absolute path inside uploads/, or '' if it is not one.
	 */
	protected function resolve_upload_path( string $file_url ): string {
		if ( '' === $file_url ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) || empty( $uploads['baseurl'] ) ) {
			return '';
		}

		$url_path  = (string) wp_parse_url( $file_url, PHP_URL_PATH );
		$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' === $url_path || '' === $base_path || 0 !== strpos( $url_path, $base_path . '/' ) ) {
			return '';
		}

		$relative  = rawurldecode( substr( $url_path, strlen( $base_path ) ) );
		$candidate = $uploads['basedir'] . $relative;

		$real = realpath( $candidate );
		$root = realpath( $uploads['basedir'] );
		if ( false === $real || false === $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return ''; // Missing, or escaping uploads/ via `../`.
		}

		return $real;
	}

	/**
	 * Public URL for a file we resolved inside uploads/.
	 */
	protected function upload_path_to_url( string $path ): string {
		$uploads = wp_get_upload_dir();
		$root    = realpath( $uploads['basedir'] );
		if ( false === $root || 0 !== strpos( $path, $root ) ) {
			return '';
		}

		return $uploads['baseurl'] . str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) ) );
	}

	/**
	 * Resolve a source attachment to a WordPress media ID, importing it if needed.
	 *
	 * @param int    $known_media_id Media ID the source already recorded, or 0.
	 * @param string $file_url       Public URL of the file on the old forum.
	 * @param string $file_name      Original file name, for the media title.
	 * @return int WP attachment ID, or 0 if the file could not be recovered.
	 */
	protected function ensure_media_id( int $known_media_id, string $file_url, string $file_name = '' ): int {
		// The source already registered it in the media library — reuse it. Do NOT
		// re-import: that would duplicate every file on a re-run.
		if ( $known_media_id > 0 && 'attachment' === get_post_type( $known_media_id ) ) {
			return $known_media_id;
		}

		// The file already lives inside this site's uploads dir (the old forum put it
		// there), so there is nothing to download — we register what is on disk.
		$path = $this->resolve_upload_path( $file_url );
		if ( '' === $path ) {
			return 0;
		}

		$canonical_url = $this->upload_path_to_url( $path );
		if ( '' === $canonical_url ) {
			return 0;
		}

		// Already registered under a different code path? Don't create a second row.
		$existing = attachment_url_to_postid( $canonical_url );
		if ( $existing ) {
			return (int) $existing;
		}

		// Refuse anything WordPress would not accept as an upload. The old code fell
		// back to application/octet-stream here, which turned "WP rejects this file
		// type" into "register it anyway" — and that is what made a planted
		// `../../../wp-config.php` reachable.
		$filetype = wp_check_filetype( basename( $path ), null );
		if ( empty( $filetype['type'] ) ) {
			$this->log_error( 'attachment', $file_url, 'Disallowed file type' );
			return 0;
		}

		// wp_generate_attachment_metadata() reaches into all three of these for
		// audio/video (wp_read_video_metadata() lives in media.php). wpForo's own
		// copy of this code requires all three; the live path only happens to work
		// because admin-ajax loads them.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attach_id = wp_insert_attachment(
			[
				'guid'           => $canonical_url,
				'post_mime_type' => $filetype['type'],
				'post_title'     => $file_name ?: basename( $path ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			],
			$path
		);

		if ( ! $attach_id ) {
			return 0; // wp_insert_attachment() returns 0 on failure.
		}

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $path ) );

		return (int) $attach_id;
	}

	/**
	 * Link a recovered media item to an imported post or reply.
	 *
	 * Attachments moved to free in 1.7.1, so this always links via the free
	 * Attachment model regardless of whether Pro is active (Pro only adds the
	 * upload UI + download URL on top of the same table). Returns false only when
	 * there is no media id to link.
	 *
	 * @param string $object_type   'post' or 'reply'.
	 * @param int    $object_id     Jetonomy post/reply id.
	 * @param int    $attachment_id WP media ID.
	 * @param int    $sort          Display order.
	 */
	protected function link_attachment( string $object_type, int $object_id, int $attachment_id, int $sort = 0 ): bool {
		if ( ! $attachment_id ) {
			return false;
		}

		// Idempotent (unique key on object_type+object_id+attachment_id), so a
		// resumed or re-run import cannot double-attach the customer's file.
		return Attachment::link( $object_type, $object_id, $attachment_id, $sort ) > 0;
	}

	/**
	 * Register every file a post body references out of the old forum's upload
	 * folder into the WP media library.
	 *
	 * Separate from the attachment box: this is the image someone pasted *into* the
	 * text. It survives the import untouched (wp_kses_post keeps <img> and <a>), so
	 * the post still renders and the migration looks clean — but the file is not a
	 * media-library item. It is invisible in Media, a media-only backup or host
	 * migration leaves it behind, and nothing tells the owner that deleting the old
	 * forum's upload folder will 404 every image in every migrated post.
	 *
	 * Registering it makes WordPress the owner of the file, which is the whole point
	 * of migrating. We do NOT move, copy, or REWRITE anything:
	 *
	 *   - The file is already inside uploads/, so copying it would double the disk
	 *     footprint of a forum whose attachments run to gigabytes.
	 *   - The body is not touched. An inline image comes across with the body for
	 *     free — wp_kses_post keeps <img> — and its URL is already correct, because
	 *     we import from the old forum's tables in the SAME database on the SAME
	 *     site. (A body carrying an old domain only happens if the site was moved,
	 *     and that is fixed by `wp search-replace`, which rewrites the source forum's
	 *     tables too — verified. If someone skipped that step, every URL on their
	 *     whole site is broken, not just the forum's, and papering over it here would
	 *     be the wrong place.)
	 *
	 * Not rewriting is a feature, not laziness: every attachment bug we have shipped
	 * came from mutating post content.
	 *
	 * Deliberately keyed on the source forum's own folder (e.g. `wpforo`) rather than
	 * all of uploads/: a body may also reference genuine media-library URLs, including
	 * WP-generated `-800x600` sizes, which must not be re-registered as new items.
	 *
	 * Schema-free by design — it reads URLs out of the body, so it covers files put
	 * there by an addon we do not have (wpForo's paid attachments addon) as well as
	 * ones we do.
	 *
	 * @param string $body        Post body HTML.
	 * @param string $path_prefix Folder under uploads/ owned by the source forum.
	 * @return int Files now tracked in the media library.
	 */
	protected function register_body_media( string $body, string $path_prefix ): int {
		$path_prefix = trim( $path_prefix, '/' );
		if ( '' === $body || '' === $path_prefix || false === strpos( $body, $path_prefix . '/' ) ) {
			return 0;
		}

		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return 0;
		}

		$base_path = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' === $base_path ) {
			return 0;
		}
		// Only files under the source forum's own folder. Trailing slash matters:
		// without it `wpforo` would also swallow `wpforo-backup/`.
		$prefix = $base_path . '/' . $path_prefix . '/';

		if ( ! preg_match_all( '#(?:src|href)=[\'"]([^\'"]+)[\'"]#i', $body, $matches ) ) {
			return 0;
		}

		$registered = 0;
		$seen       = [];

		foreach ( array_unique( $matches[1] ) as $found ) {
			$raw_url = html_entity_decode( $found, ENT_QUOTES, 'UTF-8' );

			// Match on the path: the body may carry a protocol-relative URL (wpForo
			// writes those) or an http:// one on a site now on https.
			$url_path = (string) wp_parse_url( $raw_url, PHP_URL_PATH );
			if ( '' === $url_path || 0 !== strpos( $url_path, $prefix ) ) {
				continue;
			}

			if ( isset( $seen[ $url_path ] ) ) {
				continue;
			}
			$seen[ $url_path ] = true;

			if ( $this->ensure_media_id( 0, $raw_url ) ) {
				++$registered;
			}
		}

		return $registered;
	}
}
