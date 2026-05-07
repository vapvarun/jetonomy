<?php
/**
 * Admin view: Replies for a specific post.
 *
 * Variables seeded by Admin::render_replies() before include — declared here
 * for static analysis (PHPStan does not follow include-from-method scope).
 *
 * @var object   $post
 * @var object[] $replies
 * @var int      $total
 * @var int      $total_pages
 * @var int      $paged
 * @var int      $per_page
 * @var string   $current_status
 * @var string   $search_query
 * @var string   $nonce_value
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings  = get_option( 'jetonomy_settings', [] );
$base_slug = $settings['base_slug'] ?? 'community';

$back_url = admin_url( 'admin.php?page=jetonomy-content' );
$page_url = admin_url( 'admin.php?page=jetonomy-content&post_id=' . absint( $post->id ) );

// Post author & space.
$post_author      = get_userdata( (int) $post->author_id );
$post_author_name = $post_author ? $post_author->display_name : __( 'Unknown', 'jetonomy' );

$space       = \Jetonomy\Models\Space::find( (int) $post->space_id );
$space_title = $space ? $space->title : '';
$space_slug  = $space ? $space->slug : '';
$post_slug   = $post->slug ?? '';
$front_url   = $space_slug && $post_slug
	? home_url( "/{$base_slug}/s/{$space_slug}/t/{$post_slug}/" )
	: '';

$status_labels = [
	'all'     => __( 'All', 'jetonomy' ),
	'publish' => __( 'Published', 'jetonomy' ),
	'pending' => __( 'Pending', 'jetonomy' ),
	'spam'    => __( 'Spam', 'jetonomy' ),
	'trash'   => __( 'Trash', 'jetonomy' ),
];
?>
<div class="wrap jetonomy-admin">

	<!-- Breadcrumb -->
	<p class="jt-breadcrumb">
		<a href="<?php echo esc_url( $back_url ); ?>">
			<span class="dashicons dashicons-arrow-left-alt2" style="vertical-align:middle;font-size:16px;height:16px;width:16px;"></span>
			<?php esc_html_e( 'All Posts', 'jetonomy' ); ?>
		</a>
	</p>

	<h1>
	<?php
		printf(
			/* translators: %s: post title */
			esc_html__( 'Replies: %s', 'jetonomy' ),
			esc_html( wp_trim_words( $post->title, 10 ) )
		);
		?>
	</h1>

	<!-- Post meta bar -->
	<div class="jt-replies-page-meta">
		<span class="jt-rpmeta-item">
			<span class="dashicons dashicons-admin-users"></span>
			<?php echo esc_html( $post_author_name ); ?>
		</span>
		<?php if ( $space_title ) : ?>
			<span class="jt-rpmeta-sep">·</span>
			<span class="jt-rpmeta-item">
				<span class="dashicons dashicons-networking"></span>
				<?php echo esc_html( $space_title ); ?>
			</span>
		<?php endif; ?>
		<span class="jt-rpmeta-sep">·</span>
		<span class="jt-rpmeta-item">
			<?php
			printf(
				/* translators: %s: number of replies */
				esc_html( _n( '%s reply total', '%s replies total', (int) $post->reply_count, 'jetonomy' ) ),
				esc_html( number_format_i18n( (int) $post->reply_count ) )
			);
			?>
		</span>
		<?php if ( $front_url ) : ?>
			<span class="jt-rpmeta-sep">·</span>
			<a href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener" class="jt-rpmeta-item">
				<span class="dashicons dashicons-external"></span>
				<?php esc_html_e( 'View on site', 'jetonomy' ); ?>
			</a>
		<?php endif; ?>
	</div>

	<!-- Toolbar -->
	<form method="get" action="" id="jetonomy-replies-filters">
		<input type="hidden" name="page" value="jetonomy-content">
		<input type="hidden" name="post_id" value="<?php echo absint( $post->id ); ?>">
		<div class="jt-content-toolbar">

			<!-- Status tabs -->
			<select name="status" id="jt-filter-status" aria-label="<?php esc_attr_e( 'Filter by status', 'jetonomy' ); ?>">
				<?php foreach ( $status_labels as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_status, $slug ); ?>>
						<?php echo esc_html( $label ); ?>
					</option>
				<?php endforeach; ?>
			</select>

			<!-- Search -->
			<input
				type="search"
				name="s"
				id="jt-filter-search"
				value="<?php echo esc_attr( $search_query ); ?>"
				placeholder="<?php esc_attr_e( 'Search replies…', 'jetonomy' ); ?>"
				aria-label="<?php esc_attr_e( 'Search replies', 'jetonomy' ); ?>"
				class="regular-text"
			>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>

			<?php if ( $search_query || 'all' !== $current_status ) : ?>
				<a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'jetonomy' ); ?></a>
			<?php endif; ?>

			<span class="jt-toolbar-spacer"></span>

			<!-- Bulk actions -->
			<select id="jt-bulk-action" aria-label="<?php esc_attr_e( 'Bulk action', 'jetonomy' ); ?>">
				<option value=""><?php esc_html_e( 'Bulk Actions', 'jetonomy' ); ?></option>
				<option value="approve"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></option>
				<option value="trash"><?php esc_html_e( 'Move to Trash', 'jetonomy' ); ?></option>
				<option value="spam"><?php esc_html_e( 'Mark as Spam', 'jetonomy' ); ?></option>
			</select>
			<button type="button" class="button" id="jt-bulk-apply"><?php esc_html_e( 'Apply', 'jetonomy' ); ?></button>
			<span class="spinner" id="jt-bulk-spinner" style="float:none;margin:0;"></span>

			<?php if ( $total ) : ?>
				<span class="displaying-num">
					<?php
					$_first = ( $paged - 1 ) * $per_page + 1;
					$_last  = min( $paged * $per_page, $total );
					printf(
						esc_html__( '%1$s&#8211;%2$s of %3$s', 'jetonomy' ),
						esc_html( number_format_i18n( $_first ) ),
						esc_html( number_format_i18n( $_last ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</form>

	<?php if ( empty( $replies ) ) : ?>
		<div class="jetonomy-empty-state">
			<span class="dashicons dashicons-format-chat"></span>
			<p><?php esc_html_e( 'No replies found matching your filters.', 'jetonomy' ); ?></p>
		</div>
	<?php else : ?>

	<div class="jt-content-table-wrap">
		<table class="wp-list-table widefat fixed striped" id="jt-replies-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="jt-select-all" aria-label="<?php esc_attr_e( 'Select all rows', 'jetonomy' ); ?>">
					</td>
					<th class="manage-column column-primary"><?php esc_html_e( 'Reply', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:130px;"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:90px;"><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:130px;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
				</tr>
			</thead>
			<tbody id="jt-replies-tbody">
				<?php
				foreach ( $replies as $r ) :
					$author      = get_userdata( (int) $r->author_id );
					$author_name = $author ? $author->display_name : __( 'Unknown', 'jetonomy' );
					$row_id      = 'jt-reply-row-' . absint( $r->id );
					$preview     = wp_trim_words( wp_strip_all_tags( $r->content ?? '' ), 40 );
					?>
					<tr
						id="<?php echo esc_attr( $row_id ); ?>"
						data-id="<?php echo absint( $r->id ); ?>"
						class="jt-reply-row"
					>
						<th scope="row" class="check-column">
							<input
								type="checkbox"
								class="jt-row-cb"
								value="<?php echo absint( $r->id ); ?>"
								aria-label="<?php esc_attr_e( 'Select reply', 'jetonomy' ); ?>"
							>
						</th>
						<td class="column-primary">
							<!-- View mode -->
							<p class="jt-reply-preview"><?php echo esc_html( $preview ); ?></p>

							<!-- Inline edit -->
							<div class="jt-inline-edit" style="display:none;" aria-hidden="true">
								<textarea
									class="jt-edit-reply-content large-text"
									rows="5"
									aria-label="<?php esc_attr_e( 'Reply content', 'jetonomy' ); ?>"
								><?php echo esc_textarea( $r->content ?? '' ); ?></textarea>
								<p class="jt-inline-edit-actions">
									<button
										type="button"
										class="button button-primary button-small jt-save-reply"
										data-id="<?php echo absint( $r->id ); ?>"
									>
										<?php esc_html_e( 'Save', 'jetonomy' ); ?>
									</button>
									<button type="button" class="button button-small jt-cancel-edit">
										<?php esc_html_e( 'Cancel', 'jetonomy' ); ?>
									</button>
									<span class="spinner jt-save-spinner" style="float:none;"></span>
									<span class="jt-save-feedback" aria-live="polite"></span>
								</p>
							</div>

							<!-- Row actions -->
							<div class="row-actions">
								<span class="edit">
									<a href="#" class="jt-edit-trigger" data-reply-id="<?php echo absint( $r->id ); ?>">
										<?php esc_html_e( 'Edit', 'jetonomy' ); ?>
									</a>
									<?php if ( 'trash' !== $r->status ) : ?>
										&nbsp;|&nbsp;
									<?php endif; ?>
								</span>
								<?php if ( 'trash' !== $r->status ) : ?>
									<?php if ( in_array( $r->status ?? '', array( 'spam', 'pending' ), true ) ) : ?>
										<span class="approve">
											<a href="#"
												class="jt-action-link"
												data-id="<?php echo absint( $r->id ); ?>"
												data-action="approve"
											>
												<?php
												echo 'spam' === $r->status
													? esc_html__( 'Not Spam', 'jetonomy' )
													: esc_html__( 'Approve', 'jetonomy' );
												?>
											</a>
											&nbsp;|&nbsp;
										</span>
									<?php endif; ?>
									<span class="trash">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $r->id ); ?>"
											data-action="trash"
										>
											<?php esc_html_e( 'Trash', 'jetonomy' ); ?>
										</a>
										<?php if ( 'spam' !== $r->status ) : ?>
											&nbsp;|&nbsp;
										<?php endif; ?>
									</span>
									<?php if ( 'spam' !== $r->status ) : ?>
										<span class="spam">
											<a href="#"
												class="jt-action-link"
												data-id="<?php echo absint( $r->id ); ?>"
												data-action="spam"
											>
												<?php esc_html_e( 'Spam', 'jetonomy' ); ?>
											</a>
										</span>
									<?php endif; ?>
								<?php else : ?>
									<span class="restore">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $r->id ); ?>"
											data-action="approve"
										>
											<?php esc_html_e( 'Restore', 'jetonomy' ); ?>
										</a>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td><?php echo esc_html( $author_name ); ?></td>
						<td>
							<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $r->status ?? 'publish' ); ?>">
								<?php echo esc_html( ucfirst( $r->status ?? 'publish' ) ); ?>
							</span>
						</td>
						<td>
							<span title="<?php echo esc_attr( $r->created_at ?? '' ); ?>">
								<?php
								if ( ! empty( $r->created_at ) ) {
									echo esc_html(
										human_time_diff( strtotime( $r->created_at ), time() )
										. ' ' . __( 'ago', 'jetonomy' )
									);
								} else {
									echo '&mdash;';
								}
								?>
							</span>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" aria-label="<?php esc_attr_e( 'Select all', 'jetonomy' ); ?>">
					</td>
					<th class="manage-column column-primary"><?php esc_html_e( 'Reply', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
				</tr>
			</tfoot>
		</table>
	</div><!-- /.jt-content-table-wrap -->

		<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				$plinks = paginate_links(
					[
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $total_pages,
						'type'    => 'array',
					]
				);
				if ( $plinks ) {
					echo '<span class="pagination-links">' . wp_kses_post( implode( ' ', $plinks ) ) . '</span>';
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<?php endif; ?>

</div><!-- .jetonomy-admin -->
<?php
wp_enqueue_script(
	'jetonomy-admin-replies',
	JETONOMY_URL . 'assets/js/admin-replies.js',
	array( 'jetonomy-admin' ),
	JETONOMY_VERSION,
	true
);
wp_localize_script(
	'jetonomy-admin-replies',
	'jetonomyReplies',
	array(
		'nonce' => $nonce_value,
		'i18n'  => array(
			'confirmTrash' => esc_html__( 'Move this to trash?', 'jetonomy' ),
			'confirmSpam'  => esc_html__( 'Mark this as spam?', 'jetonomy' ),
			'confirmBulk'  => esc_html__( 'Apply this action to all selected replies?', 'jetonomy' ),
			'saved'        => esc_html__( 'Saved!', 'jetonomy' ),
			'saveError'    => esc_html__( 'Save failed. Please try again.', 'jetonomy' ),
			'noneSelected' => esc_html__( 'Please select at least one reply.', 'jetonomy' ),
			'noAction'     => esc_html__( 'Please choose a bulk action.', 'jetonomy' ),
		),
	)
);
?>
