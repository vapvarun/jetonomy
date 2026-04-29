<?php
/**
 * Activity Log list table.
 *
 * Read-only WP_List_Table over the jt_activity_log table. Supports
 * filtering by user / action type / date range, paginates 20/page by
 * default, and powers the CSV export on the same admin screen.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Activity Log list table.
 */
class Activity_List_Table extends \WP_List_Table {

	/**
	 * Distinct action values present in the table — populated lazily so
	 * the filter dropdown always reflects what's actually in the log
	 * rather than a hardcoded list that drifts as new tracker hooks land.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $known_actions = null;

	/**
	 * Total rows after WHERE filters (set by prepare_items()).
	 *
	 * @var int
	 */
	private int $total_items = 0;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'activity',
				'plural'   => 'activities',
				'ajax'     => false,
				'screen'   => 'jetonomy_page_jetonomy-activity',
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
			'created_at' => __( 'Date', 'jetonomy' ),
			'actor'      => __( 'Actor', 'jetonomy' ),
			'action'     => __( 'Type', 'jetonomy' ),
			'object'     => __( 'Object', 'jetonomy' ),
			'snippet'    => __( 'Details', 'jetonomy' ),
		);
	}

	/**
	 * Sortable columns. Limited to created_at to keep the index path
	 * (KEY created) — sorting on actor/action would need extra indexes.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns(): array {
		return array(
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Read filter inputs from the URL — single source of truth so the
	 * list query, the filter form, and the CSV export all see the same
	 * values without re-parsing $_GET twice.
	 *
	 * @return array{user_id:int, action:string, date_from:string, date_to:string}
	 */
	public static function read_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter UI.
		$user_id   = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$action    = isset( $_GET['action_type'] ) ? sanitize_key( wp_unslash( $_GET['action_type'] ) ) : '';
		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to   = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Coerce date inputs to YYYY-MM-DD; reject anything that isn't
		// parseable so a malformed value can't break the WHERE clause.
		$date_from = self::normalize_date( $date_from );
		$date_to   = self::normalize_date( $date_to );

		return array(
			'user_id'   => $user_id,
			'action'    => $action,
			'date_from' => $date_from,
			'date_to'   => $date_to,
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
	 * Build the WHERE clause + bind args for the active filters.
	 *
	 * @return array{0: string, 1: array<int, mixed>}
	 */
	private function build_where(): array {
		global $wpdb;
		$filters = self::read_filters();

		$clauses = array( '1=1' );
		$args    = array();

		if ( $filters['user_id'] > 0 ) {
			$clauses[] = 'user_id = %d';
			$args[]    = $filters['user_id'];
		}
		if ( '' !== $filters['action'] ) {
			$clauses[] = 'action = %s';
			$args[]    = $filters['action'];
		}
		if ( '' !== $filters['date_from'] ) {
			$clauses[] = 'created_at >= %s';
			$args[]    = $filters['date_from'] . ' 00:00:00';
		}
		if ( '' !== $filters['date_to'] ) {
			$clauses[] = 'created_at <= %s';
			$args[]    = $filters['date_to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $clauses ), $args );
	}

	/**
	 * Hydrate items + pagination state.
	 */
	public function prepare_items(): void {
		global $wpdb;

		$per_page = $this->get_items_per_page( 'jetonomy_activity_per_page', 20 );
		$paged    = max( 1, absint( $this->get_pagenum() ) );
		$offset   = ( $paged - 1 ) * $per_page;

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		[ $where, $args ] = $this->build_where();
		$activity_t       = table( 'activity_log' );

		// Count first — lets the pager render even when the page query
		// returns fewer rows than expected (e.g. last page partially full).
		$count_sql         = "SELECT COUNT(*) FROM {$activity_t} WHERE {$where}";
		$this->total_items = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) );

		// Order by — only created_at is sortable; whitelist defends
		// against tampered ?orderby= values.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only sort UI.
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'created_at' !== $orderby ) {
			$orderby = 'created_at';
		}

		$sql       = "SELECT * FROM {$activity_t} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		$full_args = array_merge( $args, array( $per_page, $offset ) );
		$rows      = $wpdb->get_results( $wpdb->prepare( $sql, ...$full_args ) ) ?: array();

		$this->items = $rows;

		$this->set_pagination_args(
			array(
				'total_items' => $this->total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $this->total_items / max( 1, $per_page ) ),
			)
		);
	}

	/**
	 * Distinct list of action values currently in the table — drives the
	 * Type filter dropdown. Cached per request via the $known_actions
	 * property so repeated calls (header + footer of the form) hit the
	 * DB once.
	 *
	 * @return array<int, string>
	 */
	public function get_known_actions(): array {
		if ( null !== $this->known_actions ) {
			return $this->known_actions;
		}
		global $wpdb;
		$activity_t          = table( 'activity_log' );
		$rows                = $wpdb->get_col( "SELECT DISTINCT action FROM {$activity_t} WHERE action <> '' ORDER BY action ASC" );
		$this->known_actions = is_array( $rows ) ? array_map( 'strval', $rows ) : array();
		return $this->known_actions;
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

	public function column_created_at( $item ): string {
		if ( empty( $item->created_at ) || '0000-00-00 00:00:00' === $item->created_at ) {
			return '&mdash;';
		}
		$ts = strtotime( $item->created_at );
		if ( false === $ts ) {
			return esc_html( $item->created_at );
		}
		$human = sprintf(
			/* translators: %s: human-readable time difference */
			__( '%s ago', 'jetonomy' ),
			human_time_diff( $ts, time() )
		);
		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( $item->created_at ),
			esc_html( $human )
		);
	}

	public function column_actor( $item ): string {
		$user_id = (int) ( $item->user_id ?? 0 );
		if ( $user_id <= 0 ) {
			return '<em>' . esc_html__( 'System', 'jetonomy' ) . '</em>';
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return sprintf(
				/* translators: %d: deleted user id */
				esc_html__( 'Deleted user (#%d)', 'jetonomy' ),
				$user_id
			);
		}
		// Link back to this same screen pre-filtered to the user — gives
		// admins a one-click "show me everything this account did" view.
		$filter_url = add_query_arg(
			array(
				'page'    => 'jetonomy-activity',
				'user_id' => $user_id,
			),
			admin_url( 'admin.php' )
		);
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $filter_url ),
			esc_html( $user->display_name ?: $user->user_login )
		);
	}

	public function column_action( $item ): string {
		$action = (string) ( $item->action ?? '' );
		if ( '' === $action ) {
			return '&mdash;';
		}
		// Action codes are machine identifiers (e.g. created_post). Show
		// a humanized version while keeping the raw value as a tooltip
		// for admins debugging tracker output.
		$label = ucwords( str_replace( '_', ' ', $action ) );
		return sprintf(
			'<code title="%1$s">%2$s</code>',
			esc_attr( $action ),
			esc_html( $label )
		);
	}

	public function column_object( $item ): string {
		$type = (string) ( $item->object_type ?? '' );
		$id   = (int) ( $item->object_id ?? 0 );
		if ( '' === $type || $id <= 0 ) {
			return '&mdash;';
		}

		$label = sprintf( '%s #%d', ucfirst( $type ), $id );
		$url   = $this->resolve_object_admin_url( $type, $id );
		if ( '' === $url ) {
			return esc_html( $label );
		}
		return sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( $url ),
			esc_html( $label )
		);
	}

	/**
	 * Best-effort link to the related admin screen for the object — keeps
	 * the activity row clickable without requiring per-type joins in the
	 * main query. Unknown types fall through to plain text.
	 */
	private function resolve_object_admin_url( string $type, int $id ): string {
		switch ( $type ) {
			case 'post':
				return admin_url( 'admin.php?page=jetonomy-content&post_id=' . $id );
			case 'space':
				return admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . $id );
			case 'reply':
				// Replies live under their parent post; the activity row
				// only knows the reply id, so link to the global content
				// page rather than guessing the post.
				return admin_url( 'admin.php?page=jetonomy-content' );
			case 'user':
				return admin_url( 'admin.php?page=jetonomy-users&user_id=' . $id );
			default:
				return '';
		}
	}

	public function column_snippet( $item ): string {
		$raw = (string) ( $item->metadata ?? '' );
		if ( '' === $raw ) {
			return '&mdash;';
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return esc_html( wp_trim_words( $raw, 12, '…' ) );
		}
		// Render `key=value` pairs joined with bullets; truncates each
		// value to keep the row height predictable.
		$parts = array();
		foreach ( $decoded as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = sprintf( '%s=%s', sanitize_key( (string) $key ), wp_trim_words( (string) $value, 6, '…' ) );
			} elseif ( is_array( $value ) ) {
				$parts[] = sprintf( '%s=%s', sanitize_key( (string) $key ), wp_json_encode( $value ) );
			}
		}
		return esc_html( implode( ' • ', $parts ) );
	}

	/**
	 * Empty-state copy when no rows match the active filters.
	 */
	public function no_items(): void {
		esc_html_e( 'No activity found.', 'jetonomy' );
	}

	/**
	 * Filter form rendered above the table — placed inside the page form
	 * (which already wraps the list table) so submitting any filter
	 * preserves pagination + sorting via the standard WP_List_Table
	 * hidden inputs.
	 */
	public function render_filters(): void {
		$filters = self::read_filters();
		$actions = $this->get_known_actions();
		?>
		<div class="jt-activity-filters tablenav top">
			<div class="alignleft actions">
				<label class="screen-reader-text" for="jt-activity-user"><?php esc_html_e( 'Filter by user', 'jetonomy' ); ?></label>
				<input
					type="number"
					id="jt-activity-user"
					name="user_id"
					min="0"
					step="1"
					value="<?php echo esc_attr( (string) $filters['user_id'] ); ?>"
					placeholder="<?php esc_attr_e( 'User ID', 'jetonomy' ); ?>"
					class="small-text"
				/>

				<label class="screen-reader-text" for="jt-activity-action"><?php esc_html_e( 'Filter by type', 'jetonomy' ); ?></label>
				<select id="jt-activity-action" name="action_type">
					<option value=""><?php esc_html_e( 'All types', 'jetonomy' ); ?></option>
					<?php foreach ( $actions as $code ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $filters['action'], $code ); ?>>
							<?php echo esc_html( ucwords( str_replace( '_', ' ', $code ) ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<label class="screen-reader-text" for="jt-activity-from"><?php esc_html_e( 'From date', 'jetonomy' ); ?></label>
				<input
					type="date"
					id="jt-activity-from"
					name="date_from"
					value="<?php echo esc_attr( $filters['date_from'] ); ?>"
				/>
				<label class="screen-reader-text" for="jt-activity-to"><?php esc_html_e( 'To date', 'jetonomy' ); ?></label>
				<input
					type="date"
					id="jt-activity-to"
					name="date_to"
					value="<?php echo esc_attr( $filters['date_to'] ); ?>"
				/>

				<?php submit_button( __( 'Filter', 'jetonomy' ), 'secondary', 'jt-activity-filter', false ); ?>

				<?php if ( $filters['user_id'] || '' !== $filters['action'] || '' !== $filters['date_from'] || '' !== $filters['date_to'] ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-activity' ) ); ?>" class="button">
						<?php esc_html_e( 'Clear', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
