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
 * @var object[] $join_requests The current page of pending join requests.
 * @var int      $join_total    Every pending join request on the space.
 * @var int      $join_per_page Join requests per page.
 * @var int      $join_paged    Current join requests page (?jrpg=).
 * @var int      $join_pages    Join request page count.
 * @var string   $member_search   Members tab name filter (?ms=).
 * @var int      $member_total    Members matching the filter.
 * @var int      $member_per_page Members per page.
 * @var int      $member_paged    Current members page (?mpg=).
 * @var int      $member_pages    Members page count.
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
		printf( esc_html__( 'Edit %1$s: %2$s', 'jetonomy' ), esc_html( \Jetonomy\space_label() ), esc_html( $space->title ) );
		?>
		<?php /* translators: %s: where the link returns to - the configured space label, or a specific space title. */ ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces' ) ); ?>" class="page-title-action"><?php echo esc_html( sprintf( __( 'Back to %s', 'jetonomy' ), \Jetonomy\space_label( true ) ) ); ?></a>
	</h1>

	<!-- Tabs -->
	<nav class="nav-tab-wrapper">
		<a href="<?php echo esc_url( $edit_url . '&tab=general' ); ?>" class="nav-tab <?php echo esc_attr( 'general' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'General', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=members' ); ?>" class="nav-tab <?php echo esc_attr( 'members' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php echo esc_html( \Jetonomy\jetonomy_label( 'member', true ) ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=access' ); ?>" class="nav-tab <?php echo esc_attr( 'access' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Access Rules', 'jetonomy' ); ?></a>
		<a href="<?php echo esc_url( $edit_url . '&tab=settings' ); ?>" class="nav-tab <?php echo esc_attr( 'settings' === $active_tab ? 'nav-tab-active' : '' ); ?>"><?php esc_html_e( 'Settings', 'jetonomy' ); ?></a>
		<?php
		$show_join_requests_tab = 'approval' === ( $space->join_policy ?? 'open' ) || ! empty( $join_requests );
		if ( $show_join_requests_tab ) :
			?>
			<a href="<?php echo esc_url( $edit_url . '&tab=join_requests' ); ?>" class="nav-tab <?php echo esc_attr( 'join_requests' === $active_tab ? 'nav-tab-active' : '' ); ?>">
				<?php esc_html_e( 'Join Requests', 'jetonomy' ); ?>
				<?php if ( ! empty( $join_requests ) ) : ?>
					<span class="count">(<?php echo (int) $join_total; ?>)</span>
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
		<div class="jt-settings-card">
			<div class="jt-settings-card__head">
				<p class="jt-settings-card__title"><?php esc_html_e( 'Details', 'jetonomy' ); ?></p>
				<?php /* translators: 1: category label, lowercase (e.g. category). 2: the singular space label the site owner configured (e.g. space, group). */ ?>
				<p class="jt-settings-card__desc"><?php echo esc_html( sprintf( __( 'Name, %1$s, visibility, and appearance of this %2$s.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', false, true ), \Jetonomy\space_label( false, true ) ) ); ?></p>
			</div>
		<form id="jetonomy-edit-space-form" class="jetonomy-space-form" data-space-id="<?php echo absint( $space->id ); ?>" data-edit-token="<?php echo esc_attr( \Jetonomy\Admin\Ajax\Spaces_Handler::edit_token( $space ) ); ?>">
			<table class="form-table"><!-- jetonomy-audit-table-ok: WP core settings form, not a data list; core stacks form-table th/td below 782px -->
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
					<th scope="row"><label for="space-category"><?php echo esc_html( \Jetonomy\jetonomy_label( 'category' ) ); ?></label></th>
					<td>
						<select id="space-category">
							<option value="0"><?php esc_html_e( '(None)', 'jetonomy' ); ?></option>
							<?php foreach ( $categories as $space_cat ) : ?>
								<option value="<?php echo absint( $space_cat->id ); ?>" <?php selected( $space->category_id, $space_cat->id ); ?>><?php echo esc_html( \Jetonomy\Models\Category::picker_label( $space_cat ) ); ?></option>
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
							<?php \Jetonomy\space_visibility_options( (string) $space->visibility, false ); ?>
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
						<?php
						// Adding a restrictive rule switches a public space to Private, which also
						// changes how people join; the rule toast says so once, this says it here.
						if ( 'private' === $space->visibility && array_intersect( \Jetonomy\Models\AccessRule::RESTRICTIVE_TYPES, wp_list_pluck( $access_rules, 'rule_type' ) ) ) :
							?>
							<div class="notice notice-info inline"><p>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: space label, lowercase (e.g. "space"). 2: join policy label (e.g. "Requires Approval"). */
										__( 'This %1$s has an access rule, so it is Private and its join policy is %2$s. A rule cannot gate a public %1$s.', 'jetonomy' ),
										\Jetonomy\space_label( false, true ),
										\Jetonomy\space_join_policy_label( \Jetonomy\Models\Space::effective_join_policy( $space ) )
									)
								);
								?>
							</p></div>
						<?php endif; ?>
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
					<th scope="row"><label for="space-sort-order"><?php esc_html_e( 'Display order', 'jetonomy' ); ?></label></th>
					<td>
						<input type="number" id="space-sort-order" class="small-text" step="1" min="0"
							value="<?php echo esc_attr( (string) (int) ( $space->sort_order ?? 0 ) ); ?>"
							aria-describedby="space-sort-order-help">
						<p class="description" id="space-sort-order-help">
							<?php /* translators: 1: category label, lowercase (e.g. "category"). 2: space label, plural (e.g. "Spaces"). */ echo esc_html( sprintf( __( 'Position within its %1$s, lowest first. %2$s sharing a number fall back to alphabetical order.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'category', false, true ), \Jetonomy\jetonomy_label( 'space', true ) ) ); ?>
							<br>
							<?php /* translators: 1: space label, plural (e.g. "Spaces"). 2: category label, lowercase (e.g. "category"). */ echo esc_html( sprintf( __( 'Dragging rows on the %1$s list writes this same value, so the two stay in step - filter the list to a single %2$s to drag.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'space', true ), \Jetonomy\jetonomy_label( 'category', false, true ) ) ); ?>
						</p>
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
				<?php /* translators: %s: the label of the item being saved (the configured noun). */ ?>
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( __( 'Update %s', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></button>
				<span class="spinner"></span>
			</p>
		</form>
		</div><!-- /.jt-settings-card -->

	<?php elseif ( 'members' === $active_tab ) : ?>
		<!-- Members Tab -->
		<div class="jetonomy-tab-content">
			<h2><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'Add %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member' ) ) ); ?></h2>
			<div class="jetonomy-inline-form" id="jetonomy-add-member-form">
				<div class="jetonomy-form-row">
					<input type="text" id="member-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search users by name or email...', 'jetonomy' ); ?>">
					<div id="member-search-results" class="jetonomy-search-results" style="display:none;"></div>
					<input type="hidden" id="member-user-id" value="">
					<select id="member-role">
						<option value="member"><?php /* jt-terms-ok: space role name. */ esc_html_e( 'Member', 'jetonomy' ); ?></option>
						<option value="moderator"><?php esc_html_e( 'Moderator', 'jetonomy' ); ?></option>
						<option value="admin"><?php esc_html_e( 'Admin', 'jetonomy' ); ?></option>
						<option value="viewer"><?php esc_html_e( 'Viewer', 'jetonomy' ); ?></option>
					</select>
					<button type="button" class="button button-primary" id="jetonomy-add-member" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Add', 'jetonomy' ); ?></button>
				</div>
			</div>

			<?php /* translators: 1: member label, plural (e.g. "Members"). 2: number of members. */ ?>
		<h2><?php printf( esc_html__( '%1$s (%2$d)', 'jetonomy' ), esc_html( \Jetonomy\jetonomy_label( 'member', true ) ), (int) ( $space->member_count ?? $member_total ) ); ?></h2>
		<form method="get" class="search-box jt-spacedit-member-search">
			<input type="hidden" name="page" value="jetonomy-spaces">
			<input type="hidden" name="action" value="edit">
			<input type="hidden" name="space_id" value="<?php echo absint( $space->id ); ?>">
			<input type="hidden" name="tab" value="members">
			<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
			<label class="screen-reader-text" for="jetonomy-member-filter"><?php echo esc_html( sprintf( __( 'Search %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></label>
			<input type="search" id="jetonomy-member-filter" name="ms" value="<?php echo esc_attr( $member_search ); ?>" placeholder="<?php esc_attr_e( 'Name, username or email', 'jetonomy' ); ?>">
			<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ ?>
			<button type="submit" class="button"><?php echo esc_html( sprintf( __( 'Search %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></button>
		</form>
			<?php
			// Drop members whose WP account has since gone rather than
			// rendering a blank row. The cell renderer re-reads the user from
			// WP's own cache, so this costs no extra queries and avoids
			// attaching an undeclared property to the member row.
			$jt_member_rows = array_values(
				array_filter(
					$members,
					static function ( $member ): bool {
						return (bool) get_userdata( $member->user_id );
					}
				)
			);

			jetonomy_admin_table(
				array(
					'table_id'  => 'jetonomy-members-table',
					'class'     => 'jt-spacedit-list',
					'columns'   => array(
						'user'    => array(
							'label'   => __( 'User', 'jetonomy' ),
							'primary' => true,
						),
						'role'    => array(
							'label' => __( 'Role', 'jetonomy' ),
							'width' => 'm',
						),
						'joined'  => array(
							'label' => __( 'Joined', 'jetonomy' ),
							'width' => 'm',
						),
						'actions' => array(
							'label' => __( 'Actions', 'jetonomy' ),
							'width' => 's',
						),
					),
					'rows'      => $jt_member_rows,
					'row_attrs' => static function ( $member ): array {
						return array( 'data-user-id' => (int) $member->user_id );
					},
					'empty'     => array(
						'variant' => 'compact',
						'icon'    => 'groups',
						/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
						'title'   => '' !== $member_search
							/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
							? sprintf( __( 'No %s match your search', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) )
							/* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */
							: sprintf( __( 'No %s yet', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ),
						/* translators: 1: member label, plural, lowercase (e.g. "members"). 2: the singular space label the site owner configured (e.g. space, group). */
						'body'    => '' !== $member_search ? '' : sprintf( __( 'Invite %1$s or open this %2$s to the wider community.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\space_label( false, true ) ),
					),
					'cell'      => static function ( $member, string $key ) use ( $space ): void {
						switch ( $key ) {
							case 'user':
								$jt_user = get_userdata( $member->user_id );
								echo get_avatar( (int) $member->user_id, 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core returns escaped markup.
								echo ' <strong>' . esc_html( $jt_user ? $jt_user->display_name : '' ) . '</strong> ';
								echo '<span class="description">(' . esc_html( $jt_user ? $jt_user->user_login : '' ) . ')</span>';
								break;
							case 'role':
								?>
								<select class="jetonomy-change-member-role" data-space-id="<?php echo absint( $space->id ); ?>" data-user-id="<?php echo absint( $member->user_id ); ?>">
									<option value="viewer" <?php selected( $member->role, 'viewer' ); ?>><?php esc_html_e( 'Viewer', 'jetonomy' ); ?></option>
									<option value="member" <?php selected( $member->role, 'member' ); ?>><?php /* jt-terms-ok: space role name. */ esc_html_e( 'Member', 'jetonomy' ); ?></option>
									<option value="moderator" <?php selected( $member->role, 'moderator' ); ?>><?php esc_html_e( 'Moderator', 'jetonomy' ); ?></option>
									<option value="admin" <?php selected( $member->role, 'admin' ); ?>><?php esc_html_e( 'Admin', 'jetonomy' ); ?></option>
								</select>
								<?php
								break;
							case 'joined':
								echo esc_html( human_time_diff( strtotime( $member->joined_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) );
								break;
							case 'actions':
								?>
								<button type="button" class="button button-small jetonomy-remove-member" data-space-id="<?php echo absint( $space->id ); ?>" data-user-id="<?php echo absint( $member->user_id ); ?>"><?php esc_html_e( 'Remove', 'jetonomy' ); ?></button>
								<?php
								break;
						}
					},
				)
			);
		?>
			<?php if ( $member_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						/* translators: 1: first item number on the page, 2: last item number, 3: total item count. */
						printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( ( $member_paged - 1 ) * $member_per_page + 1 ) ), esc_html( number_format_i18n( min( $member_paged * $member_per_page, $member_total ) ) ), esc_html( number_format_i18n( $member_total ) ) );
						?>
					</span>
					<?php
					$_jt_member_links = paginate_links(
						array(
							'base'    => add_query_arg(
								array(
									'tab' => 'members',
									'ms'  => rawurlencode( $member_search ),
									'mpg' => '%#%',
								),
								$edit_url
							),
							'format'  => '',
							'current' => $member_paged,
							'total'   => $member_pages,
							'type'    => 'array',
						)
					);
					if ( $_jt_member_links ) {
						echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $_jt_member_links ) ) . '</span>';
					}
					?>
				</div>
			</div>
			<?php endif; ?>

			<hr>

			<h2><?php esc_html_e( 'Invite Links', 'jetonomy' ); ?></h2>
			<?php if ( 'invite' !== ( $space->join_policy ?? 'open' ) ) : ?>
				<p class="description"><?php esc_html_e( "Invite links work with any join policy; set Join Policy to 'Invite only' to require them.", 'jetonomy' ); ?></p>
			<?php endif; ?>

			<div class="jetonomy-inline-form" id="jetonomy-generate-invite-form">
				<div class="jetonomy-form-row">
					<label for="invite-max-uses"><?php esc_html_e( 'Max uses', 'jetonomy' ); ?>
						<input type="number" id="invite-max-uses" class="small-text" min="0" step="1" value="0" placeholder="0">
					</label>
					<label for="invite-expires-at"><?php esc_html_e( 'Expires', 'jetonomy' ); ?>
						<input type="date" id="invite-expires-at">
					</label>
					<button type="button" class="button button-primary" id="jetonomy-generate-invite" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Generate invite link', 'jetonomy' ); ?></button>
				</div>
				<p class="description"><?php esc_html_e( 'Max uses 0 means unlimited. Leave Expires blank for no expiry.', 'jetonomy' ); ?></p>
			</div>

			<?php
			// Rows are injected by admin-common.js after an AJAX fetch, so this
			// renders the shell and the empty state only. The column order
			// here is the contract admin-common.js builds rows against.
			jetonomy_admin_table(
				array(
					'table_id'    => 'jetonomy-invites-table',
					'table_attrs' => array( 'data-space-id' => (int) $space->id ),
					'class'       => 'jt-spacedit-list',
					'columns'     => array(
						'link'    => array(
							'label'   => __( 'Invite Link', 'jetonomy' ),
							'primary' => true,
						),
						'uses'    => array(
							'label' => __( 'Uses', 'jetonomy' ),
							'width' => 's',
						),
						'expires' => array(
							'label' => __( 'Expires', 'jetonomy' ),
							'width' => 'm',
						),
						'actions' => array(
							'label' => __( 'Actions', 'jetonomy' ),
							'width' => 'm',
						),
					),
					'rows'        => array(),
					'empty'       => array(
						'variant' => 'compact',
						'icon'    => 'admin-links',
						'title'   => __( 'No invite links yet', 'jetonomy' ),
						/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
						'body'    => sprintf( __( 'Generate a link to invite people directly into this %s.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					),
					'cell'        => static function (): void {
						// Unreachable: rows are always empty server-side.
					},
				)
			);
			?>
		</div>

	<?php elseif ( 'access' === $active_tab ) : ?>
		<!-- Access Rules Tab -->
		<div class="jetonomy-tab-content">
			<h2><?php esc_html_e( 'Add Access Rule', 'jetonomy' ); ?></h2>

			<?php
			// What a rule actually does was invisible: two dials with no
			// explanation, and nothing saying that access follows a
			// subscription rather than being a one-time grant. Owners were
			// left guessing what they had configured.
			$jt_rule_open_join = 'open' === ( $space->join_policy ?? 'open' );
			$jt_rule_public    = 'public' === ( $space->visibility ?? 'public' );
			$jt_has_membership = false;
			foreach ( $access_rules as $jt_r ) {
				if ( 'membership' === $jt_r->rule_type ) {
					$jt_has_membership = true;
					break;
				}
			}
			?>
			<?php if ( $jt_has_membership && ( $jt_rule_public || $jt_rule_open_join ) ) : ?>
				<div class="notice notice-warning inline jt-access-warning">
					<p>
						<strong><?php esc_html_e( 'This rule is not restricting anyone yet.', 'jetonomy' ); ?></strong>
					</p>
					<p>
						<?php if ( $jt_rule_public ) : ?>
							<?php
							/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
							echo esc_html( sprintf( __( 'This %s is Public, so everyone can already read it. A membership rule only adds access - it cannot take it away.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) );
							?>
						<?php else : ?>
							<?php esc_html_e( 'The join policy is Open, so anyone signed in can join without the plan.', 'jetonomy' ); ?>
						<?php endif; ?>
					</p>
					<p>
						<?php
						/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
						echo esc_html( sprintf( __( 'To sell access to this %s, set Visibility to Private and Join Policy to Invite Only on the General tab. Plan holders still get in automatically through this rule.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<div class="jetonomy-inline-form" id="jetonomy-add-rule-form">
				<div class="jetonomy-form-row">
					<label class="screen-reader-text" for="rule-type"><?php esc_html_e( 'Rule type', 'jetonomy' ); ?></label>
					<?php // No preselected type: one click on Add Rule must never open a space by accident. ?>
					<select id="rule-type">
						<option value="" selected><?php esc_html_e( 'Choose who this rule lets in', 'jetonomy' ); ?></option>
						<option value="everyone"><?php esc_html_e( 'Everyone', 'jetonomy' ); ?></option>
						<option value="logged_in"><?php esc_html_e( 'Logged In', 'jetonomy' ); ?></option>
						<option value="role"><?php esc_html_e( 'WP Role', 'jetonomy' ); ?></option>
						<option value="capability"><?php esc_html_e( 'Capability', 'jetonomy' ); ?></option>
						<option value="trust_level"><?php esc_html_e( 'Trust Level', 'jetonomy' ); ?></option>
						<?php
						// Membership options are injected by JS, one per active adapter
						// (wp-roles is left out there: it is the Role type above). With
						// none, show the type disabled so the owner knows it exists.
						$jt_membership_active = array_filter(
							\Jetonomy\Adapters\Adapter_Registry::get_all_membership(),
							static fn( $adapter, $id ) => 'wp-roles' !== $id && $adapter->is_active(),
							ARRAY_FILTER_USE_BOTH
						);
						if ( ! $jt_membership_active ) :
							?>
							<option value="membership" disabled><?php esc_html_e( 'Membership (needs a membership plugin such as MemberPress or Paid Memberships Pro)', 'jetonomy' ); ?></option>
						<?php endif; ?>
					</select>
					<input type="text" id="rule-value" class="regular-text" placeholder="<?php esc_attr_e( 'Value (e.g., administrator, 2)', 'jetonomy' ); ?>">
					<div id="rule-value-membership-wrap" style="display:none;position:relative;">
						<input type="text" id="rule-value-membership-search" class="regular-text" placeholder="<?php esc_attr_e( 'Search courses, roles, memberships...', 'jetonomy' ); ?>" autocomplete="off">
						<input type="hidden" id="rule-value-membership" value="">
						<div id="rule-value-membership-results" class="jetonomy-ac-results" style="display:none;"></div>
					</div>
					<label class="screen-reader-text" for="rule-grants"><?php esc_html_e( 'Grants', 'jetonomy' ); ?></label>
					<?php
					// Participate is preselected because it is what a gated space
					// almost always wants - somebody let in by a rule, especially a
					// paid one, is there to take part. Read-only is the exception.
					// The ladder keeps its order so the progression still reads
					// left to right.
					?>
					<select id="rule-grants">
						<option value="read"><?php esc_html_e( 'Read', 'jetonomy' ); ?></option>
						<option value="participate" selected><?php esc_html_e( 'Participate', 'jetonomy' ); ?></option>
						<option value="full"><?php esc_html_e( 'Full', 'jetonomy' ); ?></option>
					</select>
					<?php
					// Space Role is derived from the access level and submitted as a hidden field. It
					// never affects access (Permission_Engine reads only `grants`), and a free choice
					// would let "Sync Members" deposit space admins from a rule labelled "Read". To
					// give one person a different role, use the Members tab, where roster roles belong.
					?>
					<input type="hidden" id="rule-priority" value="0">
					<button type="button" class="button button-primary" id="jetonomy-add-rule" data-space-id="<?php echo absint( $space->id ); ?>" data-private="<?php echo $jt_rule_public ? '0' : '1'; ?>" disabled><?php esc_html_e( 'Add Rule', 'jetonomy' ); ?></button>
				</div>
				<?php
				// Reads back the rule being composed in plain English, right
				// where the two confusing selects are, and warns when Grants
				// and Space Role disagree. Rendered by admin-common.js.
				?>
				<p class="description jt-rule-preview" data-jt-rule-preview aria-live="polite"></p>
			</div>

			<?php /* translators: %d: number of access rules */ ?>
		<h2><?php printf( esc_html__( 'Access Rules (%d)', 'jetonomy' ), (int) count( $access_rules ) ); ?></h2>
			<?php
			jetonomy_admin_table(
				array(
					'table_id'  => 'jetonomy-rules-table',
					'class'     => 'jt-spacedit-list',
					'columns'   => array(
						'type'    => array(
							'label'   => __( 'Type', 'jetonomy' ),
							'primary' => true,
						),
						'value'   => array( 'label' => __( 'Value', 'jetonomy' ) ),
						'grants'  => array(
							'label' => __( 'Grants', 'jetonomy' ),
							'width' => 's',
						),
						'role'    => array(
							/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
							'label' => sprintf( __( '%s Role', 'jetonomy' ), \Jetonomy\space_label() ),
							'width' => 'm',
						),
						'actions' => array(
							'label' => __( 'Actions', 'jetonomy' ),
							'width' => 'l',
						),
					),
					'rows'      => $access_rules,
					'row_attrs' => static function ( $rule ): array {
						return array( 'data-rule-id' => (int) $rule->id );
					},
					'empty'     => array(
						'variant' => 'compact',
						'icon'    => 'lock',
						'title'   => __( 'No access rules', 'jetonomy' ),
						/* translators: %s: the singular space label, lowercase (e.g. space, group). */
						'body'    => sprintf( __( 'Only this %s\'s visibility and join policy decide who gets in. Add a rule to let more people in by role, capability, trust level or membership.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					),
					'cell'      => static function ( $rule, string $key ) use ( $space ): void {
						// Membership rules store a raw level id; the adapter
						// registry turns it back into something a human reads.
						$display_type  = ucfirst( str_replace( '_', ' ', $rule->rule_type ) );
						$display_value = ! empty( $rule->rule_value ) ? $rule->rule_value : '—';
						if ( 'membership' === $rule->rule_type && ! empty( $rule->rule_value ) ) {
							$described     = \Jetonomy\Adapters\Adapter_Registry::describe_membership_level( (string) $rule->rule_value );
							$display_type  = '' !== $described['kind']
								/* translators: 1: membership plugin (e.g. LearnDash), 2: what kind of level it is (e.g. Course, Group). */
								? sprintf( __( '%1$s %2$s', 'jetonomy' ), $described['type'], $described['kind'] )
								: $described['type'];
							$display_value = $described['value'];
						}

						switch ( $key ) {
							case 'type':
								echo '<code>' . esc_html( $display_type ) . '</code>';
								break;
							case 'value':
								echo esc_html( $display_value );
								if ( 'membership' === $rule->rule_type && ! empty( $rule->rule_value ) && ! \Jetonomy\Adapters\Adapter_Registry::membership_level_available( (string) $rule->rule_value ) ) {
									$jt_kind = (string) ( $described['kind'] ?? '' );
									echo ' <span class="jt-rule-unavailable">' . esc_html(
										'' !== $jt_kind
											/* translators: %s: what kind of level it is (e.g. Course, Product). */
											? sprintf( __( '%s no longer available', 'jetonomy' ), $jt_kind )
											: __( 'No longer available', 'jetonomy' )
									) . '</span>';
									echo '<span class="description jt-rule-unavailable__why">' . esc_html__( 'It was deleted, trashed or unpublished, so nobody can get it and this rule lets nobody in. Publish it again or delete this rule.', 'jetonomy' ) . '</span>';
								}
								break;
							case 'grants':
								?>
								<select class="jetonomy-rule-grants" data-id="<?php echo absint( $rule->id ); ?>" aria-label="<?php esc_attr_e( 'What this rule grants', 'jetonomy' ); ?>">
									<option value="read" <?php selected( $rule->grants, 'read' ); ?>><?php esc_html_e( 'Read', 'jetonomy' ); ?></option>
									<option value="participate" <?php selected( $rule->grants, 'participate' ); ?>><?php esc_html_e( 'Participate', 'jetonomy' ); ?></option>
									<?php if ( \Jetonomy\Models\AccessRule::offers_full( (string) $rule->rule_type ) ) : ?>
										<option value="full" <?php selected( $rule->grants, 'full' ); ?>><?php esc_html_e( 'Full', 'jetonomy' ); ?></option>
									<?php endif; ?>
								</select>
								<?php
								break;
							case 'role':
								// The role a sync would actually store, not the raw column
								// of a rule saved before rules were capped at Member.
								echo '<span class="jetonomy-rule-role">' . esc_html( ucfirst( \Jetonomy\Models\AccessRule::role_for_grants( (string) $rule->grants ) ) ) . '</span>';
								break;
							case 'actions':
								echo '<div class="jetonomy-rule-actions">';
								if ( 'membership' === $rule->rule_type && ! empty( $rule->rule_value ) ) :
									?>
									<button type="button" class="button button-small button-primary jetonomy-sync-rule" data-id="<?php echo absint( $rule->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>" data-value="<?php echo esc_attr( $rule->rule_value ); ?>"><span class="dashicons dashicons-update"></span> <?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'Sync %s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></button>
									<?php
								endif;
								?>
								<button type="button" class="button button-small button-link-delete jetonomy-delete-rule" data-id="<?php echo absint( $rule->id ); ?>"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete', 'jetonomy' ); ?></button>
								</div>
								<?php
								break;
						}
					},
				)
			);
		?>
			<?php // Reference, not a step: closed below the form and list so the rules are on the first screen. ?>
			<details class="jt-settings-card jt-settings-card--disclosure jt-access-help">
				<summary class="jt-settings-card__head">
					<p class="jt-settings-card__title"><?php esc_html_e( 'How access rules work', 'jetonomy' ); ?></p>
				</summary>
				<div class="jt-access-help__body">
					<p class="jt-settings-card__desc jt-access-help__lead">
						<?php
						/* translators: 1: the singular space label, lowercase (e.g. space, group). 2: member label, plural, lowercase (e.g. "members"). */
						echo esc_html( sprintf( __( 'A rule lets more people in to this %1$s: anyone who matches it gets what it grants, without being added to the %2$s list. Who else gets in is set by the %1$s\'s visibility and join policy. Adding a role, capability, trust level or membership rule to a Public %1$s switches it to Private, so that the rule is what decides who gets in.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'member', true, true ) ) );
						?>
					</p>
				</div>
				<table class="widefat striped jt-access-help-table"><!-- jetonomy-audit-table-ok: static reference copy, stacks below 782px via .jt-access-help-table -->
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Grants', 'jetonomy' ); ?></th>
							<th scope="col"><?php esc_html_e( 'What the person can do', 'jetonomy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td data-colname="<?php esc_attr_e( 'Grants', 'jetonomy' ); ?>"><strong><?php esc_html_e( 'Read', 'jetonomy' ); ?></strong></td>
							<td data-colname="<?php esc_attr_e( 'What the person can do', 'jetonomy' ); ?>"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'Lets matching people in to read and report. It takes nothing away from people who already get in another way, such as %s on the roster.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ) ) ); ?></td>
						</tr>
						<tr>
							<td data-colname="<?php esc_attr_e( 'Grants', 'jetonomy' ); ?>"><strong><?php esc_html_e( 'Participate', 'jetonomy' ); ?></strong></td>
							<td data-colname="<?php esc_attr_e( 'What the person can do', 'jetonomy' ); ?>"><?php /* jt-terms-ok: "reply" is the verb here. */ esc_html_e( 'Read, plus post, reply, vote and report. The usual choice for a paid plan.', 'jetonomy' ); ?></td>
						</tr>
						<tr>
							<td data-colname="<?php esc_attr_e( 'Grants', 'jetonomy' ); ?>"><strong><?php esc_html_e( 'Full', 'jetonomy' ); ?></strong></td>
							<td data-colname="<?php esc_attr_e( 'What the person can do', 'jetonomy' ); ?>"><?php /* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: member label, lowercase (e.g. "member"). */ echo esc_html( sprintf( __( 'Participate, plus edit other people\'s content, close and pin %1$s for people whose WordPress role already allows moderation. Offered only on WP Role and Capability rules: for any other rule an ordinary %2$s would get exactly Participate.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'member', false, true ) ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<p class="description">
					<?php /* jt-terms-ok: Viewer / Member / Moderator are space role names. */ /* translators: 1: member label (e.g. "Member"). 2: member label, plural (e.g. "Members"). */ echo esc_html( sprintf( __( '%1$s lists show a matching role - Read is listed as Viewer, Participate and Full as Member. To give one person a different role, change it on the %2$s tab; that keeps it a visible, per-person decision rather than a side effect of a rule.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?>
				</p>
				<p class="description">
					<strong><?php esc_html_e( 'A rule can never hand out moderation.', 'jetonomy' ); ?></strong>
					<?php
					printf(
						/* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: the singular space label the site owner configured, lowercase (e.g. space, group). 3: product name shown in wp-admin (default "Jetonomy"). */
						esc_html__( 'Editing, closing or pinning other people\'s %1$s comes from the person\'s WordPress role - set those under %3$s - Settings - Permissions. Ordinary taking part is different: being admitted to any %2$s, by a rule or by the roster, carries reading, posting, replying, voting and reporting on its own. That holds for roles %3$s does not map, such as the ones a course or membership plugin creates for its own members.', 'jetonomy' ), // jt-terms-ok: "members" here is a membership plugin's own word.
						esc_html( \Jetonomy\jetonomy_label( 'topic', true, true ) ),
						esc_html( \Jetonomy\space_label( false, true ) ),
						esc_html( \Jetonomy\Admin\Admin::brand_name() )
					);
					?>
				</p>
				<p class="description">
					<strong><?php esc_html_e( 'Membership rules are live.', 'jetonomy' ); ?></strong>
					<?php esc_html_e( 'Access begins the moment a plan becomes active and ends when it lapses. There is nothing to sync or undo by hand.', 'jetonomy' ); ?>
					<?php if ( $jt_has_membership ) : ?>
						<?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( 'The "Sync %s" button on a membership rule below is only for listing those people on the roster as well.', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?>
					<?php endif; ?>
				</p>
			</details>
		</div>

	<?php elseif ( 'settings' === $active_tab ) : ?>
		<!-- Space Settings Tab -->
		<div class="jetonomy-tab-content">
			<div class="jt-settings-card">
				<div class="jt-settings-card__head">
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
					<p class="jt-settings-card__title"><?php echo esc_html( sprintf( __( '%s-Specific Settings', 'jetonomy' ), \Jetonomy\space_label() ) ); ?></p>
					<?php /* translators: %s: the singular space label the site owner configured (e.g. space, group). */ ?>
					<p class="jt-settings-card__desc"><?php echo esc_html( sprintf( __( 'These settings override the global defaults for this %s only.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ) ); ?></p>
				</div>
			<form id="jetonomy-space-settings-form" data-space-id="<?php echo absint( $space->id ); ?>" data-edit-token="<?php echo esc_attr( \Jetonomy\Admin\Ajax\Spaces_Handler::edit_token( $space, true ) ); ?>">
				<table class="form-table"><!-- jetonomy-audit-table-ok: WP core settings form, not a data list; core stacks form-table th/td below 782px -->
					<tr>
						<th scope="row"><label for="ss-who-can-post"><?php esc_html_e( 'Who Can Post', 'jetonomy' ); // jt-terms-ok: "post" is the verb here. ?></label></th>
						<td>
							<select id="ss-who-can-post">
								<option value="" <?php selected( $space_settings['who_can_post'] ?? '', '' ); ?>><?php esc_html_e( 'Anyone who can see it (no restriction)', 'jetonomy' ); ?></option>
								<option value="members" <?php selected( $space_settings['who_can_post'] ?? '', 'members' ); ?>><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Only', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></option>
								<option value="moderators" <?php selected( $space_settings['who_can_post'] ?? '', 'moderators' ); ?>><?php esc_html_e( 'Moderators & Admins', 'jetonomy' ); ?></option>
								<option value="admins" <?php selected( $space_settings['who_can_post'] ?? '', 'admins' ); ?>><?php esc_html_e( 'Admins Only', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-who-can-reply"><?php /* jt-terms-ok: "Reply" is the verb here. */ esc_html_e( 'Who Can Reply', 'jetonomy' ); ?></label></th>
						<td>
							<select id="ss-who-can-reply">
								<option value="" <?php selected( $space_settings['who_can_reply'] ?? '', '' ); ?>><?php esc_html_e( 'Anyone who can see it (no restriction)', 'jetonomy' ); ?></option>
								<option value="members" <?php selected( $space_settings['who_can_reply'] ?? '', 'members' ); ?>><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Only', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></option>
								<option value="moderators" <?php selected( $space_settings['who_can_reply'] ?? '', 'moderators' ); ?>><?php esc_html_e( 'Moderators & Admins', 'jetonomy' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Require Approval', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ss-require-approval" value="1" <?php checked( ! empty( $space_settings['require_approval'] ) ); ?>>
								<?php /* translators: 1: topic label, plural lowercase. 2: reply label, plural lowercase. */ echo esc_html( sprintf( __( 'New %1$s and %2$s require moderator approval before publishing', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Allow Voting', 'jetonomy' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="ss-allow-voting" value="1" <?php checked( ( $space_settings['allow_voting'] ?? '1' ) === '1' ); ?>>
								<?php /* translators: 1: topic label, plural, lowercase (e.g. "topics"). 2: reply label, plural, lowercase (e.g. "replies"). */ echo esc_html( sprintf( __( 'Enable upvote/downvote on %1$s and %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true, true ), \Jetonomy\jetonomy_label( 'reply', true, true ) ) ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ss-posts-per-page"><?php /* translators: %s: the owner-configured noun (space, topic, reply, member or category), sometimes with a count such as "14 spaces". */ echo esc_html( sprintf( __( '%s Per Page', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', true ) ) ); ?></label></th>
						<td>
							<?php
							// Render empty (not 0) when no per-space override, so the "Default"
							// placeholder surfaces and admin-common.js can save null on save.
							$ss_posts_per_page = isset( $space_settings['posts_per_page'] ) && '' !== $space_settings['posts_per_page'] && (int) $space_settings['posts_per_page'] > 0
								? absint( $space_settings['posts_per_page'] )
								: '';
							?>
							<input type="number" id="ss-posts-per-page" value="<?php echo esc_attr( $ss_posts_per_page ); ?>" min="1" max="100" class="small-text" placeholder="<?php esc_attr_e( 'Default', 'jetonomy' ); ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><?php /* translators: %s: topic label (e.g. "Topic"). */ echo esc_html( sprintf( __( '%s Prefixes', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic' ) ) ); ?></th>
						<td>
							<label style="margin-bottom:8px;display:block;">
								<input type="checkbox" id="ss-enable-prefixes" value="1" <?php checked( ! empty( $space_settings['enable_prefixes'] ) ); ?>>
								<?php /* translators: 1: topic label, lowercase (e.g. "topic"). 2: the singular space label the site owner configured (e.g. space, group). */ ?>
								<?php echo esc_html( sprintf( __( 'Enable %1$s prefixes for this %2$s', 'jetonomy' ), \Jetonomy\jetonomy_label( 'topic', false, true ), \Jetonomy\space_label( false, true ) ) ); ?>
							</label>
							<div id="jt-prefixes-config" <?php echo empty( $space_settings['enable_prefixes'] ) ? 'style="display:none;"' : ''; ?>>
								<p class="description" style="margin-bottom:8px;"><?php /* translators: 1: member label, plural, lowercase (e.g. "members"). 2: topic label, plural, lowercase (e.g. "topics"). */ echo esc_html( sprintf( __( 'Colored labels %1$s can apply to %2$s (e.g. Bug, Suggestion, Solved).', 'jetonomy' ), \Jetonomy\jetonomy_label( 'member', true, true ), \Jetonomy\jetonomy_label( 'topic', true, true ) ) ); ?></p>
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
							<?php /* translators: 1: the singular space label the site owner configured (e.g. space, group). 2: member label, plural (e.g. "Members"). */ ?>
							<p class="description"><?php echo esc_html( sprintf( __( 'Link this %1$s to a BuddyPress group. %2$s will be synced automatically.', 'jetonomy' ), \Jetonomy\space_label( false, true ), \Jetonomy\jetonomy_label( 'member', true ) ) ); ?></p>
						</td>
					</tr>
					<?php endif; ?>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'jetonomy' ); ?></button>
					<span class="spinner"></span>
				</p>
			</form>
			</div><!-- /.jt-settings-card -->
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
		<h2><?php printf( esc_html__( 'Pending Join Requests (%d)', 'jetonomy' ), (int) $join_total ); ?></h2>
			<?php
			// Same filter as the members table: drop requests whose WP account
			// has since gone, and let the cell renderer re-read from WP's cache.
			$jt_request_rows = array_values(
				array_filter(
					$join_requests,
					static function ( $request ): bool {
						return (bool) get_userdata( $request->user_id );
					}
				)
			);

			jetonomy_admin_table(
				array(
					'table_id'  => 'jetonomy-join-requests-table',
					'class'     => 'jt-spacedit-list',
					'columns'   => array(
						'user'      => array(
							'label'   => __( 'User', 'jetonomy' ),
							'primary' => true,
						),
						'message'   => array( 'label' => __( 'Message', 'jetonomy' ) ),
						'requested' => array(
							'label' => __( 'Requested', 'jetonomy' ),
							'width' => 'm',
						),
						'actions'   => array(
							'label' => __( 'Actions', 'jetonomy' ),
							'width' => 'l',
						),
					),
					'rows'      => $jt_request_rows,
					'row_attrs' => static function ( $request ): array {
						return array( 'data-request-id' => (int) $request->id );
					},
					'empty'     => array(
						'variant' => 'success',
						'icon'    => 'yes-alt',
						'title'   => __( 'No pending join requests', 'jetonomy' ),
						/* translators: %s: the singular space label the site owner configured (e.g. space, group). */
						'body'    => sprintf( __( 'New requests to join this %s will appear here for review.', 'jetonomy' ), \Jetonomy\space_label( false, true ) ),
					),
					'cell'      => static function ( $request, string $key ) use ( $space ): void {
						switch ( $key ) {
							case 'user':
								$jt_user = get_userdata( $request->user_id );
								echo get_avatar( (int) $request->user_id, 24 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core returns escaped markup.
								echo ' <strong>' . esc_html( $jt_user ? $jt_user->display_name : '' ) . '</strong> ';
								echo '<span class="description">(' . esc_html( $jt_user ? $jt_user->user_login : '' ) . ')</span>';
								break;
							case 'message':
								echo esc_html( ! empty( $request->message ) ? $request->message : '—' );
								break;
							case 'requested':
								echo esc_html( human_time_diff( strtotime( $request->created_at ), time() ) . ' ' . __( 'ago', 'jetonomy' ) );
								break;
							case 'actions':
								?>
								<div class="jetonomy-rule-actions">
								<button type="button" class="button button-small button-primary jetonomy-approve-join-request" data-id="<?php echo absint( $request->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></button>
								<button type="button" class="button button-small jetonomy-deny-join-request" data-id="<?php echo absint( $request->id ); ?>" data-space-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Deny', 'jetonomy' ); ?></button>
								</div>
								<?php
								break;
						}
					},
				)
			);
		?>
			<?php if ( $join_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<span class="displaying-num">
						<?php
						$_jr_first = ( $join_paged - 1 ) * $join_per_page + 1;
						$_jr_last  = min( $join_paged * $join_per_page, $join_total );
						/* translators: 1: first item number on the page, 2: last item number, 3: total item count. */
						printf( esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ), esc_html( number_format_i18n( $_jr_first ) ), esc_html( number_format_i18n( $_jr_last ) ), esc_html( number_format_i18n( $join_total ) ) );
						?>
					</span>
					<?php
					$_jr_links = paginate_links(
						array(
							'base'    => add_query_arg(
								array(
									'tab'  => 'join_requests',
									'jrpg' => '%#%',
								),
								$edit_url
							),
							'format'  => '',
							'current' => $join_paged,
							'total'   => $join_pages,
							'type'    => 'array',
						)
					);
					if ( $_jr_links ) {
						echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $_jr_links ) ) . '</span>';
					}
					?>
				</div>
			</div>
			<?php endif; ?>
		</div>

	<?php endif; ?>
</div>
