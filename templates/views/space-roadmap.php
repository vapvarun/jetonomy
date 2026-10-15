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
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		)
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
		array(
			'icon'    => 'lock',
			/* translators: 1: singular member label; 2: singular space label (e.g. space, group). */
			'message' => sprintf( __( 'You need to join this %s to see its roadmap.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
			'tone'    => 'forbidden',
		)
	);
	return;
}

// Private ideas show only to admins, space staff and their author, the same
// rule as the space's topic list. Each column loads at most 50 ideas; its
// badge shows the true count.
$jt_viewer_id = get_current_user_id();
$jt_is_priv   = $jt_viewer_id
	&& ( current_user_can( 'manage_options' )
		|| \Jetonomy\Permissions\Permission_Engine::is_space_privileged( $jt_viewer_id, (int) $space->id ) );

$jt_roadmap_col_cap = 50;
$jt_status_counts   = \Jetonomy\Models\Post::count_ideas_by_status( (int) $space->id, $jt_viewer_id, $jt_is_priv );

// Canonical column order (mirrors Post::valid_idea_statuses()). Owners
// move ideas left to right; "declined" sits at the end as the off-ramp.
// One colour per stage, set in jetonomy.css from the column's data-jt-status
// (accent = committed/queued, warn = in flight, success = shipped). It is not an
// inline style here: that paints the raw status hue as title text (2.4-2.9:1) and
// boxes the header on themes that style div borders.
$columns = array(
	'planned'     => array(
		'label' => __( 'Planned', 'jetonomy' ),
		'posts' => array(),
	),
	'in_progress' => array(
		'label' => __( 'In Progress', 'jetonomy' ),
		'posts' => array(),
	),
	'shipped'     => array(
		'label' => __( 'Shipped', 'jetonomy' ),
		'posts' => array(),
	),
	'declined'    => array(
		'label' => __( 'Declined', 'jetonomy' ),
		'posts' => array(),
	),
);

foreach ( array_keys( $columns ) as $jt_col_status ) {
	$columns[ $jt_col_status ]['posts'] = \Jetonomy\Models\Post::list_ideas_by_status( (int) $space->id, $jt_col_status, $jt_viewer_id, $jt_is_priv, $jt_roadmap_col_cap );

	$columns[ $jt_col_status ]['total'] = $jt_status_counts[ $jt_col_status ] ?? count( $columns[ $jt_col_status ]['posts'] );
}

$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;

$crumbs = array();
if ( $category ) {
	$crumbs[] = array(
		'label' => $category->name,
		'url'   => '',
	);
}
$crumbs[]  = array(
	'label' => $space->title,
	'url'   => \Jetonomy\route_url( 'space', $space->slug ),
);
$crumbs[]  = array(
	'label' => __( 'Roadmap', 'jetonomy' ),
	'url'   => '',
);
$space_url = \Jetonomy\route_url( 'space', $space->slug );
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>
<main>
	<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>

	<?php \Jetonomy\Template_Loader::partial( 'space-header', array( 'space' => $space ) ); ?>

	<?php
	\Jetonomy\Template_Loader::partial(
		'space-tabs',
		array(
			'space'     => $space,
			'space_url' => $space_url,
			'active'    => 'roadmap',
		)
	);
	?>

	<div class="jt-kanban">
		<?php foreach ( $columns as $col_key => $col ) : ?>
			<div class="jt-col" data-jt-status="<?php echo esc_attr( $col_key ); ?>">
				<div class="jt-col-head">
					<span class="jt-col-title">
						<?php echo esc_html( $col['label'] ); ?>
					</span>
					<span class="jt-col-n"><?php echo esc_html( $col['total'] ?? count( $col['posts'] ) ); ?></span>
				</div>
				<?php if ( empty( $col['posts'] ) ) : ?>
					<p class="jt-kanban-empty"><?php esc_html_e( 'No ideas here yet.', 'jetonomy' ); ?></p>
				<?php else : ?>
					<?php foreach ( $col['posts'] as $idea ) : ?>
						<?php $idea_url = \Jetonomy\route_url( 'post', $space->slug, $idea->slug ); ?>
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
								<span><?php echo esc_html( \Jetonomy\count_label( (int) $idea->reply_count, 'reply' ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
					<?php if ( ( $col['total'] ?? 0 ) > count( $col['posts'] ) ) : ?>
						<p class="jt-kanban-more">
							<a href="<?php echo esc_url( \Jetonomy\route_url( 'space', $space->slug ) ); ?>">
								<?php
								printf(
									/* translators: 1: number of additional ideas not shown in this column; 2: singular space label. */
									esc_html( _n( '+%1$d more idea in the %2$s feed', '+%1$d more ideas in the %2$s feed', (int) ( $col['total'] - count( $col['posts'] ) ), 'jetonomy' ) ),
									(int) ( $col['total'] - count( $col['posts'] ) ),
									esc_html( \Jetonomy\space_label( false, true ) )
								);
								?>
							</a>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
</main>
