<?php
/**
 * Front-end space edit (G5).
 *
 * Reuses PATCH /jetonomy/v1/spaces/{id} so a space admin can edit title /
 * description / visibility / join policy / type / icon without dropping
 * into wp-admin. Permission gate: must be a space admin (Permission_Engine
 * short-circuits on manage_options for site admins).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$slug  = (string) ( $data['slug'] ?? '' );
$space = '' !== $slug ? \Jetonomy\Models\Space::find_by_slug( $slug ) : null;

if ( ! $space ) {
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div>';
	return;
}

if ( ! \Jetonomy\Permissions\Permission_Engine::is_space_admin( get_current_user_id(), (int) $space->id ) ) {
	status_header( 403 );
	echo '<div class="jt-empty"><div class="jt-empty-text">' . esc_html__( 'You do not have permission to edit this space.', 'jetonomy' ) . '</div></div>';
	return;
}

$base   = \Jetonomy\base_url();
$crumbs = array(
	array(
		'label' => $space->title,
		'url'   => $base . '/s/' . $space->slug . '/',
	),
	array(
		'label' => __( 'Edit', 'jetonomy' ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php
			/* translators: %s: space title */
			echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), $space->title ) );
			?>
		</h1>

		<form id="jt-space-edit-form" class="jt-form jt-card"
			data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>"
			data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-jt-space-id="<?php echo (int) $space->id; ?>"
			data-jt-community-base="<?php echo esc_url( $base ); ?>">

			<div class="jt-form-row">
				<label for="jt-se-title"><?php esc_html_e( 'Space title', 'jetonomy' ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
				<input type="text" id="jt-se-title" name="title" required maxlength="120" class="jt-input" value="<?php echo esc_attr( $space->title ); ?>">
			</div>

			<div class="jt-form-row">
				<label for="jt-se-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
				<textarea id="jt-se-description" name="description" rows="3" maxlength="280" class="jt-input"><?php echo esc_textarea( $space->description ?? '' ); ?></textarea>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
				<select id="jt-se-type" name="type" class="jt-input">
					<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
					<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
					<option value="feed" <?php selected( $space->type, 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
				<select id="jt-se-visibility" name="visibility" class="jt-input">
					<option value="public" <?php selected( $space->visibility, 'public' ); ?>><?php esc_html_e( 'Public — anyone can read', 'jetonomy' ); ?></option>
					<option value="private" <?php selected( $space->visibility, 'private' ); ?>><?php esc_html_e( 'Private — members only', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-join-policy"><?php esc_html_e( 'Join policy', 'jetonomy' ); ?></label>
				<select id="jt-se-join-policy" name="join_policy" class="jt-input">
					<option value="open" <?php selected( $space->join_policy ?? 'open', 'open' ); ?>><?php esc_html_e( 'Open — anyone can join', 'jetonomy' ); ?></option>
					<option value="approval" <?php selected( $space->join_policy ?? '', 'approval' ); ?>><?php esc_html_e( 'Approval required', 'jetonomy' ); ?></option>
					<option value="invite" <?php selected( $space->join_policy ?? '', 'invite' ); ?>><?php esc_html_e( 'Invite only', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
				<input type="text" id="jt-se-icon" name="icon" maxlength="40" class="jt-input" value="<?php echo esc_attr( $space->icon ?? '' ); ?>" placeholder="🚀">
				<p class="jt-form-help"><?php esc_html_e( 'Emoji or dashicon slug.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-actions">
				<button type="submit" class="jt-btn jt-btn-fill">
					<?php esc_html_e( 'Save changes', 'jetonomy' ); ?>
				</button>
				<a class="jt-btn jt-btn-ghost" href="<?php echo esc_url( $base . '/s/' . $space->slug . '/' ); ?>">
					<?php esc_html_e( 'Cancel', 'jetonomy' ); ?>
				</a>
				<span class="jt-form-saved" data-jt-saved hidden>
					<?php esc_html_e( 'Saved.', 'jetonomy' ); ?>
				</span>
			</div>
			<div class="jt-form-error" data-jt-error hidden></div>
		</form>

		<script>
		( function () {
			'use strict';
			var form = document.getElementById( 'jt-space-edit-form' );
			if ( ! form ) {
				return;
			}
			form.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				var errBox = form.querySelector( '[data-jt-error]' );
				var savedBox = form.querySelector( '[data-jt-saved]' );
				errBox.hidden = true;
				savedBox.hidden = true;
				var btn = form.querySelector( 'button[type="submit"]' );
				btn.disabled = true;
				var fd = new FormData( form );
				var payload = {};
				fd.forEach( function ( v, k ) { payload[ k ] = v; } );
				fetch( form.dataset.jtRestBase + '/spaces/' + form.dataset.jtSpaceId, {
					method: 'PATCH',
					credentials: 'same-origin',
					headers: {
						'X-WP-Nonce': form.dataset.jtRestNonce,
						'Content-Type': 'application/json'
					},
					body: JSON.stringify( payload )
				} ).then( function ( r ) {
					return r.json().then( function ( body ) { return { ok: r.ok, body: body }; } );
				} ).then( function ( res ) {
					btn.disabled = false;
					if ( ! res.ok ) {
						errBox.textContent = ( res.body && res.body.message ) || 'Could not save changes.';
						errBox.hidden = false;
						return;
					}
					savedBox.hidden = false;
					setTimeout( function () { savedBox.hidden = true; }, 2500 );
				} ).catch( function () {
					btn.disabled = false;
					errBox.textContent = 'Network error. Please try again.';
					errBox.hidden = false;
				} );
			} );
		} )();
		</script>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
</div>
