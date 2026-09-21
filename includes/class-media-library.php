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

	/**
	 * PROVENANCE. Set only when Jetonomy itself created the upload.
	 *
	 * META_FLAG cannot be used to decide whether we may DELETE a file, because the
	 * backfill below also applies it to media we did not create: it infers "this is a
	 * community upload" from the author lacking `upload_files`, which is true of every
	 * subscriber-authored attachment on the site — including another forum plugin's.
	 * wpForo, for instance, inserts its attachments into the media library authored by
	 * the posting member. Pro's GC then force-deleted any flagged, unlinked attachment
	 * over 24h old (wp_delete_attachment with $force_delete = true, so the FILE went
	 * from disk as well) — which meant enabling attachments on a site mid-migration
	 * could destroy the very files the import existed to rescue.
	 *
	 * Ownership is now RECORDED at write time rather than guessed after the fact.
	 * Only uploads carrying this meta are ever GC-eligible. A capability check is a
	 * fine heuristic for HIDING a file from the owner's media grid; it is not one for
	 * deleting it.
	 */
	const META_ORIGIN = '_jetonomy_media_origin';

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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_grid_filter' ) );
		add_action( 'admin_init', array( $this, 'maybe_backfill' ) );
		// Dedicated, paginated Community Media admin view (priority 20 so the
		// parent Jetonomy menu is already registered).
		add_action( 'admin_menu', array( $this, 'register_admin_page' ), 20 );
	}

	/**
	 * Bounded query for community uploads — the single source of truth for the
	 * Community Media admin view and the REST list endpoint. Always paginated;
	 * never an unbounded SELECT.
	 *
	 * @param array $args page, per_page, author, space_id, search, orderby, order.
	 * @return array{items: \WP_Post[], total: int, total_pages: int, page: int, per_page: int}
	 */
	public static function query( array $args = array() ): array {
		$page       = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page   = min( 100, max( 1, (int) ( $args['per_page'] ?? 24 ) ) );
		$orderby_in = (string) ( $args['orderby'] ?? 'date' );
		$orderby    = in_array( $orderby_in, array( 'date', 'title' ), true ) ? $orderby_in : 'date';
		$order      = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';

		$meta_query = array(
			array(
				'key'     => self::META_FLAG,
				'compare' => 'EXISTS',
			),
		);
		if ( ! empty( $args['space_id'] ) ) {
			$meta_query[] = array(
				'key'   => self::META_SPACE,
				'value' => (int) $args['space_id'],
			);
		}

		$q_args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'posts_per_page'         => $per_page,
			'paged'                  => $page,
			'orderby'                => $orderby,
			'order'                  => $order,
			'update_post_term_cache' => false,
			'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- scoping to community uploads is the feature.
		);
		if ( isset( $args['author'] ) && 0 !== (int) $args['author'] ) {
			$q_args['author'] = (int) $args['author'];
		}
		if ( ! empty( $args['search'] ) ) {
			$q_args['s'] = sanitize_text_field( (string) $args['search'] );
		}

		$query = new \WP_Query( $q_args );

		return array(
			'items'       => $query->posts,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
			'page'        => $page,
			'per_page'    => $per_page,
		);
	}

	/**
	 * Register the Community Media submenu under the Jetonomy menu.
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'jetonomy',
			__( 'Community Media', 'jetonomy' ),
			__( 'Community Media', 'jetonomy' ),
			'jetonomy_manage_settings',
			'jetonomy-community-media',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Render the Community Media admin page (paginated grid + space/member
	 * filters + recency sort).
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only GET filters, no state change.
		$jt_page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$jt_member = isset( $_GET['jt_member'] ) ? sanitize_text_field( wp_unslash( $_GET['jt_member'] ) ) : '';
		$jt_space  = isset( $_GET['jt_space'] ) ? (int) $_GET['jt_space'] : 0;
		$jt_sort   = ( 'oldest' === ( $_GET['jt_sort'] ?? '' ) ) ? 'oldest' : 'recent';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Resolve a member search to an author id; -1 forces an empty result so
		// "no such member" reads as zero uploads rather than all uploads.
		$author_id = 0;
		if ( '' !== $jt_member ) {
			$jt_user   = get_user_by( 'login', $jt_member );
			$jt_user   = $jt_user ? $jt_user : get_user_by( 'email', $jt_member );
			$author_id = $jt_user ? (int) $jt_user->ID : -1;
		}

		$jt_result = self::query(
			array(
				'page'     => $jt_page,
				'per_page' => 24,
				'author'   => $author_id,
				'space_id' => $jt_space,
				'order'    => 'oldest' === $jt_sort ? 'ASC' : 'DESC',
			)
		);

		$jt_spaces = \Jetonomy\Models\Space::list_all( 'active', 200, 0 );

		include JETONOMY_DIR . 'includes/admin/views/community-media.php';
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
		// WE created this file, so we are allowed to reclaim it if it is never used.
		// Nothing else may set this — see META_ORIGIN.
		update_post_meta( $attachment_id, self::META_ORIGIN, 'upload' );
		if ( $space_id > 0 ) {
			update_post_meta( $attachment_id, self::META_SPACE, $space_id );
		}
	}

	/**
	 * May this attachment be garbage-collected?
	 *
	 * Only files Jetonomy itself uploaded. Anything we merely *recognised* — another
	 * forum plugin's media, a member's older upload, anything the backfill flagged —
	 * is someone else's file, and an unused file is not a good enough reason to delete
	 * someone else's data.
	 */
	public static function is_ours( int $attachment_id ): bool {
		return 'upload' === (string) get_post_meta( $attachment_id, self::META_ORIGIN, true );
	}

	/**
	 * Option holding the first sweep's dry-run report.
	 *
	 * Its presence is also the "we have reported once" marker, which is what
	 * makes the first run report-only. See cleanup_abandoned_uploads().
	 */
	private const OPT_CLEANUP_REPORT = 'jetonomy_media_cleanup_report';

	/**
	 * Action-callback adapter for the scheduled sweep.
	 *
	 * The sweep itself returns a report, which tests and any future CLI
	 * command want; a do_action() callback must return nothing. This keeps
	 * both honest instead of throwing the report away.
	 *
	 * @return void
	 */
	public static function run_scheduled_cleanup(): void {
		self::cleanup_abandoned_uploads();
	}

	/**
	 * Delete community uploads that were never attached to anything.
	 *
	 * A member who uploads a file in the composer and then abandons the draft
	 * leaves an attachment with no link row and nothing referencing it. This
	 * sweep removes those. It lived only in Pro's attachments extension while
	 * the endpoint that CREATES these rows is in free
	 * (Media_Controller -> tag_upload), so a free-only site, or a Pro site with
	 * the extension off, accumulated them forever.
	 *
	 * THE SCOPE RULE, and it is not negotiable: only attachments carrying
	 * META_ORIGIN - ownership recorded by us, at upload time - are eligible.
	 * Never META_FLAG. That flag is also applied by the backfill, which infers
	 * "community upload" from the author lacking `upload_files`, and that is
	 * true of every subscriber-authored attachment on the site INCLUDING
	 * another forum plugin's. An earlier version of this sweep was scoped that
	 * way and force-deleted wpForo's files mid-migration, destroying the very
	 * content the import existed to rescue. See the META_ORIGIN docblock above.
	 *
	 * THE FIRST RUN DELETES NOTHING. A site upgrading into this may have
	 * months of accumulated uploads, and the first thing a new cleanup does
	 * should not be an unannounced bulk delete. The first sweep records what it
	 * would have removed; the next one acts. That costs one day and buys the
	 * owner a chance to look.
	 *
	 * @return array{reported:int,deleted:int} What this run did.
	 */
	public static function cleanup_abandoned_uploads(): array {
		global $wpdb;

		$links_table = \Jetonomy\table( 'attachments' );
		$cutoff      = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		// Link rows whose WP attachment is gone - belt and braces against a
		// manual media-library deletion bypassing the cascade. Safe on the
		// first run too: this removes only dangling rows in our own table, it
		// deletes no files, so it is not subject to the report-first rule.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE a FROM {$links_table} a LEFT JOIN {$wpdb->posts} p ON p.ID = a.attachment_id WHERE p.ID IS NULL" );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
				 LEFT JOIN {$links_table} a ON a.attachment_id = p.ID
				 WHERE p.post_type = 'attachment' AND a.id IS NULL AND p.post_date_gmt < %s",
				self::META_ORIGIN,
				'upload',
				$cutoff
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$stale = array_map( 'intval', (array) $stale );

		$report = get_option( self::OPT_CLEANUP_REPORT );
		if ( ! is_array( $report ) ) {
			// First ever sweep on this site: look, record, delete nothing.
			update_option(
				self::OPT_CLEANUP_REPORT,
				[
					'first_seen_at' => gmdate( 'Y-m-d H:i:s' ),
					'would_delete'  => count( $stale ),
				],
				false
			);

			/**
			 * Fires after the first, report-only media sweep.
			 *
			 * @since 2.0.0
			 *
			 * @param int   $count Attachments that WOULD have been deleted.
			 * @param int[] $ids   Their attachment ids.
			 */
			do_action( 'jetonomy_media_cleanup_reported', count( $stale ), $stale );

			return [
				'reported' => count( $stale ),
				'deleted'  => 0,
			];
		}

		$deleted = 0;
		foreach ( $stale as $attachment_id ) {
			// Belt and braces: re-check ownership per row, so that even if the
			// query above is ever loosened we still never delete a file we did
			// not record as ours.
			if ( ! self::is_ours( $attachment_id ) ) {
				continue;
			}

			wp_delete_attachment( $attachment_id, true );
			++$deleted;
		}

		/**
		 * Fires after a media sweep deletes abandoned uploads.
		 *
		 * @since 2.0.0
		 *
		 * @param int $deleted Attachments removed.
		 */
		do_action( 'jetonomy_media_cleanup_ran', $deleted );

		return [
			'reported' => 0,
			'deleted'  => $deleted,
		];
	}

	/**
	 * Whether the owner has opted to see community uploads on this request.
	 */
	private function show_community(): bool {
		// Read-only view toggle; nonce not required. The list-view dropdown
		// submits via GET (takes precedence); the grid-view dropdown persists a
		// cookie (admin-ajax query-attachments requests carry no GET params).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ self::SHOW_PARAM ] ) ) {
			return '1' === $_GET[ self::SHOW_PARAM ];
		}
		if ( isset( $_COOKIE[ self::SHOW_PARAM ] ) ) {
			return '1' === $_COOKIE[ self::SHOW_PARAM ];
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
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
	 * Enqueue the grid-view community-uploads filter.
	 *
	 * `restrict_manage_posts` (render_list_filter) only fires on the list screen,
	 * so the JS grid view needs its own toolbar dropdown. The script injects the
	 * dropdown and persists the choice in a cookie that show_community() reads.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_grid_filter( $hook ): void {
		if ( 'upload.php' !== $hook ) {
			return;
		}
		// Grid is the default; only the list view already has the dropdown.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen-mode toggle.
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : 'grid';
		if ( 'list' === $mode ) {
			return;
		}
		wp_enqueue_script(
			'jetonomy-media-grid',
			JETONOMY_URL . 'assets/js/admin-media-grid.js',
			array(),
			JETONOMY_VERSION,
			true
		);
		wp_localize_script(
			'jetonomy-media-grid',
			'jetonomyMediaGrid',
			array(
				'label' => __( 'Community uploads', 'jetonomy' ),
				'show'  => __( 'Show community uploads', 'jetonomy' ),
				'hide'  => __( 'Hide community uploads', 'jetonomy' ),
			)
		);
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
