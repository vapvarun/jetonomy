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
		echo '<p class="jt-cat-empty">' . esc_html( sprintf( __( 'No %s in this category yet.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ) . '</p>';
		return;
	}
	echo '<div class="jt-space-grid">';
	foreach ( $spaces as $space ) {
		?>
		<a href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>"
			class="jt-card jt-space-card jt-no-underline jt-block">
			<div class="jt-space-card-inner">
				<?php
				// Always route through the icon helper so a stored "message-circle"
				// renders as the Lucide SVG (not as the literal text). The helper
				// also defends against legacy emoji values and dashicon prefixes.
				jetonomy_render_space_icon( $space->icon ?? '', 24, 'jt-space-card-emoji', $space->type ?? '' );
				?>
				<div class="jt-space-card-body">
					<div class="jt-space-card-title">
						<?php echo esc_html( $space->title ); ?>
					</div>
					<div class="jt-space-card-badges">
						<?php jetonomy_render_space_meta_badges( $space ); ?>
						<?php if ( 'hidden' === ( $space->visibility ?? '' ) ) : ?>
							<span class="jt-space-card-badge jt-space-card-badge-hidden" aria-label="<?php echo esc_attr( sprintf( __( 'Hidden %s. Only admins and members can see this listing.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>">
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
						<span class="jt-space-card-stat">
							<strong><?php echo (int) $space->post_count; ?></strong>
							<?php echo esc_html( _n( 'post', 'posts', (int) $space->post_count, 'jetonomy' ) ); ?>
						</span>
						<span class="jt-space-card-stat">
							<strong><?php echo (int) $space->member_count; ?></strong>
							<?php echo esc_html( _n( 'member', 'members', (int) $space->member_count, 'jetonomy' ) ); ?>
						</span>
						<?php
						// Recency tells a newcomer the space is alive — totals alone
						// can't distinguish a dormant space from a thriving one.
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
		/**
		 * Fires after each space card in the community home grid.
		 *
		 * Append a custom badge, button, or note after a space card. Fires
		 * OUTSIDE the card's <a> wrapper so interactive markup (buttons,
		 * forms) is valid. The same hook fires from the category view.
		 *
		 * @since 1.5.0
		 *
		 * @param object $space The space being rendered.
		 */
		do_action( 'jetonomy_space_card_after', $space );
	}
	echo '</div>';
}
?>
<?php
$settings        = get_option( 'jetonomy_settings', array() );
$community_title = ! empty( $settings['community_title'] ) ? $settings['community_title'] : __( 'Community', 'jetonomy' );
?>
<h1 class="jt-page-title jt-home-title"><?php echo esc_html( $community_title ); ?></h1>
<?php
// Newcomer welcome — the home was a wall of space cards with no orientation
// for a first-time visitor (the top expectation-audit finding). Show a short
// value-prop + live community pulse + join CTA to logged-out visitors only;
// members don't need re-introducing. Copy is filterable so owners can set
// their own without touching the template.
if ( ! is_user_logged_in() ) :
	$jt_welcome_heading = (string) apply_filters(
		'jetonomy_home_welcome_heading',
		/* translators: %s: community title. */
		sprintf( __( 'Welcome to %s', 'jetonomy' ), $community_title )
	);
	$jt_welcome_sub = (string) apply_filters(
		'jetonomy_home_welcome_subheading',
		! empty( $settings['community_tagline'] )
			? (string) $settings['community_tagline']
			: sprintf( __( 'Ask questions, share what you build, and join the discussion. Create a free account to post, vote, and follow the %s you care about.', 'jetonomy' ), \Jetonomy\space_label( true, true ) )
	);
	$jt_pulse       = jetonomy_community_pulse();
	?>
	<section class="jt-home-welcome" aria-label="<?php esc_attr_e( 'Welcome', 'jetonomy' ); ?>">
		<div class="jt-home-welcome-body">
			<h2 class="jt-home-welcome-title"><?php echo esc_html( $jt_welcome_heading ); ?></h2>
			<p class="jt-home-welcome-sub"><?php echo esc_html( $jt_welcome_sub ); ?></p>
			<div class="jt-home-welcome-pulse">
				<span class="jt-pulse-stat"><strong><?php echo esc_html( number_format_i18n( $jt_pulse['members'] ) ); ?></strong> <?php echo esc_html( _n( 'member', 'members', $jt_pulse['members'], 'jetonomy' ) ); ?></span>
				<span class="jt-pulse-stat"><strong><?php echo esc_html( number_format_i18n( $jt_pulse['posts'] ) ); ?></strong> <?php echo esc_html( _n( 'post', 'posts', $jt_pulse['posts'], 'jetonomy' ) ); ?></span>
				<?php if ( $jt_pulse['posts_week'] > 0 ) : ?>
					<span class="jt-pulse-stat jt-pulse-stat--live"><strong><?php echo esc_html( number_format_i18n( $jt_pulse['posts_week'] ) ); ?></strong> <?php esc_html_e( 'this week', 'jetonomy' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<div class="jt-home-welcome-actions">
			<a class="jt-btn jt-btn-fill" href="<?php echo esc_url( wp_registration_url() ); ?>"><?php esc_html_e( 'Create free account', 'jetonomy' ); ?></a>
			<a class="jt-btn jt-btn-ghost" href="<?php echo esc_url( wp_login_url( \Jetonomy\base_url() . '/' ) ); ?>"><?php esc_html_e( 'Log in', 'jetonomy' ); ?></a>
		</div>
	</section>
<?php endif; ?>
<div class="jt-two-col">
		<main>
			<?php if ( empty( $categories ) && empty( $uncategorized_spaces ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					[
						'icon'    => 'empty-posts',
						'message' => __( 'No categories yet. Check back soon!', 'jetonomy' ),
					]
				);
				?>
			<?php else : ?>
				<?php foreach ( $categories as $category ) : ?>
					<?php $spaces = \Jetonomy\Models\Space::list_by_category( (int) $category->id ); ?>
					<section class="jt-mb-md">
						<div class="jt-cat-row">
							<?php if ( ! empty( $category->icon ) ) : ?>
								<?php jetonomy_render_space_icon( (string) $category->icon, 20, 'jt-cat-emoji' ); ?>
							<?php endif; ?>
							<?php
							// Suppress a redundant heading when a category is named the
							// same as the page title (e.g. a "Community" category under
							// the "Community" home title). The section still lists its
							// spaces; only the duplicate label is hidden.
							if ( 0 !== strcasecmp( trim( (string) $category->name ), trim( (string) $community_title ) ) ) :
								?>
								<h2 class="jt-cat-name">
									<?php echo esc_html( $category->name ); ?>
								</h2>
							<?php endif; ?>
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
							<h2 class="jt-cat-name"><?php echo esc_html( sprintf( __( 'Other %s', 'jetonomy' ), \Jetonomy\space_label( true ) ) ); ?></h2>
						</div>
						<?php jetonomy_render_space_grid( $uncategorized_spaces, $base ); ?>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
