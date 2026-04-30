<?php
/**
 * Space roadmap view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Space not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

// Fetch posts tagged as idea-like or within ideas spaces.
// Render as a kanban board grouped by resolved/open status.
global $wpdb;
$posts_tbl = \Jetonomy\table( 'posts' );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$all_ideas = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT * FROM {$posts_tbl}
		 WHERE space_id = %d AND status = 'publish'
		 ORDER BY vote_score DESC, created_at DESC
		 LIMIT 60",
		(int) $space->id
	)
) ?: [];

// Group into columns by is_resolved / is_closed.
$columns = [
	'open'        => [
		'label' => __( 'Open', 'jetonomy' ),
		'color' => 'var(--jt-accent)',
		'posts' => [],
	],
	'in-progress' => [
		'label' => __( 'In Progress', 'jetonomy' ),
		'color' => 'var(--jt-warn)',
		'posts' => [],
	],
	'resolved'    => [
		'label' => __( 'Resolved', 'jetonomy' ),
		'color' => 'var(--jt-success)',
		'posts' => [],
	],
	'closed'      => [
		'label' => __( 'Closed', 'jetonomy' ),
		'color' => 'var(--jt-text-tertiary)',
		'posts' => [],
	],
];

foreach ( $all_ideas as $idea ) {
	if ( $idea->is_closed ) {
		$columns['closed']['posts'][] = $idea;
	} elseif ( $idea->is_resolved ) {
		$columns['resolved']['posts'][] = $idea;
	} else {
		// Simple heuristic: posts with at least one reply are "in progress".
		if ( (int) $idea->reply_count > 0 ) {
			$columns['in-progress']['posts'][] = $idea;
		} else {
			$columns['open']['posts'][] = $idea;
		}
	}
}

$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base     = \Jetonomy\base_url();

$crumbs = [];
if ( $category ) {
	$crumbs[] = [
		'label' => $category->name,
		'url'   => '',
	];
}
$crumbs[] = [
	'label' => $space->title,
	'url'   => $base . '/s/' . $space->slug . '/',
];
$crumbs[] = [
	'label' => __( 'Roadmap', 'jetonomy' ),
	'url'   => '',
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-cat-page-row">
		<?php if ( ! empty( $space->icon ) ) : ?>
			<span class="jt-space-card-emoji"><?php echo esc_html( $space->icon ); ?></span>
		<?php endif; ?>
		<h1 class="jt-page-title jt-page-title-sm">
			<?php echo esc_html( $space->title ); ?> &mdash; <?php esc_html_e( 'Roadmap', 'jetonomy' ); ?>
		</h1>
	</div>

	<div class="jt-kanban">
		<?php foreach ( $columns as $col_key => $col ) : ?>
			<div class="jt-col">
				<div class="jt-col-head" style="border-color:<?php echo esc_attr( $col['color'] ); ?>;">
					<span class="jt-col-title" style="color:<?php echo esc_attr( $col['color'] ); ?>;">
						<?php echo esc_html( $col['label'] ); ?>
					</span>
					<span class="jt-col-n"><?php echo esc_html( count( $col['posts'] ) ); ?></span>
				</div>
				<?php if ( empty( $col['posts'] ) ) : ?>
					<p class="jt-kanban-empty"><?php esc_html_e( 'None', 'jetonomy' ); ?></p>
				<?php else : ?>
					<?php foreach ( $col['posts'] as $idea ) : ?>
						<?php $idea_url = $base . '/s/' . $space->slug . '/t/' . $idea->slug . '/'; ?>
						<div class="jt-idea jt-row-clickable" data-jt-href="<?php echo esc_url( $idea_url ); ?>">
							<div class="jt-idea-title"><?php echo esc_html( $idea->title ); ?></div>
							<div class="jt-idea-excerpt">
								<?php echo esc_html( wp_strip_all_tags( $idea->content ) ); ?>
							</div>
							<div class="jt-idea-meta">
								<span class="jt-idea-votes"><?php jetonomy_echo_icon( 'chevron-up', 14 ); ?> <?php echo esc_html( (int) $idea->vote_score ); ?></span>
								<span><?php echo esc_html( (int) $idea->reply_count ); ?> <?php esc_html_e( 'replies', 'jetonomy' ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
