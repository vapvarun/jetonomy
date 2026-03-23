<?php
defined( 'ABSPATH' ) || exit;

$active_tab = sanitize_text_field( $_GET['tab'] ?? 'general' );
$edit_url   = admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . $space->id );
?>
<div class="wrap jetonomy-admin">
	<h1>
		<?php
		/* translators: %s: space title */
		printf( esc_html__( 'Edit Space: %s', 'jetonomy' ), esc_html( $space->title ) );
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Back to Spaces', 'jetonomy' ); ?></a>
	</h1>

	<!-- Tabs -->
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $edit_url . '&tab=general' ); ?>" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'General', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=members' ); ?>" class="nav-tab <?php echo 'members' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Members', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=access' ); ?>" class="nav-tab <?php echo 'access' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Access Rules', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=settings' ); ?>" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'jetonomy' ); ?></a>
		<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
			<a class="nav-tab disabled" title="<?php esc_attr_e( 'Pro required', 'jetonomy' ); ?>"><?php esc_html_e( 'Custom Fields', 'jetonomy' ); ?> <span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span></a>
			<a class="nav-tab disabled" title="<?php esc_attr_e( 'Pro required', 'jetonomy' ); ?>"><?php esc_html_e( 'Reactions', 'jetonomy' ); ?> <span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span></a>
		<?php endif; ?>
		<?php
		/**
		 * Fires to render additional space edit tabs.
		 * Pro hooks Custom Fields, Reactions, etc. here.
		 *
		 * @param object $space The space being edited.
		 */
		do_action( 'jetonomy_admin_space_edit_tabs', $space );
		?>
	</nav>

	<?php if ( 'general' === $active_tab ) : ?>
		<!-- General Tab -->
		<form id="jetonomy-edit-space-form" class="jetonomy-space-form" data-space-id="<?php echo absint( $space->id ); ?>">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="space-title"><?php esc_html_e( 'Title', 'jetonomy' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" id="space-title" class="regular-text" value="<?php echo esc_attr( $space->title ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="space-slug" class="regular-text" value="<?php echo esc_attr( $space->slug ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label></th>
					<td><textarea id="space-description" rows="4" class="large-text"><?php echo esc_textarea( $space->description ?? '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-category">
							<option value="0"><?php esc_html_e( '(None)', 'jetonomy' ); ?></option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo absint( $cat->id ); ?>" <?php selected( $space->category_id, $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-type">
							<option value="forum" <?php selected( $space->type, 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
							<option value="qa" <?php selected( $space->type, 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
							<option value="ideas" <?php selected( $space->type, 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
							<option value="feed" <?php selected( $space->type, 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-visibility">
							<option value="public" <?php selected( $space->visibility, 'public' ); ?>><?php esc_html_e( 'Public', 'jetonomy' ); ?></option>
							<option value="private" <?php selected( $space->visibility, 'private' ); ?>><?php esc_html_e( 'Private', 'jetonomy' ); ?></option>
							<option value="hidden" <?php selected( $space->visibility, 'hidden' ); ?>><?php esc_html_e( 'Hidden', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-join-policy"><?php esc_html_e( 'Join Policy', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-join-policy">
							<option value="open" <?php selected( $space->join_policy, 'open' ); ?>><?php esc_html_e( 'Open', 'jetonomy' ); ?></option>
							<option value="approval" <?php selected( $space->join_policy, 'approval' ); ?>><?php esc_html_e( 'Requires Approval', 'jetonomy' ); ?></option>
							<option value="invite" <?php selected( $space->join_policy, 'invite' ); ?>><?php esc_html_e( 'Invite Only', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-status"><?php esc_html_e( 'Status', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-status">
							<option value="active" <?php selected( $space->status, 'active' ); ?>><?php esc_html_e( 'Active', 'jetonomy' ); ?></option>
							<option value="archived" <?php selected( $space->status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'jetonomy' ); ?></option>
							<option value="locked" <?php selected( $space->status, 'locked' ); ?>><?php esc_html_e( 'Locked', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="space-icon" class="regular-text" value="<?php echo esc_attr( $space->icon ?? '' ); ?>" placeholder="dashicons-groups"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cover Image', 'jetonomy' ); ?></th>
					<td>
						<div class="jetonomy-media-upload">
							<input type="hidden" id="space-cover-image" value="<?php echo esc_attr( $space->cover_image ?? '' ); ?>">
							<div id="space-cover-preview" class="jetonomy-cover-preview" <?php echo empty( $space->cover_image ) ? 'style="display:none;"' : ''; ?>>
								<?php if ( ! empty( $space->cover_image ) ) : ?>
									<img src="<?php echo esc_url( $space->cover_image ); ?>" alt="">
								<?php endif; ?>
								<div class="jetonomy-cover-actions">
									<button type="button" class="button jetonomy-remove-cover">
										<span class="dashicons dashicons-trash"></span>
										<?php esc_html_e( 'Remove', 'jetonomy' ); ?>
									</button>
								</div>
							</div>
							<button type="button" class="button" id="space-cover-upload">
								<span class="dashicons dashicons-format-image"></span>
								<?php esc_html_e( 'Select Cover Image', 'jetonomy' ); ?>
							</button>
						</div>
					</td>
				</tr>
			</table>
			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Update Space', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</form>

	<?php elseif ( 'members' === $active_tab ) : ?>
		<!-- Members Tab -->
		<div class="jetonomy-tab-content">
			<h2><?php esc_html_e( 'Add Member', 'jetonomy' ); ?></h2>
			<div class="jetonomy-inline-form" id="jetonomy-add-member-form">
				<div class="jetonomy-form-row">
					<input type="text" id="member-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search users by name or email...', 'jetonomy' ); ?>">
					<div id="member-search-results" class="jetonomy-search-results" style="display:none;"></div>
					<input type="hidden" id="member-user-id" value="">
					<select id="member-role">
						<option value="member"><?php esc_html_e( 'Member', 'jetonomy' ); ?></option>
						<option value="moderator"><?php esc_html_e( 'Moderator', 'jetonomy' ); ?></option>
						<option value="admin"><?php esc_html_e( 'Admin', 'jetonomy' ); ?></option>
						<option value="viewer"><?php esc_html_e( 'Viewer', 'jetonomy' ); ?></option>
					</select>
					<button type="button" class="button button-primary" id="jetonomy-add-member" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Add', 'jetonomy' ); ?></button>
				</div>
			</div>

			<h2><?php printf( esc_html__( 'Members (%d)', 'jetonomy' ), count( $members ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="jetonomy-members-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'jetonomy' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Role', 'jetonomy' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Joined', 'jetonomy' ); ?></th>
						<th style="width:100px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $members ) ) : ?>
						<tr class="jetonomy-no-items"><td colspan="4"><?php esc_html_e( 'No members yet.', 'jetonomy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $members as $member ) :
							$user = get_userdata( $member->user_id );
							if ( ! $user ) continue;
						?>
							<tr data-user-id="<?php echo absint( $member->user_id ); ?>">
								<td>
									<?php echo get_avatar( $member->user_id, 24 ); ?>
									<strong><?php echo esc_html( $user->display_name ); ?></strong>
									<span class="description">(<?php echo esc_html( $user->user_login ); ?>)</span>
								</td>
								<td>
									<select class="jetonomy-change-member-role" data-space-id="<?php echo absint( $space->id ); ?>" data-user-id="<?php echo absint( $member->user_id ); ?>">
										<option value="viewer" <?php selected( $member->role, 'viewer' ); ?>><?php esc_html_e( 'Viewer', 'jetonomy' ); ?></option>
										<option value="member" <?php selected( $member->role, 'member' ); ?>><?php esc_html_e( 'Member', 'jetonomy' ); ?></option>
										<option value="moderator" <?php selected( $member->role, 'moderator' ); ?>><?php esc_html_e( 'Moderator', 'jetonomy' ); ?></option>
										<option value="admin" <?php selected( $member->role, 'admin' ); ?>><?php esc_html_e( 'Admin', 'jetonomy' ); ?></option>
									</select>
								</td>
								<td><?php echo esc_html( human_time_diff( strtotime( $member->joined_at ), current_time( 'timestamp', true ) ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
								<td>
									<button type="button" class="button button-small jetonomy-remove-member" data-space-id="<?php echo absint( $space->id ); ?>" data-user-id="<?php echo absint( $member->user_id ); ?>"><?php esc_html_e( 'Remove', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

	<?php elseif ( 'access' === $active_tab ) : ?>
		<!-- Access Rules Tab -->
		<div class="jetonomy-tab-content">
			<h2><?php esc_html_e( 'Add Access Rule', 'jetonomy' ); ?></h2>
			<div class="jetonomy-inline-form" id="jetonomy-add-rule-form">
				<div class="jetonomy-form-row">
					<select id="rule-type">
						<option value="everyone"><?php esc_html_e( 'Everyone', 'jetonomy' ); ?></option>
						<option value="logged_in"><?php esc_html_e( 'Logged In', 'jetonomy' ); ?></option>
						<option value="role"><?php esc_html_e( 'WP Role', 'jetonomy' ); ?></option>
						<option value="capability"><?php esc_html_e( 'Capability', 'jetonomy' ); ?></option>
						<option value="trust_level"><?php esc_html_e( 'Trust Level', 'jetonomy' ); ?></option>
						<option value="membership"><?php esc_html_e( 'Membership', 'jetonomy' ); ?></option>
					</select>
					<input type="text" id="rule-value" class="regular-text" placeholder="<?php esc_attr_e( 'Value (e.g., administrator, 2)', 'jetonomy' ); ?>">
					<select id="rule-grants">
						<option value="read"><?php esc_html_e( 'Read', 'jetonomy' ); ?></option>
						<option value="participate"><?php esc_html_e( 'Participate', 'jetonomy' ); ?></option>
						<option value="full"><?php esc_html_e( 'Full', 'jetonomy' ); ?></option>
					</select>
					<select id="rule-space-role">
						<option value="viewer"><?php esc_html_e( 'Viewer', 'jetonomy' ); ?></option>
						<option value="member"><?php esc_html_e( 'Member', 'jetonomy' ); ?></option>
						<option value="moderator"><?php esc_html_e( 'Moderator', 'jetonomy' ); ?></option>
						<option value="admin"><?php esc_html_e( 'Admin', 'jetonomy' ); ?></option>
					</select>
					<input type="number" id="rule-priority" value="0" min="0" style="width:60px;" title="<?php esc_attr_e( 'Priority', 'jetonomy' ); ?>">
					<button type="button" class="button button-primary" id="jetonomy-add-rule" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Add Rule', 'jetonomy' ); ?></button>
				</div>
			</div>

			<h2><?php printf( esc_html__( 'Access Rules (%d)', 'jetonomy' ), count( $access_rules ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="jetonomy-rules-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Value', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Grants', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Space Role', 'jetonomy' ); ?></th>
						<th style="width:70px;"><?php esc_html_e( 'Priority', 'jetonomy' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $access_rules ) ) : ?>
						<tr class="jetonomy-no-items"><td colspan="6"><?php esc_html_e( 'No access rules defined. Default permissions apply.', 'jetonomy' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $access_rules as $rule ) : ?>
							<tr data-rule-id="<?php echo absint( $rule->id ); ?>">
								<td><code><?php echo esc_html( $rule->rule_type ); ?></code></td>
								<td><?php echo esc_html( $rule->rule_value ?: '&mdash;' ); ?></td>
								<td><span class="jetonomy-badge jetonomy-badge--<?php echo esc_attr( $rule->grants ); ?>"><?php echo esc_html( ucfirst( $rule->grants ) ); ?></span></td>
								<td><?php echo esc_html( ucfirst( $rule->space_role ) ); ?></td>
								<td><?php echo absint( $rule->priority ); ?></td>
								<td>
									<button type="button" class="button button-small button-link-delete jetonomy-delete-rule" data-id="<?php echo absint( $rule->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

	<?php elseif ( 'settings' === $active_tab ) : ?>
		<!-- Space Settings Tab -->
		<div class="jetonomy-tab-content">
			<h2><?php esc_html_e( 'Space-Specific Settings', 'jetonomy' ); ?></h2>
			<p class="description"><?php esc_html_e( 'These settings override the global defaults for this space only.', 'jetonomy' ); ?></p>

			<form id="jetonomy-space-settings-form" data-space-id="<?php echo absint( $space->id ); ?>">
				<table class="form-table">
					<tr>
						<th scope="row"><label for="ss-who-can-post"><?php esc_html_e( 'Who Can Post', 'jetonomy' ); ?></label></th>
						<td>
							<select id="ss-who-can-post">
								<option value="" <?php selected( $space_settings['who_can_post'] ?? '', '' ); ?>><?php esc_html_e( '(Use Global Default)', 'jetonomy' ); ?></option>
								<option value="members" <?php selected( $space_settings['who_can_post'] ?? '', 'members' ); ?>><?php esc_html_e( 'Members Only', 'jetonomy' ); ?></option>
								<option value="moderators" <?php selected( $space_settings['who_can_post'] ?? '', 'moderators' ); ?>><?php esc_html_e( 'Moderators & Admins', 'jetonomy' ); ?></option>
								<option value="admins" <?php selected( $space_settings['who_can_post'] ?? '', 'admins' ); ?>><?php esc_html_e( 'Admins Only', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-who-can-reply"><?php esc_html_e( 'Who Can Reply', 'jetonomy' ); ?></label></th>
						<td>
							<select id="ss-who-can-reply">
								<option value="" <?php selected( $space_settings['who_can_reply'] ?? '', '' ); ?>><?php esc_html_e( '(Use Global Default)', 'jetonomy' ); ?></option>
								<option value="members" <?php selected( $space_settings['who_can_reply'] ?? '', 'members' ); ?>><?php esc_html_e( 'Members Only', 'jetonomy' ); ?></option>
								<option value="moderators" <?php selected( $space_settings['who_can_reply'] ?? '', 'moderators' ); ?>><?php esc_html_e( 'Moderators & Admins', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require Approval', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ss-require-approval" value="1" <?php checked( ! empty( $space_settings['require_approval'] ) ); ?>>
								<?php esc_html_e( 'New posts require moderator approval before publishing', 'jetonomy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allow Voting', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ss-allow-voting" value="1" <?php checked( ( $space_settings['allow_voting'] ?? '1' ) === '1' ); ?>>
								<?php esc_html_e( 'Enable upvote/downvote on posts and replies', 'jetonomy' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-posts-per-page"><?php esc_html_e( 'Posts Per Page', 'jetonomy' ); ?></label></th>
						<td>
							<input type="number" id="ss-posts-per-page" value="<?php echo absint( $space_settings['posts_per_page'] ?? '' ); ?>" min="0" max="100" class="small-text" placeholder="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>">
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'jetonomy' ); ?></button>
					<span class="spinner"></span>
				</p>
			</form>
		</div>
	<?php
	/**
	 * Fires to render additional space edit tab content.
	 * Pro hooks Custom Fields, Reactions, etc. here.
	 *
	 * @param string $active_tab Current active tab slug.
	 * @param object $space      The space being edited.
	 */
	do_action( 'jetonomy_admin_space_edit_tab_content', $active_tab, $space );
	?>

	<?php endif; ?>
</div>
