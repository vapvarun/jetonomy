<?php
/**
 * Admin setup wizard view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings  = get_option( 'jetonomy_settings', [] );
$base_slug = $settings['base_slug'] ?? 'community';
$site_url  = trailingslashit( home_url() );
$nonce     = wp_create_nonce( 'jetonomy_setup' );
$ajax_url  = admin_url( 'admin-ajax.php' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php esc_html_e( 'Jetonomy Setup', 'jetonomy' ); ?></title>
<?php
// Standalone page — set screen context and disable admin bar / deprecated hooks.
if ( function_exists( 'set_current_screen' ) ) {
	set_current_screen( 'jetonomy-setup' );
}
show_admin_bar( false );
remove_action( 'wp_head', 'wp_admin_bar_header' );
remove_action( 'wp_print_styles', 'print_emoji_styles' );
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );

wp_enqueue_style(
	'jetonomy-setup-wizard',
	JETONOMY_URL . 'assets/css/setup-wizard.css',
	array(),
	JETONOMY_VERSION
);
wp_enqueue_script(
	'jetonomy-setup-wizard',
	JETONOMY_URL . 'assets/js/setup-wizard.js',
	array(),
	JETONOMY_VERSION,
	true
);
wp_localize_script(
	'jetonomy-setup-wizard',
	'jetonomySetup',
	array(
		'ajaxUrl' => $ajax_url,
		'nonce'   => $nonce,
		'siteUrl' => $site_url,
		'i18n'    => array(
			'slugRequired'         => esc_html__( 'Please enter a community URL slug.', 'jetonomy' ),
			'fillCategoryAndSpace' => esc_html__( 'Please fill in the category and space name.', 'jetonomy' ),
			'genericError'         => esc_html__( 'Something went wrong. Please try again.', 'jetonomy' ),
			'networkError'         => esc_html__( 'Network error. Please try again.', 'jetonomy' ),
		),
	)
);

wp_head();
?>
</head>
<body class="jt-setup-body">

<div class="jt-setup-wrap">

	<!-- Header -->
	<div class="jt-setup-header">
		<div class="jt-setup-logo">
			<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
				<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 14H9V8h2v8zm4 0h-2V8h2v8z"/>
			</svg>
		</div>
		<h1 class="jt-setup-title"><?php esc_html_e( 'Jetonomy Setup', 'jetonomy' ); ?></h1>
		<p class="jt-setup-subtitle"><?php esc_html_e( "Let's get your community ready in a few simple steps.", 'jetonomy' ); ?></p>
	</div>

	<!-- Progress indicator -->
	<div class="jt-setup-progress" id="jt-progress">
		<div class="jt-setup-step-dot jt-setup-step-dot--active" data-step="1">
			<div class="jt-setup-step-dot__circle">1</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'Basics', 'jetonomy' ); ?></div>
		</div>
		<div class="jt-setup-step-connector" id="jt-conn-1"></div>
		<div class="jt-setup-step-dot" data-step="2">
			<div class="jt-setup-step-dot__circle">2</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'First Space', 'jetonomy' ); ?></div>
		</div>
		<div class="jt-setup-step-connector" id="jt-conn-2"></div>
		<div class="jt-setup-step-dot" data-step="3">
			<div class="jt-setup-step-dot__circle">3</div>
			<div class="jt-setup-step-dot__label"><?php esc_html_e( 'Done!', 'jetonomy' ); ?></div>
		</div>
	</div>

	<!-- Step 1: Welcome + Basics -->
	<div class="jt-setup-card jt-step jt-step--active" id="jt-step-1">
		<h2><?php esc_html_e( 'Welcome to Jetonomy', 'jetonomy' ); ?></h2>
		<p class="jt-setup-card__desc"><?php esc_html_e( 'Configure the basics for your community. You can always change these later in Settings.', 'jetonomy' ); ?></p>

		<div class="jt-setup-error" id="jt-error-1"></div>

		<div class="jt-form-group">
			<label for="jt-base-slug"><?php esc_html_e( 'Community URL Slug', 'jetonomy' ); ?></label>
			<input type="text" id="jt-base-slug" value="<?php echo esc_attr( $base_slug ); ?>" placeholder="community">
			<div class="jt-url-preview" id="jt-url-preview">
				<span class="jt-url-preview__icon">
					<?php jetonomy_echo_icon( 'link', 14 ); ?>
				</span>
				<span class="jt-url-preview__url">
					<span id="jt-url-base"><?php echo esc_html( $site_url ); ?></span><span class="jt-url-preview__slug" id="jt-url-slug"><?php echo esc_html( $base_slug ); ?></span><span>/</span>
				</span>
			</div>
			<p class="jt-form-hint"><?php esc_html_e( 'All community pages will be nested under this URL.', 'jetonomy' ); ?></p>
		</div>

		<div class="jt-form-group">
			<label><?php esc_html_e( 'Default Community Type', 'jetonomy' ); ?></label>
			<div class="jt-radio-group">
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-forum" value="forum" checked>
					<label for="jt-type-forum">
						<strong><?php esc_html_e( 'Forum', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Threaded discussions, replies, topics', 'jetonomy' ); ?></span>
					</label>
				</div>
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-qa" value="qa">
					<label for="jt-type-qa">
						<strong><?php esc_html_e( 'Q&amp;A', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Questions, answers, accepted solution', 'jetonomy' ); ?></span>
					</label>
				</div>
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-ideas" value="ideas">
					<label for="jt-type-ideas">
						<strong><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Feature requests, voting, planned/in progress/shipped/declined roadmap lanes', 'jetonomy' ); ?></span>
					</label>
				</div>
				<div class="jt-radio-option">
					<input type="radio" name="jt-default-type" id="jt-type-feed" value="feed">
					<label for="jt-type-feed">
						<strong><?php esc_html_e( 'Show &amp; Tell', 'jetonomy' ); ?></strong>
						<span><?php esc_html_e( 'Short-form feed for status updates, screenshots, and quick wins', 'jetonomy' ); ?></span>
					</label>
				</div>
			</div>
		</div>

		<div class="jt-btn-row">
			<button type="button" class="jt-btn jt-btn--primary" id="jt-next-1">
				<?php esc_html_e( 'Next: First Space', 'jetonomy' ); ?>
				<span class="jt-spinner"></span>
			</button>
		</div>
	</div>

	<!-- Step 2: Create First Space -->
	<div class="jt-setup-card jt-step" id="jt-step-2">
		<h2><?php esc_html_e( 'Create Your First Space', 'jetonomy' ); ?></h2>
		<p class="jt-setup-card__desc"><?php esc_html_e( 'A Space is a community section where people can start discussions. You can add more spaces later.', 'jetonomy' ); ?></p>

		<div class="jt-setup-error" id="jt-error-2"></div>

		<div class="jt-form-group">
			<label for="jt-cat-name"><?php esc_html_e( 'Category Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-cat-name" value="General" placeholder="General">
			<p class="jt-form-hint"><?php esc_html_e( 'Categories group related spaces together.', 'jetonomy' ); ?></p>
		</div>

		<div class="jt-form-group">
			<label for="jt-space-name"><?php esc_html_e( 'Space Name', 'jetonomy' ); ?></label>
			<input type="text" id="jt-space-name" value="Community Discussion" placeholder="Community Discussion">
		</div>

		<div class="jt-form-group">
			<label for="jt-space-desc"><?php esc_html_e( 'Space Description', 'jetonomy' ); ?></label>
			<textarea id="jt-space-desc" placeholder="<?php esc_attr_e( 'A place for community discussions...', 'jetonomy' ); ?>"></textarea>
		</div>

		<div class="jt-setup-divider"><?php esc_html_e( 'or', 'jetonomy' ); ?></div>

		<button type="button" class="jt-btn jt-btn--ghost" id="jt-create-sample">
			<?php jetonomy_echo_icon( 'plus', 16 ); ?>
			<?php esc_html_e( 'Create sample data instead (2 categories, 6 spaces across all four types, ~12 posts)', 'jetonomy' ); ?>
			<span class="jt-spinner" style="border-color:rgba(100,116,139,.4);border-top-color:#64748b;"></span>
		</button>

		<div class="jt-btn-row">
			<button type="button" class="jt-btn jt-btn--secondary" id="jt-back-2"><?php esc_html_e( 'Back', 'jetonomy' ); ?></button>
			<button type="button" class="jt-btn jt-btn--primary" id="jt-next-2">
				<?php esc_html_e( 'Create Space', 'jetonomy' ); ?>
				<span class="jt-spinner"></span>
			</button>
		</div>
	</div>

	<!-- Step 3: Done! -->
	<div class="jt-setup-card jt-step" id="jt-step-3">
		<div class="jt-success-icon">
			<?php jetonomy_echo_icon( 'check-circle', 48 ); ?>
		</div>
		<h2 class="jt-success-heading"><?php esc_html_e( 'Your community is ready!', 'jetonomy' ); ?></h2>
		<p class="jt-success-sub"><?php esc_html_e( 'Everything is set up. Start exploring your new Jetonomy community.', 'jetonomy' ); ?></p>

		<div class="jt-success-cta">
			<a href="<?php echo esc_url( home_url( '/' . $base_slug . '/' ) ); ?>" class="jt-btn jt-btn--primary" id="jt-visit-community" target="_blank">
				<?php jetonomy_echo_icon( 'external-link', 16 ); ?>
				<?php esc_html_e( 'Visit Community', 'jetonomy' ); ?>
			</a>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy' ) ); ?>" class="jt-btn jt-btn--outline">
				<?php jetonomy_echo_icon( 'layout-grid', 16 ); ?>
				<?php esc_html_e( 'Go to Dashboard', 'jetonomy' ); ?>
			</a>
		</div>

		<div class="jt-success-tips">
			<h4><?php esc_html_e( 'Next Steps', 'jetonomy' ); ?></h4>
			<ul>
				<li><?php esc_html_e( 'Customize appearance in Settings (colors, fonts, layout)', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Create more spaces for different topics', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Invite members to start discussions', 'jetonomy' ); ?></li>
				<li><?php esc_html_e( 'Import existing content from bbPress or wpForo', 'jetonomy' ); ?></li>
			</ul>
		</div>
	</div>

</div><!-- .jt-setup-wrap -->

<?php wp_footer(); ?>
</body>
</html>
