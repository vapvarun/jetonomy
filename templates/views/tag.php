<?php
/**
 * Tag view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$tag_slug = $data['slug'] ?? '';
$tag      = \Jetonomy\Models\Tag::find_by_slug( $tag_slug );

if ( ! $tag ) {
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Tag not found.', 'jetonomy' ),
			'tone'      => 'warn',
		)
	);
	return;
}

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, array( 'latest', 'popular' ), true ) ) {
	$sort = 'latest';
}

// 30 a page; the total counts only what this viewer may read, like the list.
$per_page = 30;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged       = \Jetonomy\list_page();
$offset      = ( $paged - 1 ) * $per_page;
$total_posts = \Jetonomy\Models\Post::count_by_tag_visible( (int) $tag->id );
$total_pages = (int) ceil( max( 1, $total_posts ) / $per_page );

$posts = \Jetonomy\Models\Post::list_by_tag_visible( (int) $tag->id, $sort, $per_page, $offset );

$tag_url = \Jetonomy\route_url( 'tag', $tag->slug );

$crumbs = array(
	array(
		'label' => $tag->name,
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::breadcrumb( $crumbs ); ?>

<div class="jt-two-col">
		<main>
			<?php \Jetonomy\Template_Loader::breadcrumb_in_main(); ?>
			<div class="jt-flex jt-items-center jt-gap-12 jt-mb-20">
				<h1 class="jt-page-title"><?php echo esc_html( $tag->name ); ?></h1>
				<span class="jt-tag-count-label">
					<?php
					echo esc_html( \Jetonomy\count_label( $total_posts, 'topic' ) );
					?>
				</span>
				<?php
				// A tag page was a browse-only dead end — no way to contribute
				// to the topic. Give logged-in members a one-click path into the
				// composer with this tag pre-filled. Tags are cross-space, so we
				// target the space where the tag already lives (the newest
				// tagged post's space); hidden silently when none is known.
				$jt_tag_post_space = '';
				foreach ( $posts as $jt_pp ) {
					if ( ! empty( $jt_pp->space_slug ) ) {
						$jt_tag_post_space = (string) $jt_pp->space_slug;
						break;
					}
				}
				if ( is_user_logged_in() && '' !== $jt_tag_post_space ) :
					$jt_tag_compose_url = add_query_arg(
						'tag',
						rawurlencode( $tag->slug ),
						\Jetonomy\route_url( 'new-post', rawurlencode( $jt_tag_post_space ) )
					);
					?>
					<a href="<?php echo esc_url( $jt_tag_compose_url ); ?>" class="jt-btn jt-btn-fill jt-btn-sm jt-ml-auto">
						<?php
						/* translators: %s: tag name */
						echo esc_html( sprintf( __( 'Start a discussion tagged %s', 'jetonomy' ), $tag->name ) );
						?>
					</a>
				<?php endif; ?>
			</div>

			<div class="jt-bar">
				<div class="jt-pills">
					<?php
					$sorts = array(
						'latest'  => __( 'Latest', 'jetonomy' ),
						'popular' => __( 'Popular', 'jetonomy' ),
					);
					foreach ( $sorts as $key => $label ) :
						$pill_url = add_query_arg( 'sort', $key, $tag_url );
						?>
						<a href="<?php echo esc_url( $pill_url ); ?>"
							class="jt-pill <?php echo $sort === $key ? esc_attr( 'on' ) : ''; ?>"
							<?php echo $sort === $key ? 'aria-current="true"' : ''; ?>>
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<?php
				\Jetonomy\Template_Loader::partial(
					'empty-state',
					array(
						'icon'      => 'message-circle',
						'icon_size' => 48,
						'message'   => sprintf( /* translators: %s: plural topic label. */ __( 'No %s with this tag yet.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
					)
				);
				?>
			<?php else : ?>
				<div class="jt-topics">
					<?php \Jetonomy\prime_post_cards( $posts ); ?>
					<?php foreach ( $posts as $post ) : ?>
						<?php \Jetonomy\Template_Loader::partial( 'post-card', array( 'post' => $post ) ); ?>
					<?php endforeach; ?>
				</div>

				<?php
				if ( $total_pages > 1 ) :
					$prev_url = add_query_arg(
						array(
							'sort' => $sort,
							'pg'   => $paged - 1,
						),
						$tag_url
					);
					$next_url = add_query_arg(
						array(
							'sort' => $sort,
							'pg'   => $paged + 1,
						),
						$tag_url
					);
					?>
					<nav class="jt-pagination" aria-label="<?php esc_attr_e( 'Tag pagination', 'jetonomy' ); ?>">
						<?php if ( $paged > 1 ) : ?>
							<a class="jt-pagination-link" href="<?php echo esc_url( $prev_url ); ?>" rel="prev">
								<?php jetonomy_echo_icon( 'chevron-left', 14 ); ?>
								<?php esc_html_e( 'Previous', 'jetonomy' ); ?>
							</a>
						<?php endif; ?>
						<span class="jt-pagination-status">
							<?php
							/* translators: 1: current page, 2: total pages */
							echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'jetonomy' ), $paged, $total_pages ) );
							?>
						</span>
						<?php if ( $paged < $total_pages ) : ?>
							<a class="jt-pagination-link" href="<?php echo esc_url( $next_url ); ?>" rel="next">
								<?php esc_html_e( 'Next', 'jetonomy' ); ?>
								<?php jetonomy_echo_icon( 'chevron-right', 14 ); ?>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
