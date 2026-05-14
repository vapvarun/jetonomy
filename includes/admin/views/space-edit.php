<?php
/**
 * Admin space edit view.
 *
 * Variables seeded by Admin::render_space_edit() before include.
 *
 * @var object{id:int,title:string,slug:string,type:string,visibility:string,join_policy:string,status:string,description:?string,icon:?string,cover_image:?string,settings:?string,sort_order:int,post_count:int,member_count:int,parent_id:?int,category_id:?int,last_activity_at:?string,created_at:string,updated_at:string} $space
 * @var object[] $categories
 * @var array<int,object{user_id:int,role:string,space_role?:string,joined_at?:string,display_name?:string}> $members
 * @var object[] $access_rules
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		<a href="<?php echo esc_url( $edit_url . '&tab=general' ); ?>" class="nav-tab <?php echo esc_attr( 'general' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'General', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=members' ); ?>" class="nav-tab <?php echo esc_attr( 'members' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Members', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=access' ); ?>" class="nav-tab <?php echo esc_attr( 'access' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Access Rules', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=settings' ); ?>" class="nav-tab <?php echo esc_attr( 'settings' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Settings', 'jetonomy' ); ?></a>
		<?php
		$show_join_requests_tab = 'approval' === ( $space->join_policy ?? 'open' ) || ! empty( $join_requests );
		if ( $show_join_requests_tab ) :
			?>
			<a href="<?php echo esc_url( $edit_url . '&tab=join_requests' ); ?>" class="nav-tab <?php echo esc_attr( 'join_requests' === $active_tab ? 'nav-tab-active' : '' ); ?>">
				<?php esc_html_e( 'Join Requests', 'jetonomy' ); ?>
				<?php if ( ! empty( $join_requests ) ) : ?>
					<span class="count">(<?php echo (int) count( $join_requests ); ?>)</span>
				<?php endif; ?>
			</a>
		<?php endif; ?>
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
							<?php foreach ( $categories as $space_cat ) : ?>
								<option value="<?php echo absint( $space_cat->id ); ?>" <?php selected( $space->category_id, $space_cat->id ); ?>><?php echo esc_html( $space_cat->name ); ?></option>
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
					<th scope="row"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></th>
					<td>
						<?php
						\Jetonomy\Template_Loader::partial(
							'icon-picker',
							array(
								'field_name'    => 'icon',
								'current_value' => (string) ( $space->icon ?? 'users' ),
								'id_prefix'     => 'jt-admin-edit-space-icon',
								'label'         => '',
							)
						);
						?>
					</td>
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

			<?php /* translators: %d: number of members */ ?>
		<h2><?php printf( esc_html__( 'Members (%d)', 'jetonomy' ), (int) count( $members ) ); ?></h2>
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
						<?php
						jetonomy_admin_empty_state(
							array(
								'colspan' => 4,
								'variant' => 'compact',
								'icon'    => 'groups',
								'title'   => __( 'No members yet', 'jetonomy' ),
								'body'    => __( 'Invite members or open this space to the wider community.', 'jetonomy' ),
							)
						);
						?>
					<?php else : ?>
						<?php
						foreach ( $members as $member ) :
							$user = get_userdata( $member->user_id );
							if ( ! $user ) {
								continue;
							}
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
								<td><?php echo esc_html( human_time_diff( strtotime( $member->joined_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
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
						<?php /* Dynamic membership adapter options injected by JS */ ?>
					</select>
					<input type="text" id="rule-value" class="regular-text" placeholder="<?php esc_attr_e( 'Value (e.g., administrator, 2)', 'jetonomy' ); ?>">
					<div id="rule-value-membership-wrap" style="display:none;position:relative;">
						<input type="text" id="rule-value-membership-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search courses, roles, memberships...', 'jetonomy' ); ?>" autocomplete="off">
						<input type="hidden" id="rule-value-membership" value="">
						<div id="rule-value-membership-results" class="jetonomy-ac-results" style="display:none;"></div>
					</div>
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
					<input type="hidden" id="rule-priority" value="0">
					<button type="button" class="button button-primary" id="jetonomy-add-rule" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Add Rule', 'jetonomy' ); ?></button>
				</div>
			</div>

			<?php /* translators: %d: number of access rules */ ?>
		<h2><?php printf( esc_html__( 'Access Rules (%d)', 'jetonomy' ), (int) count( $access_rules ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="jetonomy-rules-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Value', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Grants', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Space Role', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $access_rules ) ) : ?>
						<?php
						jetonomy_admin_empty_state(
							array(
								'colspan' => 5,
								'variant' => 'compact',
								'icon'    => 'lock',
								'title'   => __( 'No access rules', 'jetonomy' ),
								'body'    => __( 'Default permissions apply. Add a rule to limit access by role, membership, or trust level.', 'jetonomy' ),
							)
						);
						?>
					<?php else : ?>
						<?php foreach ( $access_rules as $rule ) : ?>
							<?php
							// Resolve human-readable labels for membership rules.
							$display_type  = ucfirst( str_replace( '_', ' ', $rule->rule_type ) );
							$display_value = ! empty( $rule->rule_value ) ? $rule->rule_value : '—';

							if ( 'membership' === $rule->rule_type && ! empty( $rule->rule_value ) ) {
								$adapter_prefix_map = array(
									'tutor_course_'    => array( 'tutor', __( 'Tutor Course', 'jetonomy' ) ),
									'sensei_course_'   => array( 'sensei', __( 'Sensei Course', 'jetonomy' ) ),
									'ms_course_'       => array( 'masterstudy', __( 'MasterStudy Course', 'jetonomy' ) ),
									'llms_course_'     => array( 'lifterlms', __( 'LifterLMS Course', 'jetonomy' ) ),
									'llms_membership_' => array( 'lifterlms', __( 'LifterLMS Membership', 'jetonomy' ) ),
									'ld_course_'       => array( 'learndash', __( 'LearnDash Course', 'jetonomy' ) ),
									'ld_group_'        => array( 'learndash', __( 'LearnDash Group', 'jetonomy' ) ),
									'wc_membership_'   => array( 'woocommerce', __( 'WooCommerce Membership', 'jetonomy' ) ),
									'wc_subscription_' => array( 'woocommerce', __( 'WooCommerce Subscription', 'jetonomy' ) ),
									'rcp_'             => array( 'rcp', __( 'RCP Membership', 'jetonomy' ) ),
									'mepr_'            => array( 'memberpress', __( 'MemberPress Plan', 'jetonomy' ) ),
									'pmpro_'           => array( 'pmpro', __( 'PMPro Level', 'jetonomy' ) ),
								);
								foreach ( $adapter_prefix_map as $prefix => $info ) {
									if ( str_starts_with( $rule->rule_value, $prefix ) ) {
										$display_type = $info[1];
										$adapter      = \Jetonomy\Adapters\Adapter_Registry::get_membership( $info[0] );
										if ( $adapter && $adapter->is_active() ) {
											$display_value = $adapter->get_level_label( $rule->rule_value );
										}
										break;
									}
								}
							}
							?>
							<tr data-rule-id="<?php echo absint( $rule->id ); ?>">
								<td><code><?php echo esc_html( $display_type ); ?></code></td>
								<td><?php echo esc_html( $display_value ); ?></td>
								<td><span class="jetonomy-badge jetonomy-badge--<?php echo esc_attr( $rule->grants ); ?>"><?php echo esc_html( ucfirst( $rule->grants ) ); ?></span></td>
								<td><?php echo esc_html( ucfirst( $rule->space_role ) ); ?></td>
								<td class="jetonomy-rule-actions">
									<?php if ( 'membership' === $rule->rule_type && ! empty( $rule->rule_value ) ) : ?>
										<button type="button" class="button button-small button-primary jetonomy-sync-rule" data-id="<?php echo absint( $rule->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>" data-value="<?php echo esc_attr( $rule->rule_value ); ?>" data-role="<?php echo esc_attr( $rule->space_role ); ?>"><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Sync Members', 'jetonomy' ); ?></button>
									<?php endif; ?>
									<button type="button" class="button button-small button-link-delete jetonomy-delete-rule" data-id="<?php echo absint( $rule->id ); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
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
							<?php
							// Render empty (not 0) when no per-space override, so the "Default"
							// placeholder surfaces and admin.js can save null on save.
							$ss_posts_per_page = isset( $space_settings['posts_per_page'] ) && '' !== $space_settings['posts_per_page'] && (int) $space_settings['posts_per_page'] > 0
								? absint( $space_settings['posts_per_page'] )
								: '';
							?>
							<input type="number" id="ss-posts-per-page" value="<?php echo esc_attr( $ss_posts_per_page ); ?>" min="1" max="100" class="small-text" placeholder="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Topic Prefixes', 'jetonomy' ); ?></th>
						<td>
							<label style="margin-bottom:8px;display:block;">
								<input type="checkbox" id="ss-enable-prefixes" value="1" <?php checked( ! empty( $space_settings['enable_prefixes'] ) ); ?>>
								<?php esc_html_e( 'Enable topic prefixes for this space', 'jetonomy' ); ?>
							</label>
							<div id="jt-prefixes-config" <?php echo empty( $space_settings['enable_prefixes'] ) ? 'style="display:none;"' : ''; ?>>
								<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Colored labels members can apply to topics (e.g. Bug, Suggestion, Solved).', 'jetonomy' ); ?></p>
								<div id="jt-prefixes-list">
									<?php
									$prefixes = ! empty( $space_settings['prefixes'] ) ? $space_settings['prefixes'] : array();
									if ( ! empty( $prefixes ) ) :
										foreach ( $prefixes as $pfx ) :
											?>
											<div class="jt-prefix-row">
												<input type="text" class="jt-prefix-name" value="<?php echo esc_attr( $pfx['name'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Label', 'jetonomy' ); ?>" maxlength="50">
												<input type="color" class="jt-prefix-color" value="<?php echo esc_attr( $pfx['color'] ?? '#3B82F6' ); ?>">
												<button type="button" class="button jt-prefix-remove" title="<?php esc_attr_e( 'Remove', 'jetonomy' ); ?>">&times;</button>
											</div>
											<?php
										endforeach;
									endif;
									?>
								</div>
								<button type="button" class="button" id="jt-add-prefix"><?php esc_html_e( '+ Add Prefix', 'jetonomy' ); ?></button>
							</div>
						</td>
					</tr>
					<?php if ( function_exists( 'bp_is_active' ) && bp_is_active( 'groups' ) ) : ?>
					<tr>
						<th scope="row"><label for="ss-bp-group"><?php esc_html_e( 'BuddyPress Group', 'jetonomy' ); ?></label></th>
						<td>
							<?php
							$linked_group_id = \Jetonomy\Integrations\BuddyPress::find_group_by_space( (int) $space->id );
							$bp_groups       = \BP_Groups_Group::get(
								array(
									'per_page'          => 100,
									'show_hidden'       => true,
									'update_meta_cache' => false,
								)
							);
							$groups_list     = $bp_groups['groups'] ?? array();
							?>
							<select id="ss-bp-group">
								<option value=""><?php esc_html_e( '(Not linked)', 'jetonomy' ); ?></option>
								<?php foreach ( $groups_list as $bp_group ) : ?>
									<option value="<?php echo absint( $bp_group->id ); ?>" <?php selected( $linked_group_id, (int) $bp_group->id ); ?>>
										<?php echo esc_html( $bp_group->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Link this space to a BuddyPress group. Members will be synced automatically.', 'jetonomy' ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
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

	<?php elseif ( ! in_array( $active_tab, array( 'general', 'members', 'access', 'settings', 'join_requests' ), true ) ) : ?>
		<?php
		/**
		 * Fires for custom tabs registered by Pro extensions (SEO, Custom Fields, etc.).
		 *
		 * @param string $active_tab Current active tab slug.
		 * @param object $space      The space being edited.
		 */
		do_action( 'jetonomy_admin_space_edit_tab_content', $active_tab, $space );
		?>

	<?php elseif ( 'join_requests' === $active_tab ) : ?>
		<!-- Join Requests Tab -->
		<div class="jetonomy-tab-content">
			<?php /* translators: %d: number of pending join requests */ ?>
		<h2><?php printf( esc_html__( 'Pending Join Requests (%d)', 'jetonomy' ), (int) count( $join_requests ) ); ?></h2>
			<table class="wp-list-table widefat fixed striped" id="jetonomy-join-requests-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'jetonomy' ); ?></th>
						<th><?php esc_html_e( 'Message', 'jetonomy' ); ?></th>
						<th style="width:150px;"><?php esc_html_e( 'Requested', 'jetonomy' ); ?></th>
						<th style="width:180px;"><?php esc_html_e( 'Actions', 'jetonomy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $join_requests ) ) : ?>
						<?php
						jetonomy_admin_empty_state(
							array(
								'colspan' => 4,
								'variant' => 'success',
								'icon'    => 'yes-alt',
								'title'   => __( 'No pending join requests', 'jetonomy' ),
								'body'    => __( 'New requests to join this space will appear here for review.', 'jetonomy' ),
							)
						);
						?>
					<?php else : ?>
						<?php
						foreach ( $join_requests as $request ) :
							$user = get_userdata( $request->user_id );
							if ( ! $user ) {
								continue;
							}
							?>
							<tr data-request-id="<?php echo absint( $request->id ); ?>">
								<td>
									<?php echo get_avatar( $request->user_id, 24 ); ?>
									<strong><?php echo esc_html( $user->display_name ); ?></strong>
									<span class="description">(<?php echo esc_html( $user->user_login ); ?>)</span>
								</td>
								<td><?php echo esc_html( ! empty( $request->message ) ? $request->message : '—' ); ?></td>
								<td><?php echo esc_html( human_time_diff( strtotime( $request->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) ); ?></td>
								<td>
									<button type="button" class="button button-small button-primary jetonomy-approve-join-request" data-id="<?php echo absint( $request->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
									<button type="button" class="button button-small jetonomy-deny-join-request" data-id="<?php echo absint( $request->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Deny', 'jetonomy' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>

	<?php endif; ?>
</div>
