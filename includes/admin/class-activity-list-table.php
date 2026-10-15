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

use Jetonomy\Models\ActivityLog;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;

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

	/**
	 * Posts and replies this page's rows point at, for anonymous-actor masking.
	 *
	 * @var array<string, object>
	 */
	private array $objects = array();

	/**
	 * Titles, parents and spaces this page's rows point at (see prime()).
	 *
	 * @var array{posts: array<int, object>, replies: array<int, object>, spaces: array<int, object>}
	 */
	private array $context = array(
		'posts'   => array(),
		'replies' => array(),
		'spaces'  => array(),
	);

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'activity',
				'plural'   => 'activities',
				'ajax'     => false,
				// Use the real current screen so column/screen-option prefs bind
				// correctly even when White Label renames the menu (which changes
				// the screen id prefix). Fall back to the canonical id if the
				// screen isn't set yet.
				'screen'   => get_current_screen() ? get_current_screen()->id : 'jetonomy_page_jetonomy-activity',
			)
		);
	}

	/**
	 * Column definitions.
	 *
	 * @return array<string, string>
	 */
	public function get_columns(): array {
		// Actor FIRST, because it is the primary. Core hides small-screen columns
		// with `td.column-primary ~ td`, a sibling combinator that only reaches
		// columns AFTER the primary cell. With Date first and the primary
		// overridden to Actor, Date stayed display:table-cell, table-layout:fixed
		// squeezed it to 0 width, and at 390px it spilled a character per line.
		//
		// Same defect the Revisions screen had: both
		// overrode the primary without reordering.
		// Do not reorder these two without re-checking 390px - the browser gate
		// in tests/browser/admin-tables.spec.js asserts it.
		return array(
			'actor'      => __( 'Actor', 'jetonomy' ),
			'created_at' => __( 'Date', 'jetonomy' ),
			'action'     => __( 'Type', 'jetonomy' ),
			'object'     => __( 'Object', 'jetonomy' ),
			'snippet'    => __( 'Details', 'jetonomy' ),
		);
	}

	/**
	 * Collapse mobile rows around WHO acted, not when.
	 *
	 * WP_List_Table makes the first column primary, which here was the date -
	 * so every collapsed row on a phone read "22 minutes ago" and nothing
	 * distinguished one entry from the next. The actor
	 * is the identity in an activity log; the rest expands behind the toggle.
	 *
	 * Actor is now first, so this restates WP's default rather than changing it.
	 * Kept deliberately: it pins the intent, so reordering the columns cannot
	 * silently move the primary back onto the date.
	 */
	protected function get_default_primary_column_name(): string {
		return 'actor';
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
	 * Hydrate items + pagination state.
	 */
	public function prepare_items(): void {
		$per_page = $this->get_items_per_page( 'jetonomy_activity_per_page', 20 );
		$paged    = max( 1, absint( $this->get_pagenum() ) );
		$offset   = ( $paged - 1 ) * $per_page;

		// Fourth element is the PRIMARY column, set explicitly. Leaving it off
		// means core resolves it via get_primary_column_name(), which validates
		// the name against get_column_headers( $this->screen ) - and a custom admin
		// page registers no screen columns, so the check fails and it silently
		// falls back to the first column. That put the DATE in the mobile primary
		// cell, so every collapsed row read "22 minutes ago".
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'actor' );

		$filters = self::read_filters();

		// Count first — lets the pager render even when the page query
		// returns fewer rows than expected (e.g. last page partially full).
		$this->total_items = ActivityLog::count_filtered( $filters );

		// Only created_at is sortable (KEY created), so only the direction is read.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only sort UI.
		$order = isset( $_GET['order'] ) && 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';

		$this->items   = ActivityLog::list_filtered( $filters, $order, $per_page, $offset );
		$this->objects = \Jetonomy\Author::objects_of( $this->items );
		$this->context = self::prime( $this->items );

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
		$this->known_actions = ActivityLog::list_actions();
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
			/* translators: %s: human-readable time difference. */
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
		$object = $this->objects[ ( $item->object_type ?? '' ) . ':' . (int) ( $item->object_id ?? 0 ) ] ?? null;
		if ( \Jetonomy\Author::hides_actor( $user_id, $object ) ) {
			return '<em>' . esc_html__( 'Anonymous', 'jetonomy' ) . '</em>';
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
		if ( '' === (string) ( $item->action ?? '' ) ) {
			return '&mdash;';
		}
		// The raw code stays as a tooltip for admins debugging tracker output.
		return sprintf(
			'<span title="%1$s">%2$s</span>',
			esc_attr( (string) $item->action ),
			esc_html( self::action_text( $item ) )
		);
	}

	public function column_object( $item ): string {
		return self::object_html( $item, $this->context );
	}

	public function column_snippet( $item ): string {
		$text = self::details_text( $item );
		return '' === $text ? '&mdash;' : esc_html( $text );
	}

	/**
	 * Batch-load everything the activity formatters need for a set of log rows:
	 * one query each for posts, replies (plus their parent posts) and spaces,
	 * and one for the users involved, so rendering a page is N+1-free.
	 *
	 * Shared by the Activity Log screen and the Dashboard's Recent Activity.
	 *
	 * @param array<int, object> $rows Activity log rows.
	 * @return array{posts: array<int, object>, replies: array<int, object>, spaces: array<int, object>}
	 */
	public static function prime( array $rows ): array {
		$ids = array(
			'post'  => array(),
			'reply' => array(),
			'space' => array(),
			'user'  => array(),
		);
		foreach ( $rows as $row ) {
			$type = (string) ( $row->object_type ?? '' );
			if ( isset( $ids[ $type ] ) ) {
				$ids[ $type ][] = (int) $row->object_id;
			}
			$ids['user'][] = (int) ( $row->user_id ?? 0 );
		}

		$replies = Reply::list_by_ids( $ids['reply'] );
		foreach ( $replies as $reply ) {
			$ids['post'][] = (int) $reply->post_id;
		}
		$user_ids = array_values( array_unique( array_filter( $ids['user'] ) ) );
		if ( $user_ids ) {
			cache_users( $user_ids ); // Primes get_userdata() for the actor and user-object cells.
		}

		return array(
			'posts'   => Post::list_by_ids( $ids['post'] ),
			'replies' => $replies,
			'spaces'  => array_column( Space::list_by_ids( array_unique( $ids['space'] ) ), null, 'id' ),
		);
	}

	/**
	 * The action, in words. Reputation rows say how much was gained or lost
	 * instead of a bare "Reputation changed".
	 *
	 * @param object $row Activity log row.
	 * @return string Unescaped.
	 */
	public static function action_text( object $row ): string {
		$action = (string) ( $row->action ?? '' );
		if ( 'reputation_changed' === $action ) {
			$delta = (int) ( self::metadata( $row )['delta'] ?? 0 );
			$abs   = abs( $delta );
			return $delta < 0
				/* translators: %s: number of reputation points. */
				? sprintf( _n( 'Lost %s reputation point', 'Lost %s reputation points', $abs, 'jetonomy' ), number_format_i18n( $abs ) )
				/* translators: %s: number of reputation points. */
				: sprintf( _n( 'Gained %s reputation point', 'Gained %s reputation points', $abs, 'jetonomy' ), number_format_i18n( $abs ) );
		}
		return Admin::action_label( $action );
	}

	/**
	 * The thing acted on: its linked title, or a "Deleted ..." note when it is gone.
	 *
	 * @param object $row     Activity log row.
	 * @param array  $context Output of prime() for the rows being rendered.
	 * @return string Escaped HTML.
	 */
	public static function object_html( object $row, array $context ): string {
		$type = (string) ( $row->object_type ?? '' );
		$id   = (int) ( $row->object_id ?? 0 );
		if ( '' === $type || $id <= 0 ) {
			return '&mdash;';
		}
		$link = static fn ( string $url, string $text ): string => sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html( $text ) );

		switch ( $type ) {
			case 'post':
				$post = $context['posts'][ $id ] ?? null;
				return $post
					? $link( admin_url( 'admin.php?page=jetonomy-content&post_id=' . $id ), jetonomy_post_title_or_excerpt( $post ) )
					: esc_html( self::deleted_label( 'topic' ) );

			case 'reply':
				$reply  = $context['replies'][ $id ] ?? null;
				$parent = $reply ? ( $context['posts'][ (int) $reply->post_id ] ?? null ) : null;
				if ( ! $parent ) {
					return esc_html( self::deleted_label( 'reply' ) );
				}
				return sprintf(
					/* translators: 1: reply label (e.g. "Reply"). 2: linked post title. */
					esc_html__( '%1$s on %2$s', 'jetonomy' ),
					esc_html( \Jetonomy\jetonomy_label( 'reply' ) ),
					$link( admin_url( 'admin.php?page=jetonomy-content&post_id=' . (int) $reply->post_id ), jetonomy_post_title_or_excerpt( $parent ) )
				);

			case 'space':
				$space = $context['spaces'][ $id ] ?? null;
				return $space
					? $link( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . $id ), (string) $space->title )
					: esc_html( self::deleted_label( 'space' ) );

			case 'user':
				$user = get_userdata( $id );
				return $user
					? $link( admin_url( 'admin.php?page=jetonomy-users&user_id=' . $id ), $user->display_name ?: $user->user_login )
					/* translators: %d: deleted user id */
					: esc_html( sprintf( __( 'Deleted user (#%d)', 'jetonomy' ), $id ) );
		}
		return esc_html( sprintf( '%s #%d', Admin::object_type_label( $type ), $id ) );
	}

	/**
	 * "Deleted topic" / "Deleted reply" / "Deleted space" in the owner's terms.
	 *
	 * @param string $noun Terminology noun (topic, reply, space).
	 * @return string Unescaped.
	 */
	public static function deleted_label( string $noun ): string {
		/* translators: %s: content label, lowercase (e.g. "topic" or "reply"). */
		return sprintf( __( 'Deleted %s', 'jetonomy' ), \Jetonomy\jetonomy_label( $noun, false, true ) );
	}

	/**
	 * The row's metadata in plain words (why reputation changed, the new
	 * trust level, the role a member joined with). Empty when there is none.
	 *
	 * @param object $row Activity log row.
	 * @return string Unescaped.
	 */
	public static function details_text( object $row ): string {
		$meta = self::metadata( $row );
		if ( ! $meta ) {
			return '';
		}
		$humanize = static fn ( $value ): string => ucfirst( str_replace( '_', ' ', (string) $value ) );

		switch ( (string) ( $row->action ?? '' ) ) {
			case 'reputation_changed':
				return self::reputation_reason( (string) ( $meta['reason'] ?? '' ) );
			case 'trust_level_changed':
				return sprintf(
					/* translators: 1: previous value. 2: new value. */
					__( '%1$s to %2$s', 'jetonomy' ),
					\Jetonomy\Trust\Trust_Levels::label( (int) ( $meta['old'] ?? 0 ) ),
					\Jetonomy\Trust\Trust_Levels::label( (int) ( $meta['new'] ?? 0 ) )
				);
			case 'idea_status_changed':
				return sprintf(
					/* translators: 1: previous value. 2: new value. */
					__( '%1$s to %2$s', 'jetonomy' ),
					$humanize( $meta['old'] ?? '' ),
					$humanize( $meta['new'] ?? '' )
				);
			case 'joined_space':
				/* translators: %s: the member's role in the space (e.g. "Moderator"). */
				return sprintf( __( 'Role: %s', 'jetonomy' ), $humanize( $meta['role'] ?? '' ) );
		}

		// Unknown action (an add-on's): readable "Key: value" pairs.
		$parts = array();
		foreach ( $meta as $key => $value ) {
			$parts[] = $humanize( $key ) . ': ' . ( is_scalar( $value ) ? wp_trim_words( (string) $value, 6, '…' ) : wp_json_encode( $value ) );
		}
		return implode( ' · ', $parts );
	}

	/**
	 * Why reputation changed, in words. Reason keys are Reputation's action
	 * keys; "{key}_revoked" is the same award taken back (e.g. an upvote removed).
	 *
	 * @param string $reason Reason key from the reputation_changed row.
	 * @return string Unescaped.
	 */
	public static function reputation_reason( string $reason ): string {
		$revoked = str_ends_with( $reason, '_revoked' );
		$key     = $revoked ? substr( $reason, 0, -8 ) : $reason;
		$topic   = \Jetonomy\jetonomy_label( 'topic', false, true );
		$reply   = \Jetonomy\jetonomy_label( 'reply', false, true );

		/* translators: %s: content label, lowercase (e.g. "topic" or "reply"). */
		$upvoted = __( 'Their %s was upvoted', 'jetonomy' );
		/* translators: %s: content label, lowercase (e.g. "topic" or "reply"). */
		$downvoted = __( 'Their %s was downvoted', 'jetonomy' );
		/* translators: %s: content label, lowercase (e.g. "topic" or "reply"). */
		$created = __( 'They posted a new %s', 'jetonomy' );

		$labels = array(
			'post_created'    => sprintf( $created, $topic ),
			'reply_created'   => sprintf( $created, $reply ),
			'post_upvoted'    => sprintf( $upvoted, $topic ),
			'reply_upvoted'   => sprintf( $upvoted, $reply ),
			'post_downvoted'  => sprintf( $downvoted, $topic ),
			'reply_downvoted' => sprintf( $downvoted, $reply ),
			'downvoted'       => __( 'Their content was downvoted', 'jetonomy' ),
			/* translators: %s: reply label, lowercase (e.g. "reply"). */
			'reply_accepted'  => sprintf( __( 'Their %s was accepted as the answer', 'jetonomy' ), $reply ),
			'idea_planned'    => __( 'Their idea moved to Planned or Shipped', 'jetonomy' ),
			'flag_validated'  => __( 'A report they filed was upheld', 'jetonomy' ),
			/* translators: %s: topic label, lowercase (e.g. "topic"). */
			'post_reported'   => sprintf( __( 'Their %s was reported', 'jetonomy' ), $topic ),
			/* translators: %s: topic label, lowercase (e.g. "topic"). */
			'post_removed'    => sprintf( __( 'Their %s was removed by a moderator', 'jetonomy' ), $topic ),
			'badge_earned'    => __( 'They earned a badge', 'jetonomy' ),
		);
		$text   = $labels[ $key ] ?? ucfirst( str_replace( '_', ' ', $key ) );

		/* translators: %s: why reputation changed (e.g. "Their topic was upvoted"). */
		return $revoked ? sprintf( __( '%s, then undone', 'jetonomy' ), $text ) : $text;
	}

	/**
	 * Decoded metadata JSON of a log row.
	 *
	 * @param object $row Activity log row.
	 * @return array<string, mixed>
	 */
	private static function metadata( object $row ): array {
		$decoded = json_decode( (string) ( $row->metadata ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
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
							<?php echo esc_html( Admin::action_label( (string) $code ) ); ?>
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
