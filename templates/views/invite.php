<?php
/**
 * Invite link landing page view.
 *
 * Presentation only — the token → membership flow lives in
 * InviteLink::accept() (shared with POST /invite/{token}, audit B).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$token = $data['slug'] ?? '';

if ( empty( $token ) ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Invalid invite link.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

$result = \Jetonomy\Models\InviteLink::accept( $token, get_current_user_id() );

$settings  = get_option( 'jetonomy_settings', [] );
$base_slug = $settings['base_slug'] ?? 'community';

if ( is_wp_error( $result ) ) {
	// Logged-out visitors with a VALID token get the invite panel with a
	// login CTA; every other error renders as an empty state.
	if ( 'jetonomy_login_required' === $result->get_error_code() ) {
		$error_data  = (array) $result->get_error_data();
		$space_title = (string) ( $error_data['space_title'] ?? '' );
		$space_desc  = (string) ( $error_data['space_description'] ?? '' );
		$login_url   = wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) );
		?>
		<div class="jt-narrow" style="text-align:center;padding:48px 0;">
			<h2><?php printf( esc_html__( 'You\'ve been invited to join %s', 'jetonomy' ), '<strong>' . esc_html( $space_title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?></h2>
			<?php if ( '' !== $space_desc ) : ?>
				<p class="jt-text-secondary"><?php echo esc_html( wp_strip_all_tags( $space_desc ) ); ?></p>
			<?php endif; ?>
			<a href="<?php echo esc_url( $login_url ); ?>" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Log in to accept invite', 'jetonomy' ); ?></a>
		</div>
		<?php
		return;
	}

	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => $result->get_error_message(),
			'tone'      => 'warn',
		]
	);
	return;
}

// joined / already_member — straight into the space.
$space_url = home_url( '/' . $base_slug . '/s/' . $result['space']->slug . '/' );
echo '<meta http-equiv="refresh" content="0; url=' . esc_url( $space_url ) . '">';
exit;
