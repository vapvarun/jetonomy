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

// Top-level categories for the Category select — mirrors the edit form (G5) so
// the create form (G6) exposes the same space options the backend accepts.
$categories = \Jetonomy\Models\Category::list_top_level();

$base   = \Jetonomy\base_url();
$crumbs = array(
	array(
		'label' => sprintf( __( 'Create %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php echo esc_html( sprintf( __( 'Create a %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
		</h1>

		<?php if ( ! $qualifies ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'      => 'lock',
					'icon_size' => 64,
					'message'   => __( 'Creating spaces is reserved for community administrators.', 'jetonomy' ),
					'cta_label' => __( 'Back to community', 'jetonomy' ),
					'cta_url'   => $base . '/',
					'tone'      => 'forbidden',
				]
			);
			?>
		<?php else : ?>
			<form id="jt-new-space-form" class="jt-form jt-card" data-wp-on--submit="actions.createSpace" data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>" data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-jt-community-base="<?php echo esc_url( $base ); ?>">
				<div class="jt-form-row">
					<label for="jt-ns-title"><?php echo esc_html( sprintf( __( '%s title', 'jetonomy' ), \Jetonomy\space_label() ) ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
					<input type="text" id="jt-ns-title" name="title" required maxlength="120" class="jt-input">
					<p class="jt-form-help"><?php esc_html_e( 'Short, descriptive. What people will look for.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
					<textarea id="jt-ns-description" name="description" rows="3" maxlength="280" class="jt-input"></textarea>
					<p class="jt-form-help"><?php esc_html_e( '1–2 sentences. Sets expectations for what belongs here.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label>
					<select id="jt-ns-type" name="type" class="jt-input">
						<option value="forum" <?php selected( $default_type, 'forum' ); ?>><?php esc_html_e( 'Forum: discussions and replies', 'jetonomy' ); ?></option>
						<option value="qa" <?php selected( $default_type, 'qa' ); ?>><?php esc_html_e( 'Q&A: questions with accepted answers', 'jetonomy' ); ?></option>
						<option value="ideas" <?php selected( $default_type, 'ideas' ); ?>><?php esc_html_e( 'Ideas: feedback voted by members', 'jetonomy' ); ?></option>
						<option value="feed" <?php selected( $default_type, 'feed' ); ?>><?php esc_html_e( 'Feed: short-form posts', 'jetonomy' ); ?></option>
					</select>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
					<select id="jt-ns-visibility" name="visibility" class="jt-input">
						<option value="public"><?php esc_html_e( 'Public: anyone can read', 'jetonomy' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private: members only', 'jetonomy' ); ?></option>
					</select>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-join-policy"><?php esc_html_e( 'Join policy', 'jetonomy' ); ?></label>
					<select id="jt-ns-join-policy" name="join_policy" class="jt-input">
						<option value="open"><?php esc_html_e( 'Open: anyone can join', 'jetonomy' ); ?></option>
						<option value="approval"><?php esc_html_e( 'Approval required', 'jetonomy' ); ?></option>
						<option value="invite"><?php esc_html_e( 'Invite only', 'jetonomy' ); ?></option>
					</select>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></label>
					<select id="jt-ns-category" name="category_id" class="jt-input">
						<option value="0"><?php esc_html_e( 'No category', 'jetonomy' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo absint( $cat->id ); ?>">
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="jt-form-help"><?php esc_html_e( 'Group this space under a top-level category on the community home.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label><?php esc_html_e( 'Cover image', 'jetonomy' ); ?></label>
					<div class="jt-cover-uploader" data-jt-cover>
						<div class="jt-cover-preview" data-jt-cover-preview hidden></div>
						<div class="jt-cover-actions">
							<label class="jt-btn jt-btn-ghost jt-cover-pick">
								<?php esc_html_e( 'Choose image', 'jetonomy' ); ?>
								<input type="file" accept="image/*" data-jt-cover-input data-wp-on--change="actions.uploadCover" hidden>
							</label>
							<button type="button" class="jt-btn jt-btn-ghost jt-cover-remove" data-jt-cover-remove data-wp-on--click="actions.removeCover" hidden>
								<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
							</button>
							<span class="jt-cover-status" data-jt-cover-status></span>
						</div>
					</div>
					<input type="hidden" name="cover_image" value="" data-jt-cover-value>
					<p class="jt-form-help"><?php esc_html_e( 'Wide banner shown at the top of the space page. Optional.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<?php
					\Jetonomy\Template_Loader::partial(
						'icon-picker',
						array(
							'field_name'    => 'icon',
							'current_value' => 'users',
							'id_prefix'     => 'jt-ns-icon',
							'help'          => __( 'Pick the icon that matches what this space is about.', 'jetonomy' ),
						)
					);
					?>
				</div>

				<?php
				/**
				 * Fires inside the create-space form, after the built-in fields.
				 * Pro custom-fields (context = space) renders its inputs here;
				 * new-space.js bundles them into the create request as
				 * `custom_fields`. Mirrors jetonomy_new_post_fields.
				 */
				do_action( 'jetonomy_new_space_fields' );
				?>

				<div class="jt-form-actions">
					<button type="submit" class="jt-btn jt-btn-fill">
						<?php echo esc_html( sprintf( __( 'Create %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
					</button>
					<a class="jt-btn jt-btn-ghost" href="<?php echo esc_url( $base . '/' ); ?>">
						<?php esc_html_e( 'Cancel', 'jetonomy' ); ?>
					</a>
				</div>
				<div class="jt-form-error" data-jt-error hidden></div>
			</form>

		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
