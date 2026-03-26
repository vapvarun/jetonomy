<?php
defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div>';
	return;
}

if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $user_id ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			$join_policy = $space->join_policy ?? 'open';
			if ( ! $user_id ) {
				// Guest — prompt to log in.
				echo '<div class="jt-empty">';
				echo '<p>' . esc_html__( 'This space is private. Please log in to request access.', 'jetonomy' ) . '</p>';
				echo '<a href="' . esc_url( wp_login_url( get_permalink() ) ) . '" class="jt-btn jt-btn-fill">' . esc_html__( 'Log In', 'jetonomy' ) . '</a>';
				echo '</div>';
			} elseif ( 'invite' === $join_policy ) {
				// Invite-only — cannot self-join.
				echo '<div class="jt-empty"><p>' . esc_html__( 'This space is invite-only. You need an invitation to join.', 'jetonomy' ) . '</p></div>';
			} elseif ( 'approval' === $join_policy ) {
				// Approval required — show a request form with nonce.
				$join_nonce = wp_create_nonce( 'wp_rest' );
				echo '<div class="jt-empty jt-space-gate">';
				echo '<p>' . esc_html__( 'This space requires approval to join. Submit a request below.', 'jetonomy' ) . '</p>';
				echo '<form class="jt-join-request-form" data-space-id="' . (int) $space->id . '" data-nonce="' . esc_attr( $join_nonce ) . '">';
				echo '<textarea class="jt-input" name="message" rows="3" placeholder="' . esc_attr__( 'Optional: why do you want to join?', 'jetonomy' ) . '"></textarea>';
				echo '<button type="submit" class="jt-btn jt-btn-fill">' . esc_html__( 'Request to Join', 'jetonomy' ) . '</button>';
				echo '</form>';
				echo '</div>';
			} else {
				// Open join policy but private visibility — allow direct join.
				$join_nonce = wp_create_nonce( 'wp_rest' );
				echo '<div class="jt-empty jt-space-gate">';
				echo '<p>' . esc_html__( 'This space is private. Join to access posts and discussions.', 'jetonomy' ) . '</p>';
				echo '<button class="jt-btn jt-btn-fill jt-join-btn" data-space-id="' . (int) $space->id . '" data-nonce="' . esc_attr( $join_nonce ) . '">' . esc_html__( 'Join Space', 'jetonomy' ) . '</button>';
				echo '</div>';
			}
			return;
		}
	}
}

