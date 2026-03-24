<?php
defined( 'ABSPATH' ) || exit;

$post_slug = $data['slug'] ?? '';
$post      = \Jetonomy\Models\Post::find_by_slug( $post_slug );

if ( ! $post ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Post not found.', 'jetonomy' ) . '</div></div>';
	return;
}

if ( 'publish' !== $post->status ) {
	$viewer_id = get_current_user_id();
	$is_author = $viewer_id && (int) $post->author_id === $viewer_id;
	$is_mod    = current_user_can( 'jetonomy_moderate' ) || current_user_can( 'manage_options' );

	if ( ! $is_author && ! $is_mod ) {
		status_header( 404 );
		echo '<div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Post not found.', 'jetonomy' ) . '</div></div>';
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
$trust    = $profile ? (int) $profile->trust_level : 0;
$time_ago = human_time_diff( strtotime( $post->created_at ), current_time( 'timestamp', true ) );
$base     = home_url( '/community' );
$post_url = $base . '/s/' . ( $space ? $space->slug : '' ) . '/t/' . $post->slug . '/';

// Replies sort.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$reply_sort = isset( $_GET['rsort'] ) ? sanitize_key( $_GET['rsort'] ) : 'oldest';
if ( ! in_array( $reply_sort, [ 'oldest', 'newest', 'best' ], true ) ) {
	$reply_sort = 'oldest';
}
// Smart threaded loading: first 10 top-level + last 10 top-level, with gap in between.
$total_replies     = (int) $post->reply_count;
$top_level_count   = \Jetonomy\Models\Reply::count_top_level( (int) $post->id );
$batch_size        = 10;

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
	$crumbs[] = [ 'label' => $category->name, 'url' => '' ];
}
if ( $space ) {
	$crumbs[] = [ 'label' => $space->title, 'url' => $base . '/s/' . $space->slug . '/' ];
}
$crumbs[] = [ 'label' => $post->title, 'url' => '' ];

