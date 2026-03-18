<?php
/**
 * Jetonomy community sub-navigation.
 *
 * This renders a lightweight nav bar BELOW the theme's header.
 * It does NOT duplicate the theme's logo, search, or user menu.
 * The theme handles all of that.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$current_route = $data['route'] ?? 'home';
$user_id       = get_current_user_id();
$unread        = 0;
if ( $user_id ) {
	$unread = \Jetonomy\Models\Notification::unread_count( $user_id );
}
$base = home_url( '/community' );

/**
 * Filter whether to show the Jetonomy community navigation bar.
 * Themes can disable this if they integrate the nav into their own header.
 *
 * @param bool $show Whether to show the community nav. Default true.
 */
if ( ! apply_filters( 'jetonomy_show_community_nav', true ) ) {
	return;
}
?>
<nav class="jt-community-nav" aria-label="<?php esc_attr_e( 'Community navigation', 'jetonomy' ); ?>">
	<div class="jt-community-nav-inner">
		<div class="jt-community-nav-links">
			<a href="<?php echo esc_url( $base . '/' ); ?>" class="<?php echo 'home' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Community', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="<?php echo 'search' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Search', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="<?php echo 'leaderboard' === $current_route ? 'active' : ''; ?>">
				<?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?>
			</a>
			<?php do_action( 'jetonomy_header_nav_items' ); ?>
		</div>

		<div class="jt-community-nav-actions">
			<?php if ( $user_id ) : ?>
				<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-community-nav-notif" aria-label="<?php esc_attr_e( 'Notifications', 'jetonomy' ); ?>">
					<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
					<?php if ( $unread > 0 ) : ?>
						<span class="jt-community-nav-badge"><?php echo (int) $unread; ?></span>
					<?php endif; ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
</nav>
<?php
