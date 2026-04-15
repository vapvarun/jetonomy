<?php
/**
 * Single post view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$post_slug = $data['slug'] ?? '';
$post      = \Jetonomy\Models\Post::find_by_slug( $post_slug );

if ( ! $post ) {
	status_header( 404 );
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- jetonomy_icon() returns trusted SVG
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'Post not found.', 'jetonomy' ) . '</div></div>';
	return;
}

if ( 'publish' !== $post->status ) {
	$viewer_id = get_current_user_id();
	$is_author = $viewer_id && (int) $post->author_id === $viewer_id;
	$is_mod    = current_user_can( 'jetonomy_moderate' ) || current_user_can( 'manage_options' );

	if ( ! $is_author && ! $is_mod ) {
		status_header( 404 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- jetonomy_icon() returns trusted SVG
		echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'Post not found.', 'jetonomy' ) . '</div></div>';
		return;
	}
}

$space = \Jetonomy\Models\Space::find( (int) $post->space_id );

if ( $space && in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $user_id ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="jt-empty"><p>' . esc_html__( 'This content is in a private space.', 'jetonomy' ) . '</p></div>';
			return;
		}
	}
}

$author   = get_userdata( (int) $post->author_id );
$profile  = \Jetonomy\Models\UserProfile::find_by_user( (int) $post->author_id );
$tags     = \Jetonomy\Models\Tag::list_for_post( (int) $post->id );
$category = ( $space && $space->category_id ) ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;

$author_id = (int) $post->author_id;
$trust     = $profile ? (int) $profile->trust_level : 0;
$time_ago  = human_time_diff( strtotime( $post->created_at ), time() );
$base      = \Jetonomy\base_url();
$post_url  = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';

// Resolve prefix color from space settings.
$prefix_name  = $post->prefix ?? null;
$prefix_color = null;
if ( $prefix_name && $space ) {
	$sp_settings_pf = \Jetonomy\Models\Space::get_settings( (int) $space->id );
	$prefix_list    = $sp_settings_pf['prefixes'] ?? array();
	foreach ( $prefix_list as $pfx ) {
		if ( ( $pfx['name'] ?? '' ) === $prefix_name ) {
			$prefix_color = $pfx['color'] ?? null;
			break;
		}
	}
}

// Replies sort.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_sort = isset( $_GET['rsort'] ) ? sanitize_key( $_GET['rsort'] ) : 'oldest';
if ( ! in_array( $reply_sort, [ 'oldest', 'newest', 'best' ], true ) ) {
	$reply_sort = 'oldest';
}
// Smart threaded loading: first 10 top-level + last 10 top-level, with gap in between.
$total_replies   = (int) $post->reply_count;
$top_level_count = \Jetonomy\Models\Reply::count_top_level( (int) $post->id );
$batch_size      = 10;

if ( $top_level_count <= $batch_size * 2 ) {
	// Small post — load all threaded.
	$reply_tree  = \Jetonomy\Models\Reply::get_threaded( (int) $post->id, $reply_sort );
	$first_batch = $reply_tree;
	$last_batch  = [];
	$gap_count   = 0;
} else {
	// Large post — split into first + gap + last.
	$first_batch = \Jetonomy\Models\Reply::get_threaded( (int) $post->id, 'oldest', $batch_size, 0 );
	$last_batch  = \Jetonomy\Models\Reply::get_threaded( (int) $post->id, 'oldest', $batch_size, $top_level_count - $batch_size );
	$gap_count   = $top_level_count - ( $batch_size * 2 );

	// Apply sort to split batches when not 'oldest'.
	if ( 'newest' === $reply_sort ) {
		$first_batch = array_reverse( $first_batch );
		$last_batch  = array_reverse( $last_batch );
	} elseif ( 'best' === $reply_sort ) {
		usort( $first_batch, fn( $a, $b ) => (int) $b->vote_score - (int) $a->vote_score );
		usort( $last_batch, fn( $a, $b ) => (int) $b->vote_score - (int) $a->vote_score );
	}
}

// Current user vote on post.
$user_id        = get_current_user_id();
$user_post_vote = $user_id ? \Jetonomy\Models\Vote::get_user_vote( $user_id, 'post', (int) $post->id ) : null;

// Breadcrumb.
$crumbs = [];
if ( $category ) {
	$crumbs[] = [
		'label' => $category->name,
		'url'   => '',
	];
}
if ( $space ) {
	$crumbs[] = [
		'label' => $space->title,
		'url'   => $base . '/s/' . $space->slug . '/',
	];
}
$crumbs[] = [
	'label' => $post->title,
	'url'   => '',
];

// Server-side state for IA.
$post_scores = [ (int) $post->id => (int) $post->vote_score ];
wp_interactivity_state(
	'jetonomy',
	[
		'currentPostId' => (int) $post->id,
		'postScores'    => $post_scores,
		'replyScores'   => [],
		'activeReply'   => 0,
		'submitting'    => false,
		'replyToId'     => null,
		'replyToAuthor' => '',
	]
);
?>
<?php
/**
 * Render a threaded reply recursively with depth-based nesting.
 *
 * @param object $reply Reply object with optional ->children and ->depth.
 * @param object $post  Parent post object.
 * @param int    $depth Current nesting depth (0 = top-level).
 */