// Server-side state for IA.
$post_scores = [ (int) $post->id => (int) $post->vote_score ];
wp_interactivity_state(
	'jetonomy',
	[
		'currentPostId'  => (int) $post->id,
		'postScores'     => $post_scores,
		'replyScores'    => [],
		'activeReply'    => 0,
		'submitting'     => false,
		'replyToId'      => null,
		'replyToAuthor'  => '',
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
function jetonomy_render_threaded_reply( $reply, $post, $depth = 0 ) {
	$depth         = isset( $reply->depth ) ? (int) $reply->depth : $depth;
	$wrapper_class = $depth > 0 ? 'jt-nested jt-nested-' . min( $depth, 3 ) : '';
	?>
	<div class="<?php echo esc_attr( $wrapper_class ); ?>">
		<?php \Jetonomy\Template_Loader::partial( 'reply-card', [ 'reply' => $reply, 'post' => $post ] ); ?>
		<?php if ( $depth === 0 && ! empty( $reply->children ) ) : ?>
			<div class="jt-thread-toggle" data-wp-interactive="jetonomy"
				data-wp-context='{"collapsed": false, "childCount": <?php echo count( $reply->children ); ?>}'>
				<button class="jt-thread-toggle-btn" data-wp-on--click="actions.toggleThread"
					data-wp-text="context.collapsed ? '+ Show ' + context.childCount + ' replies' : '&minus; Hide replies'">
					&minus; Hide replies
				</button>
				<div class="jt-thread-children" data-wp-class--collapsed="context.collapsed">
					<?php foreach ( $reply->children as $child ) : ?>
						<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1 ); ?>
					<?php endforeach; ?>
				</div>
			</div>
		<?php elseif ( ! empty( $reply->children ) ) : ?>
			<?php foreach ( $reply->children as $child ) : ?>
				<?php jetonomy_render_threaded_reply( $child, $post, $depth + 1 ); ?>
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
					<h1><?php echo esc_html( $post->title ); ?></h1>
					<div class="jt-meta">
						<?php echo \Jetonomy\get_user_link( (int) $post->author_id, 'jt-avatar-md', 36, true ); ?>
						<span class="jt-tl" data-jt-tl="<?php echo $trust; ?>" title="<?php echo esc_attr( sprintf( __( 'Trust Level %d', 'jetonomy' ), $trust ) ); ?>"><?php echo $trust; ?></span>
						<span>
							<?php
							/* translators: %s: human-readable time difference */
							echo esc_html( sprintf( __( '%s ago', 'jetonomy' ), $time_ago ) );
							?>
						</span>
						<?php if ( $post->is_resolved ) : ?>
							<span class="jt-badge-resolved">
								&#10003; <?php esc_html_e( 'Resolved', 'jetonomy' ); ?>
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
				</div>

				<div class="jt-post-body">
					<?php echo \Jetonomy\Embeds::process( wp_kses_post( $post->content ) ); ?>
				</div>

				<?php
				/**
				 * Fires after the post body to display custom field values.
				 *
				 * @param object $post The Jetonomy post object.
				 */
				do_action( 'jetonomy_post_meta_fields', $post );
				?>

				<?php echo apply_filters( 'jetonomy_after_post_content', '', $post ); ?>

				<div class="jt-post-foot">
					<button class="jt-act <?php echo 1 === $user_post_vote ? 'voted' : ''; ?>"
						data-wp-on--click="actions.voteUp"
						data-post-id="<?php echo (int) $post->id; ?>"
						aria-label="<?php esc_attr_e( 'Vote up', 'jetonomy' ); ?>">
						&#9650;
						<span class="n" data-wp-text="state.postScores.<?php echo (int) $post->id; ?>"><?php echo (int) $post->vote_score; ?></span>
					</button>
					<button class="jt-act <?php echo -1 === $user_post_vote ? 'voted' : ''; ?>"
						data-wp-on--click="actions.voteDown"
						data-post-id="<?php echo (int) $post->id; ?>"
						aria-label="<?php esc_attr_e( 'Vote down', 'jetonomy' ); ?>">
						&#9660;
					</button>
					<span class="jt-view-count">
						<?php
						/* translators: %d: number of views */
						echo esc_html( sprintf( _n( '%d view', '%d views', (int) $post->view_count, 'jetonomy' ), (int) $post->view_count ) );
						?>
					</span>
				<?php if ( current_user_can( 'jetonomy_moderate' ) || (int) $post->author_id === get_current_user_id() ) : ?>
					<span class="jt-actions-group">
						<?php if ( current_user_can( 'jetonomy_moderate' ) ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $post->space_id ) ); ?>" class="jt-act" title="<?php esc_attr_e( 'Admin', 'jetonomy' ); ?>">&#9881;</a>
						<?php endif; ?>
					</span>
				<?php endif; ?>
				<?php do_action( 'jetonomy_post_actions', $post ); ?>
				</div>
			</article>

			<!-- Replies -->
			<div class="jt-replies-section" id="replies"
				data-wp-interactive="jetonomy"
				data-wp-init--infinite="callbacks.initInfiniteScroll"
				data-wp-init--polling="callbacks.initReplyPolling"
				data-wp-context='<?php echo wp_json_encode( [
					'postId'           => (int) $post->id,
					'totalReplies'     => $total_replies,
					'topLevelCount'    => $top_level_count,
					'sort'             => $reply_sort,
					'hasMore'          => false,
					'loadingMore'      => false,
				] ); ?>'>

				<div class="jt-replies-head">
					<h3>
						<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
						<span class="jt-count-pill"><?php echo (int) $total_replies; ?></span>
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
									class="jt-pill <?php echo $reply_sort === $key ? 'on' : ''; ?>">
									<?php echo esc_html( $label ); ?>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				</div>

				<?php if ( empty( $first_batch ) && empty( $last_batch ) ) : ?>
					<div class="jt-empty-compact">
						<div class="jt-empty-text"><?php esc_html_e( 'No replies yet. Be the first to reply!', 'jetonomy' ); ?></div>
					</div>
				<?php else : ?>

					<div class="jt-replies-list" id="jt-replies-container">
						<!-- First batch (opening conversation) -->
						<?php foreach ( $first_batch as $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post ); ?>
						<?php endforeach; ?>

						<!-- Gap loader (in-between) -->
						<?php if ( $gap_count > 0 ) : ?>
							<div class="jt-load-gap" data-wp-interactive="jetonomy"
								data-wp-context='<?php echo wp_json_encode( [
									'postId'   => (int) $post->id,
									'gapStart' => $batch_size,
									'gapCount' => $gap_count,
									'loading'  => false,
								] ); ?>'>
								<button class="jt-btn jt-btn-ghost jt-load-gap-btn"
									data-wp-on--click="actions.loadGapReplies"
									data-wp-bind--disabled="context.loading">
									<span data-wp-text="context.loading ? '<?php echo esc_js( __( 'Loading…', 'jetonomy' ) ); ?>' : '<?php echo esc_js( sprintf( /* translators: %d: number of hidden replies */ __( 'Show %d more replies', 'jetonomy' ), $gap_count ) ); ?>'">
										<?php
										/* translators: %d: number of hidden replies */
										printf( esc_html__( 'Show %d more replies', 'jetonomy' ), $gap_count );
										?>
									</span>
								</button>
							</div>
						<?php endif; ?>

						<!-- Last batch (latest conversation) -->
						<?php foreach ( $last_batch as $reply ) : ?>
							<?php jetonomy_render_threaded_reply( $reply, $post ); ?>
						<?php endforeach; ?>
					</div>

				<?php endif; ?>
			</div>

			<!-- Composer -->
			<?php if ( ! $post->is_closed ) : ?>
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
				<div class="jt-closed-notice">
					<?php esc_html_e( 'This post is closed and no longer accepts replies.', 'jetonomy' ); ?>
				</div>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
