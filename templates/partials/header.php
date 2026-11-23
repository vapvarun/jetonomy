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
// centred accent button; 'menu' marks the tab that opens the Me sheet; 'sheet' names the sheet a link tab opens instead
// of navigating, unless 'direct' (header.js). Where "New" goes: inside a space that space's composer; elsewhere a
// "Start a topic in..." sheet of the spaces the member can post in, or that one space's composer when there is only one.
// On a topic page jetonomy_slug is the TOPIC; the space is jetonomy_space_slug. Every other space route uses jetonomy_slug.
$jt_slug     = (string) get_query_var( 'post' === $current_route ? 'jetonomy_space_slug' : 'jetonomy_slug', '' );
$jt_in_space = '' !== $jt_slug && in_array( $current_route, array( 'space', 'new-post', 'post', 'space-members', 'space-feed' ), true );

// Spaces the member can start a topic in, most recently joined first. Rendered on every page (not only outside a space)
// so the sheet still works after a client-side navigation out of a space. Bounded: one indexed query for the member's
// space ids, then batches of 30 primed in one query each and checked from the request cache, so archived or read-only
// spaces at the top do not hide an older open one. Up to 30 rows; past 8 the sheet gets a filter box (header.js).
// ponytail: scans the 300 newest memberships; past that $jt_unscanned keeps "All my spaces" on screen (no one-space
// shortcut, no "Join one" note), because a space the member can post in may sit beyond the scan.
$jt_postable  = array();
$jt_unscanned = false;
if ( $user_id ) {
	$jt_member_of = \Jetonomy\Models\SpaceMember::spaces_for_user( $user_id );
	$jt_unscanned = count( $jt_member_of ) > 300;
	foreach ( array_chunk( array_slice( $jt_member_of, 0, 300 ), 30 ) as $jt_ids ) {
		\Jetonomy\Models\Space::prime( $jt_ids );
		\Jetonomy\Models\SpaceMember::warm_viewer_roles( $user_id, $jt_ids );
		foreach ( $jt_ids as $jt_sid ) {
			$jt_sp = \Jetonomy\Models\Space::find( (int) $jt_sid );
			if ( $jt_sp && true === \Jetonomy\Permissions\Content_Gate::space_accepts_content( (int) $jt_sid )
				&& \Jetonomy\Permissions\Permission_Engine::can( $user_id, 'create_posts', (int) $jt_sid ) ) {
				$jt_postable[] = $jt_sp;
			}
		}
		if ( count( $jt_postable ) >= 30 ) {
			$jt_postable = array_slice( $jt_postable, 0, 30 );
			break;
		}
	}
}
$jt_one_space   = 1 === count( $jt_postable ) && ! $jt_unscanned ? \Jetonomy\route_url( 'new-post', $jt_postable[0]->slug ) : '';
$jt_new_home    = '' !== $jt_one_space ? $jt_one_space : \Jetonomy\route_url( 'my-spaces' );
$jt_new_url     = $jt_in_space ? \Jetonomy\route_url( 'new-post', $jt_slug ) : $jt_new_home;
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
			'active' => in_array( $current_route, array( 'my-spaces', 'space', 'category', 'post', 'space-members', 'space-feed', 'space-roadmap', 'space-moderation' ), true ),
		),
		array(
			'key'     => 'new',
			'label'   => __( 'New', 'jetonomy' ),
			'url'     => $jt_new_url,
			'icon'    => 'plus',
			'primary' => true,
			'active'  => 'new-post' === $current_route,
			'sheet'   => '' === $jt_one_space ? 'jt-new-sheet' : '',
			'direct'  => $jt_in_space,
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
 * The phone bottom bar's tabs, in order. Each is array{key, label, url, icon, active?, badge?, host?, primary?, menu?, sheet?, direct?, login?}.
 * Return a different order or add/remove tabs; the bar is not shown at all when BuddyNext is active.
 *
 * @since 2.1.0
 * @param array[] $jt_mobile_tabs Tabs.
 * @param bool    $logged_in      Whether the viewer is signed in.
 */
$jt_mobile_tabs = (array) apply_filters( 'jetonomy_mobile_tabs', $jt_mobile_tabs, (bool) $user_id );
?>
<nav class="jt-mobile-tabs" aria-label="<?php esc_attr_e( 'Mobile navigation', 'jetonomy' ); ?>"
	data-jt-base="<?php echo esc_attr( (string) wp_parse_url( $base, PHP_URL_PATH ) ); ?>" data-jt-new-fallback="<?php echo esc_url( $jt_new_home ); ?>">
	<?php foreach ( $jt_mobile_tabs as $jt_tab ) : ?>
		<?php
		$jt_classes = 'jt-mobile-tab' . ( ! empty( $jt_tab['primary'] ) ? ' jt-mobile-tab--primary' : '' ) . ( ! empty( $jt_tab['active'] ) ? ' active' : '' );
		?>
		<?php // A tab that opens a menu is a button (it never navigates by itself); every other tab is a link. ?>
		<<?php echo ! empty( $jt_tab['menu'] ) ? 'button type="button"' : 'a href="' . esc_url( $jt_tab['url'] ) . '"'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- url escaped, rest is literal. ?> class="<?php echo esc_attr( $jt_classes ); ?>" data-jt-key="<?php echo esc_attr( $jt_tab['key'] ); ?>"
			<?php echo ! empty( $jt_tab['host'] ) ? 'data-jt-unread-host="' . esc_attr( $jt_tab['host'] ) . '"' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute escaped above. ?>
			<?php echo ! empty( $jt_tab['menu'] ) ? 'data-jt-menu="jt-mobile-tab-menu"' : ( ! empty( $jt_tab['sheet'] ) ? 'data-jt-menu="' . esc_attr( $jt_tab['sheet'] ) . '"' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- attribute escaped. ?>
			<?php echo ! empty( $jt_tab['direct'] ) ? 'data-jt-direct' : ''; ?>
			<?php echo ! empty( $jt_tab['login'] ) ? 'data-jt-login' : ''; ?>
			<?php echo ! empty( $jt_tab['active'] ) ? 'aria-current="page"' : ''; ?>>
			<?php if ( ! empty( $jt_tab['primary'] ) ) : ?>
				<span class="jt-mobile-tab__bubble"><?php jetonomy_echo_icon( (string) $jt_tab['icon'], 20 ); ?></span>
			<?php else : ?>
				<?php jetonomy_echo_icon( (string) $jt_tab['icon'], 20 ); ?>
			<?php endif; ?>
			<span><?php echo esc_html( $jt_tab['label'] ); ?></span>
			<?php if ( ! empty( $jt_tab['badge'] ) && (int) $jt_tab['badge'] > 0 ) : ?>
				<span class="jt-mobile-tab-badge"><?php echo esc_html( (int) $jt_tab['badge'] > 99 ? '99+' : (string) (int) $jt_tab['badge'] ); ?></span>
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
		<?php if ( '' === $jt_one_space ) : ?>
			<?php
			/* translators: %s: the topic label, singular, lowercase (e.g. "topic", "question"). */
			$jt_sheet_title = sprintf( __( 'Start a %s in...', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) );
			?>
			<?php // "Start a topic in...", opened from New outside a space. With no space to post in it says so and links to the directory. ?>
			<?php $jt_searchable = count( $jt_postable ) > 8; ?>
			<?php // With a filter box the sheet is a dialog (a text field does not belong in a menu); otherwise a menu. ?>
			<div id="jt-new-sheet" class="jt-mobile-tab-menu jt-mobile-tab-menu--wide" role="<?php echo $jt_searchable ? 'dialog' : 'menu'; ?>" aria-label="<?php echo esc_attr( $jt_sheet_title ); ?>" hidden>
				<div class="jt-mobile-tab-menu__title" aria-hidden="true"><?php echo esc_html( $jt_sheet_title ); ?></div>
				<?php if ( $jt_searchable ) : ?>
					<?php /* translators: %s: the plural space label, lowercase (e.g. "spaces"). */ ?>
					<?php $jt_find = sprintf( __( 'Find in your %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ); ?>
					<label class="jt-mobile-tab-menu__search">
						<span class="screen-reader-text"><?php echo esc_html( $jt_find ); ?></span>
						<input type="search" data-jt-sheet-filter placeholder="<?php echo esc_attr( $jt_find ); ?>" autocomplete="off">
					</label>
					<p class="jt-mobile-tab-menu__note" data-jt-sheet-empty hidden><?php esc_html_e( 'No match.', 'jetonomy' ); ?></p>
				<?php endif; ?>
				<?php if ( $jt_postable ) : ?>
					<?php foreach ( $jt_postable as $jt_sp ) : ?>
						<a class="jt-more-item" <?php echo $jt_searchable ? '' : 'role="menuitem"'; ?> data-jt-sheet-row href="<?php echo esc_url( \Jetonomy\route_url( 'new-post', $jt_sp->slug ) ); ?>"><?php jetonomy_echo_icon( 'layout-grid', 18 ); ?><span><?php echo esc_html( $jt_sp->title ); ?></span></a>
					<?php endforeach; ?>
					<?php /* translators: %s: the plural space label, lowercase (e.g. "spaces", "groups"). */ ?>
					<a class="jt-more-item" <?php echo $jt_searchable ? '' : 'role="menuitem"'; ?> href="<?php echo esc_url( \Jetonomy\route_url( 'my-spaces' ) ); ?>"><?php jetonomy_echo_icon( 'users', 18 ); ?><span><?php echo esc_html( sprintf( __( 'All my %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></span></a>
				<?php elseif ( $jt_unscanned ) : ?>
					<?php /* translators: %s: the plural space label, lowercase (e.g. "spaces", "groups"). */ ?>
					<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( \Jetonomy\route_url( 'my-spaces' ) ); ?>"><?php jetonomy_echo_icon( 'users', 18 ); ?><span><?php echo esc_html( sprintf( __( 'All my %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></span></a>
				<?php else : ?>
					<?php /* translators: 1: the plural space label, lowercase (e.g. "spaces"). 2: the topic label, singular, lowercase. */ ?>
					<p class="jt-mobile-tab-menu__note"><?php echo esc_html( sprintf( __( 'Join one of the %1$s to start a %2$s.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?></p>
					<?php /* translators: %s: the plural space label, lowercase (e.g. "spaces"). */ ?>
					<a class="jt-more-item" role="menuitem" href="<?php echo esc_url( $base . '/' ); ?>"><?php jetonomy_echo_icon( 'layout-grid', 18 ); ?><span><?php echo esc_html( sprintf( __( 'Browse %s', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></span></a>
				<?php endif; ?>
			</div>
		<?php endif; ?>
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
