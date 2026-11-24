<?php
/**
 * Sidebar widget: Trending. Rendered by partials/sidebar.php when the owner
 * has it switched on (Settings > Appearance). Fetches its own data, so a
 * switched-off widget costs nothing. Override at
 * {theme}/jetonomy/partials/sidebar/trending.php.
 *
 * @package Jetonomy
 * @since   2.1.0
 *
 * @var object|null $space     Current space, or null outside a space.
 * @var bool        $bn_active Whether BuddyNext card markup is used.
 */

defined( 'ABSPATH' ) || exit;

$trending = \Jetonomy\Models\Post::list_top_voted( ( ! empty( $space ) && isset( $space->id ) ) ? (int) $space->id : 0 );
?>
<?php if ( ! empty( $trending ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h2 class="jt-card-title"><?php esc_html_e( 'Trending', 'jetonomy' ); ?></h2>
			<?php else : ?>
				<?php esc_html_e( 'Trending', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php foreach ( $trending as $i => $t_post ) : ?>
				<div class="jt-trend">
					<div>
						<div class="jt-trend-title">
							<a href="<?php echo esc_url( \Jetonomy\route_url( 'post', $t_post->space_slug, $t_post->slug ) ); ?>">
								<bdi><?php echo esc_html( jetonomy_post_title_or_excerpt( $t_post ) ); ?></bdi>
							</a>
						</div>
						<div class="jt-trend-meta">
							<?php
							$v = (int) $t_post->vote_score;
							$r = (int) $t_post->reply_count;
							/* translators: 1: vote count with singular/plural, 2: reply count with singular/plural */
							echo esc_html(
								sprintf(
									/* translators: %d: number of votes. */
									_n( '%d vote', '%d votes', $v, 'jetonomy' ),
									$v
								)
								. ' · '
								. \Jetonomy\count_label( (int) $r, 'reply' )
							);
							?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
<?php endif; ?>
