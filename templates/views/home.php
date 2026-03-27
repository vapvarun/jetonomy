<?php
/**
 * Home view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$categories           = \Jetonomy\Models\Category::list_top_level();
$uncategorized_spaces = \Jetonomy\Models\Space::list_uncategorized();
$base                 = \Jetonomy\base_url();

/**
 * Render a grid of space cards.
 *
 * @param object[] $spaces
 * @param string   $base Community base URL.
 */
function jetonomy_render_space_grid( array $spaces, string $base ): void {
	if ( empty( $spaces ) ) {
		echo '<p class="jt-cat-empty">' . esc_html__( 'No spaces in this category yet.', 'jetonomy' ) . '</p>';
		return;
	}
	echo '<div class="jt-space-grid">';
	foreach ( $spaces as $space ) {
		?>
		<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
			class="jt-card jt-space-card jt-no-underline jt-block">
			<div class="jt-space-card-inner">
				<?php if ( ! empty( $space->icon ) ) : ?>
					<?php if ( str_starts_with( $space->icon, 'dashicons-' ) ) : ?>
						<span class="jt-space-card-emoji dashicons <?php echo esc_attr( $space->icon ); ?>"></span>
					<?php else : ?>
						<span class="jt-space-card-emoji"><?php echo esc_html( $space->icon ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
				<div class="jt-space-card-body">
					<div class="jt-space-card-title">
						<?php echo esc_html( $space->title ); ?>
					</div>
					<?php if ( ! empty( $space->description ) ) : ?>
						<div class="jt-space-card-excerpt">
							<?php echo esc_html( $space->description ); ?>
						</div>
					<?php endif; ?>
					<div class="jt-space-card-stats">
						<span class="jt-space-card-stat">
							<strong><?php echo (int) $space->post_count; ?></strong>
							<?php echo esc_html( _n( 'post', 'posts', (int) $space->post_count, 'jetonomy' ) ); ?>
						</span>
						<span class="jt-space-card-stat">
							<strong><?php echo (int) $space->member_count; ?></strong>
							<?php echo esc_html( _n( 'member', 'members', (int) $space->member_count, 'jetonomy' ) ); ?>
						</span>
					</div>
				</div>
			</div>
		</a>
		<?php
	}
	echo '</div>';
}
?>
<div class="jt-two-col">
		<main>
			<?php if ( empty( $categories ) && empty( $uncategorized_spaces ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'empty-posts', 80 ); ?></div>
					<div class="jt-empty-text"><?php esc_html_e( 'No categories yet. Check back soon!', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<?php foreach ( $categories as $category ) : ?>
					<?php $spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id ); ?>
					<section class="jt-mb-md">
						<div class="jt-cat-row">
							<?php if ( ! empty( $category->icon ) ) : ?>
								<span class="jt-cat-emoji"><?php echo esc_html( $category->icon ); ?></span>
							<?php endif; ?>
							<h2 class="jt-cat-name">
								<?php echo esc_html( $category->name ); ?>
							</h2>
							<?php if ( ! empty( $category->description ) ) : ?>
								<span class="jt-cat-desc">&mdash; <?php echo esc_html( $category->description ); ?></span>
							<?php endif; ?>
						</div>
						<?php jetonomy_render_space_grid( $spaces, $base ); ?>
					</section>
				<?php endforeach; ?>

				<?php if ( ! empty( $uncategorized_spaces ) ) : ?>
					<section class="jt-mb-md">
						<div class="jt-cat-row">
							<h2 class="jt-cat-name"><?php esc_html_e( 'Other Spaces', 'jetonomy' ); ?></h2>
						</div>
						<?php jetonomy_render_space_grid( $uncategorized_spaces, $base ); ?>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
