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
	ob_start();
	do_action( 'jetonomy_admin_settings_tabs', $active_tab );
	$advanced_tabs_html = ob_get_clean();

	// Pre-buffer Pro/extension tab content so notices can be hoisted above the layout.
	$jt_primary_tabs = [ 'general', 'permissions', 'email', 'appearance', 'seo', 'antispam', 'free-vs-pro' ];
	$jt_ext_html     = '';
	$jt_ext_notices  = '';
	if ( ! in_array( $active_tab, $jt_primary_tabs, true ) && 'license' !== $active_tab ) {
		ob_start();
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

	$tab_icons  = [
		'general'     => 'dashicons-admin-settings',
		'permissions' => 'dashicons-shield',
		'email'       => 'dashicons-email-alt',
		'appearance'  => 'dashicons-admin-appearance',
		'seo'         => 'dashicons-search',
		'antispam'    => 'dashicons-lock',
	];
	$tab_labels = [
		'general'     => __( 'General', 'jetonomy' ),
		'permissions' => __( 'Permissions', 'jetonomy' ),
		'email'       => __( 'Email', 'jetonomy' ),
		'appearance'  => __( 'Appearance', 'jetonomy' ),
		'seo'         => __( 'SEO', 'jetonomy' ),
		'antispam'    => __( 'Anti-Spam', 'jetonomy' ),
	];
	?>

	<?php settings_errors(); ?>
	<?php
	if ( $jt_ext_notices ) {
		echo $jt_ext_notices;} // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by each extension
	?>

	<div class="jt-settings-layout">

		<aside class="jt-settings-sidebar">
			<div class="jt-settings-sidebar-brand">
				<span class="dashicons dashicons-admin-settings jt-settings-brand-icon" aria-hidden="true"></span>
				<div class="jt-settings-brand-text">
					<p class="jt-settings-brand-name">Jetonomy</p>
					<p class="jt-settings-brand-sub"><?php esc_html_e( 'Settings', 'jetonomy' ); ?></p>
				</div>
			</div>
			<nav class="jt-settings-sidebar-nav" aria-label="<?php esc_attr_e( 'Settings navigation', 'jetonomy' ); ?>">
				<?php foreach ( $tab_labels as $slug => $label ) : ?>
				<a href="<?php echo esc_url( $settings_url . '&tab=' . $slug ); ?>"
					class="jt-snav-link<?php echo esc_attr( $active_tab === $slug ? ' jt-snav-link--active' : '' ); ?>">
					<span class="dashicons <?php echo esc_attr( $tab_icons[ $slug ] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</a>
				<?php endforeach; ?>

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
				<a href="<?php echo esc_url( $settings_url . '&tab=license' ); ?>"
					class="jt-snav-link<?php echo esc_attr( 'license' === $active_tab ? ' jt-snav-link--active' : '' ); ?>">
					<span class="dashicons dashicons-shield-alt" aria-hidden="true"></span>
					<?php esc_html_e( 'License', 'jetonomy' ); ?>
				</a>
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
				<table class="form-table">
					<tr>
						<th scope="row"><label for="base_slug"><?php esc_html_e( 'Community Base URL', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="base_slug" name="jetonomy_settings[base_slug]" value="<?php echo esc_attr( $settings['base_slug'] ?? 'community' ); ?>" class="regular-text">
							<p class="description"><?php echo esc_html( home_url( '/' ) ); ?><strong><?php echo esc_html( $settings['base_slug'] ?? 'community' ); ?></strong>/</p>
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
						<th scope="row"><label for="default_space_type"><?php esc_html_e( 'Default Space Type', 'jetonomy' ); ?></label></th>
						<td>
							<select id="default_space_type" name="jetonomy_settings[default_space_type]">
								<option value="forum" <?php selected( $settings['default_space_type'] ?? 'forum', 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
								<option value="qa" <?php selected( $settings['default_space_type'] ?? '', 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
								<option value="ideas" <?php selected( $settings['default_space_type'] ?? '', 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
								<option value="feed" <?php selected( $settings['default_space_type'] ?? '', 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'The pre-selected type when creating a new space.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Front-end space creation', 'jetonomy' ); ?></th>
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
								} elseif ( preg_match( '/^(ld_|tutor_|lms_|instructor|teacher|student|group_leader|memberpress|pmpro|wlm_)/i', $jt_rk ) ) {
									$jt_role_groups['lms_memberships']['keys'][] = $jt_rk;
								} elseif ( preg_match( '/^(shop_|customer|wc_|edd_|wpforms)/i', $jt_rk ) ) {
									$jt_role_groups['commerce']['keys'][] = $jt_rk;
								} else {
									$jt_role_groups['other']['keys'][] = $jt_rk;
								}
							}
							?>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Roles allowed to create spaces from the front end', 'jetonomy' ); ?></legend>
								<?php foreach ( $jt_role_groups as $jt_group ) : ?>
									<?php if ( ! empty( $jt_group['keys'] ) ) : ?>
										<p style="margin:12px 0 4px;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;color:#646970;"><?php echo esc_html( $jt_group['label'] ); ?></p>
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
							<p class="description" style="margin-block-start:12px;"><?php esc_html_e( 'Site administrators always qualify. Tick any additional WordPress roles you trust to create spaces from /community/new-space/. Leave every box unticked to keep front-end space creation admin-only.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Email verification', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[require_email_verification]" value="1" <?php checked( ! empty( $settings['require_email_verification'] ) ); ?>>
								<?php esc_html_e( 'Require new members to confirm their email before they can sign in', 'jetonomy' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'When on, the Login block sends a confirmation email after sign-up. Members can\'t log in until they click the link. Existing members are not affected.', 'jetonomy' ); ?></p>
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
				<table class="form-table">
					<tr>
						<th scope="row"><label for="posts_per_page"><?php esc_html_e( 'Posts Per Page', 'jetonomy' ); ?></label></th>
						<td><input type="number" id="posts_per_page" name="jetonomy_settings[posts_per_page]" value="<?php echo absint( $settings['posts_per_page'] ?? 20 ); ?>" min="5" max="100" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="replies_per_page"><?php esc_html_e( 'Replies Per Page', 'jetonomy' ); ?></label></th>
						<td><input type="number" id="replies_per_page" name="jetonomy_settings[replies_per_page]" value="<?php echo absint( $settings['replies_per_page'] ?? 30 ); ?>" min="5" max="100" class="small-text"></td>
					</tr>
				</table>
			</div>

			<!-- Access Control -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Access Control', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Who can read and participate in your community.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table">
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
										<?php esc_html_e( 'Anyone can read topics and replies. Visitors must log in to post, reply, or vote.', 'jetonomy' ); ?>
									</span>
								</label>
								<label style="display:block;">
									<input type="radio" name="jetonomy_settings[guest_read]" value="0" <?php checked( ! $is_public ); ?>>
									<strong><?php esc_html_e( 'Private community', 'jetonomy' ); ?></strong>
									<span class="description" style="display:block;margin-left:24px;">
										<?php esc_html_e( 'Only logged-in members can view any forum content. Everyone else is redirected to the login page.', 'jetonomy' ); ?>
									</span>
								</label>
							</fieldset>
						</td>
					</tr>
				</table>
			</div>

		<?php elseif ( 'permissions' === $active_tab ) : ?>

			<?php
			$thresholds  = $settings['trust_thresholds'] ?? [];
			$rate_limits = $settings['rate_limits'] ?? [];
			$tl_defaults = \Jetonomy\Trust\Trust_Levels::defaults();
			$rl_defaults = \Jetonomy\Permissions\Rate_Limiter::defaults();
			$level_names = [
				1 => __( 'Level 1: Member', 'jetonomy' ),
				2 => __( 'Level 2: Regular', 'jetonomy' ),
				3 => __( 'Level 3: Trusted', 'jetonomy' ),
			];
			?>

			<!-- Trust Levels -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Trust Level Thresholds', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Define what users must achieve to advance through trust levels. Higher levels unlock posting privileges and reduce moderation scrutiny.', 'jetonomy' ); ?></p>
				</div>
				<table class="wp-list-table widefat fixed" style="margin:0;border:none;box-shadow:none;border-radius:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Level', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Posts Required', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Days Active', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Reputation', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Replies Received', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						for ( $level = 1; $level <= 3; $level++ ) :
							$td = $tl_defaults[ $level ];
							?>
							<tr>
								<td><strong><?php echo esc_html( $level_names[ $level ] ); ?></strong></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][posts]" value="<?php echo absint( $thresholds[ $level ]['posts'] ?? $td['posts'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][days_active]" value="<?php echo absint( $thresholds[ $level ]['days_active'] ?? $td['days_active'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][reputation]" value="<?php echo absint( $thresholds[ $level ]['reputation'] ?? $td['reputation'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo absint( $level ); ?>][replies_received]" value="<?php echo absint( $thresholds[ $level ]['replies_received'] ?? $td['replies_received'] ); ?>" min="0" class="small-text"></td>
							</tr>
						<?php endfor; ?>
					</tbody>
				</table>
			</div>

			<!-- Rate Limits -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Rate Limits for New Users (Level 0)', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Limit how much brand-new users can post in a single day to reduce spam.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Posts per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" name="jetonomy_settings[rate_limits][posts]" value="<?php echo absint( $rate_limits['posts'] ?? $rl_defaults['posts'] ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Replies per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" name="jetonomy_settings[rate_limits][replies]" value="<?php echo absint( $rate_limits['replies'] ?? $rl_defaults['replies'] ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Votes per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" name="jetonomy_settings[rate_limits][votes]" value="<?php echo absint( $rate_limits['votes'] ?? $rl_defaults['votes'] ); ?>" min="1" class="small-text"></td>
					</tr>
				</table>
			</div>

			<!-- Role Mapping (read-only reference) -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'WordPress Role Mapping', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Jetonomy capabilities are auto-assigned based on the user\'s WordPress role. This mapping is fixed.', 'jetonomy' ); ?></p>
				</div>
				<table class="wp-list-table widefat fixed" style="margin:0;border:none;box-shadow:none;border-radius:0;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'WordPress Role', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Jetonomy Capabilities', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Administrator', 'jetonomy' ); ?></strong></td>
							<td><?php esc_html_e( 'All capabilities: manage settings, manage spaces, moderate', 'jetonomy' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Editor', 'jetonomy' ); ?></strong></td>
							<td><?php esc_html_e( 'Moderate content, manage spaces', 'jetonomy' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Author / Contributor', 'jetonomy' ); ?></strong></td>
							<td><?php esc_html_e( 'Create posts and replies (standard participant)', 'jetonomy' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Subscriber', 'jetonomy' ); ?></strong></td>
							<td><?php esc_html_e( 'Read public spaces, create posts and replies', 'jetonomy' ); ?></td>
						</tr>
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
				<table class="form-table">
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
						<th scope="row"><?php esc_html_e( 'Email Adapter', 'jetonomy' ); ?></th>
						<td>
							<select disabled>
								<option><?php esc_html_e( 'WordPress Default (wp_mail)', 'jetonomy' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Uses your WordPress email configuration. SMTP plugins are supported.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Test Email', 'jetonomy' ); ?></th>
						<td>
							<button type="button" class="button" id="jetonomy-test-email">
								<span class="dashicons dashicons-email-alt" style="vertical-align:text-bottom;"></span>
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
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Default on/off state for each notification type. Members can override these in their profile settings.', 'jetonomy' ); ?></p>
				</div>
				<?php
				$notif_defaults = $settings['notification_defaults'] ?? [];
				$notif_types    = [
					'reply_to_post'   => __( 'Reply to your post', 'jetonomy' ),
					'reply_to_reply'  => __( 'Reply to your reply', 'jetonomy' ),
					'mention'         => __( 'Mention (@username)', 'jetonomy' ),
					'accepted_answer' => __( 'Your answer accepted', 'jetonomy' ),
					'new_post_in_sub' => __( 'New post in subscribed space', 'jetonomy' ),
					'badge_earned'    => __( 'Badge earned', 'jetonomy' ),
					'vote_on_post'    => __( 'Vote on your post', 'jetonomy' ),
					'moderation'      => __( 'Moderator action on your content', 'jetonomy' ),
					'join_request'    => __( 'Space join request', 'jetonomy' ),
				];
				?>
				<table class="jt-notif-defaults-table">
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
							$web_on   = isset( $notif_defaults[ $type ]['web'] ) ? (bool) $notif_defaults[ $type ]['web'] : true;
							$email_on = isset( $notif_defaults[ $type ]['email'] ) ? (bool) $notif_defaults[ $type ]['email'] : true;
							?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td>
									<input type="checkbox"
										name="jetonomy_settings[notification_defaults][<?php echo esc_attr( $type ); ?>][web]"
										value="1"
										<?php checked( $web_on ); ?>>
								</td>
								<td>
									<input type="checkbox"
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
						<code>{site}</code> <code>{user}</code> <code>{message}</code> <code>{type}</code> <code>{url}</code>
					</p>
				</div>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="email_footer_text"><?php esc_html_e( 'Footer Text', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="email_footer_text" name="jetonomy_settings[email_footer_text]" value="<?php echo esc_attr( $settings['email_footer_text'] ?? '' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'You received this because you are a member of the community.', 'jetonomy' ); ?>">
							<p class="description"><?php esc_html_e( 'Appears at the bottom of every branded notification email.', 'jetonomy' ); ?></p>
						</td>
					</tr>
				</table>
				<?php
				$email_templates = get_option( 'jetonomy_email_templates', array() );
				$tmpl_types      = array(
					'user_welcome'          => __( 'Welcome: new member', 'jetonomy' ),
					'reply_to_post'         => __( 'Reply to your post', 'jetonomy' ),
					'reply_to_reply'        => __( 'Reply to your reply', 'jetonomy' ),
					'mention'               => __( 'Mention (@username)', 'jetonomy' ),
					'accepted_answer'       => __( 'Your answer accepted', 'jetonomy' ),
					'new_post_in_sub'       => __( 'New post in subscribed space', 'jetonomy' ),
					'badge_earned'          => __( 'Badge earned', 'jetonomy' ),
					'vote_on_post'          => __( 'Vote on your post', 'jetonomy' ),
					'moderation'            => __( 'Moderator action', 'jetonomy' ),
					'join_request'          => __( 'Space join request', 'jetonomy' ),
					'verification_reminder' => __( 'Verification reminder', 'jetonomy' ),
				);
				?>
				<table class="widefat striped jetonomy-email-templates-table" style="margin-top:12px;">
					<thead>
						<tr>
							<th style="width:220px;"><?php esc_html_e( 'Notification', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'jetonomy' ); ?></th>
							<th><?php esc_html_e( 'Body / Intro', 'jetonomy' ); ?></th>
							<th style="width:180px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $tmpl_types as $type => $label ) :
							$row     = isset( $email_templates[ $type ] ) && is_array( $email_templates[ $type ] ) ? $email_templates[ $type ] : array();
							$subject = isset( $row['subject'] ) ? (string) $row['subject'] : '';
							$body    = isset( $row['body'] ) ? (string) $row['body'] : '';
							?>
							<tr data-jt-email-type="<?php echo esc_attr( $type ); ?>">
								<td><strong><?php echo esc_html( $label ); ?></strong><br><code style="font-size:11px;color:#646970;"><?php echo esc_html( $type ); ?></code></td>
								<td>
									<input type="text"
										name="jetonomy_email_templates[<?php echo esc_attr( $type ); ?>][subject]"
										value="<?php echo esc_attr( $subject ); ?>"
										class="large-text jetonomy-email-subject-input"
										placeholder="[{site}] {message}">
								</td>
								<td>
									<textarea
										name="jetonomy_email_templates[<?php echo esc_attr( $type ); ?>][body]"
										rows="2"
										class="large-text jetonomy-email-body-input"
										placeholder="{message}"><?php echo esc_textarea( $body ); ?></textarea>
								</td>
								<td class="jetonomy-email-actions">
									<button type="button" class="button button-small jetonomy-email-preview-btn" data-type="<?php echo esc_attr( $type ); ?>">
										<?php esc_html_e( 'Preview', 'jetonomy' ); ?>
									</button>
									<button type="button" class="button button-small jetonomy-email-send-btn" data-type="<?php echo esc_attr( $type ); ?>" data-label="<?php esc_attr_e( 'Send test', 'jetonomy' ); ?>">
										<?php esc_html_e( 'Send test', 'jetonomy' ); ?>
									</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<!-- Email preview modal -->
			<div class="jetonomy-modal" id="jetonomy-email-preview-modal" style="display:none;">
				<div class="jetonomy-modal__overlay"></div>
				<div class="jetonomy-modal__content" style="max-width:720px;">
					<h2 id="jetonomy-email-preview-subject"><?php esc_html_e( 'Email Preview', 'jetonomy' ); ?></h2>
					<p class="description" style="margin:0 0 12px;color:#646970;">
						<?php esc_html_e( 'Preview rendered with sample data. Save the page to persist overrides.', 'jetonomy' ); ?>
					</p>
					<iframe id="jetonomy-email-preview-iframe" style="width:100%;height:520px;border:1px solid #dcdcde;border-radius:6px;background:#fff;" title="Email preview"></iframe>
					<p class="jetonomy-modal__actions">
						<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Close', 'jetonomy' ); ?></button>
					</p>
				</div>
			</div>

			<script>
			( function () {
				const nonce = ( window.jetonomyAdmin && window.jetonomyAdmin.nonce ) || '';
				const ajax  = ( window.jetonomyAdmin && window.jetonomyAdmin.ajaxUrl ) || window.ajaxurl;
				if ( ! nonce || ! ajax ) { return; }

				const modal   = document.getElementById( 'jetonomy-email-preview-modal' );
				const iframe  = document.getElementById( 'jetonomy-email-preview-iframe' );
				const subjEl  = document.getElementById( 'jetonomy-email-preview-subject' );

				function closeModal() { modal.style.display = 'none'; }
				function openModal()  { modal.style.display = ''; }

				modal.querySelectorAll( '.jetonomy-modal-close, .jetonomy-modal__overlay' ).forEach( el => {
					el.addEventListener( 'click', closeModal );
				} );
				document.addEventListener( 'keydown', e => {
					if ( 'Escape' === e.key && 'none' !== modal.style.display ) { closeModal(); }
				} );

				function rowFields( type ) {
					const row = document.querySelector( '[data-jt-email-type="' + type + '"]' );
					return {
						subject: row ? ( row.querySelector( '.jetonomy-email-subject-input' )?.value || '' ) : '',
						body:    row ? ( row.querySelector( '.jetonomy-email-body-input' )?.value || '' ) : ''
					};
				}

				// Preview — modal with branded HTML in an iframe (srcdoc).
				document.querySelectorAll( '.jetonomy-email-preview-btn' ).forEach( btn => {
					btn.addEventListener( 'click', async () => {
						const type = btn.dataset.type;
						const f    = rowFields( type );
						const body = new FormData();
						body.append( 'action', 'jetonomy_email_preview' );
						body.append( 'nonce', nonce );
						body.append( 'type', type );
						body.append( 'subject', f.subject );
						body.append( 'body', f.body );

						const res  = await fetch( ajax, { method: 'POST', credentials: 'same-origin', body } );
						const json = await res.json();
						if ( ! json.success ) {
							( window.jetonomyAlert || window.alert )( ( json.data && json.data.message ) || json.data || '<?php echo esc_js( __( 'Preview failed.', 'jetonomy' ) ); ?>' );
							return;
						}
						subjEl.textContent = json.data.subject || '<?php echo esc_js( __( 'Email Preview', 'jetonomy' ) ); ?>';
						// srcdoc takes a string of HTML and sandboxes it inside
						// the iframe — safer than writing into the parent document.
						iframe.srcdoc = json.data.html;
						openModal();
					} );
				} );

				// Send test — deliver a real email with this type to admin_email.
				document.querySelectorAll( '.jetonomy-email-send-btn' ).forEach( btn => {
					btn.addEventListener( 'click', async () => {
						const type   = btn.dataset.type;
						const label  = btn.dataset.label || btn.textContent;
						btn.disabled = true;
						btn.textContent = '<?php echo esc_js( __( 'Sending…', 'jetonomy' ) ); ?>';

						const body = new FormData();
						body.append( 'action', 'jetonomy_test_email' );
						body.append( 'nonce', nonce );
						body.append( 'type', type );

						const res  = await fetch( ajax, { method: 'POST', credentials: 'same-origin', body } );
						const json = await res.json();
						btn.disabled    = false;
						btn.textContent = label;
						const msg = ( json.data && json.data.message ) || json.data || '';
						( window.jetonomyAlert || window.alert )( msg || ( json.success ? '<?php echo esc_js( __( 'Sent.', 'jetonomy' ) ); ?>' : '<?php echo esc_js( __( 'Failed to send.', 'jetonomy' ) ); ?>' ) );
					} );
				} );
			} )();
			</script>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'Email Digest', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Send daily or weekly email digests to re-engage members who haven\'t visited recently.', 'jetonomy' ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'appearance' === $active_tab ) : ?>

			<!-- Theme Integration -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Theme Integration', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Control how Jetonomy adopts your active theme\'s design tokens.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Inherit from Theme', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="jetonomy_settings[inherit_fonts]" value="1" <?php checked( $settings['inherit_fonts'] ?? true ); ?>>
									<?php esc_html_e( 'Inherit theme fonts', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="jetonomy_settings[inherit_colors]" value="1" <?php checked( $settings['inherit_colors'] ?? true ); ?>>
									<?php esc_html_e( 'Inherit theme colors', 'jetonomy' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="accent_color"><?php esc_html_e( 'Custom Accent Color', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="accent_color" name="jetonomy_settings[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ?? '#0073aa' ); ?>" class="jetonomy-color-picker">
							<p class="description"><?php esc_html_e( 'Applied when "Inherit theme colors" is off.', 'jetonomy' ); ?></p>
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
				<table class="form-table">
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
							<p class="description"><?php esc_html_e( 'Applies to community pages only (Spaces, Discussions, Profile, etc.).', 'jetonomy' ); ?></p>
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
				<table class="form-table">
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
					<p><?php esc_html_e( 'Remove Jetonomy branding and replace it with your own logo and color scheme.', 'jetonomy' ); ?></p>
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
				<table class="form-table">
					<tr>
						<th scope="row"><label for="seo_post_title"><?php esc_html_e( 'Post Title', 'jetonomy' ); ?></label></th>
						<td>
							<input type="text" id="seo_post_title" name="jetonomy_settings[seo_post_title]" value="<?php echo esc_attr( $settings['seo_post_title'] ?? '{post_title} - {space_name} | {site_name}' ); ?>" class="large-text">
							<p class="description"><?php esc_html_e( 'Tokens: {post_title}, {space_name}, {site_name}', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="seo_space_title"><?php esc_html_e( 'Space Title', 'jetonomy' ); ?></label></th>
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
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Schema Markup', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_schema]" value="1" <?php checked( $settings['seo_schema'] ?? true ); ?>>
								<?php esc_html_e( 'Enable DiscussionForumPosting schema on post pages', 'jetonomy' ); ?>
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
							<p class="description"><?php esc_html_e( 'Confirms /wp-sitemap.xml is reachable and that community URLs (spaces + posts) are listed. New spaces can take a few minutes to appear after the next ping.', 'jetonomy' ); ?></p>
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
				<table class="form-table">
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
								esc_html__( 'Go to %1$s and request the %2$s permission. Meta typically approves in 1–3 business days. Your app stays in Development Mode until approved; embeds will work for the admin who created the app even before approval.', 'jetonomy' ),
								'<strong>' . esc_html__( 'App Review → Requests', 'jetonomy' ) . '</strong>',
								'<code>oembed_read</code>'
							);
							?>
						</li>
						<li>
							<?php esc_html_e( 'Save the settings here. Instagram and Facebook URLs pasted into posts and replies will now unfurl as rich embeds.', 'jetonomy' ); ?>
						</li>
					</ol>
					<p style="margin:12px 0 0;padding:10px 12px;background:var(--jt-warn-light,#fff8e5);border-left:3px solid var(--jt-warn,#dba617);border-radius:4px;font-size:13px;">
						<strong><?php esc_html_e( 'Privacy note:', 'jetonomy' ); ?></strong>
						<?php esc_html_e( 'Jetonomy only sends oEmbed requests to Meta when a user pastes an Instagram/Facebook URL. No tracking, no user data: just the public post URL and your app token. Leave these fields blank to skip Instagram/Facebook embeds entirely; the URL will render as a plain clickable link.', 'jetonomy' ); ?>
					</p>
				</details>
			</div>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'SEO Pro', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Open Graph tags, per-space canonical URLs, breadcrumb schema, and advanced robots control.', 'jetonomy' ); ?></p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'antispam' === $active_tab ) : ?>

			<!-- CAPTCHA Provider -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'CAPTCHA Provider', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Protect post and reply forms from bots. Trusted members (trust level 2+) are always exempt.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table">
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
								<?php esc_html_e( 'reCAPTCHA: get keys at', 'jetonomy' ); ?>
								<a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener noreferrer">google.com/recaptcha/admin</a>.
								<?php esc_html_e( 'Turnstile: get keys at', 'jetonomy' ); ?>
								<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener noreferrer">dash.cloudflare.com</a>.
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

			<script>
			( function () {
				var sel = document.getElementById( 'captcha_provider' );
				var rcRow = document.querySelector( '.jt-captcha-recaptcha-only' );
				if ( ! sel || ! rcRow ) return;
				sel.addEventListener( 'change', function () {
					rcRow.style.display = this.value === 'recaptcha_v3' ? '' : 'none';
				} );
			} )();
			</script>

		<?php elseif ( 'free-vs-pro' === $active_tab && ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>

			<!-- Hero -->
			<div class="jt-settings-card" style="background: linear-gradient(135deg, #EDE9FE, #FEF3C7); border: none;">
				<div style="text-align: center; padding: 12px 0;">
					<h2 style="margin: 0 0 8px; font-size: 22px; color: #1F2937;"><?php esc_html_e( 'Unlock 13 Pro Extensions', 'jetonomy' ); ?></h2>
					<p style="margin: 0 0 16px; color: #4B5563; font-size: 14px; max-width: 520px; margin-left: auto; margin-right: auto;">
						<?php esc_html_e( 'Your community is growing. Give it reactions, messaging, polls, analytics, badges, webhooks, and more. Each feature is an independent module you enable only when you need it.', 'jetonomy' ); ?>
					</p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button button-primary button-hero" target="_blank" style="font-size: 14px; padding: 8px 28px;">
						<?php esc_html_e( 'Get Jetonomy Pro. Starting at $69/yr.', 'jetonomy' ); ?>
					</a>
					<p style="margin: 8px 0 0; font-size: 12px; color: #6B7280;">
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
				$jt_pro_exts = [
					[
						'name' => __( 'Emoji Reactions', 'jetonomy' ),
						'icon' => 'dashicons-heart',
						'desc' => __( 'Like, love, and celebrate with Slack-style reactions on posts and replies.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Private Messaging', 'jetonomy' ),
						'icon' => 'dashicons-format-chat',
						'desc' => __( 'One-on-one and group conversations between community members.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Polls', 'jetonomy' ),
						'icon' => 'dashicons-chart-bar',
						'desc' => __( 'Create polls within posts for community voting and decision-making.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Analytics Dashboard', 'jetonomy' ),
						'icon' => 'dashicons-chart-area',
						'desc' => __( 'Engagement graphs, user growth, top spaces, post trends, and CSV export.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Email Digests', 'jetonomy' ),
						'icon' => 'dashicons-email',
						'desc' => __( 'Daily and weekly email digests of community activity for subscribed users.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Web Push', 'jetonomy' ),
						'icon' => 'dashicons-bell',
						'desc' => __( 'Browser push notifications for replies, mentions, and forum events.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Webhooks', 'jetonomy' ),
						'icon' => 'dashicons-rest-api',
						'desc' => __( 'Fire HTTP POST requests to Zapier, Slack, n8n, or any endpoint on forum events.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Reply by Email', 'jetonomy' ),
						'icon' => 'dashicons-email-alt2',
						'desc' => __( 'Members reply to notifications by email. No login required.', 'jetonomy' ),
						'tier' => 'Starter',
					],
					[
						'name' => __( 'Custom Badges', 'jetonomy' ),
						'icon' => 'dashicons-awards',
						'desc' => __( 'Create and auto-award custom badges based on community activity criteria.', 'jetonomy' ),
						'tier' => 'Growth',
					],
					[
						'name' => __( 'Custom Fields', 'jetonomy' ),
						'icon' => 'dashicons-forms',
						'desc' => __( 'Add custom fields to posts and user profiles: text, select, checkbox, date, and more.', 'jetonomy' ),
						'tier' => 'Growth',
					],
					[
						'name' => __( 'Advanced Moderation', 'jetonomy' ),
						'icon' => 'dashicons-shield',
						'desc' => __( 'Auto-moderation rules engine: keyword filters, regex, link limits, and spam scoring.', 'jetonomy' ),
						'tier' => 'Growth',
					],
					[
						'name' => __( 'SEO Pro', 'jetonomy' ),
						'icon' => 'dashicons-search',
						'desc' => __( 'Per-space meta titles, Open Graph, Twitter Cards, Schema.org, sitemap controls.', 'jetonomy' ),
						'tier' => 'Growth',
					],
					[
						'name' => __( 'White Label', 'jetonomy' ),
						'icon' => 'dashicons-admin-appearance',
						'desc' => __( 'Replace all Jetonomy branding: custom logo, name, footer, accent color, and CSS.', 'jetonomy' ),
						'tier' => 'Agency',
					],
				];
				?>
				<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px; padding: 16px;">
					<?php foreach ( $jt_pro_exts as $ext ) : ?>
					<div style="border: 1px solid #E5E7EB; border-radius: 8px; padding: 16px; background: #fff;">
						<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
							<span class="dashicons <?php echo esc_attr( $ext['icon'] ); ?>" style="color: var(--jt-admin-pro, #7C3AED); font-size: 18px; width: 18px; height: 18px;"></span>
							<strong style="font-size: 13px;"><?php echo esc_html( $ext['name'] ); ?></strong>
							<span style="margin-left: auto; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 10px; background: <?php echo 'Agency' === $ext['tier'] ? '#FEF3C7' : ( 'Growth' === $ext['tier'] ? '#E0E7FF' : '#F0FDF4' ); ?>; color: <?php echo 'Agency' === $ext['tier'] ? '#92400E' : ( 'Growth' === $ext['tier'] ? '#3730A3' : '#166534' ); ?>;">
								<?php echo esc_html( $ext['tier'] ); ?>
							</span>
						</div>
						<p style="margin: 0; font-size: 12.5px; color: #6B7280; line-height: 1.5;"><?php echo esc_html( $ext['desc'] ); ?></p>
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
				<?php do_action( 'jetonomy_admin_license_tab_content' ); ?>
			</div>

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
