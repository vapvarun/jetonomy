<?php
/**
 * Space roadmap view.
 *
 * Renders a kanban board for `type=ideas` spaces, grouped by the
 * `idea_status` column. Status is set by space moderators via the
 * post page, never inferred from `is_resolved` / `is_closed` /
 * `reply_count` (those signals all mean different things and were
 * never a real workflow). Posts without an explicit status (NULL)
 * live in the space's normal feed and do not appear on the roadmap.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space || \Jetonomy\Models\Space::concealed_from_viewer( $space, get_current_user_id() ) ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the singular space label. */
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		]
	);
	return;
}

// Visibility gate: the roadmap of a private/hidden ideas space is members-only.
// Mirror the main space view and the REST layer, which both require read access
// before exposing a gated space's ideas. Runs BEFORE the query below so a
// non-member never reads idea titles/content of a space they cannot access.
if ( ! \Jetonomy\Permissions\Permission_Engine::can( get_current_user_id(), 'read', (int) $space->id ) ) {
	status_header( 403 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'    => 'lock',
			/* translators: 1: singular member label; 2: singular space label (e.g. space, group). */
			'message' => sprintf( __( 'You need to be a %1$s of this %2$s to see its roadmap.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', false, true ), \Jetonomy\space_label( false, true ) ),
			'tone'    => 'forbidden',
		]
	);
	return;
}

global $wpdb;
$posts_tbl = \Jetonomy\table( 'posts' );

// Private ideas (is_private = 1) surface only to privileged viewers (admins /
// space moderators) and their own author — mirror the predicate
// Post::list_by_space_visible() uses so the roadmap never leaks private ideas,
// even on a public space.
$jt_viewer_id = get_current_user_id();
$jt_is_priv   = $jt_viewer_id
	&& ( current_user_can( 'manage_options' )
		|| \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $jt_viewer_id, (int) $space->id ) );

$jt_private_sql    = '';
$jt_private_params = array( (int) $space->id );
if ( ! $jt_is_priv ) {
	if ( $jt_viewer_id > 0 ) {
		$jt_private_sql      = ' AND (is_private = 0 OR author_id = %d)';
		$jt_private_params[] = $jt_viewer_id;
	} else {
		$jt_private_sql = ' AND is_private = 0';
	}
}

// Hide ideas authored by users the viewer has blocked. no-op for guests/no-blocks.
[ $jt_block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( $jt_viewer_id, '', 'author_id' );
if ( '' !== $jt_block_sql ) {
	$jt_private_sql .= ' AND ' . $jt_block_sql;
}

// Bounded per-column fetch (caching plan WP1.2). The old single query had
// no LIMIT — every idea in the space, longtext bodies included, loaded to
// bucket into 4 columns in PHP; fatal on a 10k-post space. Now: one
// GROUP BY for the TRUE column counts (the jt-col-n badges must not lie
// about the page size), then one bounded query per column. Columns past
// the cap render a "+N more" note.
$jt_roadmap_col_cap = 50;

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$jt_status_count_rows = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT idea_status, COUNT(*) AS n FROM {$posts_tbl}
		 WHERE space_id = %d AND status = 'publish' AND idea_status IS NOT NULL{$jt_private_sql}
		 GROUP BY idea_status",
		...$jt_private_params
	)
) ?: [];
$jt_status_counts = [];
foreach ( $jt_status_count_rows as $jt_count_row ) {
	$jt_status_counts[ (string) $jt_count_row->idea_status ] = (int) $jt_count_row->n;
}

// Canonical column order (mirrors Post::valid_idea_statuses()). Owners
// move ideas left to right; "declined" sits at the end as the off-ramp.
// One colour per stage. Planned and In Progress both used --jt-warn, so the
// two leftmost columns were indistinguishable and the colour carried no
// information at all — the whole point of a status board. Accent reads as
// "committed, queued", warn as "in flight".
$columns = array(
	'planned'     => array(
		'label' => __( 'Planned', 'jetonomy' ),
		'color' => 'var(--jt-accent)',
		'posts' => array(),
	),
	'in_progress' => array(
		'label' => __( 'In Progress', 'jetonomy' ),
		'color' => 'var(--jt-warn)',
		'posts' => array(),
	),
	'shipped'     => array(
		'label' => __( 'Shipped', 'jetonomy' ),
		'color' => 'var(--jt-success)',
		'posts' => array(),
	),
	'declined'    => array(
		'label' => __( 'Declined', 'jetonomy' ),
		'color' => 'var(--jt-text-tertiary)',
		'posts' => array(),
	),
);

