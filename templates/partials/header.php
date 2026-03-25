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

			<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
			<div class="jt-font-scale" role="group" aria-label="<?php esc_attr_e( 'Font size', 'jetonomy' ); ?>">
				<button class="jt-font-scale__btn active" type="button" data-scale="100" onclick="jtSetFontScale('100')" aria-label="<?php esc_attr_e( 'Default font size', 'jetonomy' ); ?>">A</button>
				<button class="jt-font-scale__btn" type="button" data-scale="110" onclick="jtSetFontScale('110')" aria-label="<?php esc_attr_e( 'Large font size', 'jetonomy' ); ?>">A+</button>
				<button class="jt-font-scale__btn" type="button" data-scale="120" onclick="jtSetFontScale('120')" aria-label="<?php esc_attr_e( 'Extra large font size', 'jetonomy' ); ?>">A++</button>
			</div>
			<?php endif; ?>
		</div>
	</div>
</nav>
<?php if ( ! did_action( 'buddynext_loaded' ) ) : ?>
<style>
/* Font size control — mirrors BuddyNext A/A+/A++ pattern */
.jt-font-scale {
	display: flex;
	align-items: center;
	gap: 2px;
	background: var(--jt-bg-muted, #f1f1f0);
	border: 1px solid var(--jt-border, #e0e0e0);
	border-radius: 6px;
	padding: 2px;
	margin-left: auto;
}
.jt-font-scale__btn {
	border: none;
	background: transparent;
	border-radius: 4px;
	padding: 3px 8px;
	font-size: 11px;
	font-weight: 600;
	color: var(--jt-text-secondary, #6b7280);
	cursor: pointer;
	white-space: nowrap;
	transition: background 0.12s, color 0.12s;
	line-height: 1.4;
}
.jt-font-scale__btn:hover { color: var(--jt-text, #1a1a1a); }
.jt-font-scale__btn.active {
	background: var(--jt-accent, #3b82f6);
	color: #fff;
}
</style>
<script>
(function () {
	var scales = ['100', '110', '120'];
	function applyScale(s) {
		document.documentElement.setAttribute('data-bn-font-scale', s);
		try { localStorage.setItem('bn_font_scale', s); } catch (e) { /* noop */ }
		var btns = document.querySelectorAll('.jt-font-scale__btn');
		btns.forEach(function (b) { b.classList.toggle('active', b.dataset.scale === s); });
	}
	var saved = '100';
	try { saved = localStorage.getItem('bn_font_scale') || '100'; } catch (e) { /* noop */ }
	if (scales.indexOf(saved) === -1) { saved = '100'; }
	applyScale(saved);
	window.jtSetFontScale = function (s) { if (scales.indexOf(s) !== -1) { applyScale(s); } };
})();
</script>
<?php endif; ?>
<?php