$space_status  = $space->status ?? 'active';
$is_restricted = in_array( $space_status, [ 'archived', 'locked' ], true );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, [ 'latest', 'popular', 'unanswered' ], true ) ) {
	$sort = 'latest';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged           = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$_jt_settings    = get_option( 'jetonomy_settings', [] );
$_space_settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
$limit           = (int) ( $_space_settings['posts_per_page'] ?? $_jt_settings['posts_per_page'] ?? 20 );
$offset          = ( $paged - 1 ) * $limit;

$posts    = \Jetonomy\Models\Post::list_by_space( (int) $space->id, $sort, $limit, $offset );
$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base     = \Jetonomy\base_url();
$space_url = $base . '/s/' . $space->slug . '/';

$crumbs = [];
if ( $category ) {
	$crumbs[] = [ 'label' => $category->name, 'url' => '' ];
}
$crumbs[] = [ 'label' => $space->title, 'url' => '' ];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<?php if ( ! empty( $space->cover_image ) ) : ?>
			<div class="jt-space-cover" style="background-image:url('<?php echo esc_url( $space->cover_image ); ?>')"></div>
		<?php endif; ?>
		<div class="jt-space-head">
				<?php if ( ! empty( $space->icon ) ) : ?>
					<?php if ( str_starts_with( $space->icon, 'dashicons-' ) ) : ?>
						<span class="jt-space-emoji dashicons <?php echo esc_attr( $space->icon ); ?>"></span>
					<?php else : ?>
						<span class="jt-space-emoji"><?php echo esc_html( $space->icon ); ?></span>
					<?php endif; ?>
				<?php endif; ?>
				<div>
					<h1><?php echo esc_html( $space->title ); ?></h1>
					<?php if ( ! empty( $space->description ) ) : ?>
						<p class="jt-space-desc"><?php echo esc_html( $space->description ); ?></p>
					<?php endif; ?>
				</div>
				<div class="jt-space-nums">
					<div class="jt-num">
						<div class="jt-num-val"><?php echo (int) $space->post_count; ?></div>
						<div class="jt-num-lbl"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></div>
					</div>
					<div class="jt-num">
						<div class="jt-num-val"><?php echo (int) $space->member_count; ?></div>
						<div class="jt-num-lbl"><?php esc_html_e( 'Members', 'jetonomy' ); ?></div>
					</div>
				</div>
				<?php if ( is_user_logged_in() ) :
					$is_following_space = \Jetonomy\Models\Subscription::is_subscribed( get_current_user_id(), 'space', (int) $space->id );
				?>
					<button class="jt-btn jt-btn-sm <?php echo $is_following_space ? 'jt-btn-fill jt-following' : 'jt-btn-ghost'; ?>"
						data-wp-interactive="jetonomy"
						data-wp-on--click="actions.followSpace"
						data-space-id="<?php echo (int) $space->id; ?>"
						data-following="<?php echo $is_following_space ? '1' : '0'; ?>">
						<?php echo $is_following_space ? esc_html__( 'Following', 'jetonomy' ) : esc_html__( 'Follow', 'jetonomy' ); ?>
					</button>
				<?php endif; ?>
			</div>

		<?php if ( $is_restricted ) : ?>
			<div class="jt-status-banner jt-status-banner--<?php echo esc_attr( $space_status ); ?>">
				<?php if ( 'archived' === $space_status ) : ?>
					<?php esc_html_e( 'This space is archived. New posts and replies are no longer accepted.', 'jetonomy' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'This space is locked. New posts and replies are not allowed.', 'jetonomy' ); ?>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<div class="jt-bar">
				<div class="jt-pills">
					<?php
					$sort_options = [
						'latest'     => __( 'Latest', 'jetonomy' ),
						'popular'    => __( 'Popular', 'jetonomy' ),
						'unanswered' => __( 'Unanswered', 'jetonomy' ),
					];
					foreach ( $sort_options as $key => $label ) :
						$pill_url = add_query_arg( 'sort', $key, $space_url );
					?>
						<a href="<?php echo esc_url( $pill_url ); ?>"
							class="jt-pill <?php echo $sort === $key ? 'on' : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( $is_restricted ) : ?>
					<?php /* No new-post button for archived/locked spaces. */ ?>
				<?php elseif ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( $space_url . 'new/' ); ?>" class="jt-btn jt-btn-fill">
						<?php
						$new_post_labels = [
							'qa'    => __( '+ Ask a Question', 'jetonomy' ),
							'ideas' => __( '+ Share an Idea',  'jetonomy' ),
							'feed'  => __( '+ New Status',     'jetonomy' ),
						];
						echo esc_html( $new_post_labels[ $space->type ] ?? __( '+ New Post', 'jetonomy' ) );
						?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( wp_login_url( $space_url ) ); ?>" class="jt-btn jt-btn-ghost">
						<?php esc_html_e( 'Log in to post', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'empty-posts', 80 ); ?></div>
					<div class="jt-empty-text">
						<?php
						if ( 'unanswered' === $sort ) {
							esc_html_e( 'All questions have been answered!', 'jetonomy' );
						} else {
							esc_html_e( 'No posts yet. Be the first to start a discussion!', 'jetonomy' );
						}
						?>
					</div>
					<?php if ( is_user_logged_in() ) : ?>
						<a href="<?php echo esc_url( $space_url . 'new/' ); ?>" class="jt-btn jt-btn-fill">
							+ <?php esc_html_e( 'New Post', 'jetonomy' ); ?>
						</a>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="jt-topics">
					<?php foreach ( $posts as $post ) : ?>
						<?php \Jetonomy\Template_Loader::partial( 'post-card', [ 'post' => $post ] ); ?>
					<?php endforeach; ?>
				</div>

				<?php \Jetonomy\Template_Loader::partial( 'pagination', [ 'has_more' => count( $posts ) >= $limit ] ); ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar', [ 'space' => $space ] ); ?>
	</div>
