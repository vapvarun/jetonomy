<?php
/**
 * Admin view: Replies for a specific post
 *
 * Received variables:
 *   $post           — post object from wp_jt_posts
 *   $replies        — array of reply objects (paginated, 50/page)
 *   $total          — int, total reply count matching current filters
 *   $total_pages    — int
 *   $paged          — int
 *   $per_page       — int
 *   $current_status — string, active status filter
 *   $search_query   — string
 *   $nonce_value    — string
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
				number_format_i18n( (int) $post->reply_count )
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
			<select name="status" id="jt-filter-status">
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
				class="regular-text"
			>
			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'jetonomy' ); ?></button>

			<?php if ( $search_query || 'all' !== $current_status ) : ?>
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
						<input type="checkbox" id="jt-select-all" title="<?php esc_attr_e( 'Select all', 'jetonomy' ); ?>">
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
									<span class="trash">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $r->id ); ?>"
											data-action="trash"
										>
											<?php esc_html_e( 'Trash', 'jetonomy' ); ?>
										</a>
										&nbsp;|&nbsp;
									</span>
									<span class="spam">
										<a href="#"
											class="jt-action-link"
											data-id="<?php echo absint( $r->id ); ?>"
											data-action="spam"
										>
											<?php esc_html_e( 'Spam', 'jetonomy' ); ?>
										</a>
									</span>
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

	var cfg = {
		ajaxUrl : <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
		nonce   : <?php echo wp_json_encode( $nonce_value ); ?>,
		i18n    : {
			confirmTrash : <?php echo wp_json_encode( __( 'Move this to trash?', 'jetonomy' ) ); ?>,
			confirmSpam  : <?php echo wp_json_encode( __( 'Mark this as spam?', 'jetonomy' ) ); ?>,
			confirmBulk  : <?php echo wp_json_encode( __( 'Apply this action to all selected replies?', 'jetonomy' ) ); ?>,
			saved        : <?php echo wp_json_encode( __( 'Saved!', 'jetonomy' ) ); ?>,
			saveError    : <?php echo wp_json_encode( __( 'Save failed. Please try again.', 'jetonomy' ) ); ?>,
			noneSelected : <?php echo wp_json_encode( __( 'Please select at least one reply.', 'jetonomy' ) ); ?>,
			noAction     : <?php echo wp_json_encode( __( 'Please choose a bulk action.', 'jetonomy' ) ); ?>,
		}
	};

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

	function showFeedback( el, message, type ) {
		el.textContent = message;
		el.className   = 'jt-save-feedback jt-save-feedback--' + type;
		setTimeout( function () {
			el.textContent = '';
			el.className   = 'jt-save-feedback';
		}, 3500 );
	}

	var table = document.getElementById( 'jt-replies-table' );
	if ( ! table ) { return; }

	/* ── Select-all checkbox ── */
	var selectAll = document.getElementById( 'jt-select-all' );
	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			table.querySelectorAll( '.jt-row-cb' ).forEach( function ( cb ) {
				cb.checked = selectAll.checked;
			} );
		} );
		table.addEventListener( 'change', function ( e ) {
			if ( ! e.target.classList.contains( 'jt-row-cb' ) ) { return; }
			var all     = table.querySelectorAll( '.jt-row-cb' );
			var checked = table.querySelectorAll( '.jt-row-cb:checked' );
			selectAll.checked       = all.length > 0 && checked.length === all.length;
			selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
		} );
	}
	var tfootCb = table.querySelector( 'tfoot input[type="checkbox"]' );
	if ( tfootCb && selectAll ) {
		tfootCb.addEventListener( 'change', function () { selectAll.click(); } );
	}

	/* ── Inline edit: open ── */
	table.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '.jt-edit-trigger' );
		if ( ! trigger ) { return; }
		e.preventDefault();

		var replyId = trigger.dataset.replyId;
		var row     = document.getElementById( 'jt-reply-row-' + replyId );
		if ( ! row ) { return; }

		var viewEl = row.querySelector( '.jt-reply-preview' );
		var editEl = row.querySelector( '.jt-inline-edit' );
		var isOpen = 'true' === editEl.dataset.open;

		if ( isOpen ) {
			editEl.style.display = 'none';
			editEl.setAttribute( 'aria-hidden', 'true' );
			editEl.dataset.open  = 'false';
			viewEl.style.display = '';
		} else {
			viewEl.style.display = 'none';
			editEl.style.display = '';
			editEl.removeAttribute( 'aria-hidden' );
			editEl.dataset.open  = 'true';
			var ta = editEl.querySelector( '.jt-edit-reply-content' );
			if ( ta ) { ta.focus(); }
		}
	} );

	/* ── Inline edit: cancel ── */
	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jt-cancel-edit' );
		if ( ! btn ) { return; }
		e.preventDefault();

		var editEl = btn.closest( '.jt-inline-edit' );
		var td     = editEl.closest( 'td' );
		var viewEl = td.querySelector( '.jt-reply-preview' );

		editEl.style.display = 'none';
		editEl.setAttribute( 'aria-hidden', 'true' );
		editEl.dataset.open  = 'false';
		viewEl.style.display = '';
	} );

	/* ── Inline edit: save ── */
	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jt-save-reply' );
		if ( ! btn ) { return; }
		e.preventDefault();

		var replyId  = btn.dataset.id;
		var row      = document.getElementById( 'jt-reply-row-' + replyId );
		var editEl   = row.querySelector( '.jt-inline-edit' );
		var content  = editEl.querySelector( '.jt-edit-reply-content' ).value;
		var spinner  = editEl.querySelector( '.jt-save-spinner' );
		var feedback = editEl.querySelector( '.jt-save-feedback' );
		var viewEl   = row.querySelector( '.jt-reply-preview' );

		btn.disabled = true;
		spinner.classList.add( 'is-active' );

		ajax( 'jetonomy_update_reply', { reply_id: replyId, content: content } )
			.then( function ( res ) {
				if ( res.success ) {
					var preview = ( res.data && res.data.preview ) ? res.data.preview : content.slice( 0, 200 );
					viewEl.textContent = preview;
					showFeedback( feedback, cfg.i18n.saved, 'success' );
					setTimeout( function () {
						editEl.style.display = 'none';
						editEl.setAttribute( 'aria-hidden', 'true' );
						editEl.dataset.open  = 'false';
						viewEl.style.display = '';
					}, 1200 );
				} else {
					var msg = ( res.data && res.data.message ) ? res.data.message : cfg.i18n.saveError;
					showFeedback( feedback, msg, 'error' );
				}
			} )
			.catch( function () { showFeedback( feedback, cfg.i18n.saveError, 'error' ); } )
			.finally( function () {
				btn.disabled = false;
				spinner.classList.remove( 'is-active' );
			} );
	} );

	/* ── Row action links (Trash / Spam / Restore) ── */
	table.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.jt-action-link' );
		if ( ! link ) { return; }
		e.preventDefault();

		var action    = link.dataset.action;
		var replyId   = link.dataset.id;
		var confirmMsg = 'trash' === action ? cfg.i18n.confirmTrash : cfg.i18n.confirmSpam;

		if ( 'trash' === action || 'spam' === action ) {
			if ( ! window.confirm( confirmMsg ) ) { return; }
		}

		var ajaxAction = 'approve' === action ? 'jetonomy_approve_content'
			: 'spam' === action              ? 'jetonomy_spam_content'
			: 'jetonomy_trash_content';

		ajax( ajaxAction, { type: 'reply', id: replyId } )
			.then( function ( res ) {
				if ( ! res.success ) { return; }
				var row = document.getElementById( 'jt-reply-row-' + replyId );
				if ( 'trash' === action || 'spam' === action ) {
					row.style.opacity       = '0.4';
					row.style.pointerEvents = 'none';
					setTimeout( function () { row.remove(); }, 700 );
				} else {
					window.location.reload();
				}
			} );
	} );

	/* ── Bulk actions ── */
	var bulkBtn     = document.getElementById( 'jt-bulk-apply' );
	var bulkSelect  = document.getElementById( 'jt-bulk-action' );
	var bulkSpinner = document.getElementById( 'jt-bulk-spinner' );

	if ( bulkBtn && bulkSelect ) {
		bulkBtn.addEventListener( 'click', function () {
			var action = bulkSelect.value;
			if ( ! action ) { window.alert( cfg.i18n.noAction ); return; }

			var checked = table.querySelectorAll( '.jt-row-cb:checked' );
			if ( ! checked.length ) { window.alert( cfg.i18n.noneSelected ); return; }

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

			var promises = ids.map( function ( id ) {
				return ajax( ajaxAction, { type: 'reply', id: id } );
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
