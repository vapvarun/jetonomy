<?php
defined( 'ABSPATH' ) || exit;

$post_slug = $data['slug'] ?? '';
$post      = \Jetonomy\Models\Post::find_by_slug( $post_slug );

if ( ! $post || 'publish' !== $post->status ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Post not found.', 'jetonomy' ) . '</div></div>';
	return;
}

// Increment view count (best-effort, ignore errors).
\Jetonomy\Models\Post::increment_view_count( (int) $post->id );

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
$replies = \Jetonomy\Models\Reply::list_by_post( (int) $post->id, $reply_sort, 50 );

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
		'currentPostId' => (int) $post->id,
		'postScores'    => $post_scores,
		'replyScores'   => [],
		'activeReply'   => 0,
		'submitting'    => false,
	]
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
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
					<?php echo wp_kses_post( $post->content ); ?>
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
			<div class="jt-replies-head">
				<h3>
					<?php esc_html_e( 'Replies', 'jetonomy' ); ?>
					<span class="jt-count-pill"><?php echo (int) $post->reply_count; ?></span>
				</h3>
				<div class="jt-pills">
					<?php
					$reply_sorts = [
						'oldest' => __( 'Oldest', 'jetonomy' ),
						'newest' => __( 'Newest', 'jetonomy' ),
						'best'   => __( 'Best', 'jetonomy' ),
					];
					foreach ( $reply_sorts as $key => $label ) :
						$rsort_url = add_query_arg( 'rsort', $key, $post_url );
					?>
						<a href="<?php echo esc_url( $rsort_url ); ?>"
							class="jt-pill <?php echo $reply_sort === $key ? 'on' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( empty( $replies ) ) : ?>
				<div class="jt-empty-compact">
					<div class="jt-empty-text"><?php esc_html_e( 'No replies yet. Be the first to reply!', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-replies-list">
					<?php foreach ( $replies as $reply ) : ?>
						<?php \Jetonomy\Template_Loader::partial( 'reply-card', [ 'reply' => $reply, 'post' => $post ] ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- Composer -->
			<?php if ( ! $post->is_closed ) : ?>
				<div class="jt-reply-composer">
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
