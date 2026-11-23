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

// When BuddyNext is active, use its sidebar card skeleton so all sidebar
// widgets across BuddyNext, Jetonomy, and WPMediaVerse look identical.
$bn_active = did_action( 'buddynext_loaded' );
?>
<aside class="jt-sidebar">

	<?php
	/**
	 * Fires at the top of the Jetonomy sidebar, before any widgets render.
	 * Used by integrations (ads, announcements, banners) to inject content.
	 *
	 * @param object|null $space Current space object, or null outside a space.
	 */
	do_action( 'jetonomy_sidebar_before', $space ?? null );
	?>

	<?php if ( ! empty( $space ) && isset( $space->id ) ) : ?>
		<?php
		/**
		 * Insert a custom widget or ad before the About card. Fires in space scope.
		 * Use jetonomy_show_sidebar_about to show or hide the About card. Off
		 * by default on the space page itself, whose header already shows the
		 * description and counts.
		 *
		 * @param object $space Current space object.
		 */
		do_action( 'jetonomy_sidebar_before_about', $space );
		?>
		<?php
		/**
		 * Filters whether the sidebar renders the About card.
		 *
		 * @since 1.4.5
		 *
		 * @param bool        $show  Whether to render the card.
		 * @param object|null $space Current space, or null outside a space.
		 * @return bool Whether to render the card.
		 */
		?>
		<?php if ( apply_filters( 'jetonomy_show_sidebar_about', 'space' !== get_query_var( 'jetonomy_route' ), $space ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h2 class="jt-card-title"><?php esc_html_e( 'About', 'jetonomy' ); ?></h2>
			<?php else : ?>
				<?php esc_html_e( 'About', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php if ( ! empty( $space->description ) ) : ?>
				<p class="jt-sidebar-about" dir="auto"><?php echo esc_html( $space->description ); ?></p>
			<?php endif; ?>
			<div class="jt-sidebar-stats">
				<div class="jt-sidebar-stat">
					<strong><?php echo (int) ( $space->post_count ?? 0 ); ?></strong>
					<span><?php echo esc_html( \Jetonomy\count_noun( (int) ( $space->post_count ?? 0 ), 'topic' ) ); ?></span>
				</div>
				<div class="jt-sidebar-stat">
					<strong><?php echo (int) ( $space->member_count ?? 0 ); ?></strong>
					<span><?php echo esc_html( \Jetonomy\count_noun( (int) ( $space->member_count ?? 0 ), 'member' ) ); ?></span>
				</div>
			</div>
			<?php
			$space_type_labels = array(
				'forum'  => __( 'Forum', 'jetonomy' ),
				'qa'     => __( 'Q&A', 'jetonomy' ),
				'ideas'  => __( 'Ideas', 'jetonomy' ),
				'social' => __( 'Social', 'jetonomy' ),
			);
			$space_type        = $space->type ?? 'forum';
			?>
			<div class="jt-sidebar-meta">
				<span class="jt-tag"><?php echo esc_html( $space_type_labels[ $space_type ] ?? ucfirst( $space_type ) ); ?></span>
				<?php if ( 'public' !== ( $space->visibility ?? 'public' ) ) : ?>
					<span class="jt-tag"><?php echo esc_html( \Jetonomy\space_visibility_label( (string) $space->visibility ) ); ?></span>
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
				<div class="jt-sidebar-links">
					<a href="<?php echo esc_url( \Jetonomy\route_url( 'space-members', $space->slug ) ); ?>" class="jt-sidebar-link-text">
						<?php printf( /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ esc_html__( 'View all %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
					</a>
					<?php if ( \Jetonomy\Moderation\Moderation_Permissions::can_view_space_queue( get_current_user_id(), (int) $space->id ) ) : ?>
						<a href="<?php echo esc_url( \Jetonomy\route_url( 'space-moderation', $space->slug ) ); ?>" class="jt-sidebar-link-text jt-sidebar-link-mod">
							<?php jetonomy_echo_icon( 'shield', 14 ); ?>
							<?php esc_html_e( 'Moderation queue', 'jetonomy' ); ?>
						</a>
					<?php endif; ?>
					<?php
					// 1.4.0 G2: "Edit space" link for space admins. URL flips
					// from wp-admin to /community/s/:slug/edit/ once G5 ships
					// (filtered via jetonomy_use_frontend_space_edit).
					if ( \Jetonomy\Permissions\Permission_Engine::is_space_admin( get_current_user_id(), (int) $space->id ) ) :
						?>
						<a href="<?php echo esc_url( \Jetonomy\get_space_edit_url( $space ) ); ?>" class="jt-sidebar-link-text jt-sidebar-link-edit">
							<?php jetonomy_echo_icon( 'edit', 14 ); ?>
							<?php /* translators: %s: the label of the item being edited (the configured noun). */ ?>
							<?php echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

		<?php endif; // jetonomy_show_sidebar_about. ?>

		<?php
		/**
		 * Insert a custom widget or ad before the Managed-by card. Always fires
		 * (even when the section is hidden or empty). Use
		 * jetonomy_show_sidebar_managed_by to hide the section.
		 *
		 * @param object $space Current space object.
		 */
		do_action( 'jetonomy_sidebar_before_managed_by', $space );
		?>
		<?php
		/**
		 * Filters whether the sidebar renders the Managed by card.
		 *
		 * @since 1.4.5
		 *
		 * @param bool        $show  Whether to render the card.
		 * @param object|null $space Current space, or null outside a space.
		 * @return bool Whether to render the card.
		 */
		?>
		<?php if ( apply_filters( 'jetonomy_show_sidebar_managed_by', true, $space ) ) : ?>
			<?php
			/**
			 * Managed-by sidebar card (1.4.0 G1) — admins + moderators of the
			 * current space, ordered admins-first then by join time. Reads from
			 * the cached `SpaceMember::list_privileged` so a sidebar render
			 * costs one indexed query, then nothing for 60s. Same visibility as
			 * the About card above.
			 */
			if ( class_exists( '\\Jetonomy\\Models\\SpaceMember' ) && isset( $space->id ) ) {
				$members = \Jetonomy\Models\SpaceMember::list_privileged( (int) $space->id );
				include __DIR__ . '/managed-by-card.php';
				unset( $members );
			}
			?>
		<?php endif; // jetonomy_show_sidebar_managed_by. ?>
		<?php
		/**
		 * Insert a custom widget or ad after the Managed-by card. Always fires.
		 *
		 * @param object $space Current space object.
		 */
		do_action( 'jetonomy_sidebar_after_managed_by', $space );
		?>

		<?php
		/**
		 * Fires in the sidebar immediately after the "About" space card closes.
		 * Only fires when a space is present (i.e. on space-scoped pages).
		 * Ideal slot for ads, announcements, or CTAs pinned below the space intro.
		 *
		 * @param object $space Current space object.
		 */
		do_action( 'jetonomy_sidebar_after_about', $space );
		?>
	<?php endif; ?>

	<?php
	/*
	 * Trending, Top Members and Popular Tags, in the owner's order (Settings >
	 * Appearance, since 2.1.0). For each widget, in that order:
	 *
	 * - jetonomy_sidebar_before_{id} always fires, even when the widget is off,
	 *   hidden or empty, so an injected block can always render. {id} is
	 *   trending, top_members or popular_tags.
	 * - jetonomy_show_sidebar_{id} (bool $show, object|null $space, since 1.4.5)
	 *   can hide a widget the owner switched on. It is not called for one the
	 *   owner switched off; use the jetonomy_sidebar_widgets filter for that.
	 * - jetonomy_sidebar_after_{id} always fires.
	 *
	 * Each widget lives in partials/sidebar/{id with dashes}.php and fetches its
	 * own data, so a switched-off widget runs no query.
	 */
	// Literal names so a search for any of these hooks lands here: before, show, after.
	$jt_widget_hooks = array(
		'trending'     => array( 'jetonomy_sidebar_before_trending', 'jetonomy_show_sidebar_trending', 'jetonomy_sidebar_after_trending' ),
		'top_members'  => array( 'jetonomy_sidebar_before_top_members', 'jetonomy_show_sidebar_top_members', 'jetonomy_sidebar_after_top_members' ),
		'popular_tags' => array( 'jetonomy_sidebar_before_popular_tags', 'jetonomy_show_sidebar_popular_tags', 'jetonomy_sidebar_after_popular_tags' ),
	);
	foreach ( jetonomy_sidebar_widgets() as $jt_widget ) {
		list( $jt_before, $jt_show, $jt_after ) = $jt_widget_hooks[ $jt_widget['id'] ];
		do_action( $jt_before, $space ?? null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- literal names above.
		if ( $jt_widget['enabled'] && apply_filters( $jt_show, true, $space ?? null ) ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- literal names above.
			\Jetonomy\Template_Loader::partial(
				'sidebar/' . str_replace( '_', '-', $jt_widget['id'] ),
				array(
					'space'     => $space ?? null,
					'bn_active' => $bn_active,
				)
			);
		}
		do_action( $jt_after, $space ?? null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- literal names above.
	}
	unset( $jt_widget_hooks, $jt_widget, $jt_before, $jt_show, $jt_after );
	?>

	<?php
	if ( is_active_sidebar( 'jetonomy-sidebar' ) ) {
		dynamic_sidebar( 'jetonomy-sidebar' );
	}
	?>

	<?php
	/**
	 * Fires at the bottom of the Jetonomy sidebar, after all widgets render.
	 * Used by integrations (ads, announcements, banners) to inject content.
	 *
	 * @param object|null $space Current space object, or null outside a space.
	 */
	do_action( 'jetonomy_sidebar_after', $space ?? null );
	?>

</aside>
