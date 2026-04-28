<?php
/**
 * Front-end space edit (G5).
 *
 * Reuses PATCH /jetonomy/v1/spaces/{id} so a space admin can edit title,
 * description, type, visibility, join policy, icon, and cover image
 * without dropping into wp-admin. Permission gate: must be a space admin.
 *
 * Cover image upload runs through POST /jetonomy/v1/media so the wp-admin
 * `upload_files` cap is not required — non-author space admins can still
 * upload covers, matching the customer-control promise of G5.
 *
 * Icon uses a visual Lucide picker (no text input, no emoji input).
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
	// 1.4.0: respond with 404 (not 403) so the URL existence isn't leaked
	// to non-admins, matching the pattern used by other gated views.
	status_header( 404 );
	echo '<div class="jt-empty"><div class="jt-empty-text">' . esc_html__( 'Space not found.', 'jetonomy' ) . '</div></div>';
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

// Curated palette of Lucide icons that make sense as space icons. We
// only expose icons whose SVG is actually shipped under assets/icons/
// so the picker can never select a missing file.
$icon_palette = array(
	'users'          => __( 'Users', 'jetonomy' ),
	'hand'           => __( 'Welcome', 'jetonomy' ),
	'megaphone'      => __( 'Announcements', 'jetonomy' ),
	'message-circle' => __( 'Discussion', 'jetonomy' ),
	'help-circle'    => __( 'Q&A', 'jetonomy' ),
	'lightbulb'      => __( 'Ideas', 'jetonomy' ),
	'star'           => __( 'Tips', 'jetonomy' ),
	'rocket'         => __( 'Showcase', 'jetonomy' ),
	'book-open'      => __( 'Tutorials', 'jetonomy' ),
	'award'          => __( 'Achievements', 'jetonomy' ),
	'shield'         => __( 'Moderation', 'jetonomy' ),
	'pin'            => __( 'Pinned', 'jetonomy' ),
	'bookmark'       => __( 'Resources', 'jetonomy' ),
	'home'           => __( 'Home', 'jetonomy' ),
	'hash'           => __( 'Topic', 'jetonomy' ),
	'folder'         => __( 'Category', 'jetonomy' ),
);

$current_icon = (string) ( $space->icon ?? '' );
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php
				/* translators: %s: space title */
				echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), $space->title ) );
				?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Update the basics, swap the cover or icon, and tune who can join.', 'jetonomy' ); ?>
			</p>
		</header>

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
				<label><?php esc_html_e( 'Cover image', 'jetonomy' ); ?></label>
				<div class="jt-cover-uploader" data-jt-cover>
					<div class="jt-cover-preview" data-jt-cover-preview <?php echo empty( $space->cover_image ) ? 'hidden' : ''; ?>>
						<?php if ( ! empty( $space->cover_image ) ) : ?>
							<img src="<?php echo esc_url( $space->cover_image ); ?>" alt="">
						<?php endif; ?>
					</div>
					<div class="jt-cover-actions">
						<label class="jt-btn jt-btn-ghost jt-cover-pick">
							<?php esc_html_e( 'Choose image', 'jetonomy' ); ?>
							<input type="file" accept="image/*" data-jt-cover-input hidden>
						</label>
						<button type="button" class="jt-btn jt-btn-ghost jt-cover-remove" data-jt-cover-remove <?php echo empty( $space->cover_image ) ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
						</button>
						<span class="jt-cover-status" data-jt-cover-status></span>
					</div>
				</div>
				<input type="hidden" name="cover_image" value="<?php echo esc_attr( $space->cover_image ?? '' ); ?>" data-jt-cover-value>
				<p class="jt-form-help"><?php esc_html_e( 'Wide banner shown at the top of the space page. Recommended 1500×400 px.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
				<div class="jt-icon-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Choose a space icon', 'jetonomy' ); ?>">
					<?php foreach ( $icon_palette as $name => $label ) : ?>
						<label class="jt-icon-option<?php echo $current_icon === $name ? ' is-selected' : ''; ?>">
							<input type="radio" name="icon" value="<?php echo esc_attr( $name ); ?>" <?php checked( $current_icon, $name ); ?>>
							<span class="jt-icon-option-svg" aria-hidden="true">
								<?php jetonomy_echo_icon( $name, 24 ); ?>
							</span>
							<span class="jt-icon-option-label"><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<p class="jt-form-help"><?php esc_html_e( 'Pick the icon that matches what this space is about.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
				<select id="jt-se-type" name="type" class="jt-input">
					<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php esc_html_e( 'Forum — discussions and replies', 'jetonomy' ); ?></option>
					<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A — questions with accepted answers', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php esc_html_e( 'Ideas — feedback voted by members', 'jetonomy' ); ?></option>
					<option value="feed" <?php selected( $space->type, 'feed' ); ?>><?php esc_html_e( 'Feed — short-form posts', 'jetonomy' ); ?></option>
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

			// Icon picker — radios visually styled; toggle .is-selected on change.
			form.querySelectorAll( '.jt-icon-option input[type=radio]' ).forEach( function ( radio ) {
				radio.addEventListener( 'change', function () {
					form.querySelectorAll( '.jt-icon-option' ).forEach( function ( el ) {
						el.classList.toggle( 'is-selected', el.contains( radio ) && radio.checked );
					} );
				} );
			} );

			// Cover uploader — POSTs to /jetonomy/v1/media, writes returned URL
			// into the hidden cover_image input + renders preview.
			var coverInput  = form.querySelector( '[data-jt-cover-input]' );
			var coverValue  = form.querySelector( '[data-jt-cover-value]' );
			var coverPrev   = form.querySelector( '[data-jt-cover-preview]' );
			var coverRemove = form.querySelector( '[data-jt-cover-remove]' );
			var coverStatus = form.querySelector( '[data-jt-cover-status]' );

			function setPreview( url ) {
				coverValue.value = url;
				if ( url ) {
					coverPrev.hidden = false;
					var img = coverPrev.querySelector( 'img' );
					if ( ! img ) {
						img = document.createElement( 'img' );
						img.alt = '';
						coverPrev.appendChild( img );
					}
					img.src = url;
					coverRemove.hidden = false;
				} else {
					coverPrev.hidden = true;
					coverRemove.hidden = true;
					var existing = coverPrev.querySelector( 'img' );
					if ( existing ) { existing.remove(); }
				}
			}

			coverInput.addEventListener( 'change', function () {
				var file = coverInput.files && coverInput.files[ 0 ];
				if ( ! file ) { return; }
				coverStatus.textContent = 'Uploading…';
				var fd = new FormData();
				fd.append( 'file', file );
				fetch( form.dataset.jtRestBase + '/media', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': form.dataset.jtRestNonce },
					body: fd
				} ).then( function ( r ) {
					return r.json().then( function ( b ) { return { ok: r.ok, body: b }; } );
				} ).then( function ( res ) {
					if ( ! res.ok || ! res.body || ! res.body.url ) {
						coverStatus.textContent = ( res.body && res.body.message ) || 'Upload failed.';
						return;
					}
					setPreview( res.body.url );
					coverStatus.textContent = 'Uploaded.';
					setTimeout( function () { coverStatus.textContent = ''; }, 2000 );
				} ).catch( function () {
					coverStatus.textContent = 'Network error.';
				} );
				// Reset input so re-picking the same file fires change again.
				coverInput.value = '';
			} );

			coverRemove.addEventListener( 'click', function () {
				setPreview( '' );
			} );

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
