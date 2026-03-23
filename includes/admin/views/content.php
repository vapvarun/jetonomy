<?php
/**
 * Admin view: Manage Posts & Replies
 *
 * Received variables:
 *   $posts          — array of post objects from wp_jt_posts (with space_title, space_slug, author columns joined)
 *   $spaces         — array of space objects for the filter dropdown
 *   $current_space  — int, currently selected space_id filter (0 = all)
 *   $current_status — string, currently selected status filter ('all'|'publish'|'pending'|'spam'|'trash')
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$settings  = get_option( 'jetonomy_settings', [] );
$base_slug = $settings['base_slug'] ?? 'community';

$valid_statuses = [ 'all', 'publish', 'pending', 'spam', 'trash' ];
$current_status = in_array( $current_status, $valid_statuses, true ) ? $current_status : 'all';
$current_space  = absint( $current_space );

$status_labels = [
	'all'     => __( 'All', 'jetonomy' ),
	'publish' => __( 'Published', 'jetonomy' ),
	'pending' => __( 'Pending', 'jetonomy' ),
	'spam'    => __( 'Spam', 'jetonomy' ),
	'trash'   => __( 'Trash', 'jetonomy' ),
];

$search_query = sanitize_text_field( $_GET['s'] ?? '' );
$page_url     = admin_url( 'admin.php?page=jetonomy-content' );
$nonce_value  = wp_create_nonce( 'jetonomy_admin' );
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Posts &amp; Replies', 'jetonomy' ); ?></h1>

	<!-- ── Toolbar ───────────────────────────────────────────── -->
	<form method="get" action="" id="jetonomy-content-filters">
		<input type="hidden" name="page" value="jetonomy-content">
		<div class="jt-content-toolbar">

			<!-- Space filter -->
			<select name="space_id" id="jt-filter-space">
				<option value="0" <?php selected( $current_space, 0 ); ?>><?php esc_html_e( 'All Spaces', 'jetonomy' ); ?></option>
				<?php foreach ( $spaces as $space ) : ?>
					<option value="<?php echo absint( $space->id ); ?>" <?php selected( $current_space, (int) $space->id ); ?>><?php echo esc_html( $space->title ); ?></option>
				<?php endforeach; ?>
			</select>

			<!-- Status filter -->
			<select name="status" id="jt-filter-status">
				<?php foreach ( $status_labels as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_status, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<!-- Search -->
			<input type="search" name="s" id="jt-filter-search" value="<?php echo esc_attr( $search_query ); ?>" placeholder="<?php esc_attr_e( 'Search by title…', 'jetonomy' ); ?>" class="regular-text">

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>

			<?php if ( $search_query || $current_space || 'all' !== $current_status ) : ?>
				<a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'jetonomy' ); ?></a>
			<?php endif; ?>

			<span class="jt-toolbar-spacer"></span>

			<!-- Bulk actions -->
			<select id="jt-bulk-action">
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
					number_format_i18n( $_first ),
					number_format_i18n( $_last ),
					number_format_i18n( $total )
				);
				?>
			</span>
			<?php endif; ?>
		</div>
	</form>

	<!-- ── Posts table ───────────────────────────────────────── -->
	<?php if ( empty( $posts ) ) : ?>
		<div class="jetonomy-empty-state">
			<span class="dashicons dashicons-admin-post"></span>
			<p><?php esc_html_e( 'No posts found matching your filters.', 'jetonomy' ); ?></p>
		</div>
	<?php else : ?>

	<div class="jt-content-table-wrap">
		<table class="wp-list-table widefat fixed striped" id="jt-posts-table">
			<thead>
				<tr>
					<td class="manage-column column-cb check-column">
						<input type="checkbox" id="jt-select-all" title="<?php esc_attr_e( 'Select all', 'jetonomy' ); ?>">
					</td>
					<th class="manage-column column-title column-primary"><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:120px;"><?php esc_html_e( 'Space', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:120px;"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:90px;"><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:70px;"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:70px;"><?php esc_html_e( 'Views', 'jetonomy' ); ?></th>
					<th class="manage-column" style="width:130px;"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
				</tr>
			</thead>
			<tbody id="jt-posts-tbody">
				<?php foreach ( $posts as $p ) :
					$author      = get_userdata( $p->author_id );
					$author_name = $author ? $author->display_name : __( 'Unknown', 'jetonomy' );

					$space_slug  = $p->space_slug ?? '';
					$post_slug   = $p->slug       ?? '';
					$front_url   = $space_slug && $post_slug
						? home_url( "/{$base_slug}/s/{$space_slug}/t/{$post_slug}/" )
						: '';

					$status_class_map = [
						'publish' => 'jetonomy-status-dot--active',
						'pending' => 'jetonomy-status-dot--archived',
						'spam'    => 'jetonomy-status-dot--locked',
						'trash'   => 'jetonomy-status-dot--locked',
					];
					$status_dot_class = $status_class_map[ $p->status ] ?? '';

					$row_id = 'jt-post-row-' . absint( $p->id );
				?>
					<!-- ── Post row ── -->
					<tr id="<?php echo esc_attr( $row_id ); ?>"
						data-id="<?php echo absint( $p->id ); ?>"
						data-type="post"
						class="jt-post-row">
						<th scope="row" class="check-column">
							<input
								type="checkbox"
								class="jt-row-cb"
								value="<?php echo absint( $p->id ); ?>"
								aria-label="<?php echo esc_attr( sprintf( __( 'Select "%s"', 'jetonomy' ), $p->title ) ); ?>"
							>
						</th>
						<td class="column-title column-primary">
							<!-- Title (view mode) -->
							<strong class="jt-post-title-view">
								<?php if ( $front_url ) : ?>
									<a href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener">
										<?php echo esc_html( $p->title ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $p->title ); ?>
								<?php endif; ?>
							</strong>

							<!-- Inline edit fields (hidden by default) -->
							<div class="jt-inline-edit" style="display:none;" aria-hidden="true">
								<input
									type="text"
									class="jt-edit-title large-text"
									value="<?php echo esc_attr( $p->title ); ?>"
									aria-label="<?php esc_attr_e( 'Post title', 'jetonomy' ); ?>"
									maxlength="255"
								>
								<textarea
									class="jt-edit-content large-text"
									rows="6"
									aria-label="<?php esc_attr_e( 'Post content', 'jetonomy' ); ?>"
								><?php echo esc_textarea( $p->content ?? '' ); ?></textarea>
								<p class="jt-inline-edit-actions">
									<button
										type="button"
										class="button button-primary button-small jt-save-post"
										data-id="<?php echo absint( $p->id ); ?>"
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

							<!-- Row action links -->
							<div class="row-actions">
								<span class="edit">
									<a href="#" class="jt-edit-trigger" data-post-id="<?php echo absint( $p->id ); ?>">
										<?php esc_html_e( 'Edit', 'jetonomy' ); ?>
									</a>
									<?php if ( 'trash' !== $p->status ) : ?>
										&nbsp;|&nbsp;
									<?php endif; ?>
								</span>
								<?php if ( 'trash' !== $p->status ) : ?>
									<span class="trash">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $p->id ); ?>"
											data-type="post"
											data-action="trash"
										>
											<?php esc_html_e( 'Trash', 'jetonomy' ); ?>
										</a>
										&nbsp;|&nbsp;
									</span>
									<span class="spam">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $p->id ); ?>"
											data-type="post"
											data-action="spam"
										>
											<?php esc_html_e( 'Spam', 'jetonomy' ); ?>
										</a>
									</span>
								<?php else : ?>
									<span class="approve">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $p->id ); ?>"
											data-type="post"
											data-action="approve"
										>
											<?php esc_html_e( 'Restore', 'jetonomy' ); ?>
										</a>
									</span>
								<?php endif; ?>
								<?php if ( $front_url ) : ?>
									&nbsp;|&nbsp;
									<span class="view">
										<a href="<?php echo esc_url( $front_url ); ?>" target="_blank" rel="noopener">
											<?php esc_html_e( 'View', 'jetonomy' ); ?>
										</a>
									</span>
								<?php endif; ?>
							</div>
						</td>
						<td data-colname="<?php esc_attr_e( 'Space', 'jetonomy' ); ?>">
							<?php echo esc_html( $p->space_title ?? '' ); ?>
							<?php if ( empty( $p->space_title ) ) : ?>&mdash;<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Author', 'jetonomy' ); ?>">
							<?php echo esc_html( $author_name ); ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Status', 'jetonomy' ); ?>">
							<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $p->status ); ?>">
								<?php echo esc_html( ucfirst( $p->status ) ); ?>
							</span>
						</td>
						<td data-colname="<?php esc_attr_e( 'Replies', 'jetonomy' ); ?>">
							<?php
							$reply_count = absint( $p->reply_count ?? 0 );
							if ( $reply_count > 0 ) :
								$replies_url = admin_url( 'admin.php?page=jetonomy-content&post_id=' . absint( $p->id ) );
							?>
								<a href="<?php echo esc_url( $replies_url ); ?>"><?php echo esc_html( number_format_i18n( $reply_count ) ); ?></a>
							<?php else : ?>
								0
							<?php endif; ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Views', 'jetonomy' ); ?>">
							<?php echo absint( $p->view_count ?? 0 ); ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Date', 'jetonomy' ); ?>">
							<span title="<?php echo esc_attr( $p->created_at ?? '' ); ?>">
								<?php
								if ( ! empty( $p->created_at ) ) {
									echo esc_html(
										human_time_diff( strtotime( $p->created_at ), current_time( 'timestamp', true ) )
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
					<th class="manage-column column-primary"><?php esc_html_e( 'Title', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Space', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Author', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Status', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Replies', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Views', 'jetonomy' ); ?></th>
					<th class="manage-column"><?php esc_html_e( 'Date', 'jetonomy' ); ?></th>
				</tr>
			</tfoot>
		</table>
	</div><!-- /.jt-content-table-wrap -->

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				$plinks = paginate_links( [
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $paged,
					'total'   => $total_pages,
					'type'    => 'array',
				] );
				if ( $plinks ) {
					echo '<span class="pagination-links">' . implode( ' ', $plinks ) . '</span>';
				}
				?>
			</div>
		</div>
	<?php endif; ?>

	<?php endif; ?>

</div><!-- .jetonomy-admin -->

<script>
( function () {
	'use strict';

	/* ── Config ────────────────────────────────────────────────────────── */
	var cfg = {
		ajaxUrl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce   : <?php echo wp_json_encode( $nonce_value ); ?>,
		i18n    : {
			confirmTrash : <?php echo wp_json_encode( __( 'Move this to trash?', 'jetonomy' ) ); ?>,
			confirmSpam  : <?php echo wp_json_encode( __( 'Mark this as spam?', 'jetonomy' ) ); ?>,
			confirmBulk  : <?php echo wp_json_encode( __( 'Apply this action to all selected posts?', 'jetonomy' ) ); ?>,
			saved        : <?php echo wp_json_encode( __( 'Saved!', 'jetonomy' ) ); ?>,
			saveError    : <?php echo wp_json_encode( __( 'Save failed. Please try again.', 'jetonomy' ) ); ?>,
			noneSelected : <?php echo wp_json_encode( __( 'Please select at least one post.', 'jetonomy' ) ); ?>,
			noAction     : <?php echo wp_json_encode( __( 'Please choose a bulk action.', 'jetonomy' ) ); ?>,
		}
	};

	/* ── Helpers ───────────────────────────────────────────────────────── */

	/**
	 * Send an AJAX request and return a Promise resolving to the parsed JSON body.
	 *
	 * @param {string} action  wp_ajax action name.
	 * @param {Object} data    Extra POST fields.
	 * @returns {Promise<Object>}
	 */
	function ajax( action, data ) {
		var params = new URLSearchParams();
		params.set( 'action', action );
		params.set( 'nonce',  cfg.nonce );
		Object.keys( data ).forEach( function ( k ) { params.set( k, data[ k ] ); } );

		return fetch( cfg.ajaxUrl, {
			method      : 'POST',
			credentials : 'same-origin',
			headers     : { 'Content-Type' : 'application/x-www-form-urlencoded; charset=UTF-8' },
			body        : params.toString(),
		} ).then( function ( res ) {
			if ( ! res.ok ) { throw new Error( res.statusText ); }
			return res.json();
		} );
	}

	/**
	 * Show a brief inline feedback message that auto-clears after 3.5 s.
	 *
	 * @param {Element} el       .jt-save-feedback element.
	 * @param {string}  message  Plain-text message to display.
	 * @param {string}  type     'success' | 'error'
	 */
	function showFeedback( el, message, type ) {
		el.textContent = message;
		el.className   = 'jt-save-feedback jt-save-feedback--' + type;
		setTimeout( function () {
			el.textContent = '';
			el.className   = 'jt-save-feedback';
		}, 3500 );
	}

	/* ── Bulk actions ───────────────────────────────────────────────────── */

	var bulkBtn     = document.getElementById( 'jt-bulk-apply' );
	var bulkSelect  = document.getElementById( 'jt-bulk-action' );
	var bulkSpinner = document.getElementById( 'jt-bulk-spinner' );

	if ( bulkBtn && bulkSelect ) {
		bulkBtn.addEventListener( 'click', function () {
			var action = bulkSelect.value;
			if ( ! action ) {
				window.alert( cfg.i18n.noAction );
				return;
			}

			var checked = table.querySelectorAll( '.jt-row-cb:checked' );
			if ( ! checked.length ) {
				window.alert( cfg.i18n.noneSelected );
				return;
			}

			if ( 'trash' === action || 'spam' === action ) {
				if ( ! window.confirm( cfg.i18n.confirmBulk ) ) { return; }
			}

			var ids = [];
			checked.forEach( function ( cb ) { ids.push( cb.value ); } );

			var ajaxAction = 'approve' === action ? 'jetonomy_approve_content'
				: 'spam' === action              ? 'jetonomy_spam_content'
				: 'jetonomy_trash_content';

			bulkBtn.disabled = true;
			bulkSpinner.classList.add( 'is-active' );

			// One request per post ID to keep AJAX handlers simple.
			var promises = ids.map( function ( id ) {
				return ajax( ajaxAction, { type: 'post', id: id } );
			} );

			Promise.allSettled( promises ).then( function () {
				bulkBtn.disabled = false;
				bulkSpinner.classList.remove( 'is-active' );
				window.location.reload();
			} );
		} );
	}

} () );
</script>
