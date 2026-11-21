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

// Enqueued before the nav filter below: header.js also carries the toast
// fallback, keyboard shortcuts, search overlay and hover cards, which a site
// that hides the nav still needs.
wp_enqueue_style(
	'jetonomy-header',
	JETONOMY_URL . 'assets/css/header.css',
	array(),
	JETONOMY_VERSION
);
wp_enqueue_script(
	'jetonomy-header',
	JETONOMY_URL . 'assets/js/header.js',
	array( 'jetonomy-rest', 'wp-i18n' ),
	JETONOMY_VERSION,
	true
);
\Jetonomy\script_translations( 'jetonomy-header' );
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
		// Label-dependent copy only; header.js translates the rest with wp.i18n.
		'i18n'         => array(
			/* translators: 1: plural topic label, 2: plural reply label. The %%1$d (topic count) and %%2$d (reply count) are filled in by JS. */
			'hcStatsFormat' => sprintf( __( '%%1$d %1$s · %%2$d %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
		),
	)
);

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
<?php
// Items marked jt-nav-dup are also in the phone bottom tab bar below, so the
// phone layout hides them here (jetonomy.css, <= 640px) and drops the whole
// row when nothing unique is left. Anything without the class (Moderation,
// the Pro Messages link, other jetonomy_header_nav_items) stays reachable.
?>
<nav class="jt-community-nav" aria-label="<?php esc_attr_e( 'Community navigation', 'jetonomy' ); ?>">
	<div class="jt-community-nav-inner">
		<div class="jt-community-nav-links">
			<a href="<?php echo esc_url( $base . '/' ); ?>" class="jt-nav-dup<?php echo 'home' === $current_route ? ' active' : ''; ?>" title="<?php echo esc_attr( \Jetonomy\community_title() ); ?>">
				<?php jetonomy_echo_icon( 'home', 18 ); ?>
				<span class="jt-nav-label"><?php echo esc_html( \Jetonomy\community_title() ); ?></span>
			</a>
			<a href="<?php echo esc_url( \Jetonomy\route_url( 'search' ) ); ?>" class="jt-nav-dup<?php echo 'search' === $current_route ? ' active' : ''; ?>" title="<?php esc_attr_e( 'Search', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'search', 18 ); ?>
				<span class="jt-nav-label"><?php esc_html_e( 'Search', 'jetonomy' ); ?></span>
			</a>
			<a href="<?php echo esc_url( \Jetonomy\route_url( 'leaderboard' ) ); ?>" class="jt-nav-dup<?php echo 'leaderboard' === $current_route ? ' active' : ''; ?>" title="<?php esc_attr_e( 'Leaderboard', 'jetonomy' ); ?>">
				<?php jetonomy_echo_icon( 'award', 18 ); ?>
				<span class="jt-nav-label"><?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?></span>
			</a>
			<?php if ( $user_id ) : ?>
				<a href="<?php echo esc_url( \Jetonomy\get_profile_url( $user_id ) ); ?>" class="jt-nav-dup<?php echo 'profile' === $current_route ? ' active' : ''; ?>" title="<?php esc_attr_e( 'My Profile', 'jetonomy' ); ?>">
					<?php jetonomy_echo_icon( 'user', 18 ); ?>
					<span class="jt-nav-label"><?php esc_html_e( 'My Profile', 'jetonomy' ); ?></span>
				</a>
			<?php endif; ?>
			<?php if ( $user_id && \Jetonomy\Moderation\Moderation_Permissions::can_view_any_queue( $user_id ) ) : ?>
				<a href="<?php echo esc_url( \Jetonomy\route_url( 'moderation' ) ); ?>" class="<?php echo in_array( $current_route, array( 'moderation', 'space-moderation' ), true ) ? esc_attr( 'active' ) : ''; ?>" title="<?php esc_attr_e( 'Moderation', 'jetonomy' ); ?>">
					<?php jetonomy_echo_icon( 'shield', 18 ); ?>
					<span class="jt-nav-label"><?php esc_html_e( 'Moderation', 'jetonomy' ); ?></span>
				</a>
			<?php endif; ?>
			<?php
			/**
			 * Fires at the end of the community header navigation, so extensions can add nav items.
			 *
			 * @since 1.0.0
			 */
			?>
			<?php do_action( 'jetonomy_header_nav_items' ); ?>
		</div>

		<div class="jt-community-nav-actions">
			<?php
			// With BuddyNext, its site-header bell is the one inbox (forum
			// notifications reach it), so a second bell here is a duplicate.
			if ( $user_id && ! did_action( 'buddynext_loaded' ) ) :
				?>
				<?php // jt-nav-dup: the phone tab bar's Alerts tab is the same destination. ?>
				<div class="jt-notif-dropdown-wrap jt-nav-dup">
					<button type="button" class="jt-community-nav-notif" data-jt-unread-host="jt-community-nav-badge" aria-label="<?php esc_attr_e( 'Notifications', 'jetonomy' ); ?>" aria-expanded="false">
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
						<?php
						/**
						 * A prompt above the notification list (Pro's "Get notified on this device").
						 *
						 * @since 2.0.7
						 * @param string $context 'panel' here, 'page' on the Notifications page.
						 */
						do_action( 'jetonomy_notifications_prompt', 'panel' );
						?>
						<div class="jt-notif-panel-body">
							<div class="jt-notif-panel-loading"><?php esc_html_e( 'Loading...', 'jetonomy' ); ?></div>
						</div>
						<a href="<?php echo esc_url( \Jetonomy\route_url( 'notifications' ) ); ?>" class="jt-notif-panel-footer">
							<?php esc_html_e( 'View all notifications', 'jetonomy' ); ?>
						</a>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
			<div class="jt-font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'jetonomy' ); ?>">
				<button class="jt-font-scale__btn active" type="button" dir="ltr" aria-pressed="true" data-scale="100" aria-label="<?php esc_attr_e( 'Default font size', 'jetonomy' ); ?>">A</button>
				<button class="jt-font-scale__btn" type="button" dir="ltr" aria-pressed="false" data-scale="110" aria-label="<?php esc_attr_e( 'Large font size', 'jetonomy' ); ?>">A+</button>
				<button class="jt-font-scale__btn" type="button" dir="ltr" aria-pressed="false" data-scale="120" aria-label="<?php esc_attr_e( 'Extra large font size', 'jetonomy' ); ?>">A++</button>
			</div>
			<?php endif; ?>
		</div>
	</div>
