<?php
/**
 * Abstract base importer.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

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
	 * Map an old ID to a new ID.
	 */
	protected function map_id( string $type, $old_id, int $new_id ): void {
		$this->id_map[ $type ][ $old_id ] = $new_id;
	}

	protected function get_mapped_id( string $type, $old_id ): ?int {
		return $this->id_map[ $type ][ $old_id ] ?? null;
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
	 * Is the Pro attachments extension available to link into?
	 *
	 * Attachments are a Pro feature (jt_pro_attachments) but the importers ship in
	 * free. We never let that cost the customer their files: the file is put into
	 * the WP media library either way (that part is free), and the link row is only
	 * written when Pro is present. Turning Pro on later therefore *reveals* the
	 * attachments instead of requiring a re-import.
	 */
	protected function pro_attachments_available(): bool {
		/**
		 * Whether an attachments backend is available to link imported files into.
		 *
		 * Free must not reach into a Pro class directly, so Pro's attachments
		 * extension answers this instead.
		 *
		 * @param bool $supported Default false (free alone cannot store links).
		 */
		return (bool) apply_filters( 'jetonomy_import_attachments_supported', false );
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

		if ( ! $file_url ) {
			return 0;
		}

		// Map the public URL back to a path on disk. The file already lives inside
		// this site's uploads dir (the old forum put it there), so there is nothing
		// to download — we register what is already on disk.
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['baseurl'] ) || 0 !== strpos( $file_url, $uploads['baseurl'] ) ) {
			return 0; // Off-site or unrecognised — leave the body link alone.
		}

		$path = $uploads['basedir'] . substr( $file_url, strlen( $uploads['baseurl'] ) );
		if ( ! file_exists( $path ) ) {
			return 0;
		}

		// Already registered under a different code path? Don't create a second row.
		$existing = attachment_url_to_postid( $file_url );
		if ( $existing ) {
			return (int) $existing;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filetype  = wp_check_filetype( basename( $path ), null );
		$attach_id = wp_insert_attachment(
			[
				'guid'           => $file_url,
				'post_mime_type' => $filetype['type'] ?: 'application/octet-stream',
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
	 * No-op (returns false) when Pro is absent — the caller then leaves the source
	 * markup in the body so the file is still reachable.
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

		/**
		 * Attach an imported media item to a post or reply.
		 *
		 * Pro's attachments extension hooks this and writes the jt_pro_attachments
		 * row. Its Model::link() is idempotent (unique index on object+attachment),
		 * so a resumed or re-run import cannot double-attach the same file. Returns
		 * 0 when nothing handled it — i.e. Pro is not active — and the caller then
		 * leaves the source markup in the body so the file is still reachable.
		 *
		 * @param int    $link_id       0 by default.
		 * @param string $object_type   'post' or 'reply'.
		 * @param int    $object_id     Jetonomy post/reply id.
		 * @param int    $attachment_id WP media ID.
		 * @param int    $sort          Display order.
		 */
		$link_id = (int) apply_filters(
			'jetonomy_import_link_attachment',
			0,
			$object_type,
			$object_id,
			$attachment_id,
			$sort
		);

		return $link_id > 0;
	}
}
