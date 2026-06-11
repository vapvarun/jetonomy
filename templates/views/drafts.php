<?php
/**
 * My drafts view (1.4.1 A9).
 *
 * Top-level standalone page that lists the current user's draft posts.
 * Auth-required — Template_Loader::render() redirects guests to login
 * before this template ever runs (see $auth_required_routes in
 * class-template-loader.php).
 *
 * Distinct from /u/:slug/drafts/ on the user-profile page; this is the
 * canonical login-agnostic entry point linkable from header menus,
 * emails, etc.
 *
 * Mirrors GET /jetonomy/v1/posts/drafts (list_drafts in
 * Posts_Controller) — same data source (Post::list_drafts_by_user).
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

$drafts = \Jetonomy\Models\Post::list_drafts_by_user( $user_id, $per_page, $offset );

$crumbs = array(
	array(
		'label' => __( 'My drafts', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php esc_html_e( 'My drafts', 'jetonomy' ); ?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Posts you have saved as drafts. Pick one up where you left off.', 'jetonomy' ); ?>
			</p>
		</header>

		<?php if ( empty( $drafts ) ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'        => 'edit',
					'message'     => __( 'No drafts yet.', 'jetonomy' ),
					'description' => __( 'Start writing a post and choose "Save draft" — it will wait for you here until you publish.', 'jetonomy' ),
					'cta_label'   => __( 'Start a post', 'jetonomy' ),
					'cta_url'     => $base . '/',
				]
			);
			?>
		<?php else : ?>
			<div class="jt-topics">
				<?php foreach ( $drafts as $draft_post ) : ?>
					<?php
					// Reuse post-card for visual consistency with the rest of
					// the site. The card links to /s/:space/t/:slug/ which
					// single-post.php serves to the author with edit controls
					// (404s for everyone else), so the click flow lands on the
					// edit composer for the draft.
					\Jetonomy\Template_Loader::partial( 'post-card', array( 'post' => $draft_post ) );
					?>
				<?php endforeach; ?>
			</div>
			<?php \Jetonomy\Template_Loader::partial( 'pagination', array( 'has_more' => count( $drafts ) >= $per_page ) ); ?>
		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