</nav>
<!-- Mobile bottom tab bar (visible ≤640px only, hidden when BuddyNext provides its own) -->
<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
<?php
// The tabs, built as data so the order and the items can be changed with the jetonomy_mobile_tabs filter. 'primary' is the
// centred accent button; 'menu' marks the tab that opens the sheet (header.js). Where "New" goes: inside a space that
// space's composer; elsewhere the member's spaces, where each one starts a topic.
$jt_slug        = (string) get_query_var( 'jetonomy_slug', '' );
$jt_in_space    = '' !== $jt_slug && in_array( $current_route, array( 'space', 'new-post', 'post', 'space-members', 'space-feed' ), true );
$jt_new_url     = $jt_in_space ? \Jetonomy\route_url( 'new-post', $jt_slug ) : \Jetonomy\route_url( 'my-spaces' );
$jt_mobile_tabs = $user_id
	? array(
		array(
			'key'    => 'home',
			'label'  => __( 'Home', 'jetonomy' ),
			'url'    => $base . '/',
			'icon'   => 'home',
			'active' => 'home' === $current_route,
		),
		array(
			'key'    => 'spaces',
			'label'  => \Jetonomy\space_label( true ),
			'url'    => \Jetonomy\route_url( 'my-spaces' ),
			'icon'   => 'layout-grid',
			'active' => in_array( $current_route, array( 'my-spaces', 'space', 'category' ), true ),
		),
		array(
			'key'     => 'new',
			'label'   => __( 'New', 'jetonomy' ),
			'url'     => $jt_new_url,
			'icon'    => 'plus',
			'primary' => true,
			'active'  => 'new-post' === $current_route,
		),
		array(
			'key'    => 'alerts',
			'label'  => __( 'Alerts', 'jetonomy' ),
			'url'    => \Jetonomy\route_url( 'notifications' ),
			'icon'   => 'bell',
			'badge'  => $unread,
			'host'   => 'jt-mobile-tab-badge',
			'active' => 'notifications' === $current_route,
		),
		array(
			'key'    => 'me',
			'label'  => __( 'Me', 'jetonomy' ),
			'url'    => \Jetonomy\get_profile_url( $user_id ),
			'icon'   => 'user',
			'menu'   => true,
			'active' => in_array( $current_route, array( 'profile', 'moderation', 'bookmarks', 'drafts', 'subscriptions', 'leaderboard', 'edit-profile', 'messages', 'conversation' ), true ),
		),
	)
	: array(
		array(
			'key'    => 'home',
			'label'  => __( 'Home', 'jetonomy' ),
			'url'    => $base . '/',
			'icon'   => 'home',
			'active' => 'home' === $current_route,
		),
		array(
			'key'    => 'search',
			'label'  => __( 'Search', 'jetonomy' ),
			'url'    => \Jetonomy\route_url( 'search' ),
			'icon'   => 'search',
			'active' => 'search' === $current_route,
		),
		array(
			'key'     => 'signin',
			'label'   => __( 'Sign in', 'jetonomy' ),
			'url'     => wp_login_url( \Jetonomy\current_url() ),
			'icon'    => 'users',
			'primary' => true,
			'login'   => true,
		),
	);

