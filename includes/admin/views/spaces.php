<?php
defined( 'ABSPATH' ) || exit;

$action_param = sanitize_text_field( $_GET['action'] ?? 'list' );
?>
<div class="wrap jetonomy-admin">
	<?php if ( 'new' === $action_param ) : ?>
		<!-- New Space Form -->
		<h1><?php esc_html_e( 'Add New Space', 'jetonomy' ); ?></h1>
		<form id="jetonomy-new-space-form" class="jetonomy-space-form">
			<table class="form-table">
				<tr>
					<th scope="row"><label for="space-title"><?php esc_html_e( 'Title', 'jetonomy' ); ?> <span class="required">*</span></label></th>
					<td><input type="text" id="space-title" class="regular-text" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="space-slug" class="regular-text" placeholder="<?php esc_attr_e( 'Auto-generated from title', 'jetonomy' ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label></th>
					<td><textarea id="space-description" rows="4" class="large-text"></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="space-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-category">
							<option value="0"><?php esc_html_e( '(None)', 'jetonomy' ); ?></option>
							<?php foreach ( $categories as $cat ) : ?>
								<option value="<?php echo absint( $cat->id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-type"><?php esc_html_e( 'Type', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-type">
							<option value="forum"><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
							<option value="qa"><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
							<option value="ideas"><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
							<option value="feed"><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-visibility">
							<option value="public"><?php esc_html_e( 'Public', 'jetonomy' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'jetonomy' ); ?></option>
							<option value="hidden"><?php esc_html_e( 'Hidden', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-join-policy"><?php esc_html_e( 'Join Policy', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-join-policy">
							<option value="open"><?php esc_html_e( 'Open', 'jetonomy' ); ?></option>
							<option value="approval"><?php esc_html_e( 'Requires Approval', 'jetonomy' ); ?></option>
							<option value="invite"><?php esc_html_e( 'Invite Only', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-status"><?php esc_html_e( 'Status', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-status">
							<option value="active"><?php esc_html_e( 'Active', 'jetonomy' ); ?></option>
							<option value="archived"><?php esc_html_e( 'Archived', 'jetonomy' ); ?></option>
							<option value="locked"><?php esc_html_e( 'Locked', 'jetonomy' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label></th>
					<td><input type="text" id="space-icon" class="regular-text" placeholder="dashicons-groups"></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Cover Image', 'jetonomy' ); ?></th>
					<td>
						<div class="jetonomy-media-upload">
							<input type="hidden" id="space-cover-image" value="">
							<div id="space-cover-preview" class="jetonomy-cover-preview" style="display:none;">
								<img src="" alt="">
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
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Space', 'jetonomy' ); ?></button>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></a>
				<span class="spinner"></span>
			</p>
		</form>

	<?php else : ?>
		<!-- Spaces List View -->
		<h1>
			<?php esc_html_e( 'Spaces', 'jetonomy' ); ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=new' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'jetonomy' ); ?></a>
		</h1>

		<!-- ── Toolbar ─────────────────────────────────────────────── -->
		<form method="get" action="" id="jetonomy-spaces-filters">
			<input type="hidden" name="page" value="jetonomy-spaces">
			<div class="jt-content-toolbar">
				<select name="category_id">
					<option value=""><?php esc_html_e( 'All Categories', 'jetonomy' ); ?></option>
					<?php foreach ( $categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>" <?php selected( $filter_category, (int) $cat->id ); ?>><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="type">
					<option value=""><?php esc_html_e( 'All Types', 'jetonomy' ); ?></option>
					<option value="forum" <?php selected( $filter_type, 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
					<option value="qa" <?php selected( $filter_type, 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
					<option value="ideas" <?php selected( $filter_type, 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
					<option value="feed" <?php selected( $filter_type, 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
				</select>
				<select name="status">
					<option value=""><?php esc_html_e( 'All Statuses', 'jetonomy' ); ?></option>
					<option value="active" <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Active', 'jetonomy' ); ?></option>
					<option value="archived" <?php selected( $filter_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'jetonomy' ); ?></option>
					<option value="locked" <?php selected( $filter_status, 'locked' ); ?>><?php esc_html_e( 'Locked', 'jetonomy' ); ?></option>
				</select>
				<div class="jt-content-toolbar__right">
					<?php if ( $total ) : ?>
					<span class="displaying-num">
						<?php
						$_first = ( $paged - 1 ) * $per_page + 1;
						$_last  = min( $paged * $per_page, $total );
						printf(
							esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ),
							number_format_i18n( $_first ),
							number_format_i18n( $_last ),
							number_format_i18n( $total )
						);
						?>
					</span>
					<?php endif; ?>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>
				</div>
			</div>
		</form>

		<div class="jt-content-table-wrap">
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th class="column-title"><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
					<th class="column-type" style="width:80px;"><?php esc_html_e( 'Type', 'jetonomy' ); ?></th>
					<th class="column-category"><?php esc_html_e( 'Category', 'jetonomy' ); ?></th>
					<th class="column-members" style="width:80px;"><?php esc_html_e( 'Members', 'jetonomy' ); ?></th>
					<th class="column-posts" style="width:70px;"><?php esc_html_e( 'Posts', 'jetonomy' ); ?></th>
					<th class="column-status" style="width:80px;"><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
					<th class="column-join" style="width:100px;"><?php esc_html_e( 'Join Policy', 'jetonomy' ); ?></th>
					<th class="column-visibility" style="width:90px;"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $spaces ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No spaces found.', 'jetonomy' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $spaces as $space ) :
						$cat_name = '';
						foreach ( $categories as $c ) {
							if ( (int) $c->id === (int) $space->category_id ) {
								$cat_name = $c->name;
								break;
							}
						}
					?>
						<tr data-id="<?php echo absint( $space->id ); ?>">
							<td class="column-title">
								<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . $space->id ) ); ?>"><?php echo esc_html( $space->title ); ?></a></strong>
								<br><code>/community/s/<?php echo esc_html( $space->slug ); ?>/</code>
								<div class="row-actions">
									<span class="edit"><a href="<?php echo esc_url( admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . $space->id ) ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
									<span class="delete"><a href="#" class="jetonomy-delete-space" data-id="<?php echo absint( $space->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
								</div>
							</td>
							<td class="column-type">
								<?php
								$type_labels = [
									'forum' => __( 'Forum', 'jetonomy' ),
									'qa'    => __( 'Q&A', 'jetonomy' ),
									'ideas' => __( 'Ideas', 'jetonomy' ),
									'feed'  => __( 'Feed', 'jetonomy' ),
								];
								?>
								<span class="jetonomy-type-badge jetonomy-type-badge--<?php echo esc_attr( $space->type ); ?>"><?php echo esc_html( $type_labels[ $space->type ] ?? ucfirst( $space->type ) ); ?></span>
							</td>
							<td class="column-category"><?php echo esc_html( $cat_name ?: '&mdash;' ); ?></td>
							<td class="column-members"><?php echo absint( $space->member_count ); ?></td>
							<td class="column-posts"><?php echo absint( $space->post_count ); ?></td>
							<td class="column-status">
								<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $space->status ); ?>"><?php echo esc_html( ucfirst( $space->status ) ); ?></span>
							</td>
							<td class="column-join"><?php echo esc_html( ucfirst( $space->join_policy ) ); ?></td>
							<td class="column-visibility">
								<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $space->visibility ); ?>"><?php echo esc_html( ucfirst( $space->visibility ) ); ?></span>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		</div><!-- /.jt-content-table-wrap -->

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					$page_links = paginate_links( [
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
						'type'    => 'array',
					] );
					if ( $page_links ) {
						echo '<span class="pagination-links">' . implode( ' ', $page_links ) . '</span>';
					}
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
