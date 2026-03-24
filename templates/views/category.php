<?php
defined( 'ABSPATH' ) || exit;

$category_slug = $data['slug'] ?? '';
$category      = \Jetonomy\Models\Category::find_by_slug( $category_slug );

if ( ! $category ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Category not found.', 'jetonomy' ) . '</div></div>';
	return;
}

$spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id );
$base   = \Jetonomy\base_url();

$crumbs = [
	[ 'label' => $category->name, 'url' => '' ],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<div class="jt-cat-page-row">
				<?php if ( ! empty( $category->emoji ) ) : ?>
					<span class="jt-cat-page-emoji"><?php echo esc_html( $category->emoji ); ?></span>
				<?php endif; ?>
				<div>
					<h1 class="jt-page-title"><?php echo esc_html( $category->name ); ?></h1>
					<?php if ( ! empty( $category->description ) ) : ?>
						<p class="jt-cat-page-desc"><?php echo esc_html( $category->description ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php if ( empty( $spaces ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon">&#128483;</div>
					<div class="jt-empty-text"><?php esc_html_e( 'No spaces in this category yet.', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-space-grid">
					<?php foreach ( $spaces as $space ) : ?>
						<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
							class="jt-card jt-space-card jt-no-underline jt-block">
							<div class="jt-space-card-inner">
								<?php if ( ! empty( $space->emoji ) ) : ?>
									<span class="jt-space-card-emoji"><?php echo esc_html( $space->emoji ); ?></span>
								<?php endif; ?>
								<div class="jt-space-card-body">
									<div class="jt-space-card-title"><?php echo esc_html( $space->title ); ?></div>
									<?php if ( ! empty( $space->description ) ) : ?>
										<div class="jt-space-card-excerpt">
											<?php echo esc_html( $space->description ); ?>
										</div>
									<?php endif; ?>
									<div class="jt-space-card-stats">
										<span class="jt-space-card-stat"><strong><?php echo (int) $space->post_count; ?></strong> <?php esc_html_e( 'posts', 'jetonomy' ); ?></span>
										<span class="jt-space-card-stat"><strong><?php echo (int) $space->member_count; ?></strong> <?php esc_html_e( 'members', 'jetonomy' ); ?></span>
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
