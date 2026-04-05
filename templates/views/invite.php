<?php
/**
 * Invite link landing page view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$token = $data['slug'] ?? '';

if ( empty( $token ) ) {
	echo '<div class="jt-empty"><div class="jt-empty-icon">404</div><p>' . esc_html__( 'Invalid invite link.', 'jetonomy' ) . '</p></div>';
	return;
}

$invite = \Jetonomy\Models\InviteLink::find_by_token( $token );

if ( ! $invite ) {
	echo '<div class="jt-empty"><div class="jt-empty-icon">404</div><p>' . esc_html__( 'Invite link not found.', 'jetonomy' ) . '</p></div>';
	return;
}

if ( ! \Jetonomy\Models\InviteLink::is_valid( $invite ) ) {
	echo '<div class="jt-empty"><p>' . esc_html__( 'This invite link has expired or reached its usage limit.', 'jetonomy' ) . '</p></div>';
	return;
}

$space = \Jetonomy\Models\Space::find( (int) $invite->space_id );

if ( ! $space ) {
	echo '<div class="jt-empty"><div class="jt-empty-icon">404</div><p>' . esc_html__( 'The space for this invite no longer exists.', 'jetonomy' ) . '</p></div>';
	return;
}

$settings  = get_option( 'jetonomy_settings', [] );
$base_slug = $settings['base_slug'] ?? 'community';
$space_url = home_url( '/' . $base_slug . '/s/' . $space->slug . '/' );

if ( ! is_user_logged_in() ) {
	$login_url = wp_login_url( home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) ) );
	?>
	<div class="jt-narrow" style="text-align:center;padding:48px 0;">
		<h2><?php printf( esc_html__( 'You\'ve been invited to join %s', 'jetonomy' ), '<strong>' . esc_html( $space->title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?></h2>
		<?php if ( ! empty( $space->description ) ) : ?>
			<p class="jt-text-secondary"><?php echo esc_html( wp_strip_all_tags( $space->description ) ); ?></p>
		<?php endif; ?>
		<a href="<?php echo esc_url( $login_url ); ?>" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Log in to accept invite', 'jetonomy' ); ?></a>
	</div>
	<?php
	return;
}

$user_id = get_current_user_id();

if ( \Jetonomy\Models\SpaceMember::is_member( (int) $invite->space_id, $user_id ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe
	echo '<script>window.location=' . wp_json_encode( $space_url ) . ';</script>';
	exit;
}

// Accept the invite.
$add_result = \Jetonomy\Models\SpaceMember::add( (int) $invite->space_id, $user_id, 'member' );
if ( is_wp_error( $add_result ) ) {
	echo '<div class="jt-narrow" style="text-align:center;padding:48px 0;"><p>' . esc_html( $add_result->get_error_message() ) . '</p></div>';
	return;
}
\Jetonomy\Models\InviteLink::use_invite( (int) $invite->id );

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode is safe
echo '<script>window.location=' . wp_json_encode( $space_url ) . ';</script>';
exit;
