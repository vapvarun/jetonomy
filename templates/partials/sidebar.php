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

$trending = \Jetonomy\Models\Post::list_top_voted( ( ! empty( $space ) && isset( $space->id ) ) ? (int) $space->id : 0 );

// Top members by reputation. Deliberately NOT block-filtered — this is a
// ranking/leaderboard, not a content feed; per-viewer filtering would
// re-rank the board and leak "you blocked someone" via rank gaps.
//
// Cached 600s under ONE global key — zero viewer variance,
// runs on 100% of page views, and the bare ORDER BY reputation filesorts.
// TTL-only by design: a 10-minute-stale Top Members widget is invisible,
// and busting on every reputation write would defeat the point.
//
// Reads the leaderboard model, not its own query, so the widget ranks exactly
// the population the Leaderboard page does (members with reputation > 0).
// The key is not the legacy 'sidebar:leaders', so an upgraded site does not serve a
// stale unfiltered list for the rest of the TTL.
$leaders = \Jetonomy\Cache::remember(
	'sidebar:top-members',
	static fn() => \Jetonomy\Models\UserProfile::list_for_leaderboard( 'all', 5, 0 ),
	600
);

// Warm the profile cache for the Top Members widget in one query so the avatar
// partial below does not fire a per-row SELECT on a cold cache.
if ( ! empty( $leaders ) ) {
	\Jetonomy\Models\UserProfile::prime( array_map( static fn( $r ) => (int) $r->user_id, $leaders ) );
}

// Popular tags. Cached 900s under one global key — fully
// shared, ordered by post_count (indexed since 1.9.2), moves only on
// post publish. TTL-only: staleness is invisible on a tag cloud.
$popular_tags = \Jetonomy\Cache::remember(
	'tags:popular:15',
	static function () {
		return \Jetonomy\Models\Tag::list_popular( 15 );
	},
	900
);
// The shared list is ordered by the stored count, which includes topics this
// viewer cannot read. Show each tag's count as the tag page will (one grouped
// query), and drop tags with nothing visible so a name never hints at a
// private topic.
// Guests share one cached answer (the bulk of traffic); members vary by membership.
$jt_tag_ids    = wp_list_pluck( (array) $popular_tags, 'id' );
$jt_tag_counts = is_user_logged_in()
	? \Jetonomy\Models\Post::count_by_tags_visible( $jt_tag_ids )
	: \Jetonomy\Cache::remember( 'tags:popular:15:guest-counts', static fn() => \Jetonomy\Models\Post::count_by_tags_visible( $jt_tag_ids ), 900 );
