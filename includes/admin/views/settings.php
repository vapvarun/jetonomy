<?php
/**
 * Admin settings view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$active_tab   = sanitize_text_field( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$settings_url = admin_url( 'admin.php?page=jetonomy-settings' );
?>
<div class="wrap jetonomy-admin">
	<?php
	// Screen-reader page heading + the WP notice anchor. Without `.wp-header-end`
	// WordPress core relocates admin notices to just after the first <h2> it
	// finds, which on the Integrations tab is the family-header title — so the
	// "settings saved" / companion notice rendered inside `.jt-fam-header__body`.
	// Anchoring here keeps every tab's notices at the top of the page.
	?>
	<h1 class="screen-reader-text"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( '%s Settings', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></h1>
	<hr class="wp-header-end">
	<?php
	ob_start();
	/**
	 * Fires while the settings tab bar renders, so extensions can print additional tab links.
	 *
	 * @since 1.0.0
	 *
	 * @param string $active_tab Slug of the active settings tab.
	 */
	do_action( 'jetonomy_admin_settings_tabs', $active_tab );
	$advanced_tabs_html = ob_get_clean();

	// Pre-buffer Pro/extension tab content so notices can be hoisted above the layout.
	$jt_primary_tabs = array( 'general', 'permissions', 'email', 'appearance', 'seo', 'antispam', 'free-vs-pro' );
	$jt_ext_html     = '';
	$jt_ext_notices  = '';
	if ( ! in_array( $active_tab, $jt_primary_tabs, true ) && 'license' !== $active_tab ) {
		ob_start();
		/**
		 * Fires to render the content of the active settings tab, for core tabs and extension tabs alike.
		 *
		 * @since 1.0.0
		 *
		 * @param string $active_tab Slug of the active settings tab.
		 */
		do_action( 'jetonomy_admin_settings_tab_content', $active_tab );
		$jt_ext_raw = ob_get_clean();
		if ( $jt_ext_raw ) {
			$jt_ext_html = preg_replace_callback(
				'/<div[^>]+class="[^"]*\\bnotice\\b[^"]*"[^>]*>.*?<\/div>/si',
				function ( $m ) use ( &$jt_ext_notices ) {
					$jt_ext_notices .= $m[0];
					return '';
				},
				$jt_ext_raw
			);
		}
	}

	$tab_icons  = array(
		'general'     => 'dashicons-admin-settings',
		'permissions' => 'dashicons-shield',
		'email'       => 'dashicons-email-alt',
		'appearance'  => 'dashicons-admin-appearance',
		'seo'         => 'dashicons-search',
		'antispam'    => 'dashicons-lock',
	);
	$tab_labels = array(
		'general'     => __( 'General', 'jetonomy' ),
		'permissions' => __( 'Permissions', 'jetonomy' ),
		'email'       => __( 'Email', 'jetonomy' ),
		'appearance'  => __( 'Appearance', 'jetonomy' ),
		'seo'         => __( 'SEO', 'jetonomy' ),
		'antispam'    => __( 'Anti-Spam', 'jetonomy' ),
	);

	// Integrations tab appears only when BuddyPress Groups is active — the
	// only context where the broadcast / comment-bridge toggles apply.
	$jt_bp_active = function_exists( 'bp_is_active' ) && bp_is_active( 'groups' );
	// Integrations tab is always available (Wbcom stack companions); the
	// BuddyPress broadcast / comment-bridge toggles inside it render only when
	// BP Groups is active.
	$tab_icons['integrations']  = 'dashicons-networking';
	$tab_labels['integrations'] = __( 'Integrations', 'jetonomy' );
	?>

	<?php settings_errors(); ?>
	<?php
	if ( $jt_ext_notices ) {
		echo $jt_ext_notices;} // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by each extension
	?>

	<div class="jt-settings-layout">

		<aside class="jt-settings-sidebar">
			<div class="jt-settings-sidebar-brand">
				<img class="jt-settings-brand-icon" src="<?php echo esc_url( JETONOMY_URL . 'assets/images/jetonomy-mark.svg' ); ?>" width="36" height="36" alt="" aria-hidden="true" />
				<div class="jt-settings-brand-text">
					<p class="jt-settings-brand-name"><?php echo esc_html( \Jetonomy\Admin\Admin::brand_name() ); ?></p>
					<p class="jt-settings-brand-sub"><?php esc_html_e( 'Settings', 'jetonomy' ); ?></p>
				</div>
			</div>
			<nav class="jt-settings-sidebar-nav" aria-label="<?php esc_attr_e( 'Settings navigation', 'jetonomy' ); ?>">
				<?php
				foreach ( $tab_labels as $slug => $label ) {
					\Jetonomy\settings_tab_link( $slug, $label, $tab_icons[ $slug ], $active_tab );
				}
				?>

				<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-snav-divider" role="separator"></div>
				<a href="<?php echo esc_url( $settings_url . '&tab=free-vs-pro' ); ?>"
					class="jt-snav-link<?php echo esc_attr( 'free-vs-pro' === $active_tab ? ' jt-snav-link--active' : '' ); ?>"
					style="<?php echo 'free-vs-pro' !== $active_tab ? 'color: var(--jt-admin-pro, #7C3AED);' : ''; ?>">
					<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
					<?php esc_html_e( 'Free vs Pro', 'jetonomy' ); ?>
				</a>
				<?php endif; ?>

				<?php if ( defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-snav-divider" role="separator"></div>
					<?php \Jetonomy\settings_tab_link( 'license', __( 'License', 'jetonomy' ), 'dashicons-shield-alt', $active_tab ); ?>
				<?php endif; ?>

				<?php if ( $advanced_tabs_html ) : ?>
				<div class="jt-snav-divider" role="separator"></div>
				<p class="jt-snav-section-label"><?php esc_html_e( 'Advanced', 'jetonomy' ); ?></p>
					<?php echo $advanced_tabs_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — escaped by each extension ?>
				<?php endif; ?>
			</nav>
		</aside>

		<div class="jt-settings-main">
			<?php if ( in_array( $active_tab, $jt_primary_tabs, true ) ) : ?>
			<form method="post" action="options.php" id="jetonomy-settings-form">
				<?php settings_fields( 'jetonomy_settings' ); ?>
			<?php endif; ?>

				<div class="jt-settings-cards">

		<?php if ( 'general' === $active_tab ) : ?>

			<!-- Community Setup -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Community Setup', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Core settings for your community URL and content types.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="base_slug"><?php esc_html_e( 'Community Base URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="base_slug" name="jetonomy_settings[base_slug]" value="<?php echo esc_attr( \Jetonomy\base_slug() ); ?>" class="regular-text" required>
							<p class="description"><?php echo esc_html( home_url( '/' ) ); ?><strong><?php echo esc_html( \Jetonomy\base_slug() ); ?></strong>/</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="community_title"><?php esc_html_e( 'Community Title', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="community_title" name="jetonomy_settings[community_title]" value="<?php echo esc_attr( $settings['community_title'] ?? __( 'Community', 'jetonomy' ) ); ?>" class="regular-text">
							<p class="description"><?php esc_html_e( 'Displayed as the main heading on the community home page.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="terms_url"><?php esc_html_e( 'Terms of Service URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="terms_url" name="jetonomy_settings[terms_url]" value="<?php echo esc_attr( $settings['terms_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/terms/">
							<p class="description"><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Shown to %s before they sign up in the mobile app. Leave empty if you have none.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="privacy_url"><?php esc_html_e( 'Privacy Policy URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="privacy_url" name="jetonomy_settings[privacy_url]" value="<?php echo esc_attr( $settings['privacy_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/privacy/">
							<p class="description"><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Shown to %s before they sign up in the mobile app. Leave empty to use your WordPress Privacy Policy page.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="support_url"><?php esc_html_e( 'Support URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="support_url" name="jetonomy_settings[support_url]" value="<?php echo esc_attr( $settings['support_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/support/">
							<p class="description"><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Where %s can reach you for help. Shown in the mobile app settings. Leave empty to hide the link.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Terminology', 'jetonomy' ); ?></th>
						<td>
							<?php
							// One place for every renamable product noun - nothing scattered
							// across other sections. Space/Topic/Reply/Member/Category each get a
							// singular + plural pair; both forms are explicit because we never
							// auto-pluralise (breaks on irregulars like Person/Persons).
							// Explicit per-field labels + a 12px gap.
							$jt_defaults    = \Jetonomy\label_defaults();
							$jt_noun_titles = array(
								'space'    => esc_html__( 'Space', 'jetonomy' ), // jt-terms-ok: names the Terminology field itself.
								'topic'    => esc_html__( 'Topic', 'jetonomy' ), // jt-terms-ok: names the Terminology field itself.
								'reply'    => esc_html__( 'Reply', 'jetonomy' ), // jt-terms-ok: names the Terminology field itself.
								'member'   => esc_html__( 'Member', 'jetonomy' ), // jt-terms-ok: names the Terminology field itself.
								'category' => esc_html__( 'Category', 'jetonomy' ), // jt-terms-ok: names the Terminology field itself.
							);
							foreach ( $jt_noun_titles as $jt_noun => $jt_noun_title ) :
								$jt_sing = $jt_noun . '_label_singular';
								$jt_plur = $jt_noun . '_label_plural';
								?>
								<div class="jt-term-noun">
									<span class="jt-term-noun__name"><?php echo esc_html( $jt_noun_title ); ?></span>
									<div class="jt-field-pair">
										<div class="jt-field-pair__item">
											<label for="<?php echo esc_attr( $jt_sing ); ?>"><?php esc_html_e( 'Singular', 'jetonomy' ); ?></label>
											<input type="text" id="<?php echo esc_attr( $jt_sing ); ?>" name="jetonomy_settings[<?php echo esc_attr( $jt_sing ); ?>]" value="<?php echo esc_attr( $settings[ $jt_sing ] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $jt_defaults[ $jt_noun ][0] ); ?>">
										</div>
										<div class="jt-field-pair__item">
											<label for="<?php echo esc_attr( $jt_plur ); ?>"><?php esc_html_e( 'Plural', 'jetonomy' ); ?></label>
											<input type="text" id="<?php echo esc_attr( $jt_plur ); ?>" name="jetonomy_settings[<?php echo esc_attr( $jt_plur ); ?>]" value="<?php echo esc_attr( $settings[ $jt_plur ] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $jt_defaults[ $jt_noun ][1] ); ?>">
										</div>
									</div>
								</div>
							<?php endforeach; ?>
							<?php /* jt-terms-ok: help text for the Terminology setting; the default words are the subject. */ ?>
							<p class="description"><?php esc_html_e( 'Rename the nouns your community uses, singular and plural (e.g. Space → Forum / Forums, Topic → Thread / Threads, Member → Player / Players). Leave a field empty to keep the default. Custom labels are shown exactly as typed and are not translated, so on a non-English site enter them in your own language.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="member_name_display"><?php /* translators: %s: member label (e.g. "Member"). */ echo esc_html( sprintf( __( '%s Names', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member' ) ) ); ?></label></th>
						<td>
							<?php $jt_name_mode = \Jetonomy\name_display_mode(); ?>
							<select id="member_name_display" name="jetonomy_settings[member_name_display]">
								<option value="display_name" <?php selected( $jt_name_mode, 'display_name' ); ?>><?php esc_html_e( 'Display name only - Alex Rivera', 'jetonomy' ); ?></option>
								<option value="handle" <?php selected( $jt_name_mode, 'handle' ); ?>><?php esc_html_e( 'Handle only - @alex-rivera', 'jetonomy' ); ?></option>
								<option value="both" <?php selected( $jt_name_mode, 'both' ); ?>><?php esc_html_e( 'Both - Alex Rivera @alex-rivera', 'jetonomy' ); ?></option>
							</select>
							<p class="description">
								<?php /* translators: 1: member label, plural, lowercase (e.g. "members"). 2: member label, lowercase (e.g. "member"). */ echo esc_html( sprintf( __( 'How %1$s are identified on bylines, %2$s lists and the leaderboard.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'member', false, true ) ) ); ?>
								<br>
								<?php /* translators: 1: member label, plural, lowercase (e.g. "members"). 2: member label, lowercase (e.g. "member"). */ echo esc_html( sprintf( __( 'Display names are not unique - WordPress lets any number of accounts share one, so two %1$s called "Alex Rivera" look identical everywhere. A handle is the %2$s\'s unique username, and is already what @mentions resolve against. Choose Both if you want readable names that can still be told apart.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'member', false, true ) ) ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Community as Homepage', 'jetonomy' ); ?></th>
						<td>
							<label for="front_page">
								<input type="checkbox" id="front_page" name="jetonomy_settings[front_page]" value="1" <?php checked( ! empty( $settings['front_page'] ) ); ?>>
								<?php esc_html_e( 'Show the community home on the site front page.', 'jetonomy' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Visitors to your site root see the community home page. This takes precedence over the WordPress "Your homepage displays" setting. All community URLs, pages, and feeds keep working unchanged.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
						<th scope="row"><label for="default_space_type"><?php echo esc_html( sprintf( __( 'Default %s Type', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></label></th>
						<td>
							<select id="default_space_type" name="jetonomy_settings[default_space_type]">
								<option value="forum" <?php selected( $settings['default_space_type'] ?? 'forum', 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
								<option value="qa" <?php selected( $settings['default_space_type'] ?? '', 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
								<option value="ideas" <?php selected( $settings['default_space_type'] ?? '', 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
								<option value="feed" <?php selected( $settings['default_space_type'] ?? '', 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
							</select>
							<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'The pre-selected type when creating a new %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
						<th scope="row"><?php echo esc_html( sprintf( __( 'Front-end %s creation', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></th>
						<td>
							<?php
							$selected_roles = isset( $settings['frontend_space_creation_roles'] )
								? array_map( 'sanitize_key', (array) $settings['frontend_space_creation_roles'] )
								: array();
							$wp_roles_list  = wp_roles()->get_names();

							// Group roles by source so a site with 15+ roles
							// (LMS + Career Board + e-commerce stack) doesn't
							// drop a 25-row vertical wall on the admin. Keys
							// classified by known prefixes; anything else
							// lands in "Other" (always last).
							$jt_role_groups = array(
								'wordpress'       => array(
									'label' => __( 'WordPress', 'jetonomy' ),
									'keys'  => array( 'editor', 'author', 'contributor', 'subscriber' ),
								),
								'community'       => array(
									'label' => __( 'Community & Forums', 'jetonomy' ),
									'keys'  => array(),
								),
								'lms_memberships' => array(
									'label' => __( 'LMS & Memberships', 'jetonomy' ),
									'keys'  => array(),
								),
								'commerce'        => array(
									'label' => __( 'E-commerce', 'jetonomy' ),
									'keys'  => array(),
								),
								'other'           => array(
									'label' => __( 'Other', 'jetonomy' ),
									'keys'  => array(),
								),
							);

							foreach ( $wp_roles_list as $jt_rk => $jt_rn ) {
								if ( 'administrator' === $jt_rk ) {
									continue;
								}
								if ( in_array( $jt_rk, $jt_role_groups['wordpress']['keys'], true ) ) {
									continue;
								}
								if ( preg_match( '/^(bp_|bbp_|spectator|participant|moderator|keymaster|board_)/i', $jt_rk ) ) {
									$jt_role_groups['community']['keys'][] = $jt_rk;
								} elseif ( preg_match( '/^(ld_|tutor_|lms_|lrn_|instructor|teacher|student|group_leader|memberpress|pmpro|wlm_)/i', $jt_rk ) ) {
									$jt_role_groups['lms_memberships']['keys'][] = $jt_rk;
								} elseif ( preg_match( '/^(shop_|customer|wc_|edd_|wpforms)/i', $jt_rk ) ) {
									$jt_role_groups['commerce']['keys'][] = $jt_rk;
								} else {
									$jt_role_groups['other']['keys'][] = $jt_rk;
								}
							}
							?>
							<fieldset>
								<?php /* translators: %s: the plural space label the site owner configured (e.g. spaces, groups). */ ?>
								<legend class="screen-reader-text"><?php echo esc_html( sprintf( __( 'Roles allowed to create %s from the front end', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></legend>
								<?php foreach ( $jt_role_groups as $jt_group ) : ?>
									<?php if ( ! empty( $jt_group['keys'] ) ) : ?>
										<p style="margin:12px 0 4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:var(--jt-admin-text-wp-2, #646970);"><?php echo esc_html( $jt_group['label'] ); ?></p>
										<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:4px 24px;max-width:520px;">
											<?php foreach ( $jt_group['keys'] as $jt_rk ) : ?>
												<label style="display:flex;align-items:center;gap:6px;margin:0;">
													<input type="checkbox" name="jetonomy_settings[frontend_space_creation_roles][]" value="<?php echo esc_attr( $jt_rk ); ?>" <?php checked( in_array( $jt_rk, $selected_roles, true ) ); ?>>
													<?php echo esc_html( translate_user_role( $wp_roles_list[ $jt_rk ] ) ); ?>
												</label>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								<?php endforeach; ?>
							</fieldset>
							<?php /* jt-terms-ok: new-space is the fixed URL path. */ /* translators: 1: plural space label, 2: singular space label. */ ?>
							<p class="description" style="margin-block-start:12px;"><?php echo esc_html( sprintf( __( 'Site administrators always qualify. Tick any additional WordPress roles you trust to create %1$s from /community/new-space/. Leave every box unticked to keep front-end %2$s creation admin-only.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\space_label( false, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email verification', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[require_email_verification]" value="1" <?php checked( ! empty( $settings['require_email_verification'] ) ); ?>>
								<?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Require new %s to confirm their email before they can sign in', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
							</label>
							<p class="description"><?php /* translators: 1: member label, plural (e.g. "Members"). 2: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'When on, the Login block sends a confirmation email after sign-up. %1$s can\'t log in until they click the link. Existing %2$s are not affected.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php /* translators: %s: space label, plural, lowercase (e.g. "spaces"). */ echo esc_html( sprintf( __( 'Deleting %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true, true ) ) ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[allow_space_admin_purge]" value="1" <?php checked( ! empty( $settings['allow_space_admin_purge'] ) ); ?>>
								<?php /* translators: %s: space label, lowercase (e.g. "space"). */ echo esc_html( sprintf( __( 'Let %1$s admins permanently delete their %1$s and everything in it', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ) ) ); ?>
							</label>
							<p class="description"><?php /* translators: 1: space label, lowercase (e.g. "space"). 2: member label, plural, lowercase (e.g. "members"). 3: topic label, plural, lowercase (e.g. "topics"). 4: reply label, plural, lowercase (e.g. "replies"). */ echo esc_html( sprintf( __( 'Off by default. A deleted %1$s is normally transferred and archived, because it holds %3$s and %4$s written by other %2$s - destroying it destroys their contributions too. With this on, %1$s admins can choose permanent deletion instead. Site administrators can always do either.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', false, true ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Pagination -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Pagination', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'How many items to show per page on list views.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="posts_per_page"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Per Page', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ) ) ); ?></label></th>
						<td><input type="number" id="posts_per_page" name="jetonomy_settings[posts_per_page]" value="<?php echo absint( $settings['posts_per_page'] ?? 20 ); ?>" min="5" max="100" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="replies_per_page"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Per Page', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ) ) ); ?></label></th>
						<td><input type="number" id="replies_per_page" name="jetonomy_settings[replies_per_page]" value="<?php echo absint( $settings['replies_per_page'] ?? 30 ); ?>" min="5" max="100" class="small-text"></td>
					</tr>
				</table>
			</div>

			<!-- Activity Log -->
			<div class="jt-settings-card" id="jt-activity-log-settings">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Activity Log', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'The log under %s > Activity records what happens in your community.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="activity_log_retention_days"><?php esc_html_e( 'Keep entries for', 'jetonomy' ); ?></label></th>
						<td>
							<input type="number" id="activity_log_retention_days" name="jetonomy_settings[activity_log_retention_days]" value="<?php echo absint( $settings['activity_log_retention_days'] ?? 90 ); ?>" min="1" max="3650" class="small-text" aria-describedby="activity_log_retention_days_desc"> <?php esc_html_e( 'days', 'jetonomy' ); ?>
							<p class="description" id="activity_log_retention_days_desc"><?php esc_html_e( 'Older entries are deleted automatically once a day. Default 90.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Access Control -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Access Control', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Who can read and participate in your community.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Community Access', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<?php
								// Treat a missing value as "public" — the sensible default for a new install.
								$is_public = ! isset( $settings['guest_read'] ) || ! empty( $settings['guest_read'] );
								?>
								<label style="display:block;margin-bottom:8px;">
									<input type="radio" name="jetonomy_settings[guest_read]" value="1" <?php checked( $is_public ); ?>>
									<strong><?php esc_html_e( 'Public community', 'jetonomy' ); ?></strong>
									<span class="description" style="display:block;margin-left:24px;">
										<?php /* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: reply label, plural, lowercase (e.g. "replies"). */ echo esc_html( sprintf( __( 'Anyone can read %1$s and %2$s. Visitors must log in to post, reply, or vote.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); /* jt-terms-ok: "reply" left in the text is the verb. */ ?>
									</span>
								</label>
								<label style="display:block;">
									<input type="radio" name="jetonomy_settings[guest_read]" value="0" <?php checked( ! $is_public ); ?>>
									<strong><?php esc_html_e( 'Private community', 'jetonomy' ); ?></strong>
									<span class="description" style="display:block;margin-left:24px;">
										<?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Only logged-in %s can view any forum content. Everyone else is redirected to the login page.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html( sprintf( /* translators: %s: plural space label the site owner configured. */ __( 'Private %s for visitors', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[guest_private_teasers]" value="1" <?php checked( ! empty( $settings['guest_private_teasers'] ) ); ?>>
								<?php echo esc_html( sprintf( /* translators: %s: plural space label the site owner configured. */ __( 'Show private %s to logged-out visitors', 'jetonomy' ), \Jetonomy\space_label( true, true ) ) ); ?>
							</label>
							<p class="description"><?php /* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: space label, plural, lowercase (e.g. "spaces"). */ echo esc_html( sprintf( __( 'They appear in listings with a lock, so visitors know they exist and can log in or register to ask to join. Their %1$s stay hidden. Hidden %2$s are never shown.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'space', true, true ) ) ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Data -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Data', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'What happens to your community when the plugin is deleted.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><?php esc_html_e( 'On delete', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[remove_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['remove_data_on_uninstall'] ) ); ?> <?php disabled( ! current_user_can( 'manage_options' ) ); ?>>
								<?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Remove all community data when %s is deleted', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?>
							</label>
							<p class="description"><?php /* translators: 1: space label, plural, lowercase. 2: topic label, plural, lowercase. 3: reply label, plural, lowercase. 4: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Off by default, so deleting and reinstalling the plugin keeps everything. When on, deleting %4$s from the Plugins screen permanently removes all %1$s, %2$s, %3$s, profiles and settings, and the Pro add-on\'s data too. Deactivating never removes anything.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
						</td>
					</tr>
				</table>
			</div>

		<?php elseif ( 'permissions' === $active_tab ) : ?>

			<?php
			$thresholds  = $settings['trust_thresholds'] ?? array();
			$rate_limits = $settings['rate_limits'] ?? array();
			$tl_defaults = \Jetonomy\Trust\Trust_Levels::defaults();
			$rl_defaults = \Jetonomy\Permissions\Rate_Limiter::defaults();
			// Built from Trust_Levels::label() so renaming the ladder in one
			// place updates this tab, the Users screen and the promotion email
			// together.
			$level_names = array();
			foreach ( array( 1, 2, 3 ) as $jt_lvl ) {
				$level_names[ $jt_lvl ] = sprintf(
					/* translators: 1: trust level number, 2: trust level name. */
					__( 'Level %1$d: %2$s', 'jetonomy' ),
					$jt_lvl,
					\Jetonomy\Trust\Trust_Levels::label( $jt_lvl )
				);
			}
			?>

			<!-- Trust Levels -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Trust Level Thresholds', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Define what users must achieve to advance through trust levels. Higher levels unlock posting privileges and reduce moderation scrutiny.', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( "Level 3's moderation powers only apply to roles you gave those capabilities without Moderate (queue, flags, bans). Moderators (Editor by default) are not held to trust levels.", 'jetonomy' ); ?></p>
				</div>
				<table class="wp-list-table widefat fixed jt-settings-matrix" style="margin:0;border:none;box-shadow:none;border-radius:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level', 'jetonomy' ); ?></th>
							<th><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Required', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ) ) ); ?></th>
							<?php // A comment line on the row above would be read as this string's translator comment. ?>
							<th><?php esc_html_e( 'Days Active', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Reputation', 'jetonomy' ); ?></th>
							<th><?php /* translators: %s: reply label, plural (e.g. "Replies"). */ echo esc_html( sprintf( __( '%s Received', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ) ) ); ?></th>

								<th><?php esc_html_e( 'What this unlocks', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						for ( $level = 1; $level <= 3; $level++ ) :
							$td = $tl_defaults[ $level ];
							?>
							<tr>
								<th scope="row"><strong><?php echo esc_html( $level_names[ $level ] ); ?></strong></th>
								<td data-colname="<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_attr( sprintf( __( '%s Required', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ) ) ); ?>"><input type="number" aria-label="<?php /* translators: 1: topic label, plural (e.g. "Topics"). 2: trust level name. */ echo esc_attr( sprintf( __( '%1$s Required for %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ), $level_names[ $level ] ) ); ?>" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][posts]" value="<?php echo absint( $thresholds[ $level ]['posts'] ?? $td['posts'] ); ?>" min="0" class="small-text"></td>
							<?php // A comment line on the row above would be read as this string's translator comment. ?>
								<td data-colname="<?php esc_attr_e( 'Days Active', 'jetonomy' ); ?>"><input type="number" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: trust level name. */ __( 'Days Active for %s', 'jetonomy' ), $level_names[ $level ] ) ); ?>" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][days_active]" value="<?php echo absint( $thresholds[ $level ]['days_active'] ?? $td['days_active'] ); ?>" min="0" class="small-text"></td>
								<td data-colname="<?php esc_attr_e( 'Reputation', 'jetonomy' ); ?>"><input type="number" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: trust level name. */ __( 'Reputation for %s', 'jetonomy' ), $level_names[ $level ] ) ); ?>" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][reputation]" value="<?php echo absint( $thresholds[ $level ]['reputation'] ?? $td['reputation'] ); ?>" min="0" class="small-text"></td>
								<?php /* translators: %s: reply label, plural (e.g. "Replies"). */ ?>
								<td data-colname="<?php echo esc_attr( sprintf( __( '%s Received', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ) ) ); ?>"><input type="number" aria-label="<?php /* translators: 1: reply label, plural (e.g. "Replies"). 2: trust level name. */ echo esc_attr( sprintf( __( '%1$s Received for %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ), $level_names[ $level ] ) ); ?>" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][replies_received]" value="<?php echo absint( $thresholds[ $level ]['replies_received'] ?? $td['replies_received'] ); ?>" min="0" class="small-text"></td>

									<td class="description" data-colname="<?php esc_attr_e( 'What this unlocks', 'jetonomy' ); ?>">
										<?php
										// Surface what each level unlocks (data from Trust_Levels::LEVELS,
										// which lists only what the engine enforces).
										$jt_ability_labels = array(
											'no_rate_limits' => __( 'No daily posting limits', 'jetonomy' ),
											'skip_captcha' => __( 'Skips the CAPTCHA (when CAPTCHA is on)', 'jetonomy' ),
											'edit_others_posts' => __( "Edit other people's content", 'jetonomy' ),
											'delete_others_posts' => __( "Delete other people's content", 'jetonomy' ),
											/* translators: %s: topic label, plural, lowercase (e.g. "topics"). */
											'move_posts'   => sprintf( __( 'Move %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
											/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
											'close_posts'  => sprintf( __( 'Close %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
											/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
											'pin_posts'    => sprintf( __( 'Pin %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
										);
										$jt_unlocks        = array();
										foreach ( ( \Jetonomy\Trust\Trust_Levels::get( $level )['abilities'] ?? array() ) as $jt_ab ) {
											if ( isset( $jt_ability_labels[ $jt_ab ] ) ) {
												$jt_unlocks[] = $jt_ability_labels[ $jt_ab ];
											}
										}
										echo $jt_unlocks ? esc_html( implode( ', ', $jt_unlocks ) ) : esc_html__( 'None', 'jetonomy' );
										if ( $jt_unlocks && 3 === $level ) {
											// The trust floor only matters for a role already ticked for these caps above.
											echo '<br>' . esc_html__( 'Only for roles that already have these permissions.', 'jetonomy' );
										}
										?>
									</td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
				<?php $jt_tl_names = (array) ( $settings['trust_level_names'] ?? array() ); ?>
				<fieldset class="jt-trust-names">
					<legend class="jt-settings-card__title"><?php esc_html_e( 'Trust level names', 'jetonomy' ); ?></legend>
					<p class="description"><?php echo esc_html( sprintf( /* translators: %s: plural member label, lowercase (e.g. members). */ __( 'What %s see on profiles, hover cards and badges. Leave a field blank to keep the built-in name.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
					<div class="jt-trust-name-fields">
					<?php foreach ( range( 0, 5 ) as $jt_l ) : ?>
						<?php /* translators: %d: trust level number. */ ?>
						<label class="jt-trust-name-field"><span><?php echo esc_html( sprintf( __( 'Level %d', 'jetonomy' ), $jt_l ) ); ?></span>
							<input type="text" class="regular-text" name="jetonomy_settings[trust_level_names][<?php echo (int) $jt_l; ?>]" value="<?php echo esc_attr( (string) ( $jt_tl_names[ $jt_l ] ?? '' ) ); ?>" placeholder="<?php echo esc_attr( (string) \Jetonomy\Trust\Trust_Levels::default_label( $jt_l ) ); ?>">
						</label>
					<?php endforeach; ?>
					</div>
				</fieldset>
			</div>

			<!-- Rate Limits -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Rate Limits for New Users (Level 0)', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Limit how much brand-new users can post in a single day to reduce spam.', 'jetonomy' ); // jt-terms-ok: "post" is the verb here. ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="jt-rate-posts"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s per Day', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ) ) ); ?></label></th>
						<td><input type="number" id="jt-rate-posts" name="jetonomy_settings[rate_limits][posts]" value="<?php echo absint( $rate_limits['posts'] ?? $rl_defaults['posts'] ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jt-rate-replies"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s per Day', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true ) ) ); ?></label></th>
						<td><input type="number" id="jt-rate-replies" name="jetonomy_settings[rate_limits][replies]" value="<?php echo absint( $rate_limits['replies'] ?? $rl_defaults['replies'] ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="jt-rate-votes"><?php esc_html_e( 'Votes per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" id="jt-rate-votes" name="jetonomy_settings[rate_limits][votes]" value="<?php echo absint( $rate_limits['votes'] ?? $rl_defaults['votes'] ); ?>" min="1" class="small-text"></td>
					</tr>
				</table>
			</div>

			<!-- Role -> Capability Mapping (editable) -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Role Capability Mapping', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Which %s capabilities each WordPress role holds. Administrators always hold every capability. Unticking a box revokes the capability on save.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
				</div>
				<?php if ( ! current_user_can( 'manage_options' ) ) : ?>
					<?php // Role administration stays admin-only even when settings are delegated - the sanitize branch enforces the same gate server-side. ?>
					<p class="jt-role-caps-locked"><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e( 'Only administrators can change the role capability mapping.', 'jetonomy' ); ?></p>
				<?php else : ?>
					<?php
					$jt_cap_labels = \Jetonomy\Permissions\Capabilities::labels();
					$jt_effective  = \Jetonomy\Permissions\Capabilities::effective_map();
					$jt_map_roles  = array();
					foreach ( get_editable_roles() as $jt_role_slug => $jt_role_info ) {
						if ( 'administrator' === $jt_role_slug ) {
							continue;
						}
						$jt_map_roles[ $jt_role_slug ] = translate_user_role( $jt_role_info['name'] );
					}
					?>
				<input type="hidden" name="jetonomy_settings[role_caps_submitted]" value="1">
					<?php
					// Third-party roles saved with nothing ticked are locked out of every
					// space. 2.0.1 stored them that way on any save of this tab, so offer
					// the owner a one-click way back instead of guessing.
					$jt_stored_caps = get_option( \Jetonomy\Permissions\Capabilities::ROLE_CAPS_OPTION, array() );
					$jt_role_defs   = \Jetonomy\Permissions\Capabilities::default_map();
					foreach ( $jt_map_roles as $jt_role_slug => $jt_role_label ) :
						if ( isset( $jt_role_defs[ $jt_role_slug ] ) || ! is_array( $jt_stored_caps ) || ! array_key_exists( $jt_role_slug, $jt_stored_caps ) || $jt_stored_caps[ $jt_role_slug ] ) {
							continue;
						}
						?>
						<div class="notice notice-warning inline jt-role-locked-notice">
							<p>
								<?php
								/* translators: 1: member label, plural (e.g. "Members"). 2: role name, e.g. Customer. 3: space label, lowercase (e.g. "space"). */
								echo esc_html( sprintf( __( '%1$s with the %2$s role cannot read or post in any %3$s, because nothing is ticked for this role.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ), $jt_role_label, \Jetonomy\jetonomy_label( 'space', false, true ) ) ); // jt-terms-ok: "post" is the verb here.
								?>
								<button type="submit" class="button button-small" name="jetonomy_settings[role_caps_reset]" value="<?php echo esc_attr( $jt_role_slug ); ?>">
									<?php
									/* translators: 1: role name. 2: member label, plural, lowercase (e.g. "members"). 3: space label, plural, lowercase (e.g. "spaces"). */
									echo esc_html( sprintf( __( 'Give %1$s %2$s access to their %3$s', 'jetonomy' ), $jt_role_label, \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'space', true, true ) ) );
									?>
								</button>
							</p>
						</div>
					<?php endforeach; ?>
					<?php if ( count( $jt_map_roles ) > 4 ) : // More roles than fit a typical card width. ?>
					<p class="description jt-matrix-scroll-hint" id="jt-role-matrix-hint"><?php esc_html_e( 'Scroll sideways to see every role. The capability column stays in place.', 'jetonomy' ); ?></p>
				<?php endif; ?>
				<div class="jt-matrix-scroll" tabindex="0" role="region" aria-label="<?php esc_attr_e( 'Role Capability Mapping', 'jetonomy' ); ?>">
					<?php // No .wp-list-table: core's <=782px list-table rules turned this form grid into flex rows of loose checkboxes. ?>
				<table class="widefat jt-settings-matrix jt-settings-matrix--wide" style="margin:0;border:none;box-shadow:none;border-radius:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'jetonomy' ); ?></th>
							<?php foreach ( $jt_map_roles as $jt_role_slug => $jt_role_label ) : ?>
								<th class="jt-col-s"><?php echo esc_html( $jt_role_label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jt_cap_labels as $jt_cap => $jt_cap_label ) : ?>
							<tr>
								<th scope="row"><strong><?php echo esc_html( $jt_cap_label ); ?></strong><br><code style="font-size:11px;color:var(--jt-admin-text-wp-2, #646970);"><?php echo esc_html( $jt_cap ); ?></code></th>
								<?php foreach ( $jt_map_roles as $jt_role_slug => $jt_role_label ) : ?>
									<td data-colname="<?php echo esc_attr( $jt_role_label ); ?>">
										<input type="checkbox"
											name="jetonomy_settings[role_caps][<?php echo esc_attr( $jt_role_slug ); ?>][]"
											value="<?php echo esc_attr( $jt_cap ); ?>"
											aria-label="<?php echo esc_attr( sprintf( /* translators: 1: capability label, 2: role name */ __( '%1$s for %2$s', 'jetonomy' ), $jt_cap_label, $jt_role_label ) ); ?>"
											<?php checked( in_array( $jt_cap, $jt_effective[ $jt_role_slug ] ?? array(), true ) ); ?>>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div><!-- /.jt-matrix-scroll -->
				<?php endif; ?>
			</div>

			<!-- Reputation Points -->
			<?php
			$rep_defaults  = \Jetonomy\Trust\Reputation::action_points_defaults();
			$rep_overrides = isset( $settings['reputation_points'] ) && is_array( $settings['reputation_points'] )
				? $settings['reputation_points']
				: array();
			$rep_labels    = array(
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'post_upvoted'    => sprintf( __( '%s upvoted', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ),
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'reply_upvoted'   => sprintf( __( '%s upvoted', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ) ),
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'post_downvoted'  => sprintf( __( '%s downvoted', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ),
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'reply_downvoted' => sprintf( __( '%s downvoted', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ) ),
				/* translators: %s: reply label (e.g. "Reply"). */
				'reply_accepted'  => sprintf( __( '%s accepted as answer', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply' ) ),
				'idea_planned'    => __( 'Idea moved to Planned/Shipped', 'jetonomy' ),
				'flag_validated'  => __( 'Flag confirmed (reporter)', 'jetonomy' ),
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'post_reported'   => __( 'Content reported', 'jetonomy' ),
				/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
				'post_removed'    => sprintf( __( '%s removed by moderator', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ),
			);
			?>
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Reputation Points', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'How many points each action awards (or deducts). Positive numbers reward, negative numbers penalize. Leave a row at its default if you have no reason to change it.', 'jetonomy' ); ?></p>
				</div>
				<table class="wp-list-table widefat fixed jt-settings-matrix" style="margin:0;border:none;box-shadow:none;border-radius:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Action', 'jetonomy' ); ?></th>
							<th class="jt-col-m"><?php esc_html_e( 'Points', 'jetonomy' ); ?></th>
							<th class="jt-col-m"><?php esc_html_e( 'Default', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rep_labels as $action_key => $action_label ) : ?>
							<?php
							$current = array_key_exists( $action_key, $rep_overrides )
								? (int) $rep_overrides[ $action_key ]
								: (int) ( $rep_defaults[ $action_key ] ?? 0 );
							$default = (int) ( $rep_defaults[ $action_key ] ?? 0 );
							?>
							<tr>
								<th scope="row"><strong><?php echo esc_html( $action_label ); ?></strong></th>
								<td data-colname="<?php esc_attr_e( 'Points', 'jetonomy' ); ?>">
									<input
										type="number"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: reputation action label */ __( 'Points for %s', 'jetonomy' ), $action_label ) ); ?>"
										name="jetonomy_settings[reputation_points][<?php echo esc_attr( $action_key ); ?>]"
										value="<?php echo (int) $current; ?>"
										class="small-text"
										step="1"
									>
								</td>
								<td data-colname="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>"><code><?php echo esc_html( ( $default >= 0 ? '+' : '' ) . $default ); ?></code></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

		<?php elseif ( 'email' === $active_tab ) : ?>
			<?php $admin_email = get_option( 'admin_email' ); ?>

			<!-- Email Sender -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Email Sender', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Configure the name and address that appear in outgoing community emails.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="email_from_name"><?php esc_html_e( 'From Name', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="email_from_name" name="jetonomy_settings[email_from_name]" value="<?php echo esc_attr( $settings['email_from_name'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="email_from_email"><?php esc_html_e( 'From Email', 'jetonomy' ); ?></label></th>
						<td>
							<input type="email" id="email_from_email" name="jetonomy_settings[email_from_email]" value="<?php echo esc_attr( $settings['email_from_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $admin_email ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="email_logo_url"><?php esc_html_e( 'Email Logo', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="email_logo_url" name="jetonomy_settings[email_logo_url]" value="<?php echo esc_url( $settings['email_logo_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/logo.png">
							<p class="description"><?php esc_html_e( 'URL to your logo image for notification emails. Recommended: 200x40px, PNG or SVG. Leave empty to use site name as text.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="verification_reminder_hours"><?php esc_html_e( 'Email Verification Reminder', 'jetonomy' ); ?></label></th>
						<td>
							<input type="number" id="verification_reminder_hours" name="jetonomy_settings[verification_reminder_hours]" min="0" max="168" value="<?php echo esc_attr( (string) (int) ( $settings['verification_reminder_hours'] ?? 24 ) ); ?>" class="small-text">
							<?php esc_html_e( 'hours', 'jetonomy' ); ?>
							<p class="description"><?php echo esc_html__( 'Send a one-time &quot;confirm your email&quot; reminder this many hours after signup if the email is still unverified. Set to 0 to disable.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="jt-email-adapter"><?php esc_html_e( 'Email Adapter', 'jetonomy' ); ?></label></th>
						<td>
							<select id="jt-email-adapter" disabled>
								<option><?php esc_html_e( 'WordPress Default (wp_mail)', 'jetonomy' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Uses your WordPress email configuration. SMTP plugins are supported.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test Email', 'jetonomy' ); ?></th>
						<td>
							<button type="button" class="button" id="jetonomy-test-email">
								<span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
								<?php esc_html_e( 'Send Test Email', 'jetonomy' ); ?>
							</button>
							<span class="jetonomy-test-email-status"></span>
							<p class="description">
								<?php
								/* translators: %s: admin email */
								printf( esc_html__( 'Sends a test email to %s', 'jetonomy' ), esc_html( $admin_email ) );
								?>
							</p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Notification Defaults -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Notification Defaults', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: %s: member label, plural (e.g. "Members"). */ echo esc_html( sprintf( __( 'Default on/off state for each notification type. %s can override these in their profile settings.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></p>
				</div>
				<?php
				$notif_defaults = $settings['notification_defaults'] ?? array();
				// One list (Admin::email_types()) feeds this table, the template editor and both sanitizers.
				$email_types = \Jetonomy\Admin\Admin::email_types();
				$notif_types = wp_list_pluck( array_filter( $email_types, static fn( array $t ): bool => $t['toggle'] ), 'label' );
				?>
				<table class="jt-notif-defaults-table jt-settings-matrix">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Notification Type', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Web', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Email', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $notif_types as $type => $label ) :
							// Fallbacks must match how Notifier consumes an unset type:
							// web defaults ON ( ?? true ); email uses the notifier's own
							// default_email() (OFF except types added later, such as
							// `message`), so the box never shows a phantom default.
							$web_on   = isset( $notif_defaults[ $type ]['web'] ) ? (bool) $notif_defaults[ $type ]['web'] : true;
							$email_on = \Jetonomy\Notifications\Notifier::default_email( $type, (array) $notif_defaults );
							?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td data-colname="<?php esc_attr_e( 'Web', 'jetonomy' ); ?>">
									<input type="checkbox"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: notification type */ __( 'Web notification for %s', 'jetonomy' ), $label ) ); ?>"
										name="jetonomy_settings[notification_defaults][<?php echo esc_attr( $type ); ?>][web]"
										value="1"
										<?php checked( $web_on ); ?>>
								</td>
								<td data-colname="<?php esc_attr_e( 'Email', 'jetonomy' ); ?>">
									<input type="checkbox"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: notification type */ __( 'Email notification for %s', 'jetonomy' ), $label ) ); ?>"
										name="jetonomy_settings[notification_defaults][<?php echo esc_attr( $type ); ?>][email]"
										value="1"
										<?php checked( $email_on ); ?>>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Email Templates -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Email Templates', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'Customize subject and intro copy per notification type. Leave blank to use defaults.', 'jetonomy' ); ?>
						<br>
						<?php esc_html_e( 'Placeholders:', 'jetonomy' ); ?>
						<?php foreach ( \Jetonomy\Notifications\Notifier::EMAIL_PLACEHOLDERS as $jt_placeholder ) : ?>
							<code><?php echo esc_html( $jt_placeholder ); ?></code>
						<?php endforeach; ?>
					</p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="email_footer_text"><?php esc_html_e( 'Footer Text', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="email_footer_text" name="jetonomy_settings[email_footer_text]" value="<?php echo esc_attr( $settings['email_footer_text'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'You received this because you are part of the community.', 'jetonomy' ); ?>">
							<p class="description"><?php esc_html_e( 'Appears at the bottom of every branded notification email.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				$email_templates = get_option( 'jetonomy_email_templates', array() );
				// Marker so sanitize_email_templates() can tell "the Email tab
				// posted, and the owner cleared every row" from "a different tab
				// posted and said nothing about templates". Without it, saving
				// Appearance (or any other tab) erased every override, because
				// options.php writes EVERY option registered in this settings
				// group whether the form carried it or not. Same pattern as
				// role_caps_submitted on the Permissions tab.
				?>
				<input type="hidden" name="jetonomy_email_templates[_submitted]" value="1" />
				<?php
				$tmpl_types = $email_types;
				?>
				<table class="widefat striped jetonomy-email-templates-table jt-settings-matrix" style="margin-top:12px;">
					<thead>
						<tr>
							<th class="jt-col-l"><?php esc_html_e( 'Notification', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Body / Intro', 'jetonomy' ); ?></th>
							<th class="jt-col-l"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $tmpl_types as $type => $tmpl_type ) :
							$label   = $tmpl_type['label'];
							$default = \Jetonomy\Notifications\Notifier::get_default_template( $type ); // Shown as the placeholder, so a blank field reads as "uses this".
							$row     = isset( $email_templates[ $type ] ) && is_array( $email_templates[ $type ] ) ? $email_templates[ $type ] : array();
							$subject = isset( $row['subject'] ) ? (string) $row['subject'] : '';
							$body    = isset( $row['body'] ) ? (string) $row['body'] : '';
							?>
							<tr data-jt-email-type="<?php echo esc_attr( $type ); ?>">
								<th scope="row"><strong><?php echo esc_html( $label ); ?></strong>
									<?php if ( '' !== $tmpl_type['note'] ) : ?>
										<p class="description"><?php echo esc_html( $tmpl_type['note'] ); ?></p>
									<?php endif; ?>
								</th>
								<td data-colname="<?php esc_attr_e( 'Subject', 'jetonomy' ); ?>">
									<input type="text"
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: notification type */ __( 'Email subject for %s', 'jetonomy' ), $label ) ); ?>"
										name="jetonomy_email_templates[<?php echo esc_attr( $type ); ?>][subject]"
										value="<?php echo esc_attr( $subject ); ?>"
										class="large-text jetonomy-email-subject-input"
										placeholder="<?php echo esc_attr( '' !== $default['subject'] ? $default['subject'] : '[{site}] {message}' ); ?>">
								</td>
								<td data-colname="<?php esc_attr_e( 'Body / Intro', 'jetonomy' ); ?>">
									<textarea
										aria-label="<?php echo esc_attr( sprintf( /* translators: %s: notification type */ __( 'Email body for %s', 'jetonomy' ), $label ) ); ?>"
										name="jetonomy_email_templates[<?php echo esc_attr( $type ); ?>][body]"
										rows="2"
										class="large-text jetonomy-email-body-input"
										placeholder="<?php echo esc_attr( '' !== $default['body'] ? $default['body'] : '{message}' ); ?>"><?php echo esc_textarea( $body ); ?></textarea>
								</td>
								<td class="jetonomy-email-actions" data-colname="<?php esc_attr_e( 'Actions', 'jetonomy' ); ?>">
									<button type="button" class="button button-small jetonomy-email-preview-btn" data-type="<?php echo esc_attr( $type ); ?>">
										<?php esc_html_e( 'Preview', 'jetonomy' ); ?>
									</button>
									<button type="button" class="button button-small jetonomy-email-send-btn" data-type="<?php echo esc_attr( $type ); ?>" data-label="<?php esc_attr_e( 'Send test', 'jetonomy' ); ?>">
										<?php esc_html_e( 'Send test', 'jetonomy' ); ?>
									</button>
									<?php
									// A8: only show Reset when an override is actually persisted —
									// clicking Reset on a row that's already on the default would be
									// a no-op and confusing. JS toggles visibility after the user
									// edits or saves, but the initial render is driven by the option.
									$has_override = isset( $email_templates[ $type ] ) && is_array( $email_templates[ $type ] );
									?>
									<button type="button"
										class="button button-small button-link-delete jetonomy-email-reset-btn"
										data-type="<?php echo esc_attr( $type ); ?>"
										data-label="<?php echo esc_attr( $label ); ?>"
										<?php echo $has_override ? '' : 'style="display:none;"'; ?>>
										<?php esc_html_e( 'Reset to default', 'jetonomy' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Email preview modal -->
			<div class="jetonomy-modal" id="jetonomy-email-preview-modal" role="dialog" aria-modal="true" aria-labelledby="jetonomy-email-preview-subject" style="display:none;">
				<div class="jetonomy-modal__overlay"></div>
				<div class="jetonomy-modal__content" style="max-width:720px;">
					<h2 id="jetonomy-email-preview-subject"><?php esc_html_e( 'Email Preview', 'jetonomy' ); ?></h2>
					<p class="description" style="margin:0 0 12px;color:var(--jt-admin-text-wp-2, #646970);">
						<?php esc_html_e( 'Preview rendered with sample data. Save the page to persist overrides.', 'jetonomy' ); ?>
					</p>
					<iframe id="jetonomy-email-preview-iframe" style="width:100%;height:520px;border:1px solid var(--jt-admin-border-wp, #dcdcde);border-radius:6px;background:var(--jt-admin-white, #fff);" title="<?php esc_attr_e( 'Email preview', 'jetonomy' ); ?>"></iframe>
					<p class="jetonomy-modal__actions">
						<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Close', 'jetonomy' ); ?></button>
					</p>
				</div>
			</div>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'Email Digest', 'jetonomy' ); ?></h4>
					<p><?php /* translators: %s: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Send daily or weekly email digests to re-engage %s who haven\'t visited recently.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>

					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'appearance' === $active_tab ) : ?>

			<!-- Theme Integration -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Theme Integration', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Control how %s adopts your active theme\'s design tokens.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme appearance', 'jetonomy' ); ?></th>
						<td>
							<p class="description">
								<?php esc_html_e( 'Fonts and colours follow your active theme automatically. To use a different accent, set one below, or leave it at the default to keep matching your theme.', 'jetonomy' ); ?>
							</p>
						</td>
					</tr>
					</table>
			</div>

			<!-- Brand Logo -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Logo', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Your community logo. Shown in the mobile app (login + header) and anywhere a brand mark is needed. Square or wide PNG/SVG; leave empty to show the Community Title as text.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="logo_url"><?php esc_html_e( 'Logo URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="logo_url" name="jetonomy_settings[logo_url]" value="<?php echo esc_url( $settings['logo_url'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/logo.png">
							<p class="description"><?php esc_html_e( 'Paste an image URL (e.g. from Media Library). Recommended: at least 512px on the longest side, transparent PNG.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Color Palette -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Color Palette', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Set the community colors directly. This is useful when your theme has no color tokens for %s to inherit. A colour you set here is applied in both light and dark mode and outranks the theme. Leave a field empty to keep the default; secondary shades (hover, muted text) derive automatically.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="accent_color"><?php esc_html_e( 'Accent', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="accent_color" name="jetonomy_settings[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ?? '#0073aa' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Buttons, links, active states, and notification emails.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="text_color"><?php esc_html_e( 'Text', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="text_color" name="jetonomy_settings[text_color]" value="<?php echo esc_attr( $settings['text_color'] ?? '' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Body copy and headings. Secondary and muted text derive from it.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bg_color"><?php esc_html_e( 'Background', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="bg_color" name="jetonomy_settings[bg_color]" value="<?php echo esc_attr( $settings['bg_color'] ?? '' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Cards and content surfaces.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="bg_subtle_color"><?php esc_html_e( 'Subtle Background', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="bg_subtle_color" name="jetonomy_settings[bg_subtle_color]" value="<?php echo esc_attr( $settings['bg_subtle_color'] ?? '' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Secondary surfaces: table headers, code, quiet panels.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="border_color"><?php esc_html_e( 'Border', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="border_color" name="jetonomy_settings[border_color]" value="<?php echo esc_attr( $settings['border_color'] ?? '' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Card outlines, dividers, and input borders.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Layout -->
			<?php
			$jt_container_width        = $settings['container_width'] ?? 'theme';
			$jt_container_width_custom = absint( $settings['container_width_custom'] ?? 1280 );
			$jt_sidebar_visibility     = $settings['sidebar_visibility'] ?? 'theme';
			$jt_padding_preset         = $settings['padding_preset'] ?? 'theme';
			?>
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Layout', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Control how community pages fit inside your active theme. "Theme Default" leaves your theme in charge.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Container Width', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="jetonomy_settings[container_width]" value="theme" <?php checked( $jt_container_width, 'theme' ); ?>>
									<?php esc_html_e( 'Theme Default', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="jetonomy_settings[container_width]" value="full" <?php checked( $jt_container_width, 'full' ); ?>>
									<?php esc_html_e( 'Full Width', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="jetonomy_settings[container_width]" value="custom" <?php checked( $jt_container_width, 'custom' ); ?>>
									<?php esc_html_e( 'Custom width', 'jetonomy' ); ?>
								</label>
								<input type="number" name="jetonomy_settings[container_width_custom]" value="<?php echo esc_attr( (string) $jt_container_width_custom ); ?>" min="600" max="2400" step="10" class="small-text" style="margin-inline-start:8px;">
								<span><?php esc_html_e( 'px', 'jetonomy' ); ?></span>
							</fieldset>
							<?php /* translators: %s: plural space label. */ ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Applies to community pages only (%s, Discussions, Profile, etc.).', 'jetonomy' ), \Jetonomy\space_label( true ) ) ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Theme Sidebar', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="jetonomy_settings[sidebar_visibility]" value="theme" <?php checked( $jt_sidebar_visibility, 'theme' ); ?>>
									<?php esc_html_e( 'Theme Default', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="jetonomy_settings[sidebar_visibility]" value="hide" <?php checked( $jt_sidebar_visibility, 'hide' ); ?>>
									<?php esc_html_e( 'Hide on community pages', 'jetonomy' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Hides your theme\'s sidebar (widgets) on community pages. Does not affect Jetonomy\'s own right-rail.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page Padding', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="jetonomy_settings[padding_preset]" value="theme" <?php checked( $jt_padding_preset, 'theme' ); ?>>
									<?php esc_html_e( 'Theme Default', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="jetonomy_settings[padding_preset]" value="none" <?php checked( $jt_padding_preset, 'none' ); ?>>
									<?php esc_html_e( 'None (edge to edge)', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="radio" name="jetonomy_settings[padding_preset]" value="comfortable" <?php checked( $jt_padding_preset, 'comfortable' ); ?>>
									<?php esc_html_e( 'Comfortable', 'jetonomy' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="layout_density"><?php esc_html_e( 'Layout Density', 'jetonomy' ); ?></label></th>
						<td>
							<select id="layout_density" name="jetonomy_settings[layout_density]">
								<option value="compact" <?php selected( $settings['layout_density'] ?? 'comfortable', 'compact' ); ?>><?php esc_html_e( 'Compact', 'jetonomy' ); ?></option>
								<option value="comfortable" <?php selected( $settings['layout_density'] ?? 'comfortable', 'comfortable' ); ?>><?php esc_html_e( 'Comfortable', 'jetonomy' ); ?></option>
								<option value="spacious" <?php selected( $settings['layout_density'] ?? 'comfortable', 'spacious' ); ?>><?php esc_html_e( 'Spacious', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
			</div>

			<!-- Custom CSS -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Custom CSS', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Extra CSS injected into the community frontend. Use browser DevTools to find selectors.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<td colspan="2" style="padding: 16px 20px;">
							<textarea id="custom_css" name="jetonomy_settings[custom_css]" rows="12" class="large-text code" style="font-family:monospace;width:100%;"><?php echo esc_textarea( $settings['custom_css'] ?? '' ); ?></textarea>
						</td>
					</tr>
				</table>
			</div>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'White Label', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Replace Jetonomy branding with your own community name, footer text, and wp-admin menu label and icon.', 'jetonomy' ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'seo' === $active_tab ) : ?>

			<!-- Title Templates -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Title Templates', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Control how page titles are formatted for community pages.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="seo_post_title"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s page title', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ) ); ?></label></th>
						<td>
							<input type="text" id="seo_post_title" name="jetonomy_settings[seo_post_title]" value="<?php echo esc_attr( $settings['seo_post_title'] ?? '{post_title} - {space_name} | {site_name}' ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Tokens: {post_title}, {space_name}, {site_name}', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
						<th scope="row"><label for="seo_space_title"><?php echo esc_html( sprintf( __( '%s Title', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></label></th>
						<td>
							<input type="text" id="seo_space_title" name="jetonomy_settings[seo_space_title]" value="<?php echo esc_attr( $settings['seo_space_title'] ?? '{space_name} | {site_name}' ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Tokens: {space_name}, {site_name}', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Structured Data & Indexing -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Structured Data & Indexing', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Schema markup, sitemap inclusion, and noindex rules.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema Markup', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_schema]" value="1" <?php checked( $settings['seo_schema'] ?? true ); ?>>
								<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'Enable DiscussionForumPosting schema on %s pages', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ) ) ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'XML Sitemap', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_sitemap]" value="1" <?php checked( $settings['seo_sitemap'] ?? true ); ?>>
								<?php esc_html_e( 'Include community pages in the WordPress XML sitemap', 'jetonomy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Noindex', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="jetonomy_settings[seo_noindex_profiles]" value="1" <?php checked( $settings['seo_noindex_profiles'] ?? true ); ?>>
									<?php esc_html_e( 'Noindex user profile pages', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="jetonomy_settings[seo_noindex_search]" value="1" <?php checked( $settings['seo_noindex_search'] ?? true ); ?>>
									<?php esc_html_e( 'Noindex search result pages', 'jetonomy' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Moderation queues, composer pages, notifications, edit profile, and invite landings always emit noindex (administrative or personal views, not for search results).', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seo_twitter_handle"><?php esc_html_e( 'Twitter / X handle', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="seo_twitter_handle" name="jetonomy_settings[seo_twitter_handle]" value="<?php echo esc_attr( $settings['seo_twitter_handle'] ?? '' ); ?>" class="regular-text" placeholder="@yoursite">
							<p class="description"><?php esc_html_e( 'Site handle emitted as twitter:site on every public route. Leave blank to omit.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seo_default_og_image"><?php esc_html_e( 'Default share image', 'jetonomy' ); ?></label></th>
						<td>
							<input type="url" id="seo_default_og_image" name="jetonomy_settings[seo_default_og_image]" value="<?php echo esc_attr( $settings['seo_default_og_image'] ?? '' ); ?>" class="regular-text" placeholder="https://example.com/share-card.jpg">
							<p class="description"><?php esc_html_e( 'og:image URL when a route has no image of its own. Falls back to the WordPress site logo / icon when this is empty.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Verify SEO', 'jetonomy' ); ?></th>
						<td>
							<a class="button" href="<?php echo esc_url( home_url( '/wp-sitemap.xml' ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Open XML sitemap', 'jetonomy' ); ?>
							</a>
							<?php /* translators: 1: plural space label, 2: plural topic label, 3: plural space label. */ ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Confirms /wp-sitemap.xml is reachable and that community URLs (%1$s + %2$s) are listed. New %3$s can take a few minutes to appear after the next ping.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\space_label( true, true ) ) ); ?></p>
						</td>
					</tr>
				</table>
			</div>

			<!-- Social Embeds -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Social Embeds (Instagram & Facebook)', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc">
						<?php esc_html_e( 'YouTube, Vimeo, TikTok, Twitter/X, Spotify, SoundCloud, and TED Talks embed automatically with no setup required. Instagram and Facebook require a free Meta Developer App because Meta deprecated anonymous oEmbed access in October 2020.', 'jetonomy' ); ?>
					</p>
				</div>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="fb_app_id"><?php esc_html_e( 'Facebook App ID', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="fb_app_id" name="jetonomy_settings[fb_app_id]" value="<?php echo esc_attr( $settings['fb_app_id'] ?? '' ); ?>" class="regular-text" autocomplete="off" placeholder="1234567890123456">
							<p class="description"><?php esc_html_e( 'Numeric App ID from your Meta Developer dashboard.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="fb_app_secret"><?php esc_html_e( 'Facebook App Secret', 'jetonomy' ); ?></label></th>
						<td>
							<input type="password" id="fb_app_secret" name="jetonomy_settings[fb_app_secret]" value="<?php echo esc_attr( $settings['fb_app_secret'] ?? '' ); ?>" class="regular-text" autocomplete="new-password" placeholder="&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;">
							<p class="description"><?php esc_html_e( 'Secret is stored in wp_options and never exposed to the frontend.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>

				<details class="jt-setup-guide" style="margin:12px 0 4px;padding:14px 16px;background:var(--jt-bg-subtle,#f6f7f7);border:1px solid var(--jt-border,#dcdcde);border-radius:8px;">
					<summary style="cursor:pointer;font-weight:600;color:var(--jt-text,#1d2327);list-style:revert;">
						<?php esc_html_e( 'How to create a Facebook App (5 minutes, free)', 'jetonomy' ); ?>
					</summary>
					<ol style="margin:12px 0 4px 20px;line-height:1.7;">
						<li>
							<?php
							printf(
								/* translators: 1: developers.facebook.com URL, 2: Create App button label */
								esc_html__( 'Go to %1$s and log in with a Facebook account. Click %2$s in the top-right corner.', 'jetonomy' ),
								'<a href="https://developers.facebook.com/apps" target="_blank" rel="noopener noreferrer"><code>developers.facebook.com/apps</code></a>',
								'<strong>' . esc_html__( 'Create App', 'jetonomy' ) . '</strong>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: 1: "Other" use-case label, 2: "Business" app type label */
								esc_html__( 'When asked "What do you want your app to do?", pick %1$s. When asked for the app type, pick %2$s. Name it anything, e.g. "My Forum Embeds".', 'jetonomy' ),
								'<strong>' . esc_html__( 'Other', 'jetonomy' ) . '</strong>',
								'<strong>' . esc_html__( 'Business', 'jetonomy' ) . '</strong>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: %s: "oEmbed Read" product label */
								esc_html__( 'On the new app\'s dashboard, find the %s product in the Products list and click Set Up. It\'s free.', 'jetonomy' ),
								'<strong>' . esc_html__( 'oEmbed Read', 'jetonomy' ) . '</strong>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: 1: "Settings > Basic" breadcrumb, 2: App ID label, 3: App Secret label */
								esc_html__( 'Open %1$s. Copy the %2$s and %3$s and paste them into the two fields above.', 'jetonomy' ),
								'<strong>' . esc_html__( 'Settings → Basic', 'jetonomy' ) . '</strong>',
								'<strong>' . esc_html__( 'App ID', 'jetonomy' ) . '</strong>',
								'<strong>' . esc_html__( 'App Secret', 'jetonomy' ) . '</strong>'
							);
							?>
						</li>
						<li>
							<?php
							printf(
								/* translators: 1: "App Review > Requests" breadcrumb, 2: "oembed_read" permission name */
								esc_html__( 'Go to %1$s and request the %2$s permission. Meta typically approves in 1-3 business days. Your app stays in Development Mode until approved; embeds will work for the admin who created the app even before approval.', 'jetonomy' ),
								'<strong>' . esc_html__( 'App Review → Requests', 'jetonomy' ) . '</strong>',
								'<code>oembed_read</code>'
							);
							?>
						</li>
						<li>
							<?php /* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: reply label, plural, lowercase (e.g. "replies"). */ echo esc_html( sprintf( __( 'Save the settings here. Instagram and Facebook URLs pasted into %1$s and %2$s will now unfurl as rich embeds.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
						</li>
					</ol>
					<p style="margin:12px 0 0;padding:10px 12px;background:var(--jt-warn-light,#fff8e5);border-left:3px solid var(--jt-warn,#dba617);border-radius:4px;font-size:13px;">
						<strong><?php esc_html_e( 'Privacy note:', 'jetonomy' ); ?></strong>
						<?php esc_html_e( 'Jetonomy only sends oEmbed requests to Meta when a user pastes an Instagram/Facebook URL. No tracking, no user data: just the public post URL and your app token. Leave these fields blank to skip Instagram/Facebook embeds entirely; the URL will render as a plain clickable link.', 'jetonomy' ); // jt-terms-ok: an Instagram/Facebook post. ?>
					</p>
				</details>
			</div>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'SEO Pro', 'jetonomy' ); ?></h4>
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
					<p><?php echo esc_html( sprintf( __( 'Open Graph tags, per-%s canonical URLs, breadcrumb schema, and advanced robots control.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'antispam' === $active_tab ) : ?>

			<!-- CAPTCHA Provider -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'CAPTCHA Provider', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php /* translators: 1: topic label, lowercase (e.g. "topic"). 2: reply label, lowercase (e.g. "reply"). 3: member label, plural, lowercase (e.g. "members"). */ echo esc_html( sprintf( __( 'Protect %1$s and %2$s forms from bots. Trusted %3$s (trust level 2+) are always exempt.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\jetonomy_label( 'reply', false, true ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></p>
				</div>
				<?php
				// Inline validation: warn when the provider/keys are in a state that renders nothing.
				$jt_cap_provider = $settings['captcha_provider'] ?? 'none';
				$jt_cap_site     = trim( (string) ( $settings['captcha_site_key'] ?? '' ) );
				$jt_cap_secret   = trim( (string) ( $settings['captcha_secret_key'] ?? '' ) );
				$jt_cap_warning  = '';
				if ( 'none' !== $jt_cap_provider && ( '' === $jt_cap_site || '' === $jt_cap_secret ) ) {
					$jt_cap_warning = __( 'A CAPTCHA provider is selected but the Site Key and/or Secret Key is empty. The CAPTCHA will not render until both keys are filled in.', 'jetonomy' );
				} elseif ( 'none' === $jt_cap_provider && ( '' !== $jt_cap_site || '' !== $jt_cap_secret ) ) {
					$jt_cap_warning = __( 'CAPTCHA keys are saved but the Provider is set to Disabled, so no CAPTCHA renders. Select Cloudflare Turnstile or reCAPTCHA above to activate it.', 'jetonomy' );
				}
				if ( '' !== $jt_cap_warning ) :
					?>
					<p class="jt-captcha-config-warning" style="margin:0 0 16px;padding:10px 12px;background:var(--jt-warn-light,#fff8e5);border-inline-start:3px solid var(--jt-warn,#dba617);border-radius:4px;font-size:13px;">
						<strong><?php esc_html_e( 'Heads up:', 'jetonomy' ); ?></strong>
						<?php echo esc_html( $jt_cap_warning ); ?>
					</p>
					<?php
				endif;
				?>
				<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
					<tr>
						<th scope="row"><label for="captcha_provider"><?php esc_html_e( 'Provider', 'jetonomy' ); ?></label></th>
						<td>
							<select id="captcha_provider" name="jetonomy_settings[captcha_provider]" class="jt-captcha-provider-select">
								<option value="none" <?php selected( $settings['captcha_provider'] ?? 'none', 'none' ); ?>><?php esc_html_e( 'Disabled', 'jetonomy' ); ?></option>
								<option value="recaptcha_v3" <?php selected( $settings['captcha_provider'] ?? '', 'recaptcha_v3' ); ?>><?php esc_html_e( 'Google reCAPTCHA v3 (invisible)', 'jetonomy' ); ?></option>
								<option value="turnstile" <?php selected( $settings['captcha_provider'] ?? '', 'turnstile' ); ?>><?php esc_html_e( 'Cloudflare Turnstile (privacy-friendly)', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="captcha_site_key"><?php esc_html_e( 'Site Key', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="captcha_site_key" name="jetonomy_settings[captcha_site_key]" value="<?php echo esc_attr( $settings['captcha_site_key'] ?? '' ); ?>" class="regular-text">
							<p class="description">
								<?php
								printf(
									/* translators: %s: link to the Google reCAPTCHA admin console. */
									esc_html__( 'reCAPTCHA: get keys at %s.', 'jetonomy' ),
									'<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">google.com/recaptcha/admin</a>'
								);
								echo ' ';
								printf(
									/* translators: %s: link to the Cloudflare Turnstile dashboard. */
									esc_html__( 'Turnstile: get keys at %s.', 'jetonomy' ),
									'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">dash.cloudflare.com</a>'
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="captcha_secret_key"><?php esc_html_e( 'Secret Key', 'jetonomy' ); ?></label></th>
						<td>
							<input type="password" id="captcha_secret_key" name="jetonomy_settings[captcha_secret_key]" value="<?php echo esc_attr( $settings['captcha_secret_key'] ?? '' ); ?>" class="regular-text" autocomplete="new-password">
							<p class="description"><?php esc_html_e( 'Stored securely. Never shared with visitors.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr class="jt-captcha-recaptcha-only" <?php echo ( ( $settings['captcha_provider'] ?? 'none' ) !== 'recaptcha_v3' ) ? 'style="display:none"' : ''; ?>>
						<th scope="row"><label for="captcha_score_threshold"><?php esc_html_e( 'Score Threshold', 'jetonomy' ); ?></label></th>
						<td>
							<input type="number" id="captcha_score_threshold" name="jetonomy_settings[captcha_score_threshold]" value="<?php echo esc_attr( $settings['captcha_score_threshold'] ?? '0.5' ); ?>" min="0.1" max="0.9" step="0.1" class="small-text">
							<p class="description"><?php esc_html_e( 'reCAPTCHA v3 only. Scores below this value are treated as bots (0.1 = permissive, 0.9 = strict). Default: 0.5.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
			</div>

		<?php elseif ( 'free-vs-pro' === $active_tab && ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>

			<!-- Hero -->
			<div class="jt-settings-card" style="background: linear-gradient(135deg, var(--jt-admin-pro-light, #EDE9FE), var(--jt-admin-warn-light, #FEF3C7)); border: none;">
				<div style="text-align: center; padding: 12px 0;">
					<h2 style="margin: 0 0 8px; font-size: 22px; color: var(--jt-admin-text, #1F2937);"><?php esc_html_e( 'Unlock Pro Extensions', 'jetonomy' ); ?></h2>
					<p style="margin: 0 0 16px; color: var(--jt-admin-text-2, #4B5563); font-size: 14px; max-width: 520px; margin-left: auto; margin-right: auto;">
						<?php esc_html_e( 'Your community is growing. Give it reactions, messaging, polls, analytics, badges, webhooks, and more. Each feature is an independent module you enable only when you need it.', 'jetonomy' ); ?>
					</p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button button-primary button-hero" target="_blank" style="font-size: 14px; padding: 8px 28px;">
						<?php esc_html_e( 'Get Jetonomy Pro. Starting at $69/yr.', 'jetonomy' ); ?>
					</a>
					<p style="margin: 8px 0 0; font-size: 12px; color: var(--jt-admin-text-3, #6B7280);">
						<?php
						/* translators: %s: coupon code */
						printf( esc_html__( 'Use code %s for 30%% off lifetime plans.', 'jetonomy' ), '<strong>Jetonomy30</strong>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static trusted HTML tag.
						?>
					</p>
				</div>
			</div>

			<!-- Extensions Grid -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Pro Extensions', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Each extension is independent. Enable only what you need. Disabled extensions load zero code.', 'jetonomy' ); ?></p>
				</div>
				<?php
				$jt_pro_exts = array(
					array(
						'name' => __( 'Emoji Reactions', 'jetonomy' ),
						'icon' => 'dashicons-heart',
						/* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: reply label, plural, lowercase (e.g. "replies"). */
						'desc' => sprintf( __( 'Like, love, and celebrate with Slack-style reactions on %1$s and %2$s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Private Messaging', 'jetonomy' ),
						'icon' => 'dashicons-format-chat',
						/* translators: %s: member label, plural, lowercase (e.g. "members"). */
						'desc' => sprintf( __( 'One-on-one and group conversations between community %s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Polls', 'jetonomy' ),
						'icon' => 'dashicons-chart-bar',
						/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
						'desc' => sprintf( __( 'Create polls within %s for community voting and decision-making.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Analytics Dashboard', 'jetonomy' ),
						'icon' => 'dashicons-chart-area',
						/* translators: 1: the plural space label the site owner configured (e.g. spaces, groups). 2: topic label, lowercase (e.g. "topic"). */
						'desc' => sprintf( __( 'Engagement graphs, user growth, top %1$s, %2$s trends, and CSV export.', 'jetonomy' ), \Jetonomy\space_label( true, true ), \Jetonomy\jetonomy_label( 'topic', false, true ) ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Email Digests', 'jetonomy' ),
						'icon' => 'dashicons-email',
						'desc' => __( 'Daily and weekly email digests of community activity for subscribed users.', 'jetonomy' ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Web Push', 'jetonomy' ),
						'icon' => 'dashicons-bell',
						/* translators: %s: reply label, plural, lowercase (e.g. "replies"). */
						'desc' => sprintf( __( 'Browser push notifications for %s, mentions, and forum events.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'reply', true, true ) ),
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Webhooks', 'jetonomy' ),
						'icon' => 'dashicons-rest-api',
						'desc' => __( 'Fire HTTP POST requests to Zapier, Slack, n8n, or any endpoint on forum events.', 'jetonomy' ), // jt-terms-ok: the HTTP method.
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Reply by Email', 'jetonomy' ), // jt-terms-ok: Pro add-on name; "Reply" is the verb.
						'icon' => 'dashicons-email-alt2',
						/* translators: %s: member label, plural (e.g. "Members"). */
						'desc' => sprintf( __( '%s reply to notifications by email. No login required.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ), // jt-terms-ok: "reply" left in the text is the verb.
						'tier' => 'Starter',
					),
					array(
						'name' => __( 'Custom Badges', 'jetonomy' ),
						'icon' => 'dashicons-awards',
						'desc' => __( 'Create and auto-award custom badges based on community activity criteria.', 'jetonomy' ),
						'tier' => 'Growth',
					),
					array(
						'name' => __( 'Custom Fields', 'jetonomy' ),
						'icon' => 'dashicons-forms',
						/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
						'desc' => sprintf( __( 'Add custom fields to %s and user profiles: text, select, checkbox, date, and more.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ),
						'tier' => 'Growth',
					),
					array(
						'name' => __( 'Advanced Moderation', 'jetonomy' ),
						'icon' => 'dashicons-shield',
						'desc' => __( 'Auto-moderation rules engine: keyword filters, regex, link limits, and spam scoring.', 'jetonomy' ),
						'tier' => 'Growth',
					),
					array(
						'name' => __( 'SEO Pro', 'jetonomy' ),
						'icon' => 'dashicons-search',
						/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
						'desc' => sprintf( __( 'Per-%s meta titles, Open Graph, Twitter Cards, Schema.org, sitemap controls.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
						'tier' => 'Growth',
					),
					array(
						'name' => __( 'White Label', 'jetonomy' ),
						'icon' => 'dashicons-admin-appearance',
						'desc' => __( 'Replace Jetonomy branding: community name, footer text, and wp-admin menu label and icon.', 'jetonomy' ),
						'tier' => 'Agency',
					),
				);
				?>
				<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; padding: 16px;">
					<?php foreach ( $jt_pro_exts as $ext ) : ?>
					<div style="border: 1px solid var(--jt-admin-border, #E5E7EB); border-radius: 8px; padding: 16px; background: var(--jt-admin-white, #fff);">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
							<span class="dashicons <?php echo esc_attr( $ext['icon'] ); ?>" style="color: var(--jt-admin-pro, #7C3AED); font-size: 18px; width: 18px; height: 18px;"></span>
							<strong style="font-size: 13px;"><?php echo esc_html( $ext['name'] ); ?></strong>
							<span class="jt-tier-badge jt-tier-badge--<?php echo esc_attr( sanitize_html_class( strtolower( (string) $ext['tier'] ) ) ); ?>">
								<?php
								$jt_tier_names = array(
									'Starter' => __( 'Starter', 'jetonomy' ),
									'Growth'  => __( 'Growth', 'jetonomy' ),
									'Agency'  => __( 'Agency', 'jetonomy' ),
								);
								echo esc_html( $jt_tier_names[ $ext['tier'] ] );
								?>
							</span>
						</div>
						<p style="margin: 0; font-size: 12.5px; color: var(--jt-admin-text-3, #6B7280); line-height: 1.5;"><?php echo esc_html( $ext['desc'] ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>

		<?php elseif ( 'license' === $active_tab && defined( 'JETONOMY_PRO_VERSION' ) ) : ?>

			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Jetonomy Pro License', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Activate or manage your license key to unlock Pro extensions.', 'jetonomy' ); ?></p>
				</div>
				<?php
				/**
				 * Fires inside the license card on the License settings tab, where Pro prints its license form.
				 *
				 * @since 1.0.0
				 */
				?>
				<?php do_action( 'jetonomy_admin_license_tab_content' ); ?>
			</div>

		<?php elseif ( 'integrations' === $active_tab ) : ?>

			<?php require __DIR__ . '/../../integrations/views/companion-cards.php'; ?>

			<?php if ( $jt_bp_active ) : ?>
			<form method="post" action="options.php" id="jetonomy-integrations-form">
				<?php settings_fields( 'jetonomy_integrations' ); ?>
				<div class="jt-settings-card">
					<div class="jt-settings-card__head">
						<p class="jt-settings-card__title"><?php esc_html_e( 'BuddyPress', 'jetonomy' ); ?></p>
						<p class="jt-settings-card__desc"><?php /* translators: %s: product name shown in wp-admin (default "Jetonomy"). */ echo esc_html( sprintf( __( 'Control how %s and BuddyPress group activity stay in sync.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name() ) ); ?></p>
					</div>
					<?php
					$jt_bp_broadcast = '0' !== (string) get_option( 'jetonomy_bp_broadcast', '1' );
					$jt_bp_bridge    = '0' !== (string) get_option( 'jetonomy_bp_comment_bridge', '1' );
					?>
					<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin stacks label/field rows below 782px -->
						<tr>
							<th scope="row"><?php /* translators: %s: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Broadcast %s to group activity', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></th>
							<td>
								<label for="jetonomy_bp_broadcast">
									<input type="hidden" name="jetonomy_bp_broadcast" value="0">
									<input type="checkbox" id="jetonomy_bp_broadcast" name="jetonomy_bp_broadcast" value="1" <?php checked( $jt_bp_broadcast ); ?>>
									<?php /* translators: 1: product name shown in wp-admin (default "Jetonomy"). 2: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Post new %1$s %2$s to the paired BuddyPress group activity stream.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name(), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); // jt-terms-ok: "post" is the verb here. ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Round-trip activity comments', 'jetonomy' ); ?></th>
							<td>
								<label for="jetonomy_bp_comment_bridge">
									<input type="hidden" name="jetonomy_bp_comment_bridge" value="0">
									<input type="checkbox" id="jetonomy_bp_comment_bridge" name="jetonomy_bp_comment_bridge" value="1" <?php checked( $jt_bp_bridge ); ?>>
									<?php /* translators: 1: product name shown in wp-admin (default "Jetonomy"). 2: reply label, plural, lowercase (e.g. "replies"). */ echo esc_html( sprintf( __( 'Mirror BuddyPress activity comments on broadcast items back as %1$s %2$s.', 'jetonomy' ), \Jetonomy\Admin\Admin::brand_name(), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
								</label>
								<p class="description"><?php /* translators: %s: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Requires "Broadcast %s to group activity" to be enabled.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></p>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Save Settings', 'jetonomy' ) ); ?>
				</div>
			</form>
			<?php endif; ?>

		<?php endif; ?>

		<?php
			// Render primary tabs inline; Pro/extension tabs were pre-buffered at the top.
		if ( in_array( $active_tab, $jt_primary_tabs, true ) ) {
			do_action( 'jetonomy_admin_settings_tab_content', $active_tab );
		} elseif ( $jt_ext_html ) {
			echo $jt_ext_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by each extension
		}
		?>

				</div><!-- /.jt-settings-cards -->

			<?php if ( in_array( $active_tab, $jt_primary_tabs, true ) && 'free-vs-pro' !== $active_tab ) : ?>
				<?php submit_button( __( 'Save Settings', 'jetonomy' ) ); ?>
			</form>
			<?php endif; ?>
		</div><!-- /.jt-settings-main -->
	</div><!-- /.jt-settings-layout -->
</div>
