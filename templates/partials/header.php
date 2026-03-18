<?php
defined( 'ABSPATH' ) || exit;
$current_route = $data['route'] ?? 'home';
$user_id       = get_current_user_id();
$unread        = 0;
if ( $user_id ) {
	$unread = \Jetonomy\Models\Notification::unread_count( $user_id );
}
$base = home_url( '/community' );
?>
<header class="jt-header">
	<?php
	$jt_logo_html = '<a href="' . esc_url( $base ) . '" class="jt-logo"><span class="jt-logo-icon">J</span> ' . esc_html( get_bloginfo( 'name' ) ) . '</a>';
	echo apply_filters( 'jetonomy_header_logo', $jt_logo_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Filtered HTML is expected to be pre-escaped by filter callbacks.
	?>
	<nav class="jt-nav">
		<a href="<?php echo esc_url( $base ); ?>" class="<?php echo 'home' === $current_route ? 'active' : ''; ?>"><?php esc_html_e( 'Home', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $base . '/search/' ); ?>" class="<?php echo 'search' === $current_route ? 'active' : ''; ?>"><?php esc_html_e( 'Search', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $base . '/leaderboard/' ); ?>" class="<?php echo 'leaderboard' === $current_route ? 'active' : ''; ?>"><?php esc_html_e( 'Leaderboard', 'jetonomy' ); ?></a>
		<?php
		/**
		 * Fires inside the header nav to allow Pro or other plugins to
		 * add additional navigation links (e.g., Messages).
		 */
		do_action( 'jetonomy_header_nav_items' );
		?>
	</nav>
	<div class="jt-header-right">
		<form class="jt-search" action="<?php echo esc_url( $base . '/search/' ); ?>" method="get" role="search" aria-label="<?php esc_attr_e( 'Search community', 'jetonomy' ); ?>">
			<label for="jt-search-input" class="jt-sr-only"><?php esc_html_e( 'Search', 'jetonomy' ); ?></label>
			<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
			<input type="text" id="jt-search-input" name="q" placeholder="<?php esc_attr_e( 'Search...', 'jetonomy' ); ?>" value="<?php echo esc_attr( isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
		</form>
		<?php if ( $user_id ) : ?>
			<a href="<?php echo esc_url( $base . '/notifications/' ); ?>" class="jt-icon-btn" aria-label="<?php esc_attr_e( 'Notifications', 'jetonomy' ); ?>">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
				<?php if ( $unread > 0 ) : ?>
					<span class="jt-badge-dot"></span>
				<?php endif; ?>
			</a>
			<?php $current_user = wp_get_current_user(); ?>
			<a href="<?php echo esc_url( $base . '/u/' . $current_user->user_login . '/' ); ?>" title="<?php echo esc_attr( $current_user->display_name ); ?>">
				<?php \Jetonomy\Template_Loader::partial( 'avatar', [ 'user_id' => $current_user->ID, 'size' => 30, 'class' => 'jt-avatar-sm' ] ); ?>
			</a>
		<?php else : ?>
			<?php if ( get_option( 'users_can_register' ) ) : ?>
				<a href="<?php echo esc_url( wp_registration_url() ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Register', 'jetonomy' ); ?></a>
			<?php endif; ?>
			<a href="<?php echo esc_url( wp_login_url( $base ) ); ?>" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Log In', 'jetonomy' ); ?></a>
		<?php endif; ?>
	</div>
	<button class="jt-mobile-toggle" aria-label="<?php esc_attr_e( 'Menu', 'jetonomy' ); ?>">
		<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
			<path d="M3 12h18M3 6h18M3 18h18"/>
		</svg>
	</button>
</header>
