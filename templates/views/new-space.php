<?php
/**
 * New space view (G6).
 *
 * Front-end create-space form for qualified community members. Permission
 * mirrors the REST POST /spaces gate: jetonomy_create_spaces cap OR
 * (admin toggle ON AND trust level >= configured threshold). When the
 * viewer doesn't qualify, an empty-state explains why instead of a 403.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings   = get_option( 'jetonomy_settings', array() );
$allow_fe   = ! empty( $settings['allow_frontend_space_creation'] );
$min_trust  = isset( $settings['min_trust_level_to_create_space'] ) ? (int) $settings['min_trust_level_to_create_space'] : 2;
$user_id    = get_current_user_id();
$has_cap    = current_user_can( 'jetonomy_create_spaces' );
$profile    = \Jetonomy\Models\UserProfile::find_by_user( $user_id );
$user_trust = $profile ? (int) $profile->trust_level : 0;
$qualifies  = $has_cap || ( $allow_fe && $user_trust >= $min_trust );

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
					<?php if ( ! $allow_fe ) : ?>
						<?php esc_html_e( 'Front-end space creation is disabled on this community.', 'jetonomy' ); ?>
					<?php else : ?>
						<?php
						/* translators: 1: required trust level, 2: viewer's trust level */
						echo esc_html( sprintf( __( 'Creating a space requires trust level %1$d. Yours is %2$d — keep contributing and you will get there.', 'jetonomy' ), $min_trust, $user_trust ) );
						?>
					<?php endif; ?>
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
					<label for="jt-ns-icon"><?php esc_html_e( 'Icon (emoji or dashicon)', 'jetonomy' ); ?></label>
					<input type="text" id="jt-ns-icon" name="icon" maxlength="40" class="jt-input" placeholder="🚀">
					<p class="jt-form-help"><?php esc_html_e( 'A single emoji works. For dashicons, use the slug, e.g. dashicons-forms.', 'jetonomy' ); ?></p>
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