$popular_tags  = array_values( array_filter( (array) $popular_tags, static fn( $t ) => ! empty( $jt_tag_counts[ (int) $t->id ] ) ) );

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
				<h4><?php esc_html_e( 'About', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'About', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php if ( ! empty( $space->description ) ) : ?>
				<p class="jt-sidebar-about"><?php echo esc_html( $space->description ); ?></p>
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
					<span class="jt-tag"><?php echo esc_html( ucfirst( $space->visibility ) ); ?></span>
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
						<?php printf( /* translators: %s: the plural label of the item (the configured noun). */ esc_html__( 'View all %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
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
	/**
	 * Insert a custom widget or ad before the Trending section. Always fires
	 * (even when the section is hidden or empty) so an injected block can
	 * always render. Use jetonomy_show_sidebar_trending to hide the section.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_before_trending', $space ?? null );
	?>
	<?php
	/**
	 * Filters whether the sidebar renders the Trending card.
	 *
	 * @since 1.4.5
	 *
	 * @param bool        $show  Whether to render the card.
	 * @param object|null $space Current space, or null outside a space.
	 * @return bool Whether to render the card.
	 */
	?>
	<?php if ( apply_filters( 'jetonomy_show_sidebar_trending', true, $space ?? null ) ) : ?>
		<?php if ( ! empty( $trending ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'Trending', 'jetonomy' ); ?></h4>
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
								<?php echo esc_html( jetonomy_post_title_or_excerpt( $t_post ) ); ?>
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
	<?php endif; ?>
	<?php
	/**
	 * Insert a custom widget or ad after the Trending section. Always fires.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_after_trending', $space ?? null );
	?>

	<?php
	/**
	 * Insert a custom widget or ad before the Top Members section. Always fires
	 * (even when the section is hidden or empty). Use
	 * jetonomy_show_sidebar_top_members to hide the section.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_before_top_members', $space ?? null );
	?>
	<?php
	/**
	 * Filters whether the sidebar renders the Top members card.
	 *
	 * @since 1.4.5
	 *
	 * @param bool        $show  Whether to render the card.
	 * @param object|null $space Current space, or null outside a space.
	 * @return bool Whether to render the card.
	 */
	?>
	<?php if ( apply_filters( 'jetonomy_show_sidebar_top_members', true, $space ?? null ) ) : ?>
		<?php if ( ! empty( $leaders ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card jt-mb-md' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php printf( /* translators: %s: plural member label. */ esc_html__( 'Top %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></h4>
			<?php else : ?>
				<?php printf( /* translators: %s: plural member label. */ esc_html__( 'Top %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true ) ) ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<?php foreach ( $leaders as $rank => $leader ) : ?>
				<?php
				$lu = get_userdata( (int) $leader->user_id );
				if ( ! $lu ) {
					continue;
				}
				?>
				<div class="jt-leader">
					<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( (int) $leader->user_id ) ? esc_attr( 'is-online' ) : ''; ?>">
						<?php
						// Real avatar (image with initials fallback) via the shared
						// partial, consistent with every other member surface.
						\Jetonomy\Template_Loader::partial(
							'avatar',
							array(
								'user_id' => (int) $leader->user_id,
								'size'    => 30,
								'class'   => 'jt-avatar-sm jt-flex-shrink-0',
							)
						);
						?>
					</span>
					<span class="jt-leader-name">
						<a href="<?php echo esc_url( \Jetonomy\get_profile_url( (int) $leader->user_id ) ); ?>">
							<?php echo esc_html( \Jetonomy\user_display_name( $lu ) ); ?>
						</a>
					</span>
					<span class="jt-leader-pts"><?php echo (int) $leader->reputation; ?></span>
				</div>
			<?php endforeach; ?>
			<div class="jt-sidebar-link">
				<a href="<?php echo esc_url( \Jetonomy\route_url( 'leaderboard' ) ); ?>"><?php esc_html_e( 'View full leaderboard', 'jetonomy' ); ?></a>
			</div>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>
	<?php
	/**
	 * Insert a custom widget or ad after the Top Members section. Always fires.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_after_top_members', $space ?? null );
	?>

	<?php
	/**
	 * Insert a custom widget or ad before the Popular Tags section. Always fires
	 * (even when the section is hidden or empty). Use
	 * jetonomy_show_sidebar_popular_tags to hide the section.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_before_popular_tags', $space ?? null );
	?>
	<?php
	/**
	 * Filters whether the sidebar renders the Popular tags card.
	 *
	 * @since 1.4.5
	 *
	 * @param bool        $show  Whether to render the card.
	 * @param object|null $space Current space, or null outside a space.
	 * @return bool Whether to render the card.
	 */
	?>
	<?php if ( apply_filters( 'jetonomy_show_sidebar_popular_tags', true, $space ?? null ) ) : ?>
		<?php if ( ! empty( $popular_tags ) ) : ?>
	<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card' : 'jt-card' ); ?>">
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__header' : '' ); ?>">
			<?php if ( ! $bn_active ) : ?>
				<h4><?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?></h4>
			<?php else : ?>
				<?php esc_html_e( 'Popular Tags', 'jetonomy' ); ?>
			<?php endif; ?>
		</div>
		<div class="<?php echo esc_attr( $bn_active ? 'bn-sidebar-card__body' : '' ); ?>">
			<div class="jt-tags">
				<?php foreach ( $popular_tags as $tag ) : ?>
					<a href="<?php echo esc_url( \Jetonomy\route_url( 'tag', $tag->slug ) ); ?>" class="jt-tag">
						<?php echo esc_html( $tag->name ); ?>
						<span class="jt-tag-count"><?php echo (int) $jt_tag_counts[ (int) $tag->id ]; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>
	<?php endif; ?>
	<?php
	/**
	 * Insert a custom widget or ad after the Popular Tags section. Always fires.
	 *
	 * @param object|null $space Current space object, or null.
	 */
	do_action( 'jetonomy_sidebar_after_popular_tags', $space ?? null );
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
