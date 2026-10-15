<?php
/**
 * Admin setup wizard view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$base_slug = \Jetonomy\base_slug();
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
	array( 'wp-i18n' ),
	JETONOMY_VERSION,
	true
);
\Jetonomy\script_translations( 'jetonomy-setup-wizard' );
wp_localize_script(
	'jetonomy-setup-wizard',
	'jetonomySetup',
	array(
		'ajaxUrl' => $ajax_url,
		'nonce'   => $nonce,
		'siteUrl' => $site_url,
		// The one label-dependent string; the rest use wp.i18n in the script.
		'i18n'    => array(
			/* translators: 1: category label, lowercase (e.g. "category"). 2: the singular space label the site owner configured (e.g. space, group). */
			'fillCategoryAndSpace' => esc_html( sprintf( __( 'Please fill in the %1$s and %2$s name.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', false, true ), \Jetonomy\space_label( false, true ) ) ),
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
			<?php /* translators: %s: singular space label. */ ?>
			<div class="jt-setup-step-dot__label"><?php echo esc_html( sprintf( __( 'First %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></div>
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
						<span><?php /* translators: 1: reply label, plural, lowercase (e.g. "replies"). 2: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Threaded discussions, %1$s, %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></span>
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
				<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
				<?php echo esc_html( sprintf( __( 'Next: First %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
				<span class="jt-spinner"></span>
			</button>
		</div>
	</div>

	<!-- Step 2: Create First Space -->
	<div class="jt-setup-card jt-step" id="jt-step-2">
		<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
		<h2><?php echo esc_html( sprintf( __( 'Create Your First %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></h2>
		<?php /* translators: 1: singular space label, 2: plural space label. */ ?>
		<p class="jt-setup-card__desc"><?php echo esc_html( sprintf( __( 'Each %1$s is a community section where people can start discussions. You can add more %2$s later.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\space_label( true, true ) ) ); ?></p>

		<div class="jt-setup-error" id="jt-error-2"></div>

		<div class="jt-form-group">
			<label for="jt-cat-name"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Name', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category' ) ) ); ?></label>
			<input type="text" id="jt-cat-name" value="<?php esc_attr_e( 'General', 'jetonomy' ); ?>" placeholder="<?php esc_attr_e( 'General', 'jetonomy' ); ?>">
			<?php /* translators: 1: category label, plural (e.g. "Categories"). 2: the plural space label the site owner configured (e.g. spaces, groups). */ ?>
			<p class="jt-form-hint"><?php echo esc_html( sprintf( __( '%1$s group related %2$s together.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', true ), \Jetonomy\space_label( true, true ) ) ); ?></p>
		</div>

		<div class="jt-form-group">
			<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
			<label for="jt-space-name"><?php echo esc_html( sprintf( __( '%s Name', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></label>
			<input type="text" id="jt-space-name" value="<?php esc_attr_e( 'Community Discussion', 'jetonomy' ); ?>" placeholder="<?php esc_attr_e( 'Community Discussion', 'jetonomy' ); ?>">
		</div>

		<div class="jt-form-group">
			<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
			<label for="jt-space-desc"><?php echo esc_html( sprintf( __( '%s Description', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></label>
			<textarea id="jt-space-desc" placeholder="<?php esc_attr_e( 'A place for community discussions...', 'jetonomy' ); ?>"></textarea>
		</div>

		<div class="jt-setup-divider"><?php esc_html_e( 'or', 'jetonomy' ); ?></div>

		<button type="button" class="jt-btn jt-btn--ghost" id="jt-create-sample">
			<?php jetonomy_echo_icon( 'plus', 16 ); ?>
			<?php esc_html_e( 'Import the demo community instead', 'jetonomy' ); ?>
			<span class="jt-spinner" style="border-color:rgba(100,116,139,.4);border-top-color:var(--jt-admin-text-3, #64748b);"></span>
		</button>

		<div class="jt-btn-row">
			<button type="button" class="jt-btn jt-btn--secondary" id="jt-back-2"><?php esc_html_e( 'Back', 'jetonomy' ); ?></button>
			<button type="button" class="jt-btn jt-btn--primary" id="jt-next-2">
				<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
				<?php echo esc_html( sprintf( __( 'Create %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?>
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
				<?php /* jt-terms-ok: "topics" means subjects here, not the content noun. */ /* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */ ?>
				<li><?php echo esc_html( sprintf( __( 'Create more %s for different topics', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></li>
				<li><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Invite %s to start discussions', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></li>
				<li><?php esc_html_e( 'Import existing content from bbPress or wpForo', 'jetonomy' ); ?></li>
			</ul>
		</div>
	</div>

</div><!-- .jt-setup-wrap -->

<?php wp_footer(); ?>
</body>
</html>
