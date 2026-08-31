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
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the singular label of the item (the configured noun). */
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		]
	);
	return;
}

if ( ! \Jetonomy\Permissions\Permission_Engine::is_space_admin( get_current_user_id(), (int) $space->id ) ) {
	// 1.4.0: respond with 404 (not 403) so the URL existence isn't leaked
	// to non-admins, matching the pattern used by other gated views.
	status_header( 404 );
	\Jetonomy\Template_Loader::partial(
		'empty-state',
		[
			'icon'      => 'empty-search',
			'icon_size' => 48,
			/* translators: %s: the singular label of the item (the configured noun). */
			'message'   => sprintf( __( '%s not found.', 'jetonomy' ), \Jetonomy\space_label() ),
			'tone'      => 'warn',
		]
	);
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

$current_icon   = (string) ( $space->icon ?? '' );
$space_settings = \Jetonomy\Models\Space::get_settings( (int) $space->id );
$categories     = \Jetonomy\Models\Category::list_top_level();
$posts_per_page = isset( $space_settings['posts_per_page'] ) && '' !== $space_settings['posts_per_page'] && (int) $space_settings['posts_per_page'] > 0
	? absint( $space_settings['posts_per_page'] )
	: '';
$prefixes       = ! empty( $space_settings['prefixes'] ) ? (array) $space_settings['prefixes'] : array();
$prefixes_on    = ! empty( $space_settings['enable_prefixes'] );
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<header class="jt-page-head">
			<h1 class="jt-page-title">
				<?php
				/* translators: %s: the label of the item being edited (the configured noun). */
				echo esc_html( sprintf( __( 'Edit %s', 'jetonomy' ), $space->title ) );
				?>
			</h1>
			<p class="jt-page-subtitle">
				<?php esc_html_e( 'Update the basics, swap the cover or icon, and tune who can join.', 'jetonomy' ); ?>
			</p>
		</header>

		<form id="jt-space-edit-form" class="jt-form jt-card"
			data-wp-on--submit="actions.saveSpace"
			data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>"
			data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
			data-jt-space-id="<?php echo (int) $space->id; ?>"
			data-jt-community-base="<?php echo esc_url( $base ); ?>">

			<div class="jt-form-row">
				<?php /* translators: %s: the singular label of the item (the configured noun). */ ?>
				<label for="jt-se-title"><?php echo esc_html( sprintf( __( '%s title', 'jetonomy' ), \Jetonomy\space_label() ) ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
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
							<input type="file" accept="image/*" data-jt-cover-input data-wp-on--change="actions.uploadCover" hidden>
						</label>
						<button type="button" class="jt-btn jt-btn-ghost jt-cover-remove" data-jt-cover-remove data-wp-on--click="actions.removeCover" <?php echo empty( $space->cover_image ) ? 'hidden' : ''; ?>>
							<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
						</button>
						<span class="jt-cover-status" data-jt-cover-status></span>
					</div>
				</div>
				<input type="hidden" name="cover_image" value="<?php echo esc_attr( $space->cover_image ?? '' ); ?>" data-jt-cover-value>
				<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
				<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'Wide banner shown at the top of the %s page. Recommended 1500×400 px.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
			</div>

			<div class="jt-form-row">
				<?php
				\Jetonomy\Template_Loader::partial(
					'icon-picker',
					array(
						'field_name'    => 'icon',
						'current_value' => $current_icon,
						'id_prefix'     => 'jt-se-icon',
						/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
						'help'          => sprintf( __( 'Pick the icon that matches what this %s is about.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					)
				);
				?>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
				<select id="jt-se-type" name="type" class="jt-input">
					<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php printf( /* translators: %s: plural reply label. */ esc_html__( 'Forum: discussions and %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?></option>
					<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A: questions with accepted answers', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php printf( /* translators: %s: plural member label. */ esc_html__( 'Ideas: feedback voted by %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></option>
					<option value="feed" <?php selected( $space->type, 'feed' ); ?>><?php esc_html_e( 'Feed: short-form posts', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
				<select id="jt-se-visibility" name="visibility" class="jt-input">
					<?php \Jetonomy\space_visibility_options( (string) $space->visibility ); ?>
				</select>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-join-policy"><?php esc_html_e( 'Join policy', 'jetonomy' ); ?></label>
				<select id="jt-se-join-policy" name="join_policy" class="jt-input">
					<option value="open" <?php selected( $space->join_policy ?? 'open', 'open' ); ?>><?php esc_html_e( 'Open: anyone can join', 'jetonomy' ); ?></option>
					<option value="approval" <?php selected( $space->join_policy ?? '', 'approval' ); ?>><?php esc_html_e( 'Approval required', 'jetonomy' ); ?></option>
					<option value="invite" <?php selected( $space->join_policy ?? '', 'invite' ); ?>><?php esc_html_e( 'Invite only', 'jetonomy' ); ?></option>
				</select>
			</div>

			<div class="jt-form-row">
				<label class="jt-checkbox-label">
					<input type="checkbox" id="jt-se-require-approval" value="1" <?php checked( ! empty( $space_settings['require_approval'] ) ); ?>>
					<?php esc_html_e( 'New posts require moderator approval before publishing', 'jetonomy' ); ?>
				</label>
				<p class="jt-form-help"><?php esc_html_e( 'Hold new posts in the moderation queue until a moderator approves them.', 'jetonomy' ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-category"><?php echo esc_html( \Jetonomy\jetonomy_label( 'category' ) ); ?></label>
				<select id="jt-se-category" name="category_id" class="jt-input">
					<option value="0"><?php printf( /* translators: %s: singular category label. */ esc_html__( 'No %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'category', false, true ) ) ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>" <?php selected( (int) ( $space->category_id ?? 0 ), (int) $cat->id ); ?>>
							<?php echo esc_html( $cat->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php /* translators: 1: singular space label (e.g. space, group); 2: singular category label. */ ?>
				<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'Group this %1$s under a top-level %2$s on the community home.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'category', false, true ) ) ); ?></p>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-posts-per-page"><?php esc_html_e( 'Posts per page', 'jetonomy' ); ?></label>
				<input
					type="number"
					id="jt-se-posts-per-page"
					name="posts_per_page"
					min="1"
					max="100"
					class="jt-input jt-input-narrow"
					value="<?php echo esc_attr( (string) $posts_per_page ); ?>"
					placeholder="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>">
				<?php /* translators: 1: plural topic label; 2: singular space label (e.g. space, group). */ ?>
				<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'How many %1$s to show per page in this %2$s. Leave blank to use the site default.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\space_label( false, true ) ) ); ?></p>
			</div>

			<div class="jt-form-row jt-prefixes-row">
				<label class="jt-prefix-toggle">
					<input type="checkbox" name="enable_prefixes" value="1" <?php checked( $prefixes_on ); ?> data-jt-prefix-toggle data-wp-on--change="actions.togglePrefixConfig">
					<?php printf( /* translators: %s: singular topic label. */ esc_html__( 'Enable %s prefixes', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?>
				</label>
				<p class="jt-form-help"><?php printf( /* translators: 1: plural member label; 2: plural topic label. */ esc_html__( 'Colored labels %1$s can pin to %2$s, e.g. Bug, Suggestion, Solved.', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ), esc_html( \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></p>

				<div class="jt-prefix-config" data-jt-prefix-config <?php echo $prefixes_on ? '' : 'hidden'; ?>>
					<div class="jt-prefix-list" data-jt-prefix-list>
						<?php
						if ( ! empty( $prefixes ) ) :
							foreach ( $prefixes as $pfx ) :
								?>
								<div class="jt-prefix-row">
									<input type="text" class="jt-input jt-prefix-name" value="<?php echo esc_attr( $pfx['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label', 'jetonomy' ); ?>" maxlength="50">
									<input type="color" class="jt-prefix-color" value="<?php echo esc_attr( $pfx['color'] ?? '#3B82F6' ); ?>">
									<button type="button" class="jt-btn jt-btn-ghost jt-prefix-remove" data-wp-on--click="actions.removePrefixRow" aria-label="<?php esc_attr_e( 'Remove prefix', 'jetonomy' ); ?>">&times;</button>
								</div>
								<?php
							endforeach;
						endif;
						?>
					</div>
					<button type="button" class="jt-btn jt-btn-ghost" data-jt-prefix-add data-wp-on--click="actions.addPrefixRow">
						<?php esc_html_e( '+ Add prefix', 'jetonomy' ); ?>
					</button>
				</div>
			</div>

			<?php
			/**
			 * Fires inside the edit-space form, after the built-in fields. Pro
			 * custom-fields (context = space) renders its inputs here, pre-filled
			 * with the space's saved values; space-edit.js bundles them into the
			 * PATCH request as `custom_fields`.
			 *
			 * @param object $space The space being edited.
			 */
			do_action( 'jetonomy_space_edit_fields', $space );
			?>

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

		<?php
		/*
		 * Danger zone.
		 *
		 * DELETE /spaces/{id} has supported both modes since 1.4.x and enforces
		 * allow_space_admin_purge server-side, but nothing on the frontend ever
		 * called it - the setting's own description promises space admins can
		 * delete their space, and the only way to do it was wp-admin, which a
		 * space admin has no reason to be able to reach (Basecamp 10221373732).
		 *
		 * Two actions, because the route has two modes and they are not the same
		 * decision:
		 *   transfer - the default. The space is archived and handed to a
		 *              successor; every member's topics and replies survive.
		 *              Offered only when a successor exists, since the route
		 *              answers 409 otherwise and a button that cannot work is
		 *              worse than no button.
		 *   purge    - destroys the space and everything in it. Gated on exactly
		 *              what the route gates on, so the UI never renders a
		 *              control that can only 403.
		 */
		$jt_settings  = get_option( 'jetonomy_settings', array() );
		$jt_may_purge = current_user_can( 'manage_options' ) || ! empty( $jt_settings['allow_space_admin_purge'] );
		$jt_successor = \Jetonomy\Models\Space::resolve_successor( (int) $space->id, get_current_user_id() );
		$jt_space_one = \Jetonomy\space_label( false, true );

		/* translators: 1: singular space label the site owner configured; 2: plural member label. */
		$jt_confirm_archive = sprintf( __( 'Archive this %1$s and hand it over? %2$s keep everything they posted.', 'jetonomy' ), $jt_space_one, \Jetonomy\jetonomy_label( 'member', true ) );
		/* translators: %s: the space title the admin must type to confirm. */
		$jt_confirm_purge = sprintf( __( 'This destroys everything in it and cannot be undone. Type %s to confirm.', 'jetonomy' ), (string) $space->title );
		?>
		<?php if ( $jt_may_purge || $jt_successor ) : ?>
			<section class="jt-danger-zone" aria-labelledby="jt-danger-zone-title">
				<h2 class="jt-danger-zone-title" id="jt-danger-zone-title">
					<?php esc_html_e( 'Danger zone', 'jetonomy' ); ?>
				</h2>

				<?php if ( $jt_successor ) : ?>
					<div class="jt-danger-row">
						<div class="jt-danger-copy">
							<h3 class="jt-danger-heading">
								<?php
								/* translators: %s: the singular space label the site owner configured. */
								echo esc_html( sprintf( __( 'Archive and hand over this %s', 'jetonomy' ), $jt_space_one ) );
								?>
							</h3>
							<p class="jt-danger-desc">
								<?php
								/* translators: 1: singular topic label; 2: singular reply label; 3: display name of the member who would take ownership. */
								echo esc_html( sprintf( __( 'Every %1$s and %2$s is kept. Ownership passes to %3$s and the space is archived.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\jetonomy_label( 'reply', false, true ), \Jetonomy\user_display_name( get_userdata( $jt_successor ) ) ) );
								?>
							</p>
						</div>
						<button type="button"
							class="jt-btn jt-btn-ghost jt-space-delete"
							data-wp-on--click="actions.deleteSpace"
							data-space-id="<?php echo absint( $space->id ); ?>"
							data-mode="transfer"
							data-redirect="<?php echo esc_attr( $base . '/s/' . $space->slug . '/' ); ?>"
							data-confirm="<?php echo esc_attr( $jt_confirm_archive ); ?>">
							<?php esc_html_e( 'Archive and hand over', 'jetonomy' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<?php if ( $jt_may_purge ) : ?>
					<div class="jt-danger-row jt-danger-row--critical">
						<div class="jt-danger-copy">
							<h3 class="jt-danger-heading">
								<?php
								/* translators: %s: the singular space label the site owner configured. */
								echo esc_html( sprintf( __( 'Delete this %s permanently', 'jetonomy' ), $jt_space_one ) );
								?>
							</h3>
							<p class="jt-danger-desc">
								<?php printf( /* translators: 1: singular topic label; 2: singular reply label. */ esc_html__( 'Every %1$s, %2$s, and attachment in it is destroyed. This cannot be undone.', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'topic', false, true ) ), esc_html( \Jetonomy\jetonomy_label( 'reply', false, true ) ) ); ?>
							</p>
						</div>
						<?php // Type-to-confirm: no dialog to click past, the name must be typed. ?>
						<button type="button"
							class="jt-btn jt-btn-fill jt-btn-danger jt-space-delete"
							data-wp-on--click="actions.deleteSpace"
							data-space-id="<?php echo absint( $space->id ); ?>"
							data-mode="purge"
							data-space-title="<?php echo esc_attr( (string) $space->title ); ?>"
							data-redirect="<?php echo esc_attr( $base . '/' ); ?>"
							data-confirm="<?php echo esc_attr( $jt_confirm_purge ); ?>">
							<?php jetonomy_echo_icon( 'trash', 14 ); ?>
							<?php esc_html_e( 'Delete permanently', 'jetonomy' ); ?>
						</button>
					</div>
				<?php endif; ?>

				<p class="jt-danger-error" data-jt-delete-error role="alert" hidden></p>
			</section>
		<?php endif; ?>

	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
</div>
