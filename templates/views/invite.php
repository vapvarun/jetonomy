<?php
/**
 * Invite link landing page view.
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

$invite = \Jetonomy\Models\InviteLink::find_by_token( $token );

if ( ! $invite ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Invite link not found.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
	return;
}

if ( ! \Jetonomy\Models\InviteLink::is_valid( $invite ) ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'message' => __( 'This invite link has expired or reached its usage limit.', 'jetonomy' ),
			'tone'    => 'warn',
		]
	);
	return;
}

$space = \Jetonomy\Models\Space::find( (int) $invite->space_id );

if ( ! $space ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'The space for this invite no longer exists.', 'jetonomy' ),
			'tone'      => 'warn',
		]
	);
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
	echo '<meta http-equiv="refresh" content="0; url=' . esc_url( $space_url ) . '">';
	exit;
}

// Accept the invite.
$add_result = \Jetonomy\Models\SpaceMember::add( (int) $invite->space_id, $user_id, 'member' );
if ( is_wp_error( $add_result ) ) {
	echo '<div class="jt-narrow" style="text-align:center;padding:48px 0;"><p>' . esc_html( $add_result->get_error_message() ) . '</p></div>';
	return;
}
\Jetonomy\Models\InviteLink::use_invite( (int) $invite->id );

echo '<meta http-equiv="refresh" content="0; url=' . esc_url( $space_url ) . '">';
exit;
