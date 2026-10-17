<?php
/**
 * Invite link landing page view.
 *
 * Presentation only — the token → membership flow lives in
 * InviteLink::accept() (shared with POST /invite/{token}, audit B).
 *
 * Opening the link only checks it and shows a Join button; the join itself
 * is the button's nonce-checked POST, so a link preview, prefetch or a
 * cross-site request can never add anyone to a space.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$token = $data['slug'] ?? '';

if ( empty( $token ) ) {
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => __( 'Invalid invite link.', 'jetonomy' ),
			'tone'      => 'warn',
		)
	);
	return;
}

$jt_confirmed = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
	&& isset( $_POST['jt_invite_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['jt_invite_nonce'] ) ), 'jetonomy_accept_invite_' . $token );
$result       = \Jetonomy\Models\InviteLink::accept( $token, get_current_user_id(), $jt_confirmed );

if ( is_wp_error( $result ) ) {
	// Logged-out visitors with a VALID token get the invite panel with a
	// login CTA; every other error renders as an empty state.
	if ( 'jetonomy_login_required' === $result->get_error_code() ) {
		$error_data  = (array) $result->get_error_data();
		$space_title = (string) ( $error_data['space_title'] ?? '' );
		$space_desc  = (string) ( $error_data['space_description'] ?? '' );
		$invite_url  = home_url( esc_url_raw( wp_unslash( isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/' ) ) );
		$login_url   = wp_login_url( $invite_url );
		// Most invitees have no account yet: when the site allows sign-up,
		// offer it first and bring them back here to accept the invite.
		$signup_url = get_option( 'users_can_register' ) ? add_query_arg( 'redirect_to', rawurlencode( $invite_url ), wp_registration_url() ) : '';
		?>
		<div class="jt-narrow jt-invite-landing">
			<?php /* translators: %s: space title. */ ?>
			<h2><?php printf( esc_html__( 'You\'ve been invited to join %s', 'jetonomy' ), '<strong dir="auto">' . esc_html( $space_title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?></h2>
			<?php if ( '' !== $space_desc ) : ?>
				<p class="jt-text-secondary"><?php echo esc_html( wp_strip_all_tags( $space_desc ) ); ?></p>
			<?php endif; ?>
			<div class="jt-invite-actions">
				<?php if ( $signup_url ) : ?>
					<a href="<?php echo esc_url( $signup_url ); ?>" class="jt-btn jt-btn-fill"><?php esc_html_e( 'Create free account', 'jetonomy' ); ?></a>
				<?php endif; ?>
				<a href="<?php echo esc_url( $login_url ); ?>" class="jt-btn <?php echo $signup_url ? 'jt-btn-ghost' : 'jt-btn-fill'; ?>"><?php esc_html_e( 'Log in to accept invite', 'jetonomy' ); ?></a>
			</div>
		</div>
		<?php
		return;
	}

	\Jetonomy\Template_Loader::partial(
		'empty-state',
		array(
			'icon'      => 'empty-search',
			'icon_size' => 48,
			'message'   => $result->get_error_message(),
			'tone'      => 'warn',
		)
	);
	return;
}

if ( 'ready' === $result['status'] ) {
	?>
	<div class="jt-narrow jt-invite-landing">
		<?php /* translators: %s: space title. */ ?>
		<h2><?php printf( esc_html__( 'You\'ve been invited to join %s', 'jetonomy' ), '<strong dir="auto">' . esc_html( (string) $result['space']->title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?></h2>
		<?php if ( ! empty( $result['space']->description ) ) : ?>
			<p class="jt-text-secondary"><?php echo esc_html( wp_strip_all_tags( (string) $result['space']->description ) ); ?></p>
		<?php endif; ?>
		<form method="post" class="jt-invite-actions">
			<?php wp_nonce_field( 'jetonomy_accept_invite_' . $token, 'jt_invite_nonce' ); ?>
			<button type="submit" class="jt-btn jt-btn-fill">
				<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
				<?php echo esc_html( sprintf( __( 'Join %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
			</button>
		</form>
	</div>
	<?php
	return;
}

$space_url = \Jetonomy\route_url( 'space', $result['space']->slug );

// Already a member: say so rather than silently bouncing them into the space.
if ( 'already_member' === $result['status'] ) {
	?>
	<div class="jt-narrow jt-invite-landing">
		<?php /* translators: 1: the singular member label, lowercase; 2: space title. */ ?>
		<p><?php printf( esc_html__( 'You are already a %1$s of %2$s.', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', false, true ) ), '<strong dir="auto">' . esc_html( (string) $result['space']->title ) . '</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- %s contains esc_html() output wrapped in static tag. ?></p>
		<div class="jt-invite-actions">
			<?php /* translators: %s: the owner-configured singular space label. */ ?>
			<a href="<?php echo esc_url( $space_url ); ?>" class="jt-btn jt-btn-fill"><?php echo esc_html( sprintf( __( 'Go to %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></a>
		</div>
	</div>
	<?php
	return;
}

// Just joined: straight into the space.
echo '<meta http-equiv="refresh" content="0; url=' . esc_url( $space_url ) . '">';
exit;
