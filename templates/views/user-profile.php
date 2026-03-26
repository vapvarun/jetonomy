<?php
defined( 'ABSPATH' ) || exit;

$user_login = $data['slug'] ?? '';
$user       = get_user_by( 'login', $user_login );

if ( ! $user ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'User not found.', 'jetonomy' ) . '</div></div>';
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

$joined = $profile && $profile->created_at
	? date_i18n( get_option( 'date_format' ), strtotime( $profile->created_at ) )
	: date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered ) );

// Recent posts by this user (paginated).
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

global $wpdb;
$posts_tbl  = \Jetonomy\table( 'posts' );
$spaces_tbl = \Jetonomy\table( 'spaces' );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$recent_posts = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT p.*, sp.slug AS space_slug, sp.title AS space_title
		 FROM {$posts_tbl} p
		 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		 WHERE p.author_id = %d AND p.status = 'publish'
		 ORDER BY p.created_at DESC
		 LIMIT %d OFFSET %d",
		(int) $user->ID,
		$per_page,
		$offset
	)
) ?: [];

// Current tab.
$current_tab  = $data['tab'] ?? '';
$is_own       = is_user_logged_in() && get_current_user_id() === (int) $user->ID;

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
	[ 'label' => $user->display_name, 'url' => '' ],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<!-- Profile card -->
			<div class="jt-profile jt-mb-md">
				<div class="jt-profile-banner"></div>
				<div class="jt-profile-body">
					<span class="jt-avatar-wrap <?php echo \Jetonomy\Models\UserProfile::is_online( $profile_user_id ) ? 'is-online' : ''; ?>">
					<?php \Jetonomy\Template_Loader::partial( 'avatar', [ 'user_id' => $profile_user_id, 'size' => 64, 'class' => 'jt-profile-av' ] ); ?>
				</span>
					<div class="jt-flex jt-items-start jt-justify-between jt-w-full">
						<h1 class="jt-profile-name">
							<?php echo esc_html( $user->display_name ); ?>
							<span class="jt-tl jt-avatar-sm" data-jt-tl="<?php echo $trust; ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
							<span class="jt-level-tag"><?php echo esc_html( sprintf( __( 'Level %d', 'jetonomy' ), $trust ) ); ?></span>
						</h1>
						<?php if ( is_user_logged_in() && get_current_user_id() === $profile_user_id ) : ?>
							<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/edit/' ); ?>" class="jt-btn jt-btn-ghost jt-flex-shrink-0">
								<?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?>
							</a>
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
							<div class="jt-stat-n"><?php echo $rep; ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Reputation', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo $p_count; ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo $r_count; ?></div>
							<div class="jt-stat-l"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></div>
						</div>
						<div class="jt-stat">
							<div class="jt-stat-n"><?php echo $trust; ?></div>
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
			<div class="jt-profile-tabs">
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/' ); ?>" class="jt-profile-tab <?php echo empty( $current_tab ) ? 'active' : ''; ?>">
					<?php esc_html_e( 'Posts', 'jetonomy' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/replies/' ); ?>" class="jt-profile-tab <?php echo 'replies' === $current_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/votes/' ); ?>" class="jt-profile-tab <?php echo 'votes' === $current_tab ? 'active' : ''; ?>">
					<?php esc_html_e( 'Votes', 'jetonomy' ); ?>
				</a>
				<?php if ( $is_own ) : ?>
					<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/bookmarks/' ); ?>" class="jt-profile-tab <?php echo 'bookmarks' === $current_tab ? 'active' : ''; ?>">
						<?php esc_html_e( 'Bookmarks', 'jetonomy' ); ?>
					</a>
					<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/drafts/' ); ?>" class="jt-profile-tab <?php echo 'drafts' === $current_tab ? 'active' : ''; ?>">
						<?php esc_html_e( 'Drafts', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( 'replies' === $current_tab ) : ?>
				<?php if ( empty( $user_replies ) ) : ?>
					<div class="jt-empty-compact">
						<div class="jt-empty-text"><?php esc_html_e( 'No replies yet.', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_replies as $ur ) : ?>
							<?php
							$ur_url = $base . '/s/' . ( $ur->space_slug ?? '' ) . '/t/' . ( $ur->post_slug ?? '' ) . '/#reply-' . (int) $ur->id;
							$ur_ago = human_time_diff( strtotime( $ur->created_at ), current_time( 'timestamp', true ) );
							?>
							<div class="jt-row" onclick="window.location='<?php echo esc_url( $ur_url ); ?>'">
								<div class="jt-votes">
									<span class="jt-v-num"><?php echo (int) $ur->vote_score; ?></span>
								</div>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( wp_trim_words( wp_strip_all_tags( $ur->content ), 15 ) ); ?></div>
									<div class="jt-row-sub">
										<?php
										/* translators: %s: post title */
										echo esc_html( sprintf( __( 'on %s', 'jetonomy' ), $ur->post_title ?? '' ) );
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
					<div class="jt-empty-compact">
						<div class="jt-empty-text"><?php esc_html_e( 'No votes yet.', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_votes as $uv ) : ?>
							<?php
							$uv_url = $base . '/s/' . ( $uv->space_slug ?? '' ) . '/t/' . ( $uv->post_slug ?? '' ) . '/';
							$uv_ago = human_time_diff( strtotime( $uv->voted_at ), current_time( 'timestamp', true ) );
							?>
							<div class="jt-row" onclick="window.location='<?php echo esc_url( $uv_url ); ?>'">
								<div class="jt-votes">
									<span class="jt-v-num"><?php echo (int) $uv->vote_score; ?></span>
								</div>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( $uv->title ); ?></div>
									<div class="jt-row-sub">
										<?php echo esc_html( $uv->space_title ?? '' ); ?>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo (int) $uv->reply_count; ?></div>
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
				<div class="jt-empty-compact">
					<div class="jt-empty-text"><?php esc_html_e( 'No drafts yet. Save a post as draft and it will appear here.', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-topics">
					<?php foreach ( $user_drafts as $dr_post ) : ?>
						<?php
						$dr_ago      = human_time_diff( strtotime( $dr_post->created_at ), current_time( 'timestamp', true ) );
						$dr_edit_url = $base . '/s/' . ( $dr_post->space_slug ?? '' ) . '/new/';
						$is_scheduled = ! empty( $dr_post->published_at );
						?>
						<div class="jt-row jt-row--draft">
							<div class="jt-row-main">
								<div class="jt-row-title">
									<?php echo esc_html( $dr_post->title ); ?>
									<?php if ( $is_scheduled ) : ?>
										<span class="jt-badge jt-badge--scheduled">
											<?php
											$sched_date = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $dr_post->published_at ) );
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
										onclick="event.stopPropagation();">
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
							</div>
						</div>
					<?php endforeach; ?>
				</div>
				<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $user_drafts ) >= $per_page ] ); ?>
			<?php endif; ?>

		<?php elseif ( 'bookmarks' === $current_tab && $is_own ) : ?>
				<?php if ( empty( $bookmarks ) ) : ?>
					<div class="jt-empty-compact">
						<div class="jt-empty-text"><?php esc_html_e( 'No bookmarks yet. Bookmark posts to find them here later.', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $bookmarks as $bk_post ) : ?>
							<?php
							$bk_space = \Jetonomy\Models\Space::find( (int) $bk_post->space_id );
							$bk_url   = $base . '/s/' . ( $bk_space->slug ?? '' ) . '/t/' . $bk_post->slug . '/';
							$bk_ago   = human_time_diff( strtotime( $bk_post->bookmarked_at ), current_time( 'timestamp', true ) );
							?>
							<div class="jt-row" onclick="window.location='<?php echo esc_url( $bk_url ); ?>'">
								<div class="jt-votes">
									<span class="jt-v-num"><?php echo (int) $bk_post->vote_score; ?></span>
								</div>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( $bk_post->title ); ?></div>
									<div class="jt-row-sub">
										<?php echo esc_html( $bk_space->title ?? '' ); ?>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo (int) $bk_post->reply_count; ?></div>
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
					<div class="jt-empty-compact">
						<div class="jt-empty-text"><?php esc_html_e( 'No posts yet.', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $recent_posts as $r_post ) : ?>
							<?php
							$time_ago = human_time_diff( strtotime( $r_post->created_at ), current_time( 'timestamp', true ) );
							$post_url = $base . '/s/' . $r_post->space_slug . '/t/' . $r_post->slug . '/';
							?>
							<div class="jt-row" onclick="window.location='<?php echo esc_url( $post_url ); ?>'">
								<div class="jt-votes">
									<span class="jt-v-num"><?php echo (int) $r_post->vote_score; ?></span>
								</div>
								<div class="jt-row-main">
									<div class="jt-row-title"><?php echo esc_html( $r_post->title ); ?></div>
									<div class="jt-row-sub">
										<a href="<?php echo esc_url( $base . '/s/' . $r_post->space_slug . '/' ); ?>"
											onclick="event.stopPropagation();">
											<?php echo esc_html( $r_post->space_title ); ?>
										</a>
									</div>
								</div>
								<div class="jt-row-stat">
									<div class="jt-row-stat-n"><?php echo (int) $r_post->reply_count; ?></div>
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
