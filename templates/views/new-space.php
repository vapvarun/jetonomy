<?php
/**
 * New space view (G6).
 *
 * Front-end create-space form. Permission mirrors REST POST /spaces:
 * site admin (manage_options) + jetonomy_create_spaces cap holders + WP
 * roles the admin allow-listed in Settings. Anyone else sees a friendly
 * empty state instead of a 403.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings      = get_option( 'jetonomy_settings', array() );
$user_id       = get_current_user_id();
$is_site_admin = current_user_can( 'manage_options' );
$has_cap       = current_user_can( 'jetonomy_create_spaces' );
$allowed_roles = isset( $settings['frontend_space_creation_roles'] )
	? array_filter( array_map( 'sanitize_key', (array) $settings['frontend_space_creation_roles'] ) )
	: array();
$wp_user       = wp_get_current_user();
$user_roles    = $wp_user && ! empty( $wp_user->roles ) ? (array) $wp_user->roles : array();
$role_match    = ! empty( $allowed_roles ) && count( array_intersect( $user_roles, $allowed_roles ) ) > 0;
$qualifies     = $is_site_admin || $has_cap || $role_match;

$default_type = sanitize_key( (string) ( $settings['default_space_type'] ?? 'forum' ) );
if ( ! in_array( $default_type, array( 'forum', 'qa', 'ideas', 'feed' ), true ) ) {
	$default_type = 'forum';
}

$base   = \Jetonomy\base_url();
$crumbs = array(
	array(
		'label' => __( 'Create space', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php esc_html_e( 'Create a space', 'jetonomy' ); ?>
		</h1>

		<?php if ( ! $qualifies ) : ?>
			<div class="jt-empty">
				<div class="jt-empty-icon"><?php jetonomy_echo_icon( 'lock', 64 ); ?></div>
				<div class="jt-empty-text">
					<?php esc_html_e( 'Creating spaces is reserved for community administrators.', 'jetonomy' ); ?>
				</div>
				<p class="jt-empty-cta">
					<a class="jt-btn jt-btn-fill" href="<?php echo esc_url( $base . '/' ); ?>">
						<?php esc_html_e( 'Back to community', 'jetonomy' ); ?>
					</a>
				</p>
			</div>
		<?php else : ?>
			<form id="jt-new-space-form" class="jt-form jt-card" data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>" data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-jt-community-base="<?php echo esc_url( $base ); ?>">
				<div class="jt-form-row">
					<label for="jt-ns-title"><?php esc_html_e( 'Space title', 'jetonomy' ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
					<input type="text" id="jt-ns-title" name="title" required maxlength="120" class="jt-input">
					<p class="jt-form-help"><?php esc_html_e( 'Short, descriptive — what people will look for.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
					<textarea id="jt-ns-description" name="description" rows="3" maxlength="280" class="jt-input"></textarea>
					<p class="jt-form-help"><?php esc_html_e( '1–2 sentences. Sets expectations for what belongs here.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
					<select id="jt-ns-type" name="type" class="jt-input">
						<option value="forum" <?php selected( $default_type, 'forum' ); ?>><?php esc_html_e( 'Forum — discussions and replies', 'jetonomy' ); ?></option>
						<option value="qa" <?php selected( $default_type, 'qa' ); ?>><?php esc_html_e( 'Q&A — questions with accepted answers', 'jetonomy' ); ?></option>
						<option value="ideas" <?php selected( $default_type, 'ideas' ); ?>><?php esc_html_e( 'Ideas — feedback voted by members', 'jetonomy' ); ?></option>
						<option value="feed" <?php selected( $default_type, 'feed' ); ?>><?php esc_html_e( 'Feed — short-form posts', 'jetonomy' ); ?></option>
					</select>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
					<input type="text" id="jt-ns-icon" name="icon" maxlength="40" class="jt-input" placeholder="rocket" pattern="[a-z0-9-]+">
					<p class="jt-form-help">
						<?php
						printf(
							/* translators: %s: comma-separated list of available Lucide icon names */
							esc_html__( 'Lucide icon name. Available: %s. See the full set at lucide.dev/icons.', 'jetonomy' ),
							'<code>users</code>, <code>star</code>, <code>rocket</code>, <code>lightbulb</code>, <code>megaphone</code>, <code>help-circle</code>, <code>book-open</code>, <code>hash</code>, <code>folder</code>, <code>hand</code>, <code>message-circle</code>, <code>award</code>, <code>shield</code>'
						);
						?>
					</p>
				</div>

				<div class="jt-form-actions">
					<button type="submit" class="jt-btn jt-btn-fill">
						<?php esc_html_e( 'Create space', 'jetonomy' ); ?>
					</button>
					<a class="jt-btn jt-btn-ghost" href="<?php echo esc_url( $base . '/' ); ?>">
						<?php esc_html_e( 'Cancel', 'jetonomy' ); ?>
					</a>
				</div>
				<div class="jt-form-error" data-jt-error hidden></div>
			</form>

			<script>
			( function () {
				'use strict';
				var form = document.getElementById( 'jt-new-space-form' );
				if ( ! form ) {
					return;
				}
				form.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					var errBox = form.querySelector( '[data-jt-error]' );
					errBox.hidden = true;
					var btn = form.querySelector( 'button[type="submit"]' );
					btn.disabled = true;
					var fd = new FormData( form );
					var payload = {};
					fd.forEach( function ( v, k ) { if ( v ) { payload[ k ] = v; } } );
					fetch( form.dataset.jtRestBase + '/spaces', {
						method: 'POST',
						credentials: 'same-origin',
						headers: {
							'X-WP-Nonce': form.dataset.jtRestNonce,
							'Content-Type': 'application/json'
						},
						body: JSON.stringify( payload )
					} ).then( function ( r ) {
						return r.json().then( function ( body ) { return { ok: r.ok, body: body }; } );
					} ).then( function ( res ) {
						if ( ! res.ok || ! res.body || ! res.body.slug ) {
							errBox.textContent = ( res.body && res.body.message ) || 'Could not create the space. Please try again.';
							errBox.hidden = false;
							btn.disabled = false;
							return;
						}
						window.location.href = form.dataset.jtCommunityBase + '/s/' + res.body.slug + '/';
					} ).catch( function () {
						errBox.textContent = 'Network error. Please try again.';
						errBox.hidden = false;
						btn.disabled = false;
					} );
				} );
			} )();
			</script>
		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
