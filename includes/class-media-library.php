<?php
/**
 * Community media library hygiene.
 *
 * Member uploads go through the standard WordPress media library on purpose —
 * that is what keeps third-party S3/R2 offload and image-compression plugins
 * working transparently. The downside on a busy community is that wp-admin →
 * Media floods with member images mixed into the site owner's own media.
 *
 * This class tags every Jetonomy upload and hides community uploads from the
 * admin Media Library by default, with an opt-in "Show community uploads"
 * filter on the list screen. Files are never moved or stored differently, so
 * offload plugins keep working.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use WP_Query;

class Media_Library {

	/** Marks an attachment as a Jetonomy community upload. */
	const META_FLAG = '_jetonomy_media';

	/** Stores the space a community upload was made in (0 when unknown). */
	const META_SPACE = '_jetonomy_space_id';

	/** Skip-marker set on non-community attachments during backfill. */
	const META_CHECKED = '_jetonomy_media_checked';

	/** Request flag that reveals community uploads in the admin list screen. */
	const SHOW_PARAM = 'jetonomy_show_community';

	/** One-shot option: backfill complete. */
	const OPT_BACKFILLED = 'jetonomy_media_backfilled';

	/** Attachments classified per admin request during backfill. */
	const BACKFILL_BATCH = 100;

	/**
	 * Register admin-side hooks. Self-gates to wp-admin so the front-end and
	 * REST stack never pay for it.
	 */
	public function register(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_filter( 'ajax_query_attachments_args', array( $this, 'filter_grid_query' ) );
		add_action( 'pre_get_posts', array( $this, 'filter_list_query' ) );
		add_action( 'restrict_manage_posts', array( $this, 'render_list_filter' ) );
		add_action( 'admin_init', array( $this, 'maybe_backfill' ) );
	}

	/**
	 * Tag an attachment as a Jetonomy community upload.
	 *
	 * Called from the REST media controller at upload time. Static + safe to
	 * call outside admin (no admin-only dependencies).
	 *
	 * @param int $attachment_id The new attachment ID.
	 * @param int $space_id      Optional originating space ID.
	 */
	public static function tag_upload( int $attachment_id, int $space_id = 0 ): void {
		if ( $attachment_id <= 0 ) {
			return;
		}
		update_post_meta( $attachment_id, self::META_FLAG, 1 );
		if ( $space_id > 0 ) {
			update_post_meta( $attachment_id, self::META_SPACE, $space_id );
		}
	}

	/**
	 * Whether the owner has opted to see community uploads on this request.
	 */
	private function show_community(): bool {
		// Read-only list filter; nonce not required for a GET view toggle.
		return isset( $_GET[ self::SHOW_PARAM ] ) && '1' === $_GET[ self::SHOW_PARAM ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Media modal + grid (admin-ajax `query-attachments`). Excludes community
	 * uploads unless explicitly opted in.
	 *
	 * @param array $args WP_Query args.
	 * @return array
	 */
	public function filter_grid_query( $args ) {
		if ( $this->show_community() ) {
			return $args;
		}
		$args['meta_query'] = $this->merge_exclude( $args['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the exclude is the feature.
		return $args;
	}

	/**
	 * Media list screen (upload.php?mode=list). Excludes community uploads
	 * unless opted in via the filter dropdown.
	 *
	 * @param WP_Query $query The query.
	 */
	public function filter_list_query( $query ): void {
		if ( ! is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() ) {
			return;
		}
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'upload' !== $screen->id || $this->show_community() ) {
			return;
		}
		$query->set( 'meta_query', $this->merge_exclude( (array) $query->get( 'meta_query' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the exclude is the feature.
	}

	/**
	 * Append a "not a community upload" clause to an existing meta_query.
	 *
	 * @param array $existing Existing meta_query.
	 * @return array
	 */
	private function merge_exclude( array $existing ): array {
		$clause = array(
			'key'     => self::META_FLAG,
			'compare' => 'NOT EXISTS',
		);
		if ( empty( $existing ) ) {
			return array( $clause );
		}
		return array(
			'relation' => 'AND',
			$existing,
			$clause,
		);
	}

	/**
	 * Render the "Hide / Show community uploads" dropdown on the media list
	 * screen toolbar.
	 *
	 * @param string $post_type Current screen post type.
	 */
	public function render_list_filter( $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$show = $this->show_community();
		?>
		<label class="screen-reader-text" for="jetonomy-community-media"><?php esc_html_e( 'Community uploads', 'jetonomy' ); ?></label>
		<select name="<?php echo esc_attr( self::SHOW_PARAM ); ?>" id="jetonomy-community-media">
			<option value="0" <?php selected( $show, false ); ?>><?php esc_html_e( 'Hide community uploads', 'jetonomy' ); ?></option>
			<option value="1" <?php selected( $show, true ); ?>><?php esc_html_e( 'Show community uploads', 'jetonomy' ); ?></option>
		</select>
		<?php
	}

	/**
	 * One-shot, idempotent, bounded backfill of pre-existing uploads.
	 *
	 * Signal: an attachment whose author cannot `upload_files` could only have
	 * entered the library through Jetonomy's REST endpoint (which allows
	 * jetonomy_create_posts/replies without upload_files). Those are tagged as
	 * community uploads; everything else gets a skip-marker so it is not
	 * re-examined. Conservative by design — it never tags an admin/editor's own
	 * media. Processes BACKFILL_BATCH attachments per admin request to stay
	 * big-site-safe; completes over successive page loads.
	 */
	public function maybe_backfill(): void {
		if ( get_option( self::OPT_BACKFILLED ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$batch = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => self::BACKFILL_BATCH,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded one-shot maintenance.
					'relation' => 'AND',
					array(
						'key'     => self::META_FLAG,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => self::META_CHECKED,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		if ( empty( $batch ) ) {
			update_option( self::OPT_BACKFILLED, 1, false );
			return;
		}

		foreach ( $batch as $attachment_id ) {
			$author_id = (int) get_post_field( 'post_author', $attachment_id );
			if ( $author_id > 0 && ! user_can( $author_id, 'upload_files' ) ) {
				update_post_meta( $attachment_id, self::META_FLAG, 1 );
			} else {
				update_post_meta( $attachment_id, self::META_CHECKED, 1 );
			}
		}
	}
}
