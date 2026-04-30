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
					<span class="jt-cat-page-emoji"><?php echo esc_html( $category->icon ); ?></span>
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
								<?php if ( ! empty( $space->icon ) ) : ?>
									<span class="jt-space-card-emoji"><?php echo esc_html( $space->icon ); ?></span>
								<?php endif; ?>
								<div class="jt-space-card-body">
									<div class="jt-space-card-title"><?php echo esc_html( $space->title ); ?></div>
									<?php if ( 'hidden' === ( $space->visibility ?? '' ) ) : ?>
										<span class="jt-space-card-badge jt-space-card-badge-hidden" aria-label="<?php esc_attr_e( 'Hidden space. Only admins and members can see this listing.', 'jetonomy' ); ?>">
											<?php jetonomy_echo_icon( 'lock', 12 ); ?>
											<?php esc_html_e( 'Hidden', 'jetonomy' ); ?>
										</span>
									<?php endif; ?>
									<?php if ( ! empty( $space->description ) ) : ?>
										<div class="jt-space-card-excerpt">
											<?php echo esc_html( $space->description ); ?>
										</div>
									<?php endif; ?>
									<div class="jt-space-card-stats">
										<span class="jt-space-card-stat"><strong><?php echo esc_html( (int) $space->post_count ); ?></strong> <?php esc_html_e( 'posts', 'jetonomy' ); ?></span>
										<span class="jt-space-card-stat"><strong><?php echo esc_html( (int) $space->member_count ); ?></strong> <?php esc_html_e( 'members', 'jetonomy' ); ?></span>
									</div>
								</div>
							</div>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
