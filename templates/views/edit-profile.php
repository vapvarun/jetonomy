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
$base         = \Jetonomy\base_url();

\Jetonomy\Template_Loader::partial(
	'breadcrumb',
	[
		'crumbs' => [
			[
				'label' => $current_user->display_name,
				'url'   => $base . '/u/' . $current_user->user_login . '/',
			],
			[
				'label' => __( 'Edit Profile', 'jetonomy' ),
				'url'   => '',
			],
		],
	]
);
?>

<div class="jt-two-col">
<main>
<div class="jt-narrower">
	<h1 class="jt-post-create-title"><?php esc_html_e( 'Edit Profile', 'jetonomy' ); ?></h1>

	<form id="jt-edit-profile" class="jt-new-post-form"
			data-wp-interactive="jetonomy"
			data-wp-on--submit="actions.saveProfile"
			data-wp-context='<?php echo wp_json_encode( [ 'profileUrl' => $base . '/u/' . $current_user->user_login . '/' ] ); ?>'>
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
			<div class="jt-avatar-row">
				<?php
				$jt_default_avatar = get_avatar_url(
					$current_user->ID,
					[
						'size'          => 64,
						'force_default' => true,
					]
				);
				?>
				<img id="jt-avatar-preview"
					src="<?php echo esc_url( get_avatar_url( $current_user->ID, [ 'size' => 64 ] ) ); ?>"
					data-default-src="<?php echo esc_url( $jt_default_avatar ); ?>"
					width="64" height="64" class="jt-avatar-round" alt="">
				<div class="jt-avatar-controls">
					<input type="hidden" name="avatar_url" value="<?php echo esc_url( $profile->avatar_url ?? '' ); ?>">
					<input type="file" id="jt-avatar-file" accept="image/png,image/jpeg,image/gif,image/webp" hidden
						data-wp-on--change="actions.avatarFileSelected">
					<div class="jt-avatar-buttons">
						<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm"
							data-wp-on--click="actions.chooseAvatar"><?php esc_html_e( 'Upload photo', 'jetonomy' ); ?></button>
						<button type="button" class="jt-btn jt-btn-ghost jt-btn-sm" id="jt-avatar-remove"
							data-wp-on--click="actions.removeAvatar"
							<?php echo empty( $profile->avatar_url ) ? 'hidden' : ''; ?>><?php esc_html_e( 'Remove photo', 'jetonomy' ); ?></button>
					</div>
					<span class="jt-avatar-hint"><?php esc_html_e( 'JPG, PNG, GIF or WebP. Square images look best - you can crop after choosing a photo. Without a photo, your Gravatar is used.', 'jetonomy' ); ?></span>
				</div>
			</div>

			<dialog id="jt-avatar-crop" class="jt-crop-dialog" aria-labelledby="jt-crop-title">
				<h3 id="jt-crop-title" class="jt-crop-dialog__title"><?php esc_html_e( 'Crop your photo', 'jetonomy' ); ?></h3>
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
		$user_settings = json_decode( $profile->settings ?? '{}', true ) ?: [];
		$notif_prefs   = $user_settings['notifications'] ?? [];
		$global_defs   = get_option( 'jetonomy_settings', [] )['notification_defaults'] ?? [];
		$notif_types   = [
			'reply_to_post'       => __( 'Reply to my post', 'jetonomy' ),
			'reply_to_reply'      => __( 'Reply to my reply', 'jetonomy' ),
			'mention'             => __( '@Mention', 'jetonomy' ),
			'vote_on_post'        => __( 'Vote on my post', 'jetonomy' ),
			'accepted_answer'     => __( 'Accepted answer', 'jetonomy' ),
			'idea_status_changed' => __( 'My idea roadmap status changed', 'jetonomy' ),
			'new_post_in_sub'     => __( 'New post in followed space', 'jetonomy' ),
			'badge_earned'        => __( 'Badge earned', 'jetonomy' ),
		];
		?>
		<div class="jt-form-group">
			<label class="jt-label"><?php esc_html_e( 'Notification Preferences', 'jetonomy' ); ?></label>
			<div class="jt-notif-prefs">
				<div class="jt-notif-row jt-notif-header">
					<span></span>
					<span><?php esc_html_e( 'Web', 'jetonomy' ); ?></span>
					<span><?php esc_html_e( 'Email', 'jetonomy' ); ?></span>
				</div>
				<?php
				foreach ( $notif_types as $key => $label ) :
					$web_on   = isset( $notif_prefs[ $key ]['web'] ) ? ! empty( $notif_prefs[ $key ]['web'] ) : ! empty( $global_defs[ $key ]['web'] );
					$email_on = isset( $notif_prefs[ $key ]['email'] ) ? ! empty( $notif_prefs[ $key ]['email'] ) : ! empty( $global_defs[ $key ]['email'] );
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
			<a href="<?php echo esc_url( $base . '/u/' . $current_user->user_login . '/' ); ?>" class="jt-btn jt-btn-ghost"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
			<button type="submit" class="jt-btn jt-btn-fill" data-wp-bind--disabled="state.isSubmitting"><?php esc_html_e( 'Save Profile', 'jetonomy' ); ?></button>
		</div>
	</form>
</div>
</main>
<?php \Jetonomy\Template_Loader::partial( 'sidebar' ); ?>
</div>
