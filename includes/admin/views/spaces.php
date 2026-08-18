<?php
/**
 * Admin spaces management view.
 *
 * Variables seeded by Admin::render_spaces() before include.
 *
 * @var int      $per_page
 * @var int      $paged
 * @var int      $total
 * @var int      $total_pages
 * @var object[] $categories
 * @var int|null $filter_category
 * @var string   $filter_status
 * @var string   $filter_type
 * @var bool     $can_reorder Whether manual drag-ordering applies to this view.
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$action_param = sanitize_text_field( $_GET['action'] ?? 'list' );
?>
<div class="wrap jetonomy-admin">
	<?php if ( 'new' === $action_param ) : ?>
		<!-- New Space Form -->
		<h1><?php esc_html_e( 'Add New Space', 'jetonomy' ); ?></h1>
		<form id="jetonomy-new-space-form" class="jetonomy-space-form">
			<table class="form-table"><!-- jetonomy-audit-table-ok: core .form-table; wp-admin's own CSS stacks label/field rows below 782px -->
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
						<?php
						$jt_settings       = get_option( 'jetonomy_settings', array() );
						$default_new_space = in_array( $jt_settings['default_space_type'] ?? 'forum', array( 'forum', 'qa', 'ideas', 'feed' ), true )
							? $jt_settings['default_space_type']
							: 'forum';
						?>
						<select id="space-type">
							<option value="forum" <?php selected( $default_new_space, 'forum' ); ?>><?php esc_html_e( 'Forum', 'jetonomy' ); ?></option>
							<option value="qa" <?php selected( $default_new_space, 'qa' ); ?>><?php esc_html_e( 'Q&A', 'jetonomy' ); ?></option>
							<option value="ideas" <?php selected( $default_new_space, 'ideas' ); ?>><?php esc_html_e( 'Ideas', 'jetonomy' ); ?></option>
							<option value="feed" <?php selected( $default_new_space, 'feed' ); ?>><?php esc_html_e( 'Feed', 'jetonomy' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default comes from Settings → General → Default Space Type.', 'jetonomy' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="space-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label></th>
					<td>
						<select id="space-visibility">
							<?php \Jetonomy\space_visibility_options( '', false ); ?>
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
					<th scope="row"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></th>
					<td>
						<?php
						\Jetonomy\Template_Loader::partial(
							'icon-picker',
							array(
								'field_name'    => 'icon',
								'current_value' => 'users',
								'id_prefix'     => 'jt-admin-new-space-icon',
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
			<?php echo esc_html( \Jetonomy\space_label( true ) ); ?>
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
				<select name="per_page" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Spaces per page', 'jetonomy' ); ?>">
					<?php foreach ( array( 20, 50, 100 ) as $jt_pp ) : ?>
						<option value="<?php echo (int) $jt_pp; ?>" <?php selected( (int) $per_page, $jt_pp ); ?>>
							<?php
							/* translators: %d: per-page count. */
							printf( esc_html__( '%d per page', 'jetonomy' ), (int) $jt_pp );
							?>
						</option>
					<?php endforeach; ?>
				</select>
				<div class="jt-content-toolbar__right">
					<?php if ( $total ) : ?>
					<span class="displaying-num">
						<?php
						$_first = ( $paged - 1 ) * $per_page + 1;
						$_last  = min( $paged * $per_page, $total );
						printf(
							/* translators: 1: first item number on the page, 2: last item number, 3: total item count. */
							esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ),
							esc_html( number_format_i18n( $_first ) ),
							esc_html( number_format_i18n( $_last ) ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</span>
					<?php endif; ?>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>
				</div>
			</div>
		</form>

		<?php
		// Category id => name lookup once, replacing the per-row inner loop.
		$jt_cat_names = array();
		foreach ( (array) $categories as $jt_c ) {
			$jt_cat_names[ (int) $jt_c->id ] = $jt_c->name;
		}
		$jt_type_labels = array(
			'forum' => __( 'Forum', 'jetonomy' ),
			'qa'    => __( 'Q&A', 'jetonomy' ),
			'ideas' => __( 'Ideas', 'jetonomy' ),
			'feed'  => __( 'Feed', 'jetonomy' ),
		);

		// Rendered through the shared responsive primitive: this table's 8
		// fixed-width columns clipped unreadably on mobile (Basecamp
		// 10146405861, root cause 10146443346).
		jetonomy_admin_table(
			array(
				'tbody_id'  => 'jetonomy-spaces-list',
				'columns'   => array(
					'title'      => array(
						'label'   => __( 'Title', 'jetonomy' ),
						'primary' => true,
					),
					'type'       => array(
						'label' => __( 'Type', 'jetonomy' ),
						'width' => 's',
					),
					'category'   => array( 'label' => __( 'Category', 'jetonomy' ) ),
					'members'    => array(
						'label' => __( 'Members', 'jetonomy' ),
						'width' => 's',
					),
					'posts'      => array(
						'label' => __( 'Posts', 'jetonomy' ),
						'width' => 'xs',
					),
					'status'     => array(
						'label' => __( 'Status', 'jetonomy' ),
						'width' => 's',
					),
					'join'       => array(
						'label' => __( 'Join Policy', 'jetonomy' ),
						'width' => 'm',
					),
					'visibility' => array(
						'label' => __( 'Visibility', 'jetonomy' ),
						'width' => 's',
					),
				),
				'rows'      => (array) ( $spaces ?? array() ),
				'row_attrs' => static function ( $space ): array {
					return array( 'data-id' => (int) $space->id );
				},
				'empty'     => array(
					'icon'  => 'admin-multisite',
					'title' => __( 'No spaces yet', 'jetonomy' ),
					'body'  => __( 'Spaces group your topics. Create one to start organizing the community.', 'jetonomy' ),
				),
				'cell'      => static function ( $space, string $key ) use ( $jt_cat_names, $jt_type_labels, $can_reorder ): void {
					switch ( $key ) {
						case 'title':
							$edit_url = admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $space->id );
							// The handle lives inside the primary cell, matching
							// categories: a dedicated column has no label to
							// collapse under on mobile and breaks the
							// one-primary-cell contract.
							if ( $can_reorder ) {
								echo '<span class="dashicons dashicons-menu jetonomy-drag-handle" title="' . esc_attr__( 'Drag to reorder', 'jetonomy' ) . '"></span> ';
							}
							echo '<strong><a href="' . esc_url( $edit_url ) . '">' . esc_html( $space->title ) . '</a></strong>';
							echo '<br><code>/community/s/' . esc_html( $space->slug ) . '/</code>';
							?>
							<div class="row-actions">
								<span class="edit"><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
								<span class="view"><a href="<?php echo esc_url( \Jetonomy\base_url() . '/s/' . $space->slug . '/' ); ?>" target="_blank"><?php esc_html_e( 'View', 'jetonomy' ); ?></a> | </span>
								<?php
								// Two separate actions rather than one Delete with a
								// mode picker: the safe one and the irreversible one
								// should not be a dropdown apart. Archive is always
								// offered; permanent deletion appears only for whoever
								// the site owner has allowed to do it, so the setting
								// is visible in the UI and not just enforced on POST.
								$jt_may_purge = current_user_can( 'manage_options' )
									|| ! empty( get_option( 'jetonomy_settings', array() )['allow_space_admin_purge'] );
								?>
								<span class="archive"><a href="#" class="jetonomy-delete-space" data-id="<?php echo absint( $space->id ); ?>" data-mode="transfer"><?php esc_html_e( 'Archive', 'jetonomy' ); ?></a><?php echo $jt_may_purge ? ' | ' : ''; ?></span>
								<?php if ( $jt_may_purge ) : ?>
									<span class="delete"><a href="#" class="jetonomy-delete-space jt-danger" data-id="<?php echo absint( $space->id ); ?>" data-mode="purge" data-title="<?php echo esc_attr( $space->title ); ?>"><?php esc_html_e( 'Delete permanently', 'jetonomy' ); ?></a></span>
								<?php endif; ?>
							</div>
							<?php
							break;
						case 'type':
							echo '<span class="jetonomy-type-badge jetonomy-type-badge--' . esc_attr( $space->type ) . '">' . esc_html( $jt_type_labels[ $space->type ] ?? ucfirst( $space->type ) ) . '</span>';
							break;
						case 'category':
							echo esc_html( $jt_cat_names[ (int) $space->category_id ] ?? '—' );
							break;
						case 'members':
							echo absint( $space->member_count );
							break;
						case 'posts':
							echo absint( $space->post_count );
							break;
						case 'status':
							echo '<span class="jt-status-badge jt-status-badge--' . esc_attr( $space->status ) . '">' . esc_html( \Jetonomy\space_status_label( (string) $space->status ) ) . '</span>';
							break;
						case 'join':
							echo esc_html( \Jetonomy\space_join_policy_label( (string) $space->join_policy ) );
							break;
						case 'visibility':
							echo '<span class="jt-status-badge jt-status-badge--' . esc_attr( $space->visibility ) . '">' . esc_html( \Jetonomy\space_visibility_label( (string) $space->visibility ) ) . '</span>';
							break;
					}
				},
			)
		);
		?>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav bottom">
				<div class="tablenav-pages">
					<?php
					$page_links = paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $paged,
							'total'   => $total_pages,
							'type'    => 'array',
						)
					);
					if ( $page_links ) {
						echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $page_links ) ) . '</span>';
					}
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>
<?php if ( ! defined( 'JETONOMY_PRO_VERSION' ) ) : ?>
<div class="jt-pro-upsell">
	<span class="jt-pro-badge"><?php esc_html_e( 'PRO', 'jetonomy' ); ?></span>
	<h4><?php esc_html_e( 'Polls, Reactions & Analytics for Every Space', 'jetonomy' ); ?></h4>
	<p><?php esc_html_e( 'Add emoji reactions, polls, and custom fields to your spaces. Track engagement with the analytics dashboard.', 'jetonomy' ); ?></p>
	<a href="https://store.wbcomdesigns.com/jetonomy-pro/" class="button" target="_blank"><?php esc_html_e( 'Upgrade to Pro', 'jetonomy' ); ?></a>
	&nbsp;
	<a href="https://store.wbcomdesigns.com/jetonomy/docs/" class="button button-link" target="_blank"><?php esc_html_e( 'View Docs', 'jetonomy' ); ?></a>
</div>
<?php endif; ?>
