<?php
/**
 * New space view (G6).
 *
 * Front-end create-space form. Permission is resolved by the same gate REST
 * POST /spaces uses — Capabilities::can_create_space_frontend(): site admins,
 * plus the WP roles the admin allow-listed in Settings. Anyone else sees a
 * friendly empty state instead of a 403.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings  = get_option( 'jetonomy_settings', array() );
$user_id   = get_current_user_id();
$qualifies = \Jetonomy\Permissions\Capabilities::can_create_space_frontend();

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
		/* translators: %s: the space label the site owner configured, singular or plural (e.g. space, spaces, group, groups). */
		'label' => sprintf( __( 'Create %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
		'url'   => '',
	),
);
?>
<?php \Jetonomy\Template_Loader::partial( 'breadcrumb', array( 'crumbs' => $crumbs ) ); ?>

<div class="jt-two-col">
	<main>
		<h1 class="jt-page-title jt-mb-20">
			<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
			<?php echo esc_html( sprintf( __( 'Create a %s', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?>
		</h1>

		<?php if ( ! $qualifies ) : ?>
			<?php
			\Jetonomy\Template_Loader::partial(
				'empty-state',
				[
					'icon'      => 'lock',
					'icon_size' => 64,
					/* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */
					'message'   => sprintf( __( 'Creating %s is reserved for community administrators.', 'jetonomy' ), \Jetonomy\space_label( true, true ) ),
					'cta_label' => __( 'Back to community', 'jetonomy' ),
					'cta_url'   => $base . '/',
					'tone'      => 'forbidden',
				]
			);
			?>
		<?php else : ?>
			<form id="jt-new-space-form" class="jt-form jt-card" data-wp-on--submit="actions.createSpace" data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>" data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-jt-community-base="<?php echo esc_url( $base ); ?>">
				<div class="jt-form-row">
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
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
						<option value="forum" <?php selected( $default_type, 'forum' ); ?>><?php printf( /* translators: %s: plural reply label. */ esc_html__( 'Forum: discussions and %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?></option>
						<option value="qa" <?php selected( $default_type, 'qa' ); ?>><?php esc_html_e( 'Q&A: questions with accepted answers', 'jetonomy' ); ?></option>
						<option value="ideas" <?php selected( $default_type, 'ideas' ); ?>><?php printf( /* translators: %s: plural member label. */ esc_html__( 'Ideas: feedback voted by %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></option>
						<option value="feed" <?php selected( $default_type, 'feed' ); ?>><?php esc_html_e( 'Feed: short-form posts', 'jetonomy' ); ?></option>
					</select>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
					<select id="jt-ns-visibility" name="visibility" class="jt-input">
						<?php \Jetonomy\space_visibility_options(); ?>
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
					<label for="jt-ns-category"><?php echo esc_html( \Jetonomy\jetonomy_label( 'category' ) ); ?></label>
					<select id="jt-ns-category" name="category_id" class="jt-input">
						<option value="0"><?php printf( /* translators: %s: singular category label. */ esc_html__( 'No %s', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'category', false, true ) ) ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo absint( $cat->id ); ?>">
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php /* translators: 1: singular space label (e.g. space, group); 2: singular category label. */ ?>
					<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'Group this %1$s under a top-level %2$s on the community home.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'category', false, true ) ) ); ?></p>
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
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
					<p class="jt-form-help"><?php echo esc_html( sprintf( __( 'Wide banner shown at the top of the %s page. Optional.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
				</div>

				<div class="jt-form-row">
					<?php
					\Jetonomy\Template_Loader::partial(
						'icon-picker',
						array(
							'field_name'    => 'icon',
							'current_value' => 'users',
							'id_prefix'     => 'jt-ns-icon',
							/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
							'help'          => sprintf( __( 'Pick the icon that matches what this %s is about.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
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
						<?php /* translators: %s: the space label the site owner configured, singular or plural (e.g. space, spaces, group, groups). */ ?>
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
