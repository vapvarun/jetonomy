<?php
defined( 'ABSPATH' ) || exit;

$category_slug = $data['slug'] ?? '';
$category      = \Jetonomy\Models\Category::find_by_slug( $category_slug );

if ( ! $category ) {
	status_header( 404 );
	echo '<div class="jt-container"><div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Category not found.', 'jetonomy' ) . '</div></div></div>';
	return;
}

$spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id );
$base   = home_url( '/community' );

$crumbs = [
	[ 'label' => $category->name, 'url' => '' ],
];
?>
<div class="jt-container">

	<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

	<div class="jt-two-col">
		<main>
			<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;">
				<?php if ( ! empty( $category->emoji ) ) : ?>
					<span style="font-size:28px;"><?php echo esc_html( $category->emoji ); ?></span>
				<?php endif; ?>
				<div>
					<h1 style="font-family:var(--jt-font-heading);font-size:22px;font-weight:700;margin:0;"><?php echo esc_html( $category->name ); ?></h1>
					<?php if ( ! empty( $category->description ) ) : ?>
						<p style="color:var(--jt-text-secondary);font-size:14px;margin-top:4px;"><?php echo esc_html( $category->description ); ?></p>
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
							class="jt-card jt-space-card"
							style="text-decoration:none;display:block;">
							<div style="display:flex;align-items:flex-start;gap:10px;">
								<?php if ( ! empty( $space->emoji ) ) : ?>
									<span style="font-size:24px;flex-shrink:0;"><?php echo esc_html( $space->emoji ); ?></span>
								<?php endif; ?>
								<div style="min-width:0;">
									<div style="font-weight:600;font-size:14px;color:var(--jt-text);"><?php echo esc_html( $space->title ); ?></div>
									<?php if ( ! empty( $space->description ) ) : ?>
										<div style="font-size:12px;color:var(--jt-text-tertiary);margin-top:3px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
											<?php echo esc_html( $space->description ); ?>
										</div>
									<?php endif; ?>
									<div style="display:flex;gap:12px;margin-top:8px;font-size:11px;color:var(--jt-text-tertiary);">
										<span><strong style="color:var(--jt-text-secondary);font-family:var(--jt-font-mono);"><?php echo (int) $space->post_count; ?></strong> <?php esc_html_e( 'posts', 'jetonomy' ); ?></span>
										<span><strong style="color:var(--jt-text-secondary);font-family:var(--jt-font-mono);"><?php echo (int) $space->member_count; ?></strong> <?php esc_html_e( 'members', 'jetonomy' ); ?></span>
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

</div>
