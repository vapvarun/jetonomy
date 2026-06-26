<?php
/**
 * Category view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$category_slug = $data['slug'] ?? '';
$category      = \Jetonomy\Models\Category::find_by_slug( $category_slug );

if ( ! $category ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Category not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id );
$base   = \Jetonomy\base_url();

$crumbs = [
	[
		'label' => $category->name,
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<div class="jt-cat-page-row">
				<?php if ( ! empty( $category->icon ) ) : ?>
					<?php jetonomy_render_space_icon( (string) $category->icon, 32, 'jt-cat-page-emoji' ); ?>
				<?php endif; ?>
				<div>
					<h1 class="jt-page-title"><?php echo esc_html( $category->name ); ?></h1>
					<?php if ( ! empty( $category->description ) ) : ?>
						<p class="jt-cat-page-desc"><?php echo esc_html( $category->description ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( empty( $spaces ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'      => 'empty-search',
						'icon_size' => 48,
						'message'   => __( 'No spaces in this category yet.', 'jetonomy' ),
					]
				);
				?>
			<?php else : ?>
				<div class="jt-space-grid">
					<?php foreach ( $spaces as $space ) : ?>
						<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
							class="jt-card jt-space-card jt-no-underline jt-block">
							<div class="jt-space-card-inner">
								<?php jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-emoji', $space->type ?? '' ); ?>
								<div class="jt-space-card-body">
									<div class="jt-space-card-title"><?php echo esc_html( $space->title ); ?></div>
									<div class="jt-space-card-badges">
										<?php jetonomy_render_space_meta_badges( $space ); ?>
										<?php if ( 'hidden' === ( $space->visibility ?? '' ) ) : ?>
											<span class="jt-space-card-badge jt-space-card-badge-hidden" aria-label="<?php esc_attr_e( 'Hidden space. Only admins and members can see this listing.', 'jetonomy' ); ?>">
												<?php jetonomy_echo_icon( 'lock', 12 ); ?>
												<?php esc_html_e( 'Hidden', 'jetonomy' ); ?>
											</span>
										<?php endif; ?>
									</div>
									<?php if ( ! empty( $space->description ) ) : ?>
										<div class="jt-space-card-excerpt">
											<?php echo esc_html( $space->description ); ?>
										</div>
									<?php endif; ?>
									<div class="jt-space-card-stats">
										<span class="jt-space-card-stat"><strong><?php echo esc_html( (int) $space->post_count ); ?></strong> <?php esc_html_e( 'posts', 'jetonomy' ); ?></span>
										<span class="jt-space-card-stat"><strong><?php echo esc_html( (int) $space->member_count ); ?></strong> <?php esc_html_e( 'members', 'jetonomy' ); ?></span>
										<?php
										$jt_activity = jetonomy_space_activity_label( $space );
										if ( '' !== $jt_activity ) :
											?>
											<span class="jt-space-card-stat jt-space-card-activity"><?php echo esc_html( $jt_activity ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							</div>
						</a>
						<?php
						/** Fires after each space card; mirror of the home grid hook. @since 1.5.0 @param object $space */
						do_action( 'jetonomy_space_card_after', $space );
						?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
