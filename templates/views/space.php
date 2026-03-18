<?php
defined( 'ABSPATH' ) || exit;

$space_slug = $data['slug'] ?? '';
$space      = \Jetonomy\Models\Space::find_by_slug( $space_slug );

if ( ! $space ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-icon">&#128483;</div><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div>';
	return;
}

if ( in_array( $space->visibility, [ 'private', 'hidden' ], true ) ) {
	$user_id = get_current_user_id();
	if ( ! $user_id || ! \Jetonomy\Models\SpaceMember::is_member( (int) $space->id, $user_id ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="jt-empty"><p>' . esc_html__( 'This space is private. You need to be a member to access it.', 'jetonomy' ) . '</p></div>';
			return;
		}
	}
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, [ 'latest', 'popular', 'unanswered' ], true ) ) {
	$sort = 'latest';
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged           = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$_jt_settings    = get_option( 'jetonomy_settings', [] );
$limit           = (int) ( $_jt_settings['posts_per_page'] ?? 20 );
$offset          = ( $paged - 1 ) * $limit;

$posts    = \Jetonomy\Models\Post::list_by_space( (int) $space->id, $sort, $limit, $offset );
$category = $space->category_id ? \Jetonomy\Models\Category::find( (int) $space->category_id ) : null;
$base     = home_url( '/community' );
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
			<div class="jt-space-head">
				<?php if ( ! empty( $space->icon ) ) : ?>
					<span class="jt-space-emoji"><?php echo esc_html( $space->icon ); ?></span>
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
			</div>

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
				<?php if ( is_user_logged_in() ) : ?>
					<a href="<?php echo esc_url( $space_url . 'new/' ); ?>" class="jt-btn jt-btn-fill">
						+ <?php esc_html_e( 'New Post', 'jetonomy' ); ?>
					</a>
				<?php else : ?>
					<a href="<?php echo esc_url( wp_login_url( $space_url ) ); ?>" class="jt-btn jt-btn-ghost">
						<?php esc_html_e( 'Log in to post', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon">&#128172;</div>
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
