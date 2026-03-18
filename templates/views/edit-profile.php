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

	<form id="jt-edit-profile" class="jt-new-post-form">
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
			<button type="submit" class="jt-btn jt-btn-fill" id="jt-save-profile"><?php esc_html_e( 'Save Profile', 'jetonomy' ); ?></button>
		</div>
	</form>
</div>

<script>
document.getElementById( 'jt-edit-profile' ).addEventListener( 'submit', function ( e ) {
	e.preventDefault();
	var btn  = document.getElementById( 'jt-save-profile' );
	var form = new FormData( this );
	btn.disabled    = true;
	btn.textContent = '<?php echo esc_js( __( 'Saving...', 'jetonomy' ) ); ?>';

	fetch( '<?php echo esc_url( rest_url( 'jetonomy/v1/users/me' ) ); ?>', {
		method: 'PATCH',
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': '<?php echo wp_create_nonce( 'wp_rest' ); ?>',
		},
		body: JSON.stringify( {
			display_name: form.get( 'display_name' ),
			bio: form.get( 'bio' ),
		} ),
	} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			if ( res.code ) {
				alert( res.message || 'Error' );
			} else {
				window.location.href = '<?php echo esc_url( $base . '/u/' . $current_user->user_login . '/' ); ?>';
			}
		} )
		.catch( function () { alert( 'Network error' ); } )
		.finally( function () {
			btn.disabled    = false;
			btn.textContent = '<?php echo esc_js( __( 'Save Profile', 'jetonomy' ) ); ?>';
		} );
} );
</script>
