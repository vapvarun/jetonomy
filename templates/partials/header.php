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
$base = \Jetonomy\base_url();

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
			<a href="<?php echo esc_url( $base . '/' ); ?>" class="<?php echo 'home' === $current_route ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'Community', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'home', 18 ); ?>
				<span class="jt-nav-label"><?php esc_html_e( 'Community', 'jetonomy' ); ?></span>
			</a>
			<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="<?php echo 'search' === $current_route ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'Search', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'search', 18 ); ?>
				<span class="jt-nav-label"><?php esc_html_e( 'Search', 'jetonomy' ); ?></span>
			</a>
			<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="<?php echo 'leaderboard' === $current_route ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'Leaderboard', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'award', 18 ); ?>
				<span class="jt-nav-label"><?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?></span>
			</a>
			<?php if ( $user_id ) : ?>
				<a href="<?php echo esc_url( $base . '/u/' . wp_get_current_user()->user_login . '/' ); ?>" class="<?php echo 'profile' === $current_route ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'My Profile', 'jetonomy' ); ?>">
					<?php jetonomy_echo_icon( 'user', 18 ); ?>
					<span class="jt-nav-label"><?php esc_html_e( 'My Profile', 'jetonomy' ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( $user_id && \Jetonomy\Moderation\Moderation_Permissions::can_view_any_queue( $user_id ) ) : ?>
				<a href="<?php echo esc_url( $base . '/mod/' ); ?>" class="<?php echo in_array( $current_route, array( 'moderation', 'space-moderation' ), true ) ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'Moderation', 'jetonomy' ); ?>">
					<?php jetonomy_echo_icon( 'shield', 18 ); ?>
					<span class="jt-nav-label"><?php esc_html_e( 'Moderation', 'jetonomy' ); ?></span>
				</a>
			<?php endif; ?>
			<?php do_action( 'jetonomy_header_nav_items' ); ?>
		</div>

		<div class="jt-community-nav-actions">
			<?php if ( $user_id ) : ?>
				<div class="jt-notif-dropdown-wrap">
					<button type="button" class="jt-community-nav-notif" aria-label="<?php esc_attr_e( 'Notifications', 'jetonomy' ); ?>">
						<?php jetonomy_echo_icon( 'bell', 16 ); ?>
						<?php if ( $unread > 0 ) : ?>
							<span class="jt-community-nav-badge"><?php echo (int) $unread; ?></span>
						<?php endif; ?>
					</button>
					<div class="jt-notif-panel" hidden>
						<div class="jt-notif-panel-head">
							<strong><?php esc_html_e( 'Notifications', 'jetonomy' ); ?></strong>
							<button type="button" class="jt-notif-mark-read"><?php esc_html_e( 'Mark all read', 'jetonomy' ); ?></button>
						</div>
						<div class="jt-notif-panel-body">
							<div class="jt-notif-panel-loading"><?php esc_html_e( 'Loading...', 'jetonomy' ); ?></div>
						</div>
						<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-notif-panel-footer">
							<?php esc_html_e( 'View all notifications', 'jetonomy' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
			<div class="jt-font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'jetonomy' ); ?>">
				<button class="jt-font-scale__btn active" type="button" data-scale="100" aria-label="<?php esc_attr_e( 'Default font size', 'jetonomy' ); ?>">A</button>
				<button class="jt-font-scale__btn" type="button" data-scale="110" aria-label="<?php esc_attr_e( 'Large font size', 'jetonomy' ); ?>">A+</button>
				<button class="jt-font-scale__btn" type="button" data-scale="120" aria-label="<?php esc_attr_e( 'Extra large font size', 'jetonomy' ); ?>">A++</button>
			</div>
			<?php endif; ?>
		</div>
	</div>
</nav>
<!-- Mobile bottom tab bar (visible ≤640px only, hidden when BuddyNext provides its own) -->
<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
<nav class="jt-mobile-tabs" aria-label="<?php esc_attr_e( 'Mobile navigation', 'jetonomy' ); ?>">
	<a href="<?php echo esc_url( $base . '/' ); ?>" class="jt-mobile-tab <?php echo 'home' === $current_route ? esc_attr( 'active' ) : ''; ?>">
		<?php jetonomy_echo_icon( 'home', 20 ); ?>
		<span><?php esc_html_e( 'Home', 'jetonomy' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="jt-mobile-tab <?php echo 'search' === $current_route ? esc_attr( 'active' ) : ''; ?>">
		<?php jetonomy_echo_icon( 'search', 20 ); ?>
		<span><?php esc_html_e( 'Search', 'jetonomy' ); ?></span>
	</a>
	<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="jt-mobile-tab <?php echo 'leaderboard' === $current_route ? esc_attr( 'active' ) : ''; ?>">
		<?php jetonomy_echo_icon( 'award', 20 ); ?>
		<span><?php esc_html_e( 'Ranks', 'jetonomy' ); ?></span>
	</a>
	<?php if ( $user_id ) : ?>
		<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-mobile-tab <?php echo 'notifications' === $current_route ? esc_attr( 'active' ) : ''; ?>">
			<?php jetonomy_echo_icon( 'bell', 20 ); ?>
			<span><?php esc_html_e( 'Alerts', 'jetonomy' ); ?></span>
			<?php if ( $unread > 0 ) : ?>
				<span class="jt-mobile-tab-badge"><?php echo (int) $unread; ?></span>
			<?php endif; ?>
		</a>
		<a href="<?php echo esc_url( $base . '/u/' . wp_get_current_user()->user_login . '/' ); ?>" class="jt-mobile-tab <?php echo 'profile' === $current_route ? esc_attr( 'active' ) : ''; ?>">
			<?php jetonomy_echo_icon( 'users', 20 ); ?>
			<span><?php esc_html_e( 'Profile', 'jetonomy' ); ?></span>
		</a>
	<?php else : ?>
		<a href="<?php echo esc_url( wp_login_url( $base . '/' ) ); ?>" class="jt-mobile-tab">
			<?php jetonomy_echo_icon( 'users', 20 ); ?>
			<span><?php esc_html_e( 'Login', 'jetonomy' ); ?></span>
		</a>
	<?php endif; ?>
</nav>
<?php endif; ?>

<?php
// Server-rendered search-icon SVG. The header JS clones this template
// into the search overlay (avoids assigning innerHTML in JS).
?>
<template id="jt-search-icon-tpl"><?php jetonomy_echo_icon( 'search', 20 ); ?></template>

<?php
// Enqueue header CSS + JS. Font-scale assets only when BuddyNext is
// not present (it provides its own implementation).
wp_enqueue_style(
	'jetonomy-header',
	JETONOMY_URL . 'assets/css/header.css',
	array(),
	JETONOMY_VERSION
);
wp_enqueue_script(
	'jetonomy-header',
	JETONOMY_URL . 'assets/js/header.js',
	array( 'jetonomy-rest' ),
	JETONOMY_VERSION,
	true
);
wp_localize_script(
	'jetonomy-header',
	'jetonomyHeader',
	array(
		'base'         => $base,
		'restBase'     => rest_url( 'jetonomy/v1' ),
		'nonce'        => wp_create_nonce( 'wp_rest' ),
		'isLoggedIn'   => (bool) $user_id,
		'restNotif'    => rest_url( 'jetonomy/v1/notifications' ),
		'restMarkRead' => rest_url( 'jetonomy/v1/notifications/mark-all-read' ),
		'restSearch'   => rest_url( 'jetonomy/v1/search' ),
		'i18n'         => array(
			'noNotifs'         => esc_html__( 'No notifications yet.', 'jetonomy' ),
			'noResults'        => esc_html__( 'No results found.', 'jetonomy' ),
			'searchPH'         => esc_html__( 'Search discussions...', 'jetonomy' ),
			'shortcuts'        => esc_html__( 'Keyboard Shortcuts', 'jetonomy' ),
			'close'            => esc_html__( 'Close', 'jetonomy' ),
			'loadFail'         => esc_html__( 'Failed to load', 'jetonomy' ),
			'escKey'           => esc_html_x( 'ESC', 'keyboard key label shown next to the search overlay', 'jetonomy' ),
			// WS4-C: keyboard-shortcut labels + hover-card trust line.
			'kbSearch'         => esc_html__( 'Search', 'jetonomy' ),
			'kbNavigate'       => esc_html__( 'Navigate up/down', 'jetonomy' ),
			'kbOpenSelected'   => esc_html__( 'Open selected', 'jetonomy' ),
			'kbHome'           => esc_html__( 'Home', 'jetonomy' ),
			'kbThisHelp'       => esc_html__( 'This help', 'jetonomy' ),
			/* translators: 1: trust level number, 2: reputation points. */
			'trustLevelFormat' => __( 'Level %1$d · %2$d rep', 'jetonomy' ),
			/* translators: 1: post count, 2: reply count. */
			'hcStatsFormat'    => __( '%1$d posts · %2$d replies', 'jetonomy' ),
		),
	)
);

if ( ! did_action( 'buddynext_loaded' ) ) {
	wp_enqueue_script(
		'jetonomy-header-font-scale',
		JETONOMY_URL . 'assets/js/header-font-scale.js',
		array(),
		JETONOMY_VERSION,
		true
	);
}
?>
<?php