function jetonomy_render_threaded_reply( $reply, $post, $depth = 0, $space = null ) {
	$depth         = isset( $reply->depth ) ? (int) $reply->depth : $depth;
	$wrapper_class = $depth > 0 ? 'jt-nested jt-nested-' . min( $depth, 3 ) : '';
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php
		\Jetonomy\Template_Loader::partial(
			'reply-card',
			[
				'reply' => $reply,
				'post'  => $post,
				'space' => $space,
			]
		);
		?>
		<?php if ( $depth === 0 && ! empty( $reply->children ) ) : ?>
			<div class="jt-thread-toggle" data-wp-interactive="jetonomy"
				data-wp-context='{"collapsed": false, "childCount": <?php echo (int) count( $reply->children ); ?>}'>
				<button class="jt-thread-toggle-btn" data-wp-on--click="actions.toggleThread"
					data-wp-text="context.collapsed ? '+ Show ' + context.childCount + ' replies' : '&minus; Hide replies'">
					&minus; Hide replies
				</button>
				<div class="jt-thread-children" data-wp-class--collapsed="context.collapsed">
					<?php foreach ( $reply->children as $child ) : ?>
						<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php elseif ( ! empty( $reply->children ) ) : ?>
			<?php foreach ( $reply->children as $child ) : ?>
				<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1, $space ); ?>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php
}
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<?php if ( 'publish' !== $post->status ) : ?>
				<div class="jt-notice jt-notice-warning">
					<?php
					if ( 'pending' === $post->status ) {
						esc_html_e( 'This post is pending review and not yet publicly visible.', 'jetonomy' );
					} elseif ( 'spam' === $post->status ) {
						esc_html_e( 'This post has been marked as spam.', 'jetonomy' );
					} else {
						/* translators: %s: post status */
						echo esc_html( sprintf( __( 'This post has status: %s', 'jetonomy' ), $post->status ) );
					}
					?>
				</div>
			<?php endif; ?>
			<!-- Post -->
			<article class="jt-post" data-wp-interactive="jetonomy">
				<div class="jt-post-head">
					<h1>
						<?php if ( $prefix_name ) : ?>
							<span class="jt-prefix" 
							<?php
							if ( $prefix_color ) :
								?>
								style="--jt-pfx:<?php echo esc_attr( $prefix_color ); ?>"<?php endif; ?>><?php echo esc_html( $prefix_name ); ?></span>
						<?php endif; ?>
						<?php echo esc_html( $post->title ); ?>
					</h1>
					<div class="jt-meta">
						<?php echo wp_kses_post( \Jetonomy\get_user_link( (int) $post->author_id, 'jt-avatar-md', 36, true ) ); ?>
						<span class="jt-tl" data-jt-tl="<?php echo esc_attr( (string) $trust ); ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo esc_html( (int) $trust ); ?></span>
						<span>
							<?php
							/* translators: %s: human-readable time difference */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
							?>
						</span>
						<?php if ( ! empty( $post->is_private ) ) : ?>
							<span class="jt-badge-private">
								<?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php esc_html_e( 'Private', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $post->is_resolved ) : ?>
							<span class="jt-badge-resolved">
								<?php jetonomy_echo_icon( 'check-circle', 14 ); ?> <?php esc_html_e( 'Resolved', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php if ( $post->is_closed ) : ?>
							<span class="jt-badge-closed">
								<?php esc_html_e( 'Closed', 'jetonomy' ); ?>
							</span>
						<?php endif; ?>
						<?php foreach ( $tags as $tag ) : ?>
							<a href="<?php echo esc_url( $base . '/tag/' . $tag->slug . '/' ); ?>" class="jt-tag">
								<?php echo esc_html( $tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
					<?php
					if ( is_user_logged_in() ) :
						$is_following = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'post', (int) $post->id );
						?>
						<button class="jt-btn jt-btn-sm <?php echo esc_attr( $is_following ? 'jt-btn-fill jt-following' : 'jt-btn-ghost' ); ?>"
							data-wp-on--click="actions.followPost"
							data-post-id="<?php echo absint( $post->id ); ?>"
							data-following="<?php echo esc_attr( $is_following ? '1' : '0' ); ?>">
							<?php echo $is_following ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<div class="jt-post-body">
					<?php
					// jetonomy_kses_embedded_content() is a wp_kses() wrapper with an extended iframe allowlist — safe to echo.
					echo jetonomy_kses_embedded_content( \Jetonomy\Embeds::process( jetonomy_format_content( wp_kses_post( $post->content ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</div>

				<?php
				/**
				 * Fires after the post body to display custom field values.
				 *
				 * @param object $post The Jetonomy post object.
				 */
				do_action( 'jetonomy_post_meta_fields', $post );
				?>

				<?php echo wp_kses_post( apply_filters( 'jetonomy_after_post_content', '', $post ) ); ?>

				<div class="jt-post-foot">
					<?php if ( is_user_logged_in() ) : ?>
					<button class="jt-act <?php echo 1 === $user_post_vote ? 'voted' : ''; ?>"
						data-wp-on--click="actions.voteUp"
						data-post-id="<?php echo absint( $post->id ); ?>"
						title="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>"
						aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">
						<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
						<span class="n" data-wp-text="state.postScores.<?php echo absint( $post->id ); ?>"><?php echo esc_html( (int) $post->vote_score ); ?></span>
					</button>
					<button class="jt-act <?php echo -1 === $user_post_vote ? 'voted' : ''; ?>"
						data-wp-on--click="actions.voteDown"
						data-post-id="<?php echo absint( $post->id ); ?>"
						title="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>"
						aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>">
						<?php jetonomy_echo_icon( 'chevron-down', 16 ); ?>
					</button>
					<?php else : ?>
					<span class="jt-act">
						<?php jetonomy_echo_icon( 'chevron-up', 16 ); ?>
						<span class="n"><?php echo esc_html( (int) $post->vote_score ); ?></span>
					</span>
					<?php endif; ?>
					<span class="jt-view-count" title="<?php
						/* translators: %d: number of views */
						echo esc_attr( sprintf( _n( '%d view', '%d views', (int) $post->view_count, 'jetonomy' ), (int) $post->view_count ) );
					?>" aria-label="<?php
						echo esc_attr( sprintf( _n( '%d view', '%d views', (int) $post->view_count, 'jetonomy' ), (int) $post->view_count ) );
					?>">
						<?php jetonomy_echo_icon( 'eye', 14 ); ?>
						<span class="n"><?php echo esc_html( (int) $post->view_count ); ?></span>
					</span>
				<button class="jt-act jt-share-btn"
					data-wp-on--click="actions.sharePost"
					data-post-url="<?php echo esc_url( \Jetonomy\base_url() . '/s/' . ( $space->slug ?? '' ) . '/t/' . $post->slug . '/' ); ?>"
					data-post-title="<?php echo esc_attr( $post->title ); ?>"
					title="<?php esc_attr_e( 'Share', 'jetonomy' ); ?>"
					aria-label="<?php esc_attr_e( 'Share', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'link', 16 ); ?></button>
				<?php
				if ( is_user_logged_in() ) :
					$is_bookmarked = \Jetonomy\Models\Bookmark::is_bookmarked( get_current_user_id(), (int) $post->id );
					?>
					<button class="jt-act jt-bookmark-btn <?php echo $is_bookmarked ? esc_attr( 'bookmarked' ) : ''; ?>"
						data-wp-on--click="actions.toggleBookmark"
						data-post-id="<?php echo absint( $post->id ); ?>"
						data-bookmarked="<?php echo esc_attr( $is_bookmarked ? '1' : '0' ); ?>"
						title="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"
						aria-label="<?php echo $is_bookmarked ? esc_attr__( 'Remove bookmark', 'jetonomy' ) : esc_attr__( 'Bookmark', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'bookmark', 16 ); ?></button>
					<?php if ( (int) $post->author_id !== get_current_user_id() ) : ?>
						<button class="jt-act"
							data-wp-on--click="actions.flagPost"
							data-post-id="<?php echo absint( $post->id ); ?>"
							title="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"
							aria-label="<?php esc_attr_e( 'Report', 'jetonomy' ); ?>"><?php jetonomy_echo_icon( 'flag', 16 ); ?></button>
					<?php endif; ?>
				<?php endif; ?>
				<?php if ( current_user_can( 'jetonomy_moderate' ) || (int) $post->author_id === get_current_user_id() ) : ?>
					<div class="jt-more-menu">
						<button class="jt-act jt-more-trigger" type="button"
							title="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							aria-label="<?php esc_attr_e( 'More options', 'jetonomy' ); ?>"
							data-wp-on--click="actions.toggleMoreMenu"><?php jetonomy_echo_icon( 'more-horizontal', 16 ); ?></button>
						<div class="jt-more-dropdown" hidden>
							<?php if ( (int) $post->author_id === get_current_user_id() || current_user_can( 'jetonomy_moderate' ) ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.editPost"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'edit', 14 ); ?> <?php esc_html_e( 'Edit', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.togglePrivate"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-private="<?php echo esc_attr( ! empty( $post->is_private ) ? '1' : '0' ); ?>"><?php jetonomy_echo_icon( 'lock', 14 ); ?> <?php echo ! empty( $post->is_private ) ? esc_html__( 'Make Public', 'jetonomy' ) : esc_html__( 'Make Private', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( current_user_can( 'jetonomy_moderate' ) ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.pinPost"
									data-post-id="<?php echo absint( $post->id ); ?>"><?php jetonomy_echo_icon( 'pin', 16 ); ?> <?php echo $post->is_sticky ? esc_html__( 'Unpin', 'jetonomy' ) : esc_html__( 'Pin', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( current_user_can( 'jetonomy_moderate' ) ) : ?>
								<button class="jt-more-item"
									data-wp-on--click="actions.movePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'move', 14 ); ?> <?php esc_html_e( 'Move', 'jetonomy' ); ?></button>
								<button class="jt-more-item"
									data-wp-on--click="actions.mergePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-id="<?php echo absint( $post->space_id ); ?>"><?php jetonomy_echo_icon( 'merge', 14 ); ?> <?php esc_html_e( 'Merge', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( (int) $post->author_id === get_current_user_id() || current_user_can( 'jetonomy_moderate' ) ) : ?>
								<button class="jt-more-item jt-more-item--danger"
									data-wp-on--click="actions.deletePost"
									data-post-id="<?php echo absint( $post->id ); ?>"
									data-space-slug="<?php echo esc_attr( $space->slug ?? '' ); ?>"><?php jetonomy_echo_icon( 'trash', 16 ); ?> <?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
							<?php endif; ?>
							<?php if ( current_user_can( 'jetonomy_moderate' ) ) : ?>
								<a class="jt-more-item" href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $post->space_id ) ); ?>"><?php jetonomy_echo_icon( 'settings', 14 ); ?> <?php esc_html_e( 'Admin', 'jetonomy' ); ?></a>
							<?php endif; ?>
						</div>
					</div>
				<?php endif; ?>
				<?php do_action( 'jetonomy_post_actions', $post ); ?>
				</div>
			</article>

			<?php
			/**
			 * Fires after the main post article element, before the replies section.
			 * Prime slot for ads, related posts, or CTAs between topic and replies.
			 *
			 * Note: Named `_article` (not `_content`) to avoid collision with the
			 * existing `jetonomy_after_post_content` FILTER that injects HTML inside
			 * the post body (see single-post.php:269).
			 *
			 * @param object $post Current post object.
			 */
			do_action( 'jetonomy_after_post_article', $post );
			?>

			<!-- Replies -->
			<div class="jt-replies-section" id="replies"
				data-wp-interactive="jetonomy"
				data-wp-init--infinite="callbacks.initInfiniteScroll"
				data-wp-init--polling="callbacks.initReplyPolling"
				data-wp-context='
				<?php
				echo wp_json_encode(
					[
						'postId'        => (int) $post->id,
						'totalReplies'  => $total_replies,
						'topLevelCount' => $top_level_count,
						'sort'          => $reply_sort,
						'hasMore'       => false,
						'loadingMore'   => false,
					]
				);
				?>
				'>

				<div class="jt-replies-head">
					<h3>
						<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
						<span class="jt-count-pill"><?php echo esc_html( (int) $total_replies ); ?></span>
					</h3>
					<div class="jt-replies-controls">
						<div class="jt-pills">
							<?php
							$reply_sorts = [
								'oldest' => __( 'Oldest', 'jetonomy' ),
								'newest' => __( 'Newest', 'jetonomy' ),
								'best'   => __( 'Best', 'jetonomy' ),
							];
							foreach ( $reply_sorts as $key => $label ) :
								$rsort_url = add_query_arg( [ 'rsort' => $key ], $post_url );
								?>
								<a href="<?php echo esc_url( $rsort_url ); ?>#replies"
									class="jt-pill <?php echo $reply_sort === $key ? esc_attr( 'on' ) : ''; ?>">
									<?php echo esc_html( $label ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<?php
				/**
				 * Fires before the replies list renders (above both empty state and populated list).
				 * Ad slot / announcement injection point above replies.
				 *
				 * @param object $post         Current post object.
				 * @param int    $total_replies Total reply count.
				 */
				do_action( 'jetonomy_before_replies', $post, $total_replies );
				?>

				<?php if ( empty( $first_batch ) && empty( $last_batch ) ) : ?>
					<div class="jt-empty">
						<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'empty-replies', 80 ); ?></div>
						<div class="jt-empty-text"><?php esc_html_e( 'No replies yet. Be the first to reply!', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>

					<div class="jt-replies-list" id="jt-replies-container">
						<!-- First batch (opening conversation) -->
						<?php foreach ( $first_batch as $index => $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post, 0, $space ); ?>
							<?php
							/**
							 * Fires after each top-level reply in the replies list.
							 * Ad slot / injection between replies (e.g. every Nth reply).
							 *
							 * @param object $reply Reply object just rendered.
							 * @param int    $index Zero-based index within the batch.
							 * @param object $post  Current post object.
							 */
							do_action( 'jetonomy_between_replies', $reply, $index, $post );
							?>
						<?php endforeach; ?>

						<!-- Gap loader (in-between) -->
						<?php if ( $gap_count > 0 ) : ?>
							<div class="jt-load-gap" data-wp-interactive="jetonomy"
								data-wp-context='
								<?php
								echo wp_json_encode(
									[
										'postId'   => (int) $post->id,
										'gapStart' => $batch_size,
										'gapCount' => $gap_count,
										'loading'  => false,
									]
								);
								?>
								'>
								<button class="jt-btn jt-btn-ghost jt-load-gap-btn"
									data-wp-on--click="actions.loadGapReplies"
									data-wp-bind--disabled="context.loading">
									<span data-wp-text="context.loading ? '<?php echo esc_js( __( 'Loading…', 'jetonomy' ) ); ?>' : '<?php echo esc_js( sprintf( /* translators: %d: number of hidden replies */ __( 'Show %d more replies', 'jetonomy' ), $gap_count ) ); ?>'">
										<?php
										/* translators: %d: number of hidden replies */
										printf( esc_html__( 'Show %d more replies', 'jetonomy' ), absint( $gap_count ) );
										?>
									</span>
								</button>
							</div>
						<?php endif; ?>

						<!-- Last batch (latest conversation) -->
						<?php foreach ( $last_batch as $index => $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post, 0, $space ); ?>
							<?php
							/** @see hook docblock above on jetonomy_between_replies */
							do_action( 'jetonomy_between_replies', $reply, $index, $post );
							?>
						<?php endforeach; ?>
					</div>

				<?php endif; ?>

				<?php
				/**
				 * Fires after the replies list renders.
				 * Ad slot / CTA injection below replies, above the composer.
				 *
				 * @param object $post          Current post object.
				 * @param int    $total_replies Total reply count.
				 */
				do_action( 'jetonomy_after_replies', $post, $total_replies );
				?>
			</div>

			<!-- Composer -->
			<?php if ( $post->is_closed ) : ?>
				<div class="jt-closed-notice">
					<?php esc_html_e( 'This post is closed and no longer accepts replies.', 'jetonomy' ); ?>
				</div>
			<?php elseif ( is_user_logged_in() ) : ?>
				<div class="jt-reply-composer" id="jt-composer">
					<h4>
						<?php esc_html_e( 'Your Reply', 'jetonomy' ); ?>
					</h4>
					<?php
					\Jetonomy\Template_Loader::partial(
						'composer',
						[
							'post_id'  => (int) $post->id,
							'post_url' => $post_url,
						]
					);
					?>
				</div>
			<?php else : ?>
				<div class="jt-login-prompt">
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>"><?php esc_html_e( 'Log in to reply', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
