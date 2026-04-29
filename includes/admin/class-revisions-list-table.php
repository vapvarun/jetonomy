<?php
/**
 * Revisions list table (list mode).
 *
 * Read-only WP_List_Table over jt_revisions, aggregated to one row per
 * (object_type, object_id) pair. Detail mode (per-object diff) is rendered
 * separately by views/revisions.php — this table only powers the index
 * screen at ?page=jetonomy-revisions.
 *
 * Mirrors the pattern of Activity_List_Table (A6): URL-driven filters via
 * a static read_filters() helper, a single owning prepare_items() that
 * fills both the rows and the pagination state, and zero edit / delete
 * actions (revisions are read-only in v1).
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Revision;
use function Jetonomy\table;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Revisions list table.
 */
class Revisions_List_Table extends \WP_List_Table {

	/**
	 * Total aggregate rows after WHERE filters (set by prepare_items()).
	 *
	 * @var int
	 */
	private int $total_items = 0;

	/**
	 * Resolved title cache, keyed by "{type}:{id}". Populated once per
	 * page load via batched IN() queries against jt_posts / jt_replies
	 * to avoid an N+1 lookup when rendering the Title column.
	 *
	 * @var array<string, string>
	 */
	private array $title_cache = [];

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'revision-object',
				'plural'   => 'revision-objects',
				'ajax'     => false,
				'screen'   => 'jetonomy_page_jetonomy-revisions',
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		return array(
			'object_type'    => __( 'Type', 'jetonomy' ),
			'object_title'   => __( 'Title', 'jetonomy' ),
			'revision_count' => __( 'Revisions', 'jetonomy' ),
			'last_edited'    => __( 'Last Edited', 'jetonomy' ),
			'last_edited_by' => __( 'Last Edited By', 'jetonomy' ),
		);
	}

	/**
	 * No sortable columns — list mode is always ordered by last_edited DESC.
	 * Allowing sort on the aggregate would require composite indexes the
	 * table doesn't have, so the column is fixed for now.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array();
	}

	/**
	 * Read filter inputs from the URL — single source of truth so the
	 * list query and the filter form see the same values without
	 * re-parsing $_GET twice.
	 *
	 * @return array{object_type:string, date_from:string, date_to:string}
	 */
	public static function read_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter UI.
		$type      = isset( $_GET['object_type'] ) ? sanitize_key( wp_unslash( $_GET['object_type'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! in_array( $type, array( 'post', 'reply' ), true ) ) {
			$type = '';
		}

		$date_from = self::normalize_date( $date_from );
		$date_to   = self::normalize_date( $date_to );

		return array(
			'object_type' => $type,
			'date_from'   => $date_from,
			'date_to'     => $date_to,
		);
	}

	/**
	 * Normalize a date-ish string to YYYY-MM-DD or empty.
	 */
	private static function normalize_date( string $raw ): string {
		if ( '' === $raw ) {
			return '';
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return '';
		}
		return gmdate( 'Y-m-d', $ts );
	}

	/**
	 * Hydrate items + pagination state.
	 */
	public function prepare_items(): void {
		$per_page = 20;
		$paged    = max( 1, absint( $this->get_pagenum() ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$filters = self::read_filters();

		$this->total_items = Revision::count_objects_with_revisions( $filters );
		$rows              = Revision::list_objects_with_revisions( $filters, $per_page, $offset );

		$this->items = $rows;

		// Pre-warm the title cache so column_object_title() doesn't fall
		// into N+1: collect every (type, id) on the page, then batch the
		// post + reply lookups in two single IN() queries.
		$this->prime_title_cache( $rows );

		$this->set_pagination_args(
			array(
				'total_items' => $this->total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $this->total_items / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Batch-resolve titles for the rows on this page. Posts have a
	 * native title column; replies do not, so we fall back to the parent
	 * post's title and prefix with "Reply to:" so the UI is still
	 * meaningful. Cache key is "{type}:{id}".
	 *
	 * @param array<int, object> $rows
	 */
	private function prime_title_cache( array $rows ): void {
		global $wpdb;

		$post_ids  = array();
		$reply_ids = array();
		foreach ( $rows as $row ) {
			$type = (string) ( $row->object_type ?? '' );
			$id   = (int) ( $row->object_id ?? 0 );
			if ( $id <= 0 ) {
				continue;
			}
			if ( 'post' === $type ) {
				$post_ids[] = $id;
			} elseif ( 'reply' === $type ) {
				$reply_ids[] = $id;
			}
		}

		if ( ! empty( $post_ids ) ) {
			$post_ids   = array_values( array_unique( $post_ids ) );
			$posts_t    = table( 'posts' );
			$placehold  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			$post_rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, title FROM {$posts_t} WHERE id IN ({$placehold})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$post_ids
				)
			) ?: array();
			foreach ( $post_rows as $pr ) {
				$this->title_cache[ 'post:' . (int) $pr->id ] = (string) $pr->title;
			}
		}

		if ( ! empty( $reply_ids ) ) {
			$reply_ids = array_values( array_unique( $reply_ids ) );
			$replies_t = table( 'replies' );
			$posts_t   = table( 'posts' );
			$placehold = implode( ',', array_fill( 0, count( $reply_ids ), '%d' ) );
			$reply_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT r.id, p.title AS post_title
					 FROM {$replies_t} r
					 LEFT JOIN {$posts_t} p ON p.id = r.post_id
					 WHERE r.id IN ({$placehold})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					...$reply_ids
				)
			) ?: array();
			foreach ( $reply_rows as $rr ) {
				$post_title = (string) ( $rr->post_title ?? '' );
				$this->title_cache[ 'reply:' . (int) $rr->id ] = $post_title;
			}
		}
	}

	/**
	 * Generic column rendering — escapes raw values; specialized
	 * column_* methods below take precedence.
	 *
	 * @param object $item        Row object.
	 * @param string $column_name Column key.
	 */
	public function column_default( $item, $column_name ): string {
		return isset( $item->$column_name ) ? esc_html( (string) $item->$column_name ) : '';
	}

	public function column_object_type( $item ): string {
		$type = (string) ( $item->object_type ?? '' );
		if ( '' === $type ) {
			return '&mdash;';
		}
		return sprintf(
			'<code>%s</code>',
			esc_html( ucfirst( $type ) )
		);
	}

	public function column_object_title( $item ): string {
		$type = (string) ( $item->object_type ?? '' );
		$id   = (int) ( $item->object_id ?? 0 );
		if ( '' === $type || $id <= 0 ) {
			return '&mdash;';
		}

		$cache_key = $type . ':' . $id;
		$title     = isset( $this->title_cache[ $cache_key ] ) ? $this->title_cache[ $cache_key ] : '';

		// Replies don't have their own title — fall back to the parent
		// post's title, prefixed so the row is still scannable.
		if ( '' === $title ) {
			$label = sprintf(
				/* translators: %1$s: object type, %2$d: object id */
				__( '%1$s #%2$d', 'jetonomy' ),
				ucfirst( $type ),
				$id
			);
		} elseif ( 'reply' === $type ) {
			$label = sprintf(
				/* translators: %s: parent post title */
				__( 'Reply to: %s', 'jetonomy' ),
				$title
			);
		} else {
			$label = $title;
		}

		$detail_url = add_query_arg(
			array(
				'page'        => 'jetonomy-revisions',
				'object_type' => $type,
				'object_id'   => $id,
			),
			admin_url( 'admin.php' )
		);

		return sprintf(
			'<strong><a href="%1$s">%2$s</a></strong>',
			esc_url( $detail_url ),
			esc_html( $label )
		);
	}

	public function column_revision_count( $item ): string {
		$count = (int) ( $item->revision_count ?? 0 );
		return esc_html( number_format_i18n( $count ) );
	}

	public function column_last_edited( $item ): string {
		$raw = (string) ( $item->last_edited ?? '' );
		if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
			return '&mdash;';
		}
		$ts = strtotime( $raw );
		if ( false === $ts ) {
			return esc_html( $raw );
		}
		$human = sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'jetonomy' ),
			human_time_diff( $ts, time() )
		);
		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $raw ),
			esc_html( $human )
		);
	}

	public function column_last_edited_by( $item ): string {
		$user_id = (int) ( $item->last_edited_by ?? 0 );
		if ( $user_id <= 0 ) {
			return '<em>' . esc_html__( 'Unknown', 'jetonomy' ) . '</em>';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return sprintf(
				/* translators: %d: deleted user id */
				esc_html__( 'Deleted user (#%d)', 'jetonomy' ),
				$user_id
			);
		}
		return esc_html( $user->display_name ?: $user->user_login );
	}

	/**
	 * Empty-state copy when no rows match the active filters.
	 */
	public function no_items(): void {
		esc_html_e( 'No revisions have been recorded yet. Revisions are created automatically when a post or reply is edited.', 'jetonomy' );
	}

	/**
	 * Filter form rendered above the table — placed inside the page form
	 * (which already wraps the list table) so submitting any filter
	 * preserves pagination via the standard WP_List_Table hidden inputs.
	 */
	public function render_filters(): void {
		$filters = self::read_filters();
		?>
		<div class="jt-revisions-filters tablenav top">
			<div class="alignleft actions">
				<label class="screen-reader-text" for="jt-revisions-type"><?php esc_html_e( 'Filter by type', 'jetonomy' ); ?></label>
				<select id="jt-revisions-type" name="object_type">
					<option value=""><?php esc_html_e( 'All types', 'jetonomy' ); ?></option>
					<option value="post" <?php selected( $filters['object_type'], 'post' ); ?>><?php esc_html_e( 'Posts', 'jetonomy' ); ?></option>
					<option value="reply" <?php selected( $filters['object_type'], 'reply' ); ?>><?php esc_html_e( 'Replies', 'jetonomy' ); ?></option>
				</select>

				<label class="screen-reader-text" for="jt-revisions-from"><?php esc_html_e( 'From date', 'jetonomy' ); ?></label>
				<input
					type="date"
					id="jt-revisions-from"
					name="date_from"
					value="<?php echo esc_attr( $filters['date_from'] ); ?>"
				/>
				<label class="screen-reader-text" for="jt-revisions-to"><?php esc_html_e( 'To date', 'jetonomy' ); ?></label>
				<input
					type="date"
					id="jt-revisions-to"
					name="date_to"
					value="<?php echo esc_attr( $filters['date_to'] ); ?>"
				/>

				<?php submit_button( __( 'Filter', 'jetonomy' ), 'secondary', 'jt-revisions-filter', false ); ?>

				<?php if ( '' !== $filters['object_type'] || '' !== $filters['date_from'] || '' !== $filters['date_to'] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-revisions' ) ); ?>" class="button">
						<?php esc_html_e( 'Clear', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
