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
				/* translators: %s: space title */
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
						'help'          => sprintf( __( 'Pick the icon that matches what this %s is about.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					)
				);
				?>
			</div>

			<div class="jt-form-row">
				<label for="jt-se-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
				<select id="jt-se-type" name="type" class="jt-input">
					<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php esc_html_e( 'Forum: discussions and replies', 'jetonomy' ); ?></option>
					<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A: questions with accepted answers', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php esc_html_e( 'Ideas: feedback voted by members', 'jetonomy' ); ?></option>
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
				<label for="jt-se-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></label>
				<select id="jt-se-category" name="category_id" class="jt-input">
					<option value="0"><?php esc_html_e( 'No category', 'jetonomy' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>" <?php selected( (int) ( $space->category_id ?? 0 ), (int) $cat->id ); ?>>
							<?php echo esc_html( $cat->name ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'Group this %s under a top-level category on the community home.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
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
				<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'How many topics to show per page in this %s. Leave blank to use the site default.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
			</div>

			<div class="jt-form-row jt-prefixes-row">
				<label class="jt-prefix-toggle">
					<input type="checkbox" name="enable_prefixes" value="1" <?php checked( $prefixes_on ); ?> data-jt-prefix-toggle data-wp-on--change="actions.togglePrefixConfig">
					<?php esc_html_e( 'Enable topic prefixes', 'jetonomy' ); ?>
				</label>
				<p class="jt-form-help"><?php esc_html_e( 'Colored labels members can pin to topics, e.g. Bug, Suggestion, Solved.', 'jetonomy' ); ?></p>

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

	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => $space ) ); ?>
</div>
