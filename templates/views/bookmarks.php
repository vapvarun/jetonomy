<?php
/**
 * My bookmarks view (1.4.1 A9).
 *
 * Top-level standalone page that lists the current user's bookmarked
 * posts. Auth-required — Template_Loader::render() redirects guests to
 * login before this template ever runs (see $auth_required_routes in
 * class-template-loader.php).
 *
 * Distinct from /u/:slug/bookmarks/ on the user-profile page; this is
 * the canonical login-agnostic entry point linkable from header menus,
 * emails, etc.
 *
 * Mirrors GET /jetonomy/v1/bookmarks (Bookmarks_Controller::list_items)
 * — same data source (Bookmark::list_by_user).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$user_id = get_current_user_id();
$base    = \Jetonomy\base_url();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page     = max( 1, (int) ( $_GET['pg'] ?? 1 ) );
$per_page = 20;
$offset   = ( $page - 1 ) * $per_page;

// Deliberately NOT block-filtered — the viewer bookmarked this content
// themselves, on purpose. Hiding it because the author is now blocked would
// silently destroy something the viewer chose to keep.
$bookmarks = \Jetonomy\Models\Bookmark::list_by_user( $user_id, $per_page, $offset );

// has_more compares the real total against what is shown — never count( $bookmarks ),
// which showed a phantom "Load More" when the bookmark count was an exact multiple
// of $per_page (Basecamp).
$total    = \Jetonomy\Models\Bookmark::count_by_user( $user_id );
$has_more = ( $page * $per_page ) < $total;

$crumbs = array(
	array(
		'label' => __( 'My bookmarks', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php esc_html_e( 'My bookmarks', 'jetonomy' ); ?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Posts you have bookmarked. Quick access to anything you wanted to come back to.', 'jetonomy' ); ?>
			</p>
		</header>

		<?php if ( empty( $bookmarks ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'      => 'bookmark',
					'message'   => __( "You haven't bookmarked anything yet. Bookmark posts to find them here later.", 'jetonomy' ),
					'cta_label' => __( 'Browse the community', 'jetonomy' ),
					'cta_url'   => $base . '/',
				]
			);
			?>
		<?php else : ?>
			<div class="jt-topics">
				<?php foreach ( $bookmarks as $bookmarked_post ) : ?>
					<?php
					// Reuse post-card for visual consistency with the rest of
					// the site. Bookmark::list_by_user already filters to
					// status='publish', so every row is a normal published
					// topic and post-card renders correctly.
					\Jetonomy\Template_Loader::partial(
						'post-card',
						array(
							'post'                 => $bookmarked_post,
							'show_bookmark_toggle' => true,
						)
					);
					?>
				<?php endforeach; ?>
			</div>
			<?php \Jetonomy\Template_Loader::partial( 'pagination', array( 'has_more' => $has_more ) ); ?>
		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
