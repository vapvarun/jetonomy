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
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- jetonomy_icon() returns trusted SVG
	echo '<div class="jt-empty"><div class="jt-empty-icon">' . jetonomy_icon( 'search', 48 ) . '</div><div class="jt-empty-text">' . esc_html__( 'Tag not found.', 'jetonomy' ) . '</div></div>';
	return;
}

global $wpdb;
$posts_tbl     = \Jetonomy\table( 'posts' );
$spaces_tbl    = \Jetonomy\table( 'spaces' );
$post_tags_tbl = \Jetonomy\table( 'post_tags' );

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$sort = isset( $_GET['sort'] ) ? sanitize_key( $_GET['sort'] ) : 'latest';
if ( ! in_array( $sort, [ 'latest', 'popular' ], true ) ) {
	$sort = 'latest';
}

// 1.4.0 C.4 fix: classic offset pagination so popular tags don't silently
// drop post 31+. 30/page balances density with viewport — page param via
// `?paged=2`. Total post count comes from $tag->post_count which is kept
// in sync by the tag denormaliser.
$order_by = 'popular' === $sort ? 'p.vote_score DESC' : 'p.created_at DESC';
$per_page = 30;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$paged       = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$offset      = ( $paged - 1 ) * $per_page;
$total_posts = (int) $tag->post_count;
$total_pages = (int) ceil( max( 1, $total_posts ) / $per_page );

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$posts = $wpdb->get_results(
	$wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT p.*, sp.slug AS space_slug FROM {$posts_tbl} p
		 INNER JOIN {$post_tags_tbl} pt ON pt.post_id = p.id
		 LEFT JOIN {$spaces_tbl} sp ON sp.id = p.space_id
		 WHERE pt.tag_id = %d AND p.status = 'publish'
		 ORDER BY {$order_by}
		 LIMIT %d OFFSET %d",
		(int) $tag->id,
		$per_page,
		$offset
	)
) ?: [];

$base    = \Jetonomy\base_url();
$tag_url = $base . '/tag/' . $tag->slug . '/';

$crumbs = [
	[
		'label' => $tag->name,
		'url'   => '',
	],
];
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', [ 'crumbs' => $crumbs ] ); ?>

<div class="jt-two-col">
		<main>
			<div class="jt-flex jt-items-center jt-gap-12 jt-mb-20">
				<span class="jt-tag jt-tag-hero"><?php echo esc_html( $tag->name ); ?></span>
				<span class="jt-tag-count-label">
					<?php
					/* translators: %d: number of posts with this tag */
					echo esc_html( sprintf( _n( '%d post', '%d posts', (int) $tag->post_count, 'jetonomy' ), (int) $tag->post_count ) );
					?>
				</span>
			</div>

			<div class="jt-bar">
				<div class="jt-pills">
					<?php
					$sorts = [
						'latest'  => __( 'Latest', 'jetonomy' ),
						'popular' => __( 'Popular', 'jetonomy' ),
					];
					foreach ( $sorts as $key => $label ) :
						$pill_url = add_query_arg( 'sort', $key, $tag_url );
						?>
						<a href="<?php echo esc_url( $pill_url ); ?>"
							class="jt-pill <?php echo $sort === $key ? esc_attr( 'on' ) : ''; ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( empty( $posts ) ) : ?>
				<div class="jt-empty">
					<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'message-circle', 48 ); ?></div>
					<div class="jt-empty-text"><?php esc_html_e( 'No posts with this tag yet.', 'jetonomy' ); ?></div>
				</div>
			<?php else : ?>
				<div class="jt-topics">
					<?php foreach ( $posts as $post ) : ?>
						<?php \Jetonomy\Template_Loader::partial( 'post-card', [ 'post' => $post ] ); ?>
					<?php endforeach; ?>
				</div>

				<?php
				if ( $total_pages > 1 ) :
					$prev_url = add_query_arg(
						array(
							'sort'  => $sort,
							'paged' => $paged - 1,
						),
						$tag_url
					);
					$next_url = add_query_arg(
						array(
							'sort'  => $sort,
							'paged' => $paged + 1,
						),
						$tag_url
					);
					?>
					<nav class="jt-pagination" aria-label="<?php esc_attr_e( 'Tag pagination', 'jetonomy' ); ?>">
						<?php if ( $paged > 1 ) : ?>
							<a class="jt-pagination-link" href="<?php echo esc_url( $prev_url ); ?>" rel="prev">
								<?php esc_html_e( '← Previous', 'jetonomy' ); ?>
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
								<?php esc_html_e( 'Next →', 'jetonomy' ); ?>
							</a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</main>

		<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
	</div>
