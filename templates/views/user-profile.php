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
						<?php elseif ( is_user_logged_in() && defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
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
			<div class="jt-profile-tabs" data-wp-interactive="jetonomy" data-wp-init--active-tab="callbacks.initProfileTabsActive">
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/' ); ?>" class="jt-profile-tab <?php echo esc_attr( empty( $current_tab ) ? 'active' : '' ); ?>">
					<?php esc_html_e( 'Posts', 'jetonomy' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/replies/' ); ?>" class="jt-profile-tab <?php echo esc_attr( 'replies' === $current_tab ? 'active' : '' ); ?>">
					<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
				</a>
				<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/votes/' ); ?>" class="jt-profile-tab <?php echo esc_attr( 'votes' === $current_tab ? 'active' : '' ); ?>">
					<?php esc_html_e( 'Votes', 'jetonomy' ); ?>
				</a>
				<?php if ( $is_own ) : ?>
					<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/bookmarks/' ); ?>" class="jt-profile-tab <?php echo esc_attr( 'bookmarks' === $current_tab ? 'active' : '' ); ?>">
						<?php esc_html_e( 'Bookmarks', 'jetonomy' ); ?>
					</a>
					<a href="<?php echo esc_url( $base . '/u/' . $user->user_login . '/drafts/' ); ?>" class="jt-profile-tab <?php echo esc_attr( 'drafts' === $current_tab ? 'active' : '' ); ?>">
						<?php esc_html_e( 'Drafts', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( 'replies' === $current_tab ) : ?>
				<?php if ( empty( $user_replies ) ) : ?>
					<?php
					\Jetonomy\Template_Loader::partial(
						'empty-state',
						[
							'message' => __( 'No replies yet.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_replies as $ur ) : ?>
							<?php
							$ur_url    = $base . '/s/' . ( $ur->space_slug ?? '' ) . '/t/' . ( $ur->post_slug ?? '' ) . '/#reply-' . (int) $ur->id;
							$ur_ago    = human_time_diff( strtotime( $ur->created_at ), time() );
							$ur_space  = ! empty( $ur->space_slug ) ? \Jetonomy\Models\Space::find_by_slug( $ur->space_slug ) : null;
							$ur_voting = jetonomy_space_allows_voting( $ur_space );
							$ur_class  = 'jt-row jt-row-clickable' . ( $ur_voting ? '' : ' jt-row--no-vote' );
							?>
							<div class="<?php echo esc_attr( $ur_class ); ?>" data-jt-href="<?php echo esc_url( $ur_url ); ?>">
								<?php if ( $ur_voting ) : ?>
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
							'message' => __( 'No votes yet.', 'jetonomy' ),
							'variant' => 'compact',
						]
					);
					?>
				<?php else : ?>
					<div class="jt-topics">
						<?php foreach ( $user_votes as $uv ) : ?>
							<?php
							$uv_url    = $base . '/s/' . ( $uv->space_slug ?? '' ) . '/t/' . ( $uv->post_slug ?? '' ) . '/';
							$uv_ago    = human_time_diff( strtotime( $uv->voted_at ), time() );
							$uv_space  = ! empty( $uv->space_slug ) ? \Jetonomy\Models\Space::find_by_slug( $uv->space_slug ) : null;
							$uv_voting = jetonomy_space_allows_voting( $uv_space );
							$uv_class  = 'jt-row jt-row-clickable' . ( $uv_voting ? '' : ' jt-row--no-vote' );
							?>
							<div class="<?php echo esc_attr( $uv_class ); ?>" data-jt-href="<?php echo esc_url( $uv_url ); ?>">
								<?php if ( $uv_voting ) : ?>
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
							<?php if ( '' !== $dr_url ) : ?>
								data-jt-href="<?php echo esc_url( $dr_url ); ?>"
							<?php endif; ?>>
							<div class="jt-row-main">
								<div class="jt-row-title">
									<?php echo esc_html( jetonomy_post_title_or_excerpt( $dr_post ) ); ?>
									<?php if ( $is_scheduled ) : ?>
										<span class="jt-badge jt-badge--scheduled">
											<?php
											$sched_date = date_i18n( $datetime_format, strtotime( $dr_post->published_at ) );
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
							$bk_space  = $bk_spaces[ (int) $bk_post->space_id ] ?? null;
							$bk_url    = $base . '/s/' . ( $bk_space->slug ?? '' ) . '/t/' . $bk_post->slug . '/';
							$bk_ago    = human_time_diff( strtotime( $bk_post->bookmarked_at ), time() );
							$bk_voting = jetonomy_space_allows_voting( $bk_space );
							$bk_class  = 'jt-row jt-row-clickable' . ( $bk_voting ? '' : ' jt-row--no-vote' );
							?>
							<div class="<?php echo esc_attr( $bk_class ); ?>" data-jt-href="<?php echo esc_url( $bk_url ); ?>">
								<?php if ( $bk_voting ) : ?>
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
							$r_space  = ! empty( $r_post->space_slug ) ? \Jetonomy\Models\Space::find_by_slug( $r_post->space_slug ) : null;
							$r_voting = jetonomy_space_allows_voting( $r_space );
							$r_class  = 'jt-row jt-row-clickable' . ( $r_voting ? '' : ' jt-row--no-vote' );
							?>
							<div class="<?php echo esc_attr( $r_class ); ?>" data-jt-href="<?php echo esc_url( $post_url ); ?>">
								<?php if ( $r_voting ) : ?>
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
