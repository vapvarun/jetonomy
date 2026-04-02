<?php
/**
 * Sidebar partial.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow bridge plugins to suppress Jetonomy's sidebar (e.g. when
 * BuddyNext replaces it with its own community sidebar).
 *
 * @param bool $show Whether to render the sidebar. Default true.
 */
if ( ! apply_filters( 'jetonomy_show_sidebar', true ) ) {
	return;
}

$base = \Jetonomy\base_url();

global $wpdb;
$posts_tbl    = \Jetonomy\table( 'posts' );
$spaces_tbl   = \Jetonomy\table( 'spaces' );
$profiles_tbl = \Jetonomy\table( 'user_profiles' );

// Trending: top voted published posts, optionally scoped to the current space.
if ( ! empty( $space ) && isset( $space->id ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$trending = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT p.*, sp.slug AS space_slug FROM {$posts_tbl} p
			 INNER JOIN {$spaces_tbl} sp ON sp.id = p.space_id
			 WHERE p.space_id = %d AND p.status = 'publish'
			 ORDER BY p.vote_score DESC, p.reply_count DESC
			 LIMIT 5",
			(int) $space->id
		)
	) ?: [];
} else {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$trending = $wpdb->get_results(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT p.*, sp.slug AS space_slug FROM {$posts_tbl} p
		 INNER JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		 WHERE p.status = 'publish'
		 ORDER BY p.vote_score DESC, p.reply_count DESC
		 LIMIT 5"
	) ?: [];
}

// Top members by reputation.
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$leaders = $wpdb->get_results(
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	"SELECT * FROM {$profiles_tbl} ORDER BY reputation DESC LIMIT 5"
) ?: [];

// Popular tags.
$popular_tags = \Jetonomy\Models\Tag::list_popular( 15 );

// When BuddyNext is active, use its sidebar card skeleton so all sidebar
// widgets across BuddyNext, Jetonomy, and WPMediaVerse look identical.
$bn_active = did_action( 'buddynext_loaded' );
?>
<aside class="jt-sidebar">

	<?php if ( ! empty( $space ) && isset( $space->id ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'About', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'About', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php if ( ! empty( $space->description ) ) : ?>
				<p class="jt-sidebar-about"><?php echo esc_html( $space->description ); ?></p>
			<?php endif; ?>
			<div class="jt-sidebar-stats">
				<div class="jt-sidebar-stat">
					<strong><?php echo (int) ( $space->post_count ?? 0 ); ?></strong>
					<span><?php esc_html_e( 'Posts', 'jetonomy' ); ?></span>
				</div>
				<div class="jt-sidebar-stat">
					<strong><?php echo (int) ( $space->member_count ?? 0 ); ?></strong>
					<span><?php esc_html_e( 'Members', 'jetonomy' ); ?></span>
				</div>
			</div>
			<?php
			$space_type_labels = [
				'forum'  => __( 'Forum', 'jetonomy' ),
				'qa'     => __( 'Q&A', 'jetonomy' ),
				'ideas'  => __( 'Ideas', 'jetonomy' ),
				'social' => __( 'Social', 'jetonomy' ),
			];
			$space_type        = $space->type ?? 'forum';
			?>
			<div class="jt-sidebar-meta">
				<span class="jt-tag"><?php echo esc_html( $space_type_labels[ $space_type ] ?? ucfirst( $space_type ) ); ?></span>
				<?php if ( 'public' !== ( $space->visibility ?? 'public' ) ) : ?>
					<span class="jt-tag"><?php echo esc_html( ucfirst( $space->visibility ) ); ?></span>
				<?php endif; ?>
			</div>
			<?php
			/**
			 * Fires inside the sidebar About card, after the meta tags.
			 * Used by BuddyPress integration to show linked group.
			 *
			 * @param object $space The current space object.
			 */
			do_action( 'jetonomy_sidebar_about_after_meta', $space );
			?>
			<?php if ( is_user_logged_in() ) : ?>
				<div style="margin-top:12px;">
					<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/members/' ); ?>" class="jt-sidebar-link-text">
						<?php esc_html_e( 'View all members', 'jetonomy' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $trending ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'Trending', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'Trending', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php foreach ( $trending as $i => $t_post ) : ?>
				<div class="jt-trend">
					<div>
						<div class="jt-trend-title">
							<a href="<?php echo esc_url( $base . '/s/' . $t_post->space_slug . '/t/' . $t_post->slug . '/' ); ?>">
								<?php echo esc_html( $t_post->title ); ?>
							</a>
						</div>
						<div class="jt-trend-meta">
							<?php
							$v = (int) $t_post->vote_score;
							$r = (int) $t_post->reply_count;
							/* translators: 1: vote count with singular/plural, 2: reply count with singular/plural */
							echo esc_html(
								sprintf(
									/* translators: %d: number of votes */
									_n( '%d vote', '%d votes', $v, 'jetonomy' ),
									$v
								)
								. ' · '
								. sprintf(
									/* translators: %d: number of replies */
									_n( '%d reply', '%d replies', $r, 'jetonomy' ),
									$r
								)
							);
							?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $leaders ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'Top Members', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'Top Members', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php foreach ( $leaders as $rank => $leader ) : ?>
				<?php
				$lu = get_userdata( (int) $leader->user_id );
				if ( ! $lu ) {
					continue;
				}
				?>
				<div class="jt-leader">
					<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( (int) $leader->user_id ) ? 'is-online' : ''; ?>">
						<span class="jt-avatar jt-avatar-sm jt-flex-shrink-0"><?php echo esc_html( strtoupper( substr( $lu->display_name, 0, 2 ) ) ); ?></span>
					</span>
					<span class="jt-leader-name">
						<a href="<?php echo esc_url( \Jetonomy\get_profile_url( (int) $leader->user_id ) ); ?>">
							<?php echo esc_html( $lu->display_name ); ?>
						</a>
					</span>
					<span class="jt-leader-pts"><?php echo (int) $leader->reputation; ?></span>
				</div>
			<?php endforeach; ?>
			<div class="jt-sidebar-link">
				<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>"><?php esc_html_e( 'View full leaderboard', 'jetonomy' ); ?></a>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $popular_tags ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<div class="jt-tags">
				<?php foreach ( $popular_tags as $tag ) : ?>
					<a href="<?php echo esc_url( $base . '/tag/' . $tag->slug . '/' ); ?>" class="jt-tag">
						<?php echo esc_html( $tag->name ); ?>
						<span class="jt-tag-count"><?php echo (int) $tag->post_count; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

</aside>