/**
 * The phone bottom bar's tabs, in order. Each is array{key, label, url, icon, active?, badge?, host?, primary?, menu?, login?}.
 * Return a different order or add/remove tabs; the bar is not shown at all when BuddyNext is active.
 *
 * @since 2.0.8
 * @param array[] $jt_mobile_tabs Tabs.
 * @param bool    $logged_in      Whether the viewer is signed in.
 */
$jt_mobile_tabs = (array) apply_filters( 'jetonomy_mobile_tabs', $jt_mobile_tabs, (bool) $user_id );
?>
<nav class="jt-mobile-tabs" aria-label="<?php esc_attr_e( 'Mobile navigation', 'jetonomy' ); ?>">
	<?php foreach ( $jt_mobile_tabs as $jt_tab ) : ?>
		<?php
		$jt_classes = 'jt-mobile-tab' . ( ! empty( $jt_tab['primary'] ) ? ' jt-mobile-tab--primary' : '' ) . ( ! empty( $jt_tab['active'] ) ? ' active' : '' );
		?>
		<?php // A tab that opens a menu is a button (it never navigates by itself); every other tab is a link. ?>
		<<?php echo ! empty( $jt_tab['menu'] ) ? 'button type="button"' : 'a href="' . esc_url( $jt_tab['url'] ) . '"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url escaped, rest is literal. ?> class="<?php echo esc_attr( $jt_classes ); ?>"
			<?php echo ! empty( $jt_tab['host'] ) ? 'data-jt-unread-host="' . esc_attr( $jt_tab['host'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute escaped above. ?>
			<?php echo ! empty( $jt_tab['menu'] ) ? 'data-jt-tab="profile"' : ''; ?>
			<?php echo ! empty( $jt_tab['login'] ) ? 'data-jt-login' : ''; ?>
			<?php echo ! empty( $jt_tab['active'] ) ? 'aria-current="page"' : ''; ?>>
			<?php if ( ! empty( $jt_tab['primary'] ) ) : ?>
				<span class="jt-mobile-tab__bubble"><?php jetonomy_echo_icon( (string) $jt_tab['icon'], 20 ); ?></span>
			<?php else : ?>
				<?php jetonomy_echo_icon( (string) $jt_tab['icon'], 20 ); ?>
			<?php endif; ?>
			<span><?php echo esc_html( $jt_tab['label'] ); ?></span>
			<?php if ( ! empty( $jt_tab['badge'] ) && (int) $jt_tab['badge'] > 0 ) : ?>
				<span class="jt-mobile-tab-badge"><?php echo (int) $jt_tab['badge']; ?></span>
			<?php endif; ?>
		</<?php echo ! empty( $jt_tab['menu'] ) ? 'button' : 'a'; ?>>
	<?php endforeach; ?>
	<?php if ( $user_id ) : ?>
		<?php // The Me sheet: header.js shows it, appending the top-nav-only links (Moderation, Pro's Messages) once. ?>
		<div id="jt-mobile-tab-menu" class="jt-mobile-tab-menu" role="menu" hidden>
			<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( \Jetonomy\get_profile_url( $user_id ) ); ?>"><?php jetonomy_echo_icon( 'user', 18 ); ?><span><?php esc_html_e( 'My profile', 'jetonomy' ); ?></span></a>
			<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( trailingslashit( \Jetonomy\get_profile_url( $user_id ) ) . 'posts/' ); ?>"><?php jetonomy_echo_icon( 'file-text', 18 ); ?><span><?php echo esc_html( sprintf( /* translators: %s: topic label, plural. */ __( 'My %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></span></a>
			<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( \Jetonomy\route_url( 'bookmarks' ) ); ?>"><?php jetonomy_echo_icon( 'bookmark', 18 ); ?><span><?php esc_html_e( 'Bookmarks', 'jetonomy' ); ?></span></a>
			<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( \Jetonomy\route_url( 'leaderboard' ) ); ?>"><?php jetonomy_echo_icon( 'award', 18 ); ?><span><?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?></span></a>
			<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( \Jetonomy\route_url( 'edit-profile', get_userdata( $user_id )->user_nicename ) ); ?>"><?php jetonomy_echo_icon( 'settings', 18 ); ?><span><?php esc_html_e( 'Settings', 'jetonomy' ); ?></span></a>
		</div>
	<?php endif; ?>
</nav>
<?php endif; ?>

<?php
// Server-rendered search-icon SVG. The header JS clones this template
// into the search overlay (avoids assigning innerHTML in JS).
?>
<template id="jt-search-icon-tpl"><?php jetonomy_echo_icon( 'search', 20 ); ?></template>

<?php
// Font-scale drives the nav's A / A+ / A++ buttons, so it loads only with the
// nav, and only when BuddyNext is not present (it provides its own).
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