foreach ( array_keys( $columns ) as $jt_col_status ) {
	// One bounded query per column. The id tiebreak keeps the cap window
	// deterministic across ties (the same rule as every paged list).
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$columns[ $jt_col_status ]['posts'] = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT * FROM {$posts_tbl}
			 WHERE space_id = %d AND status = 'publish' AND idea_status = %s{$jt_private_sql}
			 ORDER BY vote_score DESC, created_at DESC, id DESC
			 LIMIT %d",
			array_merge(
				array( (int) $space->id, $jt_col_status ),
				array_slice( $jt_private_params, 1 ),
				array( $jt_roadmap_col_cap )
			)
		)
	) ?: [];

	$columns[ $jt_col_status ]['total'] = $jt_status_counts[ $jt_col_status ] ?? count( $columns[ $jt_col_status ]['posts'] );
}

$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base     = \Jetonomy\base_url();

$crumbs = array();
if ( $category ) {
	$crumbs[] = array(
		'label' => $category->name,
		'url'   => '',
	);
}
$crumbs[]  = array(
	'label' => $space->title,
	'url'   => $base . '/s/' . $space->slug . '/',
);
$crumbs[]  = array(
	'label' => __( 'Roadmap', 'jetonomy' ),
	'url'   => '',
);
$space_url = $base . '/s/' . $space->slug . '/';
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-cat-page-row">
	<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-emoji', $space->type ?? '' ); ?>
	<div>
		<h1 class="jt-page-title jt-page-title-sm">
			<?php echo esc_html( $space->title ); ?>
		</h1>
		<p class="jt-page-subtitle"><?php esc_html_e( 'Roadmap', 'jetonomy' ); ?></p>
	</div>
</div>

<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
<nav class="jt-space-tabs" aria-label="<?php echo esc_attr( sprintf( __( '%s sections', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>">
	<a href="<?php echo esc_url( $space_url ); ?>" class="jt-space-tab">
		<?php esc_html_e( 'Ideas', 'jetonomy' ); ?>
	</a>
	<a href="<?php echo esc_url( $space_url . 'roadmap/' ); ?>" class="jt-space-tab on" aria-current="page">
		<?php esc_html_e( 'Roadmap', 'jetonomy' ); ?>
	</a>
</nav>

<div class="jt-kanban">
	<?php foreach ( $columns as $col_key => $col ) : ?>
		<div class="jt-col" data-jt-status="<?php echo esc_attr( $col_key ); ?>">
			<div class="jt-col-head" style="border-color:<?php echo esc_attr( $col['color'] ); ?>;">
				<span class="jt-col-title" style="color:<?php echo esc_attr( $col['color'] ); ?>;">
					<?php echo esc_html( $col['label'] ); ?>
				</span>
				<span class="jt-col-n"><?php echo esc_html( $col['total'] ?? count( $col['posts'] ) ); ?></span>
			</div>
			<?php if ( empty( $col['posts'] ) ) : ?>
				<p class="jt-kanban-empty"><?php esc_html_e( 'No ideas here yet.', 'jetonomy' ); ?></p>
			<?php else : ?>
				<?php foreach ( $col['posts'] as $idea ) : ?>
					<?php $idea_url = $base . '/s/' . $space->slug . '/t/' . $idea->slug . '/'; ?>
					<div class="jt-idea jt-row-clickable" data-jt-href="<?php echo esc_url( $idea_url ); ?>">
						<div class="jt-idea-title"><?php echo esc_html( $idea->title ); ?></div>
						<?php if ( ! empty( $idea->content ) ) : ?>
							<div class="jt-idea-excerpt">
								<?php echo esc_html( wp_trim_words( wp_strip_all_tags( $idea->content ), 22, '…' ) ); ?>
							</div>
						<?php endif; ?>
						<div class="jt-idea-meta">
							<?php if ( jetonomy_space_allows_voting( $space ) ) : ?>
								<span class="jt-idea-votes"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?> <?php echo esc_html( (int) $idea->vote_score ); ?></span>
							<?php endif; ?>
							<span><?php echo esc_html( (int) $idea->reply_count ); ?> <?php echo esc_html( \Jetonomy\jetonomy_label( 'reply', true, true ) ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
				<?php if ( ( $col['total'] ?? 0 ) > count( $col['posts'] ) ) : ?>
					<p class="jt-kanban-more">
						<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
							<?php
							printf(
								/* translators: %d: number of additional ideas not shown in this column. */
								esc_html( _n( '+%d more idea in the space feed', '+%d more ideas in the space feed', (int) ( $col['total'] - count( $col['posts'] ) ), 'jetonomy' ) ),
								(int) ( $col['total'] - count( $col['posts'] ) )
							);
							?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
