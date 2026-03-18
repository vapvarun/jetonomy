<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url() );
	exit;
}

$current_user = wp_get_current_user();
$profile      = \Jetonomy\Models\UserProfile::find_or_create( $current_user->ID );
$base         = home_url( '/community' );

\Jetonomy\Template_Loader::partial(
	'breadcrumb',
	[
		'crumbs' => [
			[ 'label' => $current_user->display_name, 'url' => $base . '/u/' . $current_user->user_login . '/' ],
			[ 'label' => __( 'Edit Profile', 'jetonomy' ), 'url' => '' ],
		],
	]
);
?>

<div class="jt-container" style="max-width:600px;">
	<h1 class="jt-post-create-title"><?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?></h1>

	<form id="jt-edit-profile" class="jt-new-post-form"
	      data-wp-interactive="jetonomy"
	      data-wp-on--submit="actions.saveProfile"
	      data-wp-context='<?php echo wp_json_encode( [ "profileUrl" => $base . "/u/" . $current_user->user_login . "/" ] ); ?>'>
		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Display Name', 'jetonomy' ); ?></label>
			<input type="text" name="display_name" class="jt-input" value="<?php echo esc_attr( $current_user->display_name ); ?>" required>
		</div>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Bio', 'jetonomy' ); ?></label>
			<textarea name="bio" class="jt-input" rows="4" placeholder="<?php esc_attr_e( 'Tell us about yourself...', 'jetonomy' ); ?>"><?php echo esc_textarea( $profile->bio ?? '' ); ?></textarea>
		</div>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Avatar', 'jetonomy' ); ?></label>
			<div style="display:flex;align-items:center;gap:12px;">
				<img src="<?php echo esc_url( get_avatar_url( $current_user->ID, [ 'size' => 64 ] ) ); ?>" width="64" height="64" style="border-radius:50%;">
				<span style="font-size:13px;color:var(--jt-text-tertiary);">
					<?php
					/* translators: %s: link to Gravatar */
					printf( esc_html__( 'Change your avatar at %s', 'jetonomy' ), '<a href="https://gravatar.com" target="_blank" rel="noopener noreferrer">Gravatar.com</a>' );
					?>
				</span>
			</div>
		</div>

		<div class="jt-form-actions">
			<a href="<?php echo esc_url( $base . '/u/' . $current_user->user_login . '/' ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
			<button type="submit" class="jt-btn jt-btn-fill" data-wp-bind--disabled="state.isSubmitting"><?php esc_html_e( 'Save Profile', 'jetonomy' ); ?></button>
		</div>
	</form>
</div>
