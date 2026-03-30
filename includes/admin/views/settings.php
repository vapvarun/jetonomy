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
					class="jt-snav-link<?php echo $active_tab === $slug ? ' jt-snav-link--active' : ''; ?>">
					<span class="dashicons <?php echo esc_attr( $tab_icons[ $slug ] ); ?>" aria-hidden="true"></span>
					<?php echo esc_html( $label ); ?>
				</a>
				<?php endforeach; ?>

				<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-snav-divider" role="separator"></div>
				<a href="<?php echo esc_url( $settings_url . '&tab=free-vs-pro' ); ?>"
					class="jt-snav-link<?php echo 'free-vs-pro' === $active_tab ? ' jt-snav-link--active' : ''; ?>"
					style="<?php echo 'free-vs-pro' !== $active_tab ? 'color: var(--jt-admin-pro, #7C3AED);' : ''; ?>">
					<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
					<?php esc_html_e( 'Free vs Pro', 'jetonomy' ); ?>
				</a>
				<?php endif; ?>

				<?php if ( defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-snav-divider" role="separator"></div>
				<a href="<?php echo esc_url( $settings_url . '&tab=license' ); ?>"
					class="jt-snav-link<?php echo 'license' === $active_tab ? ' jt-snav-link--active' : ''; ?>">
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
						<th scope="row"><?php esc_html_e( 'Guest Access', 'jetonomy' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="jetonomy_settings[guest_read]" value="1" <?php checked( ! empty( $settings['guest_read'] ) ); ?>>
									<?php esc_html_e( 'Allow guests to read public spaces', 'jetonomy' ); ?>
								</label>
								<br>
								<label>
									<input type="checkbox" name="jetonomy_settings[require_login]" value="1" <?php checked( ! empty( $settings['require_login'] ) ); ?>>
									<?php esc_html_e( 'Require login to participate (post, reply, vote)', 'jetonomy' ); ?>
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
			$tl_defaults = [
				1 => [
					'posts'            => 5,
					'days_active'      => 3,
					'reputation'       => 0,
					'replies_received' => 10,
				],
				2 => [
					'posts'            => 30,
					'days_active'      => 20,
					'reputation'       => 50,
					'replies_received' => 0,
				],
				3 => [
					'posts'            => 100,
					'days_active'      => 60,
					'reputation'       => 200,
					'replies_received' => 0,
				],
			];
			$level_names = [
				1 => __( 'Level 1 — Member', 'jetonomy' ),
				2 => __( 'Level 2 — Regular', 'jetonomy' ),
				3 => __( 'Level 3 — Trusted', 'jetonomy' ),
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
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo $level; ?>][posts]" value="<?php echo absint( $thresholds[ $level ]['posts'] ?? $td['posts'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo $level; ?>][days_active]" value="<?php echo absint( $thresholds[ $level ]['days_active'] ?? $td['days_active'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo $level; ?>][reputation]" value="<?php echo absint( $thresholds[ $level ]['reputation'] ?? $td['reputation'] ); ?>" min="0" class="small-text"></td>
								<td><input type="number" name="jetonomy_settings[trust_thresholds][<?php echo $level; ?>][replies_received]" value="<?php echo absint( $thresholds[ $level ]['replies_received'] ?? $td['replies_received'] ); ?>" min="0" class="small-text"></td>
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
						<td><input type="number" name="jetonomy_settings[rate_limits][posts]" value="<?php echo absint( $rate_limits['posts'] ?? 3 ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Replies per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" name="jetonomy_settings[rate_limits][replies]" value="<?php echo absint( $rate_limits['replies'] ?? 10 ); ?>" min="1" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Votes per Day', 'jetonomy' ); ?></label></th>
						<td><input type="number" name="jetonomy_settings[rate_limits][votes]" value="<?php echo absint( $rate_limits['votes'] ?? 5 ); ?>" min="1" class="small-text"></td>
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
							<input type="email" id="email_from_email" name="jetonomy_settings[email_from_email]" value="<?php echo esc_attr( $settings['email_from_email'] ?? '' ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
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
								printf( esc_html__( 'Sends a test email to %s', 'jetonomy' ), esc_html( get_option( 'admin_email' ) ) );
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
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Layout', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Adjust spacing and density across all community views.', 'jetonomy' ); ?></p>
				</div>
				<table class="form-table">
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
						</td>
					</tr>
				</table>
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
						<?php esc_html_e( 'Your community is growing. Give it reactions, messaging, polls, analytics, badges, webhooks, and more — as independent modules you enable only when you need them.', 'jetonomy' ); ?>
					</p>
					<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button button-primary button-hero" target="_blank" style="font-size: 14px; padding: 8px 28px;">
						<?php esc_html_e( 'Get Jetonomy Pro — Starting at $69/yr', 'jetonomy' ); ?>
					</a>
					<p style="margin: 8px 0 0; font-size: 12px; color: #6B7280;">
						<?php
						/* translators: %s: coupon code */
						printf( esc_html__( 'Use code %s for 30%% off lifetime plans.', 'jetonomy' ), '<strong>Jetonomy30</strong>' );
						?>
					</p>
				</div>
			</div>

			<!-- Extensions Grid -->
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'Pro Extensions', 'jetonomy' ); ?></p>
					<p class="jt-settings-card__desc"><?php esc_html_e( 'Each extension is independent — enable only what you need. Disabled extensions load zero code.', 'jetonomy' ); ?></p>
				</div>
				<?php
				$jt_pro_exts = [
					[ 'name' => __( 'Emoji Reactions', 'jetonomy' ), 'icon' => 'dashicons-heart', 'desc' => __( 'Like, love, celebrate — Slack-style reactions on posts and replies.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Private Messaging', 'jetonomy' ), 'icon' => 'dashicons-format-chat', 'desc' => __( 'One-on-one and group conversations between community members.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Polls', 'jetonomy' ), 'icon' => 'dashicons-chart-bar', 'desc' => __( 'Create polls within posts for community voting and decision-making.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Analytics Dashboard', 'jetonomy' ), 'icon' => 'dashicons-chart-area', 'desc' => __( 'Engagement graphs, user growth, top spaces, post trends, and CSV export.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Email Digests', 'jetonomy' ), 'icon' => 'dashicons-email', 'desc' => __( 'Daily and weekly email digests of community activity for subscribed users.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Web Push', 'jetonomy' ), 'icon' => 'dashicons-bell', 'desc' => __( 'Browser push notifications for replies, mentions, and forum events.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Webhooks', 'jetonomy' ), 'icon' => 'dashicons-rest-api', 'desc' => __( 'Fire HTTP POST requests to Zapier, Slack, n8n, or any endpoint on forum events.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Reply by Email', 'jetonomy' ), 'icon' => 'dashicons-email-alt2', 'desc' => __( 'Members reply to notifications by email — no login required.', 'jetonomy' ), 'tier' => 'Starter' ],
					[ 'name' => __( 'Custom Badges', 'jetonomy' ), 'icon' => 'dashicons-awards', 'desc' => __( 'Create and auto-award custom badges based on community activity criteria.', 'jetonomy' ), 'tier' => 'Growth' ],
					[ 'name' => __( 'Custom Fields', 'jetonomy' ), 'icon' => 'dashicons-forms', 'desc' => __( 'Add custom fields to posts and user profiles — text, select, checkbox, date, and more.', 'jetonomy' ), 'tier' => 'Growth' ],
					[ 'name' => __( 'Advanced Moderation', 'jetonomy' ), 'icon' => 'dashicons-shield', 'desc' => __( 'Auto-moderation rules engine — keyword filters, regex, link limits, spam scoring.', 'jetonomy' ), 'tier' => 'Growth' ],
					[ 'name' => __( 'SEO Pro', 'jetonomy' ), 'icon' => 'dashicons-search', 'desc' => __( 'Per-space meta titles, Open Graph, Twitter Cards, Schema.org, sitemap controls.', 'jetonomy' ), 'tier' => 'Growth' ],
					[ 'name' => __( 'White Label', 'jetonomy' ), 'icon' => 'dashicons-admin-appearance', 'desc' => __( 'Replace all Jetonomy branding — custom logo, name, footer, accent color, CSS.', 'jetonomy' ), 'tier' => 'Agency' ],
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

		<?php elseif ( 'license' === $active_tab ) : ?>

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
