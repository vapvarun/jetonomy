<?php
/**
 * Home view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
$categories = \Jetonomy\Models\Category::list_top_level();

/*
 * Bound the uncategorized grid. It rendered every uncategorized space with no
 * LIMIT, which on a site that never used categories is the entire directory on
 * one page.
 */
$jt_per_page = (int) apply_filters( 'jetonomy_spaces_per_page', 24 );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page number.
$jt_uncat_page        = max( 1, (int) ( $_GET['spg'] ?? 1 ) );
$jt_uncat_total       = \Jetonomy\Models\Space::count_uncategorized();
$uncategorized_spaces = \Jetonomy\Models\Space::list_uncategorized( null, $jt_per_page, ( $jt_uncat_page - 1 ) * $jt_per_page );
$jt_uncat_has_more    = ( $jt_uncat_page * $jt_per_page ) < $jt_uncat_total;
$base                 = \Jetonomy\base_url();

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
			/* translators: %s: plural space label. */
			: sprintf( __( 'Ask questions, share what you build, and join the discussion. Create a free account to post, vote, and follow the %s you care about.', 'jetonomy' ), \Jetonomy\space_label( true, true ) )
	);
	$jt_pulse = jetonomy_community_pulse();
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
<?php else : ?>
	<?php
	/*
	 * Members get orientation too, just a much lighter kind.
	 *
	 * The logged-out block above exists because the home was a wall of space
	 * cards with no orientation for a newcomer. Members had the same problem
	 * for a different reason: posting is the primary member action and there
	 * is no way to start one from here - 167 links on this page and not one
	 * of them opens a composer. Nothing said why, so the page read as a dead
	 * end rather than as a directory.
	 *
	 * Deliberately NOT a compose button. A global "New topic" has to answer
	 * "into which space?", and a picker on the primary landing page is a
	 * bigger change than the problem warrants (Basecamp 10227705351). Naming
	 * where posting happens turns the dead end into a signpost, which is the
	 * whole gap.
	 */
	$jt_member_hint = (string) apply_filters(
		'jetonomy_home_member_hint',
		sprintf(
			/* translators: 1: singular space label, 2: plural space label. */
			__( 'Open a %1$s to read or start a discussion - topics live inside %2$s, each with its own members and rules.', 'jetonomy' ),
			\Jetonomy\space_label( false, true ),
			\Jetonomy\space_label( true, true )
		)
	);
	if ( '' !== $jt_member_hint ) :
		?>
		<p class="jt-home-member-hint"><?php echo esc_html( $jt_member_hint ); ?></p>
		<?php
	endif;
	?>
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
				<?php
				// One grouped query (+ the WP4.4 tree cache) for every
				// category section instead of one query per category (WP3.9).
				$jt_spaces_by_cat = \Jetonomy\Models\Space::visible_by_category();
				?>
				<?php foreach ( $categories as $category ) : ?>
					<?php $spaces = $jt_spaces_by_cat[ (int) $category->id ] ?? []; ?>
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
								<?php // No hardcoded dash prefix: it dangled as a heading fragment when the duplicate category name above is suppressed (self-audit 2026-07-30), and customer copy carries no em-dashes. Separation comes from the header gap. ?>
								<span class="jt-cat-desc"><?php echo esc_html( $category->description ); ?></span>
							<?php endif; ?>
						</div>
						<?php jetonomy_render_space_grid( $spaces, $base ); ?>
					</section>
				<?php endforeach; ?>

				<?php if ( ! empty( $uncategorized_spaces ) ) : ?>
					<section class="jt-mb-md">
						<div class="jt-cat-row">
							<?php /* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */ ?>
							<h2 class="jt-cat-name"><?php echo esc_html( sprintf( __( 'Other %s', 'jetonomy' ), \Jetonomy\space_label( true ) ) ); ?></h2>
						</div>
						<?php jetonomy_render_space_grid( $uncategorized_spaces, $base ); ?>
						<?php
						\Jetonomy\Template_Loader::partial(
							'pagination',
							array(
								'has_more'  => $jt_uncat_has_more,
								'param_key' => 'spg',
								'target'    => '.jt-space-grid',
							)
						);
						?>
					</section>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
