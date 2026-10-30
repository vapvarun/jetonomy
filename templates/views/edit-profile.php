<?php
/**
 * Edit profile view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

// Auth check is handled by Template_Loader before output.
$current_user = wp_get_current_user();
$profile      = \Jetonomy\Models\UserProfile::find_or_create( $current_user->ID );
$profile_url  = \Jetonomy\get_profile_url( (int) $current_user->ID );

// Cancel returns the member to wherever they opened the editor from — a member
// who arrived from a topic or notification was being force-navigated to their
// profile instead (support ticket 2339920). wp_get_referer() only
// yields a SAME-ORIGIN referer and already excludes the current request, so a
// refresh can't loop Cancel back onto the form and a crafted cross-site referer
// can't bounce the member off-site. When there is no safe place to return to
// (direct link, notification opened in a fresh tab, referer stripped), it falls
// back to the profile — today's behaviour, kept as the graceful default rather
// than a raw history.back() that would strand or off-site the member.
$cancel_url = wp_get_referer();
if ( ! $cancel_url ) {
	$cancel_url = $profile_url;
}

\Jetonomy\Template_Loader::partial(
	'breadcrumb',
	array(
		'crumbs' => array(
			array(
				'label' => \Jetonomy\user_display_name( $current_user ),
				'url'   => $profile_url,
			),
			array(
				'label' => __( 'Edit Profile', 'jetonomy' ),
				'url'   => '',
			),
		),
	)
);
?>

<div class="jt-two-col">
<main>
<div class="jt-narrower">
	<h1 class="jt-post-create-title"><?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?></h1>

	<?php // One long form; these jump to its sections (same look as the space tabs). ?>
	<nav class="jt-space-tabs jt-space-tabs--wrap" aria-label="<?php esc_attr_e( 'Profile sections', 'jetonomy' ); ?>">
		<a class="jt-space-tab" href="#profile-details"><?php esc_html_e( 'Profile', 'jetonomy' ); ?></a>
		<a class="jt-space-tab" href="#notification-preferences"><?php esc_html_e( 'Notifications', 'jetonomy' ); ?></a>
		<a class="jt-space-tab" href="#blocked-members"><?php /* translators: %s: plural member label. */ echo esc_html( sprintf( __( 'Blocked %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></a>
	</nav>

	<form id="jt-edit-profile" class="jt-new-post-form"
			data-wp-interactive="jetonomy"
			data-wp-on--submit="actions.saveProfile"
			data-wp-context="<?php echo esc_attr( (string) wp_json_encode( array( 'profileUrl' => $profile_url ) ) ); ?>">
		<div class="jt-form-group" id="profile-details">
			<label class="jt-label" for="jt-first-name"><?php esc_html_e( 'First Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-first-name" name="first_name" class="jt-input" data-wp-on--input="actions.refreshDisplayNameChoices" value="<?php echo esc_attr( get_user_meta( $current_user->ID, 'first_name', true ) ); ?>">
		</div>

		<div class="jt-form-group">
			<label class="jt-label" for="jt-last-name"><?php esc_html_e( 'Last Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-last-name" name="last_name" class="jt-input" data-wp-on--input="actions.refreshDisplayNameChoices" value="<?php echo esc_attr( get_user_meta( $current_user->ID, 'last_name', true ) ); ?>">
		</div>

		<div class="jt-form-group">
			<label class="jt-label" for="jt-nickname"><?php esc_html_e( 'Nickname', 'jetonomy' ); ?></label>
			<input type="text" id="jt-nickname" name="nickname" class="jt-input" data-wp-on--input="actions.refreshDisplayNameChoices" value="<?php echo esc_attr( get_user_meta( $current_user->ID, 'nickname', true ) ); ?>" required>
			<p class="jt-form-help"><?php esc_html_e( 'Required. Only shown if you pick it below.', 'jetonomy' ); ?></p>
		</div>

		<div class="jt-form-group">
				<?php
				// A select of names the member already owns, not a free-text box: a
				// free field let anyone publish as "Administrator" or copy another
				// member's name, and it wrote through to wp_users.display_name
				// site-wide. Options come from display_name_choices() so the form and
				// PATCH /users/me agree on what is allowed. JS re-builds these live as
				// the three fields above change; this server render is the no-JS state.
				$jt_name_choices = \Jetonomy\display_name_choices( $current_user );
				?>
			<label class="jt-label" for="jt-display-name"><?php esc_html_e( 'Display name publicly as', 'jetonomy' ); ?></label>
			<select id="jt-display-name" name="display_name" class="jt-input" data-jt-display-name required
				data-jt-current="<?php echo esc_attr( $current_user->display_name ); ?>"
				data-jt-login="<?php echo esc_attr( $current_user->user_login ); ?>">
				<?php foreach ( $jt_name_choices as $jt_choice ) : ?>
					<option value="<?php echo esc_attr( $jt_choice ); ?>" <?php selected( $jt_choice, $current_user->display_name ); ?>>
						<?php echo esc_html( $jt_choice ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Bio', 'jetonomy' ); ?></label>
			<textarea name="bio" class="jt-input" rows="4" placeholder="<?php esc_attr_e( 'Tell us about yourself...', 'jetonomy' ); ?>"><?php echo esc_textarea( $profile->bio ?? '' ); ?></textarea>
		</div>

		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Avatar', 'jetonomy' ); ?></label>
			<div class="jt-avatar-row">
				<?php
				$jt_default_avatar = get_avatar_url(
					$current_user->ID,
					array(
						'size'          => 64,
						'force_default' => true,
					)
				);
				?>
				<img id="jt-avatar-preview"
					src="<?php echo esc_url( get_avatar_url( $current_user->ID, array( 'size' => 64 ) ) ); ?>"
					data-default-src="<?php echo esc_url( $jt_default_avatar ); ?>"
					width="64" height="64" class="jt-avatar-round" alt="">
				<div class="jt-avatar-controls">
					<input type="hidden" name="avatar_url" value="<?php echo esc_url( $profile->avatar_url ?? '' ); ?>">
					<?php $jt_can_upload = \Jetonomy\Permissions\Capabilities::can_upload(); ?>
					<?php if ( $jt_can_upload ) : ?>
					<input type="file" id="jt-avatar-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden
						data-wp-on--change="actions.avatarFileSelected">
					<?php endif; ?>
					<div class="jt-avatar-buttons">
						<?php if ( $jt_can_upload ) : ?>
						<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm"
							data-wp-on--click="actions.chooseAvatar"><?php esc_html_e( 'Upload photo', 'jetonomy' ); ?></button>
						<?php endif; ?>
						<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm" id="jt-avatar-remove"
							data-wp-on--click="actions.removeAvatar"
							<?php echo empty( $profile->avatar_url ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove photo', 'jetonomy' ); ?></button>
					</div>
					<?php if ( $jt_can_upload ) : ?>
					<span class="jt-avatar-hint"><?php esc_html_e( 'JPG, PNG, GIF or WebP. Square images look best - you can crop after choosing a photo. Without a photo, your Gravatar is used.', 'jetonomy' ); ?></span>
					<?php else : ?>
					<span class="jt-avatar-hint"><?php esc_html_e( 'Your Gravatar is used as your photo.', 'jetonomy' ); ?></span>
					<?php endif; ?>
				</div>
			</div>

			<dialog id="jt-avatar-crop" class="jt-crop-dialog" aria-labelledby="jt-crop-title">
				<h2 id="jt-crop-title" class="jt-crop-dialog__title"><?php esc_html_e( 'Crop your photo', 'jetonomy' ); ?></h2>
				<p class="jt-crop-dialog__hint"><?php esc_html_e( 'Drag to reposition. Use the slider to zoom.', 'jetonomy' ); ?></p>
				<div class="jt-crop-viewport" id="jt-crop-viewport">
					<img id="jt-crop-image" src="" alt="" draggable="false">
					<div class="jt-crop-ring" aria-hidden="true"></div>
				</div>
				<label class="jt-crop-zoom">
					<span class="jt-sr-only"><?php esc_html_e( 'Zoom', 'jetonomy' ); ?></span>
					<input type="range" id="jt-crop-zoom" min="100" max="300" value="100" step="1"
						aria-label="<?php esc_attr_e( 'Zoom', 'jetonomy' ); ?>"
						data-wp-on--input="actions.avatarCropZoom">
				</label>
				<div class="jt-crop-dialog__actions">
					<button type="button" class="jt-btn jt-btn-ghost"
						data-wp-on--click="actions.avatarCropCancel"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
					<button type="button" class="jt-btn jt-btn-fill"
						data-wp-on--click="actions.avatarCropApply"><?php esc_html_e( 'Crop and upload', 'jetonomy' ); ?></button>
				</div>
			</dialog>
		</div>

		<?php
		// Notification preferences.
		$user_settings = json_decode( $profile->settings ?? '{}', true ) ?: array();
		$notif_prefs   = $user_settings['notifications'] ?? array();
		$global_defs   = get_option( 'jetonomy_settings', array() )['notification_defaults'] ?? array();
		$notif_types   = array(
			/* translators: 1: singular reply label; 2: singular topic label. */
			'reply_to_post'       => sprintf( __( '%1$s to my %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
			/* translators: 1: singular reply label; 2: singular topic label. */
			'reply_to_reply'      => sprintf( __( '%1$s to my %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ), \Jetonomy\jetonomy_label( 'reply', false, true ) ),
			'mention'             => __( '@Mention', 'jetonomy' ),
			/* translators: %s: singular topic label. */
			'vote_on_post'        => sprintf( __( 'Vote on my %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
			'accepted_answer'     => __( 'Accepted answer', 'jetonomy' ),
			'idea_status_changed' => __( 'My idea roadmap status changed', 'jetonomy' ),
			/* translators: 1: singular topic label; 2: singular space label. */
			'new_post_in_sub'     => sprintf( __( 'New %1$s in followed %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\space_label( false, true ) ),
			'badge_earned'        => __( 'Badge earned', 'jetonomy' ),
			/* translators: %s: singular space label, lowercase. */
			'join_request_result' => sprintf( __( 'Answer to my request to join a %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
		);
		if ( \Jetonomy\messaging_active() ) {
			$notif_types['message'] = __( 'Private message', 'jetonomy' );
		}
		?>
		<div class="jt-form-group" id="notification-preferences">
			<label class="jt-label"><?php esc_html_e( 'Notification Preferences', 'jetonomy' ); ?></label>
			<div class="jt-notif-prefs">
				<div class="jt-notif-row jt-notif-header">
					<span></span>
					<span><?php esc_html_e( 'Web', 'jetonomy' ); ?></span>
					<span><?php esc_html_e( 'Email', 'jetonomy' ); ?></span>
				</div>
				<?php
				foreach ( $notif_types as $key => $label ) :
					$web_on   = isset( $notif_prefs[ $key ]['web'] ) ? ! empty( $notif_prefs[ $key ]['web'] ) : (bool) ( $global_defs[ $key ]['web'] ?? true );
					$email_on = isset( $notif_prefs[ $key ]['email'] ) ? ! empty( $notif_prefs[ $key ]['email'] ) : \Jetonomy\Notifications\Notifier::default_email( $key, (array) $global_defs );
					?>
					<div class="jt-notif-row">
						<span><?php echo esc_html( $label ); ?></span>
						<label class="jt-toggle">
							<input type="checkbox" name="notification_preferences[<?php echo esc_attr( $key ); ?>][web]" value="1" <?php checked( $web_on ); ?>>
							<span class="jt-toggle-slider"></span>
						</label>
						<label class="jt-toggle">
							<input type="checkbox" name="notification_preferences[<?php echo esc_attr( $key ); ?>][email]" value="1" <?php checked( $email_on ); ?>>
							<span class="jt-toggle-slider"></span>
						</label>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
			// Master email kill-switch. Honoured by every outbound email
			// (verification reminder today, digests later). Persisted as the
			// jetonomy_email_opt_out user meta the reminder already reads.
			$jt_email_opt_out = (bool) get_user_meta( $current_user->ID, 'jetonomy_email_opt_out', true );
			?>
			<label class="jt-notif-optout">
				<input type="checkbox" name="email_opt_out" value="1" <?php checked( $jt_email_opt_out ); ?>>
				<span><?php esc_html_e( 'Pause all email notifications. You will still see web notifications in the community.', 'jetonomy' ); ?></span>
			</label>
			<?php
			/**
			 * Fires inside the member's notification settings, below the email
			 * pause switch. Jetonomy Pro adds the email digest choice here.
			 *
			 * @since 2.0.3
			 * @param int $user_id The member editing their profile.
			 */
			do_action( 'jetonomy_profile_notification_fields', $current_user->ID );
			?>
		</div>

		<?php
		// Everyone this member has blocked, newest first, 20 a page. The one
		// place to review blocks; each profile's More menu adds them.
		$jt_blocked_per  = 20;
		$jt_blocked_page = max( 1, absint( $_GET['blocked_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination.
		$jt_blocked_rows = \Jetonomy\Models\BlockedUser::list_by_blocker( $current_user->ID, $jt_blocked_per, ( $jt_blocked_page - 1 ) * $jt_blocked_per );
		$jt_blocked_all  = \Jetonomy\Models\BlockedUser::count_by_blocker( $current_user->ID );
		$jt_blocked_ids  = array_map( static fn( $row ) => (int) $row->blocked_id, $jt_blocked_rows );
		cache_users( $jt_blocked_ids );
		\Jetonomy\Avatar::prime( $jt_blocked_ids );
		?>
		<div class="jt-form-group" id="blocked-members">
			<label class="jt-label">
				<?php /* translators: %s: plural member label. */ echo esc_html( sprintf( __( 'Blocked %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
			</label>
			<?php if ( ! $jt_blocked_rows ) : ?>
				<p class="jt-form-help"><?php esc_html_e( "You haven't blocked anyone.", 'jetonomy' ); ?></p>
			<?php else : ?>
				<ul class="jt-blocked-list">
					<?php
					foreach ( $jt_blocked_ids as $jt_blocked_id ) :
						$jt_blocked_user = get_userdata( $jt_blocked_id );
						$jt_blocked_name = $jt_blocked_user ? \Jetonomy\user_display_name( $jt_blocked_user ) : __( 'Deleted user', 'jetonomy' );
						?>
						<li class="jt-blocked-row">
							<?php
							\Jetonomy\Template_Loader::partial(
								'avatar',
								array(
									'user_id' => $jt_blocked_id,
									'size'    => 32,
								)
							);
							?>
							<?php if ( $jt_blocked_user ) : ?>
								<a class="jt-blocked-name" href="<?php echo esc_url( \Jetonomy\get_profile_url( $jt_blocked_id ) ); ?>"><?php echo esc_html( $jt_blocked_name ); ?></a>
							<?php else : ?>
								<span class="jt-blocked-name"><?php echo esc_html( $jt_blocked_name ); ?></span>
							<?php endif; ?>
							<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm"
								data-wp-on--click="actions.unblockUser"
								data-user-id="<?php echo absint( $jt_blocked_id ); ?>"
								aria-label="<?php /* translators: %s: member display name. */ echo esc_attr( sprintf( __( 'Unblock %s', 'jetonomy' ), $jt_blocked_name ) ); ?>"><?php esc_html_e( 'Unblock', 'jetonomy' ); ?></button>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php
				$jt_blocked_pages = (int) ceil( $jt_blocked_all / $jt_blocked_per );
				if ( $jt_blocked_pages > 1 ) :
					?>
					<nav class="jt-blocked-pager" aria-label="<?php esc_attr_e( 'Blocked list pages', 'jetonomy' ); ?>">
						<?php if ( $jt_blocked_page > 1 ) : ?>
							<a class="jt-btn jt-btn-ghost jt-btn-sm" href="<?php echo esc_url( add_query_arg( 'blocked_page', $jt_blocked_page - 1 ) . '#blocked-members' ); ?>"><?php esc_html_e( 'Previous', 'jetonomy' ); ?></a>
						<?php endif; ?>
						<span><?php /* translators: 1: current page, 2: total pages */ echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'jetonomy' ), $jt_blocked_page, $jt_blocked_pages ) ); ?></span>
						<?php if ( $jt_blocked_page < $jt_blocked_pages ) : ?>
							<a class="jt-btn jt-btn-ghost jt-btn-sm" href="<?php echo esc_url( add_query_arg( 'blocked_page', $jt_blocked_page + 1 ) . '#blocked-members' ); ?>"><?php esc_html_e( 'Next', 'jetonomy' ); ?></a>
						<?php endif; ?>
					</nav>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php
		/**
		 * Fires after the standard profile edit fields, before submit.
		 * Pro hooks custom profile fields here.
		 *
		 * @param int $user_id The user ID being edited.
		 */
		do_action( 'jetonomy_profile_edit_fields', $current_user->ID );
		?>

		<div class="jt-form-actions">
			<a href="<?php echo esc_url( $cancel_url ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
			<button type="submit" class="jt-btn jt-btn-fill" data-wp-bind--disabled="state.isSubmitting"><?php esc_html_e( 'Save Profile', 'jetonomy' ); ?></button>
		</div>
	</form>
</div>
</main>
<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
</div>
