<?php
defined( 'ABSPATH' ) || exit;

$active_tab = sanitize_text_field( $_GET['tab'] ?? 'general' );
$settings_url = admin_url( 'admin.php?page=jetonomy-settings' );
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Jetonomy Settings', 'jetonomy' ); ?></h1>

	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $settings_url . '&tab=general' ); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $settings_url . '&tab=permissions' ); ?>" class="nav-tab <?php echo 'permissions' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Permissions', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $settings_url . '&tab=email' ); ?>" class="nav-tab <?php echo 'email' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Email', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $settings_url . '&tab=seo' ); ?>" class="nav-tab <?php echo 'seo' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'SEO', 'jetonomy' ); ?></a>
		<?php
		/**
		 * Fires to render additional settings tabs.
		 * Pro hooks extra tabs (e.g. White Label, Integrations) here.
		 *
		 * @param string $active_tab Current active tab slug.
		 */
		do_action( 'jetonomy_admin_settings_tabs', $active_tab );
		?>
	</nav>

	<form method="post" action="options.php" id="jetonomy-settings-form">
		<?php settings_fields( 'jetonomy_settings' ); ?>

		<?php if ( 'general' === $active_tab ) : ?>
			<!-- General Tab -->
			<table class="form-table">
				<tr>
					<th scope="row"><label for="base_slug"><?php esc_html_e( 'Community Base URL', 'jetonomy' ); ?></label></th>
					<td>
						<input type="text" id="base_slug" name="jetonomy_settings[base_slug]" value="<?php echo esc_attr( $settings['base_slug'] ?? 'community' ); ?>" class="regular-text">
						<p class="description"><?php echo esc_html( home_url( '/' ) ); ?><strong><?php echo esc_html( $settings['base_slug'] ?? 'community' ); ?></strong>/</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="posts_per_page"><?php esc_html_e( 'Posts Per Page', 'jetonomy' ); ?></label></th>
					<td><input type="number" id="posts_per_page" name="jetonomy_settings[posts_per_page]" value="<?php echo absint( $settings['posts_per_page'] ?? 20 ); ?>" min="5" max="100" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label for="replies_per_page"><?php esc_html_e( 'Replies Per Page', 'jetonomy' ); ?></label></th>
					<td><input type="number" id="replies_per_page" name="jetonomy_settings[replies_per_page]" value="<?php echo absint( $settings['replies_per_page'] ?? 30 ); ?>" min="5" max="100" class="small-text"></td>
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
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Access', 'jetonomy' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="jetonomy_settings[guest_read]" value="1" <?php checked( ! empty( $settings['guest_read'] ) ); ?>>
								<?php esc_html_e( 'Allow guests to read public spaces', 'jetonomy' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="jetonomy_settings[require_login]" value="1" <?php checked( ! empty( $settings['require_login'] ) ); ?>>
								<?php esc_html_e( 'Require login to participate', 'jetonomy' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>

		<?php elseif ( 'permissions' === $active_tab ) : ?>
			<!-- Permissions Tab -->
			<h2><?php esc_html_e( 'Trust Level Thresholds', 'jetonomy' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Configure what users need to achieve to reach each trust level.', 'jetonomy' ); ?></p>
			<table class="wp-list-table widefat fixed">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Level', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Posts Required', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Days Active', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Reputation Required', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Replies Received', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php for ( $level = 1; $level <= 3; $level++ ) : ?>
						<tr>
							<td><strong><?php printf( esc_html__( 'Level %d', 'jetonomy' ), $level ); ?></strong></td>
							<td><input type="number" name="jetonomy_settings[trust_level_<?php echo $level; ?>_posts]" value="<?php echo absint( $settings["trust_level_{$level}_posts"] ?? ( $level * 5 ) ); ?>" min="0" class="small-text"></td>
							<td><input type="number" name="jetonomy_settings[trust_level_<?php echo $level; ?>_days]" value="<?php echo absint( $settings["trust_level_{$level}_days"] ?? ( $level * 5 ) ); ?>" min="0" class="small-text"></td>
							<td><input type="number" name="jetonomy_settings[trust_level_<?php echo $level; ?>_reputation]" value="<?php echo absint( $settings["trust_level_{$level}_reputation"] ?? ( $level * 20 ) ); ?>" min="0" class="small-text"></td>
							<td><input type="number" name="jetonomy_settings[trust_level_<?php echo $level; ?>_replies]" value="<?php echo absint( $settings["trust_level_{$level}_replies"] ?? ( $level * 5 ) ); ?>" min="0" class="small-text"></td>
						</tr>
					<?php endfor; ?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Rate Limits for Level 0 (New Users)', 'jetonomy' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Posts per Day', 'jetonomy' ); ?></label></th>
					<td><input type="number" name="jetonomy_settings[rate_limit_posts]" value="<?php echo absint( $settings['rate_limit_posts'] ?? 3 ); ?>" min="1" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Replies per Day', 'jetonomy' ); ?></label></th>
					<td><input type="number" name="jetonomy_settings[rate_limit_replies]" value="<?php echo absint( $settings['rate_limit_replies'] ?? 10 ); ?>" min="1" class="small-text"></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Votes per Day', 'jetonomy' ); ?></label></th>
					<td><input type="number" name="jetonomy_settings[rate_limit_votes]" value="<?php echo absint( $settings['rate_limit_votes'] ?? 20 ); ?>" min="1" class="small-text"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Default Role Mapping', 'jetonomy' ); ?></h2>
			<table class="wp-list-table widefat fixed">
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

		<?php elseif ( 'email' === $active_tab ) : ?>
			<!-- Email Tab -->
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
					<th scope="row"><?php esc_html_e( 'Email Adapter', 'jetonomy' ); ?></th>
					<td>
						<select disabled>
							<option><?php esc_html_e( 'WordPress Default (wp_mail)', 'jetonomy' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Uses your WordPress email configuration (SMTP plugins supported).', 'jetonomy' ); ?></p>
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

			<h2><?php esc_html_e( 'Notification Defaults', 'jetonomy' ); ?></h2>
			<table class="wp-list-table widefat fixed">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Notification Type', 'jetonomy' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Web', 'jetonomy' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Email', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$notif_types = [
						'reply_to_post'    => __( 'Reply to your post', 'jetonomy' ),
						'reply_to_reply'   => __( 'Reply to your reply', 'jetonomy' ),
						'mention'          => __( 'Mention (@username)', 'jetonomy' ),
						'accepted_answer'  => __( 'Your answer accepted', 'jetonomy' ),
						'new_post_in_sub'  => __( 'New post in subscribed space', 'jetonomy' ),
						'badge_earned'     => __( 'Badge earned', 'jetonomy' ),
					];
					foreach ( $notif_types as $type => $label ) :
					?>
						<tr>
							<td><?php echo esc_html( $label ); ?></td>
							<td><span class="dashicons dashicons-yes"></span></td>
							<td><span class="dashicons dashicons-yes"></span></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'Email Digest', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Send daily/weekly email digests to keep your community engaged.', 'jetonomy' ); ?></p>
					<a href="https://jetonomy.com/pro" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'appearance' === $active_tab ) : ?>
			<!-- Appearance Tab -->
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Theme Integration', 'jetonomy' ); ?></th>
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
						<p class="description"><?php esc_html_e( 'Only used when "Inherit theme colors" is unchecked.', 'jetonomy' ); ?></p>
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
				<tr>
					<th scope="row"><label for="custom_css"><?php esc_html_e( 'Custom CSS', 'jetonomy' ); ?></label></th>
					<td>
						<textarea id="custom_css" name="jetonomy_settings[custom_css]" rows="12" class="large-text code" style="font-family:monospace;"><?php echo esc_textarea( $settings['custom_css'] ?? '' ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Custom CSS applied to the community frontend. Use browser developer tools to identify selectors.', 'jetonomy' ); ?></p>
					</td>
				</tr>
			</table>

			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'White Label', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Remove Jetonomy branding and use your own logo, colors, and custom CSS globally.', 'jetonomy' ); ?></p>
					<a href="https://jetonomy.com/pro" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php elseif ( 'seo' === $active_tab ) : ?>
			<!-- SEO Tab -->
			<table class="form-table">
				<tr>
					<th scope="row"><label for="seo_post_title"><?php esc_html_e( 'Post Title Template', 'jetonomy' ); ?></label></th>
					<td>
						<input type="text" id="seo_post_title" name="jetonomy_settings[seo_post_title]" value="<?php echo esc_attr( $settings['seo_post_title'] ?? '{post_title} - {space_name} | {site_name}' ); ?>" class="large-text">
						<p class="description"><?php esc_html_e( 'Available tokens: {post_title}, {space_name}, {site_name}', 'jetonomy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="seo_space_title"><?php esc_html_e( 'Space Title Template', 'jetonomy' ); ?></label></th>
					<td>
						<input type="text" id="seo_space_title" name="jetonomy_settings[seo_space_title]" value="<?php echo esc_attr( $settings['seo_space_title'] ?? '{space_name} | {site_name}' ); ?>" class="large-text">
						<p class="description"><?php esc_html_e( 'Available tokens: {space_name}, {site_name}', 'jetonomy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Structured Data', 'jetonomy' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_schema]" value="1" <?php checked( $settings['seo_schema'] ?? true ); ?>>
								<?php esc_html_e( 'Enable schema markup (DiscussionForumPosting)', 'jetonomy' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sitemap', 'jetonomy' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="jetonomy_settings[seo_sitemap]" value="1" <?php checked( $settings['seo_sitemap'] ?? true ); ?>>
							<?php esc_html_e( 'Include community pages in XML sitemap', 'jetonomy' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Noindex Settings', 'jetonomy' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_noindex_profiles]" value="1" <?php checked( $settings['seo_noindex_profiles'] ?? true ); ?>>
								<?php esc_html_e( 'Noindex user profiles', 'jetonomy' ); ?>
							</label>
							<br>
							<label>
								<input type="checkbox" name="jetonomy_settings[seo_noindex_search]" value="1" <?php checked( $settings['seo_noindex_search'] ?? true ); ?>>
								<?php esc_html_e( 'Noindex search pages', 'jetonomy' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
				<div class="jt-pro-upsell">
					<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
					<h4><?php esc_html_e( 'SEO Pro', 'jetonomy' ); ?></h4>
					<p><?php esc_html_e( 'Advanced SEO controls, Open Graph tags, breadcrumbs, and canonical URL management.', 'jetonomy' ); ?></p>
					<a href="https://jetonomy.com/pro" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
				</div>
			<?php endif; ?>

		<?php endif; ?>

		<?php
		/**
		 * Fires to render additional settings tab content.
		 * Pro hooks its own tab content here.
		 * This fires outside the core if/elseif chain so Pro tabs can render.
		 *
		 * @param string $active_tab Current active tab slug.
		 */
		do_action( 'jetonomy_admin_settings_tab_content', $active_tab );
		?>

		<?php submit_button(); ?>
	</form>

	<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
		<!-- Feature Matrix: Free vs Pro -->
		<div class="jt-feature-matrix">
			<h2><?php esc_html_e( 'Free vs Pro', 'jetonomy' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Feature', 'jetonomy' ); ?></th>
						<th style="width:80px;text-align:center;"><?php esc_html_e( 'Free', 'jetonomy' ); ?></th>
						<th style="width:80px;text-align:center;"><?php esc_html_e( 'Pro', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$features = [
						[ __( 'Forum, Q&A, Ideas, Feed Spaces', 'jetonomy' ), true, true ],
						[ __( 'Trust Levels & Reputation', 'jetonomy' ), true, true ],
						[ __( 'Moderation Queue & Flags', 'jetonomy' ), true, true ],
						[ __( 'MemberPress & PMPro Integration', 'jetonomy' ), true, true ],
						[ __( 'bbPress & wpForo Import', 'jetonomy' ), true, true ],
						[ __( 'SEO (Schema, Sitemap)', 'jetonomy' ), true, true ],
						[ __( 'Custom CSS & Theme Integration', 'jetonomy' ), true, true ],
						[ __( 'Analytics Dashboard', 'jetonomy' ), false, true ],
						[ __( 'Email Digest', 'jetonomy' ), false, true ],
						[ __( 'Advanced Auto-Moderation Rules', 'jetonomy' ), false, true ],
						[ __( 'Custom Fields for Spaces', 'jetonomy' ), false, true ],
						[ __( 'Emoji Reactions', 'jetonomy' ), false, true ],
						[ __( 'Polls', 'jetonomy' ), false, true ],
						[ __( 'Private Messaging', 'jetonomy' ), false, true ],
						[ __( 'Custom Badges', 'jetonomy' ), false, true ],
						[ __( 'White Label / Branding', 'jetonomy' ), false, true ],
						[ __( 'WooCommerce, RCP & LearnDash', 'jetonomy' ), false, true ],
						[ __( 'SEO Pro (Open Graph, Breadcrumbs)', 'jetonomy' ), false, true ],
						[ __( 'Priority Support', 'jetonomy' ), false, true ],
					];
					foreach ( $features as $row ) :
					?>
						<tr>
							<td><?php echo esc_html( $row[0] ); ?></td>
							<td style="text-align:center;">
								<?php if ( $row[1] ) : ?>
									<span class="dashicons dashicons-yes" style="color:#00a32a;"></span>
								<?php else : ?>
									<span class="dashicons dashicons-lock" style="color:#c3c4c7;"></span>
								<?php endif; ?>
							</td>
							<td style="text-align:center;">
								<span class="dashicons dashicons-yes" style="color:#00a32a;"></span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p style="text-align:center;margin-top:16px;">
				<a href="https://jetonomy.com/pro" class="button button-primary button-hero" target="_blank"><?php esc_html_e( 'Upgrade to Jetonomy Pro', 'jetonomy' ); ?></a>
			</p>
		</div>
	<?php endif; ?>
</div>
