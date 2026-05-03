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
			<form id="jt-new-space-form" class="jt-form jt-card" data-jt-rest-base="<?php echo esc_url( rest_url( 'jetonomy/v1' ) ); ?>" data-jt-rest-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>" data-jt-community-base="<?php echo esc_url( $base ); ?>">
				<div class="jt-form-row">
					<label for="jt-ns-title"><?php esc_html_e( 'Space title', 'jetonomy' ); ?> <span class="jt-required" aria-hidden="true">*</span></label>
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
					<label><?php esc_html_e( 'Cover image', 'jetonomy' ); ?></label>
					<div class="jt-cover-uploader" data-jt-cover>
						<div class="jt-cover-preview" data-jt-cover-preview hidden></div>
						<div class="jt-cover-actions">
							<label class="jt-btn jt-btn-ghost jt-cover-pick">
								<?php esc_html_e( 'Choose image', 'jetonomy' ); ?>
								<input type="file" accept="image/*" data-jt-cover-input hidden>
							</label>
							<button type="button" class="jt-btn jt-btn-ghost jt-cover-remove" data-jt-cover-remove hidden>
								<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
							</button>
							<span class="jt-cover-status" data-jt-cover-status></span>
						</div>
					</div>
					<input type="hidden" name="cover_image" value="" data-jt-cover-value>
					<p class="jt-form-help"><?php esc_html_e( 'Wide banner shown at the top of the space page. Optional.', 'jetonomy' ); ?></p>
				</div>

				<div class="jt-form-row">
					<label for="jt-ns-icon-search"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
					<?php
					// Mirrors space-edit.php; first 16 entries render in 2 rows
					// of 8 (icon-only). 'extended' entries hide until the user
					// types in the search or clicks "Show more icons".
					$icon_palette = array(
						array(
							'name'     => 'users',
							'label'    => __( 'Users', 'jetonomy' ),
							'keywords' => 'users people group community members',
						),
						array(
							'name'     => 'hand',
							'label'    => __( 'Welcome', 'jetonomy' ),
							'keywords' => 'hand wave welcome hi hello onboarding',
						),
						array(
							'name'     => 'megaphone',
							'label'    => __( 'Announcements', 'jetonomy' ),
							'keywords' => 'megaphone announcements news broadcast',
						),
						array(
							'name'     => 'message-circle',
							'label'    => __( 'Discussion', 'jetonomy' ),
							'keywords' => 'message chat discussion talk thread forum',
						),
						array(
							'name'     => 'help-circle',
							'label'    => __( 'Q&A', 'jetonomy' ),
							'keywords' => 'help question qa answer faq support',
						),
						array(
							'name'     => 'lightbulb',
							'label'    => __( 'Ideas', 'jetonomy' ),
							'keywords' => 'lightbulb ideas suggestion brainstorm feedback',
						),
						array(
							'name'     => 'star',
							'label'    => __( 'Tips', 'jetonomy' ),
							'keywords' => 'star tips favorite featured highlight best',
						),
						array(
							'name'     => 'rocket',
							'label'    => __( 'Showcase', 'jetonomy' ),
							'keywords' => 'rocket showcase launch projects releases',
						),
						array(
							'name'     => 'book-open',
							'label'    => __( 'Tutorials', 'jetonomy' ),
							'keywords' => 'book tutorials guide learn docs documentation',
						),
						array(
							'name'     => 'award',
							'label'    => __( 'Achievements', 'jetonomy' ),
							'keywords' => 'award achievements badge medal trophy',
						),
						array(
							'name'     => 'shield',
							'label'    => __( 'Moderation', 'jetonomy' ),
							'keywords' => 'shield moderation security trust safe staff',
						),
						array(
							'name'     => 'pin',
							'label'    => __( 'Pinned', 'jetonomy' ),
							'keywords' => 'pin pinned sticky important highlight',
						),
						array(
							'name'     => 'bookmark',
							'label'    => __( 'Resources', 'jetonomy' ),
							'keywords' => 'bookmark resources save library reference',
						),
						array(
							'name'     => 'home',
							'label'    => __( 'Home', 'jetonomy' ),
							'keywords' => 'home main lobby general default',
						),
						array(
							'name'     => 'hash',
							'label'    => __( 'Topic', 'jetonomy' ),
							'keywords' => 'hash topic channel tag feed',
						),
						array(
							'name'     => 'folder',
							'label'    => __( 'Category', 'jetonomy' ),
							'keywords' => 'folder category group section bucket',
						),
						array(
							'name'     => 'user',
							'label'    => __( 'Profile', 'jetonomy' ),
							'keywords' => 'user profile member account',
							'extended' => true,
						),
						array(
							'name'     => 'settings',
							'label'    => __( 'Settings', 'jetonomy' ),
							'keywords' => 'settings config options gear admin',
							'extended' => true,
						),
						array(
							'name'     => 'bell',
							'label'    => __( 'Alerts', 'jetonomy' ),
							'keywords' => 'bell alerts notifications updates',
							'extended' => true,
						),
						array(
							'name'     => 'flag',
							'label'    => __( 'Reports', 'jetonomy' ),
							'keywords' => 'flag reports complaint mark issue',
							'extended' => true,
						),
						array(
							'name'     => 'image',
							'label'    => __( 'Gallery', 'jetonomy' ),
							'keywords' => 'image gallery photo media picture',
							'extended' => true,
						),
						array(
							'name'     => 'eye',
							'label'    => __( 'Watch', 'jetonomy' ),
							'keywords' => 'eye watch view see preview observe',
							'extended' => true,
						),
						array(
							'name'     => 'lock',
							'label'    => __( 'Private', 'jetonomy' ),
							'keywords' => 'lock private secure restricted closed',
							'extended' => true,
						),
						array(
							'name'     => 'smile-plus',
							'label'    => __( 'Reactions', 'jetonomy' ),
							'keywords' => 'smile reactions emoji feedback emotion',
							'extended' => true,
						),
					);
					?>
					<div class="jt-icon-picker-wrap" data-jt-icon-picker>
						<div class="jt-icon-picker-search">
							<span class="jt-icon-picker-search-icon" aria-hidden="true"><?php jetonomy_echo_icon( 'search', 16 ); ?></span>
							<input type="search" id="jt-ns-icon-search" class="jt-input" data-jt-icon-search placeholder="<?php esc_attr_e( 'Search icons…', 'jetonomy' ); ?>" autocomplete="off">
						</div>
						<div class="jt-icon-picker" role="radiogroup" aria-label="<?php esc_attr_e( 'Choose a space icon', 'jetonomy' ); ?>">
							<?php foreach ( $icon_palette as $entry ) : ?>
								<?php $is_extended = ! empty( $entry['extended'] ); ?>
								<label class="jt-icon-option<?php echo 'users' === $entry['name'] ? ' is-selected' : ''; ?>"
									data-jt-icon-keywords="<?php echo esc_attr( $entry['label'] . ' ' . $entry['keywords'] ); ?>"
									data-jt-icon-extended="<?php echo $is_extended ? '1' : '0'; ?>"
									title="<?php echo esc_attr( $entry['label'] ); ?>"
									<?php echo $is_extended ? 'hidden' : ''; ?>>
									<input type="radio" name="icon" value="<?php echo esc_attr( $entry['name'] ); ?>" <?php checked( $entry['name'], 'users' ); ?> aria-label="<?php echo esc_attr( $entry['label'] ); ?>">
									<span class="jt-icon-option-svg" aria-hidden="true">
										<?php jetonomy_echo_icon( $entry['name'], 22 ); ?>
									</span>
								</label>
							<?php endforeach; ?>
							<p class="jt-icon-picker-empty" data-jt-icon-empty hidden><?php esc_html_e( 'No icons match.', 'jetonomy' ); ?></p>
						</div>
						<button type="button" class="jt-btn jt-btn-ghost jt-icon-picker-more" data-jt-icon-more>
							<?php esc_html_e( 'Show more icons', 'jetonomy' ); ?>
						</button>
					</div>
					<p class="jt-form-help"><?php esc_html_e( 'Pick the icon that matches what this space is about.', 'jetonomy' ); ?></p>
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

		<?php endif; ?>
	</main>

	<?php \Jetonomy\Template_Loader::partial( 'sidebar', array( 'space' => null ) ); ?>
</div>
