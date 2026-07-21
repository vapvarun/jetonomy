<?php
/**
 * User profile view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$user_login = $data['slug'] ?? '';
$user       = get_user_by( 'login', $user_login );

if ( ! $user ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'User not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$profile         = \Jetonomy\Models\UserProfile::find_by_user( (int) $user->ID );
$trust           = $profile ? (int) $profile->trust_level : 0;
$rep             = $profile ? (int) $profile->reputation : 0;
$p_count         = $profile ? (int) $profile->post_count : 0;
$r_count         = $profile ? (int) $profile->reply_count : 0;
$profile_user_id = (int) $user->ID;
$base            = \Jetonomy\base_url();
$initials        = strtoupper( substr( $user->display_name, 0, 2 ) );

// Frontend member moderation (parity with the app + wp-admin, since a community
// moderator cannot reach wp-admin). Shows Ban / Silence / Lift on a member's
// profile to site moderators. The button is only rendered when the ban would
// actually be allowed - mirror the server's target guards (never self, never an
// admin, and - for a non-admin moderator - never another moderator). REST_Auth
// and ban_user() still enforce the real rules; this just avoids a dead control.
$jt_viewer_id        = get_current_user_id();
$jt_target_protected = user_can( $profile_user_id, 'manage_options' )
	|| ( user_can( $profile_user_id, 'jetonomy_moderate' ) && ! current_user_can( 'manage_options' ) );
$jt_can_moderate     = is_user_logged_in()
	&& $jt_viewer_id !== $profile_user_id
	&& \Jetonomy\Moderation\Moderation_Permissions::can_view_admin_dashboard( $jt_viewer_id )
	&& ! $jt_target_protected;

// The member's newest active restriction (id + type) for the status badge + the
// Lift control. Only queried for moderators.
$jt_restriction = null;
if ( $jt_can_moderate ) {
	$jt_rows        = \Jetonomy\Models\Restriction::list_active( array( 'user_id' => $profile_user_id, 'limit' => 1 ) );
	$jt_restriction = $jt_rows[0] ?? null;
}
$jt_restriction_label = '';
if ( $jt_restriction ) {
	$jt_restriction_label = 'silence' === $jt_restriction->type
		? __( 'Silenced', 'jetonomy' )
		: __( 'Banned', 'jetonomy' );
}

// Memoised slug -> Space lookup. The Posts/Replies/Votes tabs each need the full
// space object (for jetonomy_space_allows_voting) per row, but a user's activity
// spans only a handful of distinct spaces — caching by slug turns a per-row query
// (N+1 on a busy profile) into one load per distinct space.
$jt_profile_space_cache = array();
$jt_space_by_slug       = function ( $slug ) use ( &$jt_profile_space_cache ) {
	$slug = (string) $slug;
	if ( '' === $slug ) {
		return null;
	}
	if ( ! array_key_exists( $slug, $jt_profile_space_cache ) ) {
		$jt_profile_space_cache[ $slug ] = \Jetonomy\Models\Space::find_by_slug( $slug );
	}
	return $jt_profile_space_cache[ $slug ];
};

$wp_date_format = get_option( 'date_format' );
$wp_time_format = get_option( 'time_format' );

$joined = $profile && $profile->created_at
	? date_i18n( $wp_date_format, strtotime( $profile->created_at ) )
	: date_i18n( $wp_date_format, strtotime( $user->user_registered ) );

// Recent posts by this user (paginated).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

global $wpdb;
$posts_tbl  = \Jetonomy\table( 'posts' );
$spaces_tbl = \Jetonomy\table( 'spaces' );

// Space-visibility + per-post is_private gate: a non-member viewing another
// user's profile must not see that user's posts in private/hidden spaces, nor
// their private posts in public spaces. is_anonymous = 0 (query below) is an
// anonymity guard: an anonymous post must never surface on this profile's
// public post stream, even to the author viewing their own profile — that
// would deanonymize it by correlating it to this identity.
[ $jt_space_vis_sql, $jt_space_vis_params ] = \Jetonomy\Models\Space::content_visibility_sql( get_current_user_id(), 'sp' );
[ $jt_priv_sql, $jt_priv_params ]           = \Jetonomy\Search\Fulltext_Search::visibility_clause( null, 'p' );

$jt_gate_sql    = '';
$jt_gate_params = array();
if ( '1=1' !== $jt_space_vis_sql ) {
	$jt_gate_sql   .= ' AND ' . $jt_space_vis_sql;
	$jt_gate_params = array_merge( $jt_gate_params, $jt_space_vis_params );
}
if ( '' !== $jt_priv_sql ) {
	$jt_gate_sql   .= ' AND ' . $jt_priv_sql;
	$jt_gate_params = array_merge( $jt_gate_params, $jt_priv_params );
}

// Hide this profile's posts entirely when the VIEWER has blocked this user.
// no-op for guests/no-blocks.
[ $jt_block_sql ] = \Jetonomy\Models\BlockedUser::exclusion_sql( get_current_user_id(), 'p', 'author_id' );
if ( '' !== $jt_block_sql ) {
	$jt_gate_sql .= ' AND ' . $jt_block_sql;
}

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$recent_posts = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT p.*, sp.slug AS space_slug, sp.title AS space_title
		 FROM {$posts_tbl} p
		 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		 WHERE p.author_id = %d AND p.status = 'publish' AND p.is_anonymous = 0{$jt_gate_sql}
		 ORDER BY p.created_at DESC
		 LIMIT %d OFFSET %d",
		(int) $user->ID,
		...array_merge( $jt_gate_params, array( $per_page, $offset ) )
	)
) ?: [];

// Current tab.
$current_tab = $data['tab'] ?? '';
$is_own      = is_user_logged_in() && get_current_user_id() === (int) $user->ID;

// Bookmarks (only for own profile on bookmarks tab).
$bookmarks = [];
if ( 'bookmarks' === $current_tab && $is_own ) {
	$bookmarks = \Jetonomy\Models\Bookmark::list_by_user( (int) $user->ID, $per_page, $offset );
}

// Replies tab.
$user_replies = [];
if ( 'replies' === $current_tab ) {
	$user_replies = \Jetonomy\Models\Reply::list_by_user( (int) $user->ID, $per_page, $offset );
}

// Votes tab.
$user_votes = [];
if ( 'votes' === $current_tab ) {
	$user_votes = \Jetonomy\Models\Vote::list_by_user( (int) $user->ID, $per_page, $offset );
}

// Drafts tab — only for the profile owner.
$user_drafts = [];
if ( 'drafts' === $current_tab && $is_own ) {
	$user_drafts = \Jetonomy\Models\Post::list_drafts_by_user( (int) $user->ID, $per_page, $offset );
}

$crumbs = [
	[
		'label' => $user->display_name,
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<!-- Profile card -->
			<div class="jt-profile jt-mb-md">
				<div class="jt-profile-banner"></div>
				<div class="jt-profile-body">
					<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( $profile_user_id ) ? esc_attr( 'is-online' ) : ''; ?>">
					<?php
					\Jetonomy\Template_Loader::partial(
						'avatar',
						[
							'user_id' => $profile_user_id,
							'size'    => 64,
							'class'   => 'jt-profile-av',
						]
					);
					?>
				</span>
					<div class="jt-flex jt-items-start jt-justify-between jt-w-full">
						<h1 class="jt-profile-name">
							<?php echo esc_html( $user->display_name ); ?>
							<span class="jt-tl jt-avatar-sm" data-jt-tl="<?php echo esc_attr( (string) $trust ); ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo esc_html( (int) $trust ); ?></span>
							<span class="jt-level-tag"><?php echo esc_html( sprintf( __( 'Level %d', 'jetonomy' ), $trust ) ); ?></span>
						</h1>
						<?php if ( is_user_logged_in() && get_current_user_id() === $profile_user_id ) : ?>
							<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/edit/' ); ?>" class="jt-btn jt-btn-ghost jt-flex-shrink-0">
								<?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?>
							</a>
						<?php elseif ( is_user_logged_in() && \Jetonomy\messaging_active() ) : ?>
							<a href="<?php echo esc_url( $base . '/messages/?to=' . rawurlencode( $user->user_login ) ); ?>" class="jt-btn jt-btn-ghost jt-flex-shrink-0">
								<?php jetonomy_echo_icon( 'send', 14 ); ?>
								<?php esc_html_e( 'Message', 'jetonomy' ); ?>
							</a>
						<?php endif; ?>
						<?php if ( is_user_logged_in() && get_current_user_id() !== $profile_user_id ) : ?>
							<button class="jt-btn jt-btn-ghost jt-flex-shrink-0"
								data-wp-on--click="actions.flagUser"
								data-user-id="<?php echo absint( $profile_user_id ); ?>"
								title="<?php esc_attr_e( 'Report User', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'flag', 14 ); ?>
								<?php esc_html_e( 'Report', 'jetonomy' ); ?>
							</button>
						<?php endif; ?>

						<?php // Moderator member-moderation controls (frontend parity). ?>
						<?php if ( $jt_can_moderate && $jt_restriction ) : ?>
							<span class="jt-badge jt-badge-danger jt-flex-shrink-0"><?php echo esc_html( $jt_restriction_label ); ?></span>
							<button class="jt-btn jt-btn-ghost jt-flex-shrink-0"
								data-wp-on--click="actions.liftRestriction"
								data-restriction-id="<?php echo absint( $jt_restriction->id ); ?>"
								data-user-name="<?php echo esc_attr( $user->display_name ); ?>"
								title="<?php esc_attr_e( 'Lift this restriction', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'user-check', 14 ); ?>
								<?php esc_html_e( 'Lift', 'jetonomy' ); ?>
							</button>
						<?php elseif ( $jt_can_moderate ) : ?>
							<button class="jt-btn jt-btn-ghost jt-flex-shrink-0"
								data-wp-on--click="actions.restrictMember"
								data-user-id="<?php echo absint( $profile_user_id ); ?>"
								data-user-name="<?php echo esc_attr( $user->display_name ); ?>"
								data-restrict-type="silence"
								title="<?php esc_attr_e( 'Silence this member', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'hand', 14 ); ?>
								<?php esc_html_e( 'Silence', 'jetonomy' ); ?>
							</button>
							<button class="jt-btn jt-btn-danger jt-flex-shrink-0"
								data-wp-on--click="actions.restrictMember"
								data-user-id="<?php echo absint( $profile_user_id ); ?>"
								data-user-name="<?php echo esc_attr( $user->display_name ); ?>"
								data-restrict-type="global_ban"
								title="<?php esc_attr_e( 'Ban this member from the community', 'jetonomy' ); ?>">
								<?php jetonomy_echo_icon( 'x-circle', 14 ); ?>
								<?php esc_html_e( 'Ban', 'jetonomy' ); ?>
							</button>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $profile->bio ) ) : ?>
						<p class="jt-profile-bio">
							<?php echo esc_html( $profile->bio ); ?>
						</p>
					<?php endif; ?>

					<div class="jt-profile-meta">
						<span>
							<?php
							/* translators: %s: join date */
							echo esc_html( sprintf( __( 'Joined %s', 'jetonomy' ), $joined ) );
							?>
						</span>
						<?php if ( ! empty( $profile->website ) ) : ?>
							<a href="<?php echo esc_url( $profile->website ); ?>" rel="nofollow noopener" target="_blank">
								<?php echo esc_html( $profile->website ); ?>
							</a>
						<?php endif; ?>
					</div>

					<div class="jt-stats-bar">
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo esc_html( (int) $rep ); ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Reputation', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo esc_html( (int) $p_count ); ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo esc_html( (int) $r_count ); ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo esc_html( (int) $trust ); ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Trust', 'jetonomy' ); ?></div>
						</div>
					</div>

					<?php
					/**
					 * Fires after the user stats bar on the profile page.
					 * Pro hooks badge display here.
					 *
					 * @param int $profile_user_id The profile user's ID.
					 */
					do_action( 'jetonomy_profile_after_stats', $profile_user_id );
					?>

					<?php
					/**
					 * Fires after the stats bar to display custom profile field values.
					 * Pro hooks custom fields display here.
					 *
					 * @param int $profile_user_id The profile user's ID.
					 */
					do_action( 'jetonomy_profile_display_fields', $profile_user_id );
					?>
				</div>
			</div>

			<!-- Profile tabs -->
			<?php
			$jt_profile_url = $base . '/u/' . $user->user_login;

			// Built-in tabs as an ordered, filterable map. Each entry:
			// slug => [ 'label' => string, 'url' => string ]. The 'posts' tab is
			// the base profile URL and is the active tab when $current_tab is empty.
			$jt_profile_tabs = array(
				'posts'   => array(
					'label' => __( 'Posts', 'jetonomy' ),
					'url'   => $jt_profile_url . '/',
				),
				'replies' => array(
					'label' => __( 'Replies', 'jetonomy' ),
					'url'   => $jt_profile_url . '/replies/',
				),
				'votes'   => array(
					'label' => __( 'Votes', 'jetonomy' ),
					'url'   => $jt_profile_url . '/votes/',
				),
			);
			if ( $is_own ) {
				$jt_profile_tabs['bookmarks'] = array(
					'label' => __( 'Bookmarks', 'jetonomy' ),
					'url'   => $jt_profile_url . '/bookmarks/',
				);
				$jt_profile_tabs['drafts']    = array(
					'label' => __( 'Drafts', 'jetonomy' ),
					'url'   => $jt_profile_url . '/drafts/',
				);
			}

			/**
			 * Filters the user-profile tab bar.
			 *
			 * Add, remove, reorder, or relabel the tabs shown under a member's
			 * profile header. Each tab is `slug => [ 'label' => string, 'url' =>
			 * string ]`. A custom tab typically links to a route registered via
			 * the `jetonomy_template_map` filter (so it can render its own view),
			 * but the URL can point anywhere.
			 *
			 * @since 1.5.0
			 *
			 * @param array<string,array{label:string,url:string}> $jt_profile_tabs Ordered tab map.
			 * @param \WP_User $user    The profile owner.
			 * @param bool     $is_own  Whether the viewer is the profile owner.
			 */
			$jt_profile_tabs = apply_filters( 'jetonomy_profile_tabs', $jt_profile_tabs, $user, $is_own );
			?>
			<div class="jt-profile-tabs" data-wp-interactive="jetonomy" data-wp-init--active-tab="callbacks.initProfileTabsActive">
				<?php
				foreach ( (array) $jt_profile_tabs as $jt_tab_slug => $jt_tab ) :
					if ( empty( $jt_tab['label'] ) || ! isset( $jt_tab['url'] ) ) {
						continue;
					}
					$jt_tab_active = ( '' === (string) $current_tab && 'posts' === $jt_tab_slug )
						|| ( (string) $current_tab === (string) $jt_tab_slug );
					?>
					<a href="<?php echo esc_url( $jt_tab['url'] ); ?>" class="jt-profile-tab <?php echo $jt_tab_active ? 'active' : ''; ?>">
						<?php echo esc_html( $jt_tab['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>

			<?php if ( 'replies' === $current_tab ) : ?>
				<?php if ( empty( $user_replies ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => $is_own
								? __( 'You have not replied to anything yet — jump into a discussion and your replies will show here.', 'jetonomy' )
								: __( 'No replies yet.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_replies as $ur ) : ?>
							<?php
							$ur_url   = \Jetonomy\reply_permalink( (string) ( $ur->space_slug ?? '' ), (string) ( $ur->post_slug ?? '' ), (int) $ur->id );
							$ur_ago   = human_time_diff( strtotime( $ur->created_at ), time() );
							$ur_space = $jt_space_by_slug( $ur->space_slug ?? '' );
							?>
							<div class="jt-row jt-row-clickable" data-jt-href="<?php echo esc_url( $ur_url ); ?>">
								<?php if ( jetonomy_space_allows_voting( $ur_space ) ) : ?>
									<div class="jt-votes">
										<span class="jt-v-num"><?php echo esc_html( (int) $ur->vote_score ); ?></span>
									</div>
								<?php endif; ?>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $ur->content ), 15 ) ); ?></div>
									<div class="jt-row-sub">
										<?php
										// Feed-space parent posts store an empty title — fall
										// back to a short excerpt of the parent's body so the
										// "on …" label never reads "on " for that case.
										$jt_parent_label = jetonomy_post_title_or_excerpt(
											(object) array(
												'title'   => (string) ( $ur->post_title ?? '' ),
												'content' => (string) ( $ur->post_content_plain ?? '' ),
											)
										);
										/* translators: %s: post title */
										echo esc_html( sprintf( __( 'on %s', 'jetonomy' ), $jt_parent_label ) );
										?>
										&middot;
										<?php echo esc_html( $ur->space_title ?? '' ); ?>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-time">
										<?php
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $ur_ago ) );
										?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $user_replies ) >= $per_page ] ); ?>
				<?php endif; ?>

			<?php elseif ( 'votes' === $current_tab ) : ?>
				<?php if ( empty( $user_votes ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => $is_own
								? __( 'You have not voted yet — upvote posts and replies you find helpful and they will show here.', 'jetonomy' )
								: __( 'No votes yet.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_votes as $uv ) : ?>
							<?php
							$uv_url   = $base . '/s/' . ( $uv->space_slug ?? '' ) . '/t/' . ( $uv->post_slug ?? '' ) . '/';
							$uv_ago   = human_time_diff( strtotime( $uv->voted_at ), time() );
							$uv_space = $jt_space_by_slug( $uv->space_slug ?? '' );
							?>
							<div class="jt-row jt-row-clickable" data-jt-href="<?php echo esc_url( $uv_url ); ?>">
								<?php if ( jetonomy_space_allows_voting( $uv_space ) ) : ?>
									<div class="jt-votes">
										<span class="jt-v-num"><?php echo esc_html( (int) $uv->vote_score ); ?></span>
									</div>
								<?php endif; ?>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( jetonomy_post_title_or_excerpt( $uv ) ); ?></div>
									<div class="jt-row-sub">
										<?php echo esc_html( $uv->space_title ?? '' ); ?>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo esc_html( (int) $uv->reply_count ); ?></div>
									<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-time">
										<?php
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( 'voted %s ago', 'jetonomy' ), $uv_ago ) );
										?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $user_votes ) >= $per_page ] ); ?>
				<?php endif; ?>

			<?php elseif ( 'drafts' === $current_tab && $is_own ) : ?>
				<?php if ( empty( $user_drafts ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => __( 'No drafts yet. Save a post as draft and it will appear here.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
			<?php else : ?>
				<?php $datetime_format = $wp_date_format . ' ' . $wp_time_format; ?>
				<div class="jt-topics">
					<?php foreach ( $user_drafts as $dr_post ) : ?>
						<?php
						$dr_ago   = human_time_diff( strtotime( $dr_post->created_at ), time() );
						$dr_space = (string) ( $dr_post->space_slug ?? '' );
						$dr_slug  = (string) ( $dr_post->slug ?? '' );
						// Drafts and scheduled posts are viewable at the single-post
						// URL for their author; single-post.php serves them with the
						// Edit controls already attached (only renders a 404 to non-
						// authors). Route the row click there so the flow matches the
						// Posts/Replies/Votes tabs above — click a row, land on the
						// thing itself. Fall back to a non-clickable row if the draft
						// has no slug yet (edge case) rather than emit a broken link.
						$dr_url       = ( '' !== $dr_space && '' !== $dr_slug )
							? $base . '/s/' . $dr_space . '/t/' . $dr_slug . '/'
							: '';
						$is_scheduled = ! empty( $dr_post->published_at );
						$dr_row_class = 'jt-row jt-row--draft' . ( '' !== $dr_url ? ' jt-row-clickable' : '' );
						?>
						<div class="<?php echo esc_attr( $dr_row_class ); ?>"
							data-jt-post-id="<?php echo (int) $dr_post->id; ?>"
							<?php if ( '' !== $dr_url ) : ?>
								data-jt-href="<?php echo esc_url( $dr_url ); ?>"
							<?php endif; ?>>
							<div class="jt-row-main">
								<div class="jt-row-title">
									<?php echo esc_html( jetonomy_post_title_or_excerpt( $dr_post ) ); ?>
									<?php if ( $is_scheduled ) : ?>
										<span class="jt-badge jt-badge--scheduled">
											<?php
											// published_at is stored in UTC; render it in the
											// site timezone (Settings -> General). get_date_from_gmt()
											// is the canonical UTC-to-site-local formatter —
											// date_i18n( strtotime( $utc ) ) would print the raw
											// UTC clock time, showing the wrong scheduled time.
											$sched_date = get_date_from_gmt( $dr_post->published_at, $datetime_format );
											/* translators: %s: scheduled date/time */
											echo esc_html( sprintf( __( 'Scheduled: %s', 'jetonomy' ), $sched_date ) );
											?>
										</span>
									<?php else : ?>
										<span class="jt-badge jt-badge--draft"><?php esc_html_e( 'Draft', 'jetonomy' ); ?></span>
									<?php endif; ?>
								</div>
								<div class="jt-row-sub">
									<a href="<?php echo esc_url( $base . '/s/' . ( $dr_post->space_slug ?? '' ) . '/' ); ?>"
										>
										<?php echo esc_html( $dr_post->space_title ?? '' ); ?>
									</a>
								</div>
							</div>
							<div class="jt-row-stat">
								<div class="jt-row-time">
									<?php
									/* translators: %s: human-readable time difference */
									echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $dr_ago ) );
									?>
								</div>
								<button type="button"
									class="jt-btn jt-btn-fill jt-btn-sm jt-draft-publish"
									data-wp-on--click="actions.publishDraft">
									<?php esc_html_e( 'Publish now', 'jetonomy' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $user_drafts ) >= $per_page ] ); ?>
			<?php endif; ?>

		<?php elseif ( 'bookmarks' === $current_tab && $is_own ) : ?>
				<?php if ( empty( $bookmarks ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => __( 'No bookmarks yet. Bookmark posts to find them here later.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php
						// Batch-fetch all spaces for bookmarks to avoid N+1.
						$bk_space_ids = array_unique(
							array_filter(
								array_map(
									function ( $p ) {
										return (int) $p->space_id;
									},
									$bookmarks
								)
							)
						);
						$bk_spaces    = array();
						if ( $bk_space_ids ) {
							foreach ( $bk_space_ids as $sid ) {
								$bk_spaces[ $sid ] = \Jetonomy\Models\Space::find( $sid );
							}
						}
						?>
						<?php foreach ( $bookmarks as $bk_post ) : ?>
							<?php
							$bk_space = $bk_spaces[ (int) $bk_post->space_id ] ?? null;
							$bk_url   = $base . '/s/' . ( $bk_space->slug ?? '' ) . '/t/' . $bk_post->slug . '/';
							$bk_ago   = human_time_diff( strtotime( $bk_post->bookmarked_at ), time() );
							?>
							<div class="jt-row jt-row-clickable" data-jt-href="<?php echo esc_url( $bk_url ); ?>">
								<?php if ( jetonomy_space_allows_voting( $bk_space ) ) : ?>
									<div class="jt-votes">
										<span class="jt-v-num"><?php echo esc_html( (int) $bk_post->vote_score ); ?></span>
									</div>
								<?php endif; ?>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( jetonomy_post_title_or_excerpt( $bk_post ) ); ?></div>
									<div class="jt-row-sub">
										<?php echo esc_html( $bk_space->title ?? '' ); ?>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo esc_html( (int) $bk_post->reply_count ); ?></div>
									<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-time">
										<?php
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( 'saved %s ago', 'jetonomy' ), $bk_ago ) );
										?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $bookmarks ) >= $per_page ] ); ?>
				<?php endif; ?>
			<?php else : ?>
				<?php if ( empty( $recent_posts ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => __( 'No posts yet.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $recent_posts as $r_post ) : ?>
							<?php
							$time_ago = human_time_diff( strtotime( $r_post->created_at ), time() );
							$post_url = $base . '/s/' . $r_post->space_slug . '/t/' . $r_post->slug . '/';
							$r_space  = $jt_space_by_slug( $r_post->space_slug ?? '' );
							?>
							<div class="jt-row jt-row-clickable" data-jt-href="<?php echo esc_url( $post_url ); ?>">
								<?php if ( jetonomy_space_allows_voting( $r_space ) ) : ?>
									<div class="jt-votes">
										<span class="jt-v-num"><?php echo esc_html( (int) $r_post->vote_score ); ?></span>
									</div>
								<?php endif; ?>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( jetonomy_post_title_or_excerpt( $r_post ) ); ?></div>
									<div class="jt-row-sub">
										<a href="<?php echo esc_url( $base . '/s/' . $r_post->space_slug . '/' ); ?>"
											>
											<?php echo esc_html( $r_post->space_title ); ?>
										</a>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo esc_html( (int) $r_post->reply_count ); ?></div>
									<div class="jt-row-stat-l"><?php esc_html_e( 'replies', 'jetonomy' ); ?></div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-time">
										<?php
										/* translators: %s: human-readable time difference */
										echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
										?>
									</div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
					<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $recent_posts ) >= $per_page ] ); ?>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
