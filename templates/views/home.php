<?php
defined( 'ABSPATH' ) || exit;
$categories = \Jetonomy\Models\Category::list_top_level();
$base        = home_url( '/community' );
?>
<div class="jt-container">

	<div class="jt-two-col">
		<main>
			<?php if ( empty( $categories ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon">&#128483;</div>
					<div class="jt-empty-text"><?php esc_html_e( 'No categories yet. Check back soon!', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<?php foreach ( $categories as $category ) : ?>
					<?php $spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id ); ?>
					<section class="jt-mb-md">
						<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
							<?php if ( ! empty( $category->emoji ) ) : ?>
								<span style="font-size:20px;"><?php echo esc_html( $category->emoji ); ?></span>
							<?php endif; ?>
							<h2 style="font-family:var(--jt-font-heading);font-size:16px;font-weight:700;margin:0;">
								<?php echo esc_html( $category->name ); ?>
							</h2>
							<?php if ( ! empty( $category->description ) ) : ?>
								<span style="font-size:13px;color:var(--jt-text-tertiary);">&mdash; <?php echo esc_html( $category->description ); ?></span>
							<?php endif; ?>
						</div>

						<?php if ( empty( $spaces ) ) : ?>
							<p style="font-size:13px;color:var(--jt-text-tertiary);padding:8px 0;">
								<?php esc_html_e( 'No spaces in this category yet.', 'jetonomy' ); ?>
							</p>
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
												<div style="font-weight:600;font-size:14px;color:var(--jt-text);margin-bottom:3px;">
													<?php echo esc_html( $space->title ); ?>
												</div>
												<?php if ( ! empty( $space->description ) ) : ?>
													<div style="font-size:12px;color:var(--jt-text-tertiary);line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;">
														<?php echo esc_html( $space->description ); ?>
													</div>
												<?php endif; ?>
												<div style="display:flex;gap:12px;margin-top:8px;">
													<span style="font-size:11px;color:var(--jt-text-tertiary);">
														<strong style="font-family:var(--jt-font-mono);color:var(--jt-text-secondary);"><?php echo (int) $space->post_count; ?></strong>
														<?php esc_html_e( 'posts', 'jetonomy' ); ?>
													</span>
													<span style="font-size:11px;color:var(--jt-text-tertiary);">
														<strong style="font-family:var(--jt-font-mono);color:var(--jt-text-secondary);"><?php echo (int) $space->member_count; ?></strong>
														<?php esc_html_e( 'members', 'jetonomy' ); ?>
													</span>
												</div>
											</div>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>

</div>
