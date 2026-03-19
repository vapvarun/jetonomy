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

	<!-- ── Filters bar ───────────────────────────────────────── -->
	<form method="get" action="" id="jetonomy-content-filters" class="jetonomy-content-filters">
		<input type="hidden" name="page" value="jetonomy-content">
		<div class="tablenav top">
			<div class="alignleft actions">

				<!-- Space filter -->
				<select name="space_id" id="jt-filter-space">
					<option value="0" <?php selected( $current_space, 0 ); ?>>
						<?php esc_html_e( 'All Spaces', 'jetonomy' ); ?>
					</option>
					<?php foreach ( $spaces as $space ) : ?>
						<option value="<?php echo absint( $space->id ); ?>" <?php selected( $current_space, (int) $space->id ); ?>>
							<?php echo esc_html( $space->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<!-- Status filter -->
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
					placeholder="<?php esc_attr_e( 'Search by title\u2026', 'jetonomy' ); ?>"
					class="regular-text"
				>

				<button type="submit" class="button">
					<?php esc_html_e( 'Filter', 'jetonomy' ); ?>
				</button>

				<?php if ( $search_query || $current_space || 'all' !== $current_status ) : ?>
					<a href="<?php echo esc_url( $page_url ); ?>" class="button">
						<?php esc_html_e( 'Clear', 'jetonomy' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<!-- Bulk actions -->
			<div class="alignleft actions bulkactions">
				<select id="jt-bulk-action">
					<option value=""><?php esc_html_e( 'Bulk Actions', 'jetonomy' ); ?></option>
					<option value="approve"><?php esc_html_e( 'Approve', 'jetonomy' ); ?></option>
					<option value="trash"><?php esc_html_e( 'Move to Trash', 'jetonomy' ); ?></option>
					<option value="spam"><?php esc_html_e( 'Mark as Spam', 'jetonomy' ); ?></option>
				</select>
				<button type="button" class="button" id="jt-bulk-apply">
					<?php esc_html_e( 'Apply', 'jetonomy' ); ?>
				</button>
				<span class="spinner" id="jt-bulk-spinner" style="float:none;"></span>
			</div>

			<div class="alignright">
				<span class="displaying-num">
					<?php
					$count = count( $posts );
					/* translators: %s: number of posts */
					printf( esc_html( _n( '%s post', '%s posts', $count, 'jetonomy' ) ), number_format_i18n( $count ) );
					?>
				</span>
			</div>

			<br class="clear">
		</div>
	</form>

	<!-- ── Posts table ───────────────────────────────────────── -->
	<?php if ( empty( $posts ) ) : ?>
		<div class="jetonomy-empty-state">
			<span class="dashicons dashicons-admin-post"></span>
			<p><?php esc_html_e( 'No posts found matching your filters.', 'jetonomy' ); ?></p>
		</div>
	<?php else : ?>

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
							<!-- Expand toggle -->
							<button
								type="button"
								class="jt-expand-toggle button-link"
								aria-expanded="false"
								aria-label="<?php esc_attr_e( 'Show replies', 'jetonomy' ); ?>"
								data-post-id="<?php echo absint( $p->id ); ?>"
							>
								<span class="dashicons dashicons-arrow-right-alt2 jt-expand-icon"></span>
							</button>

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
							<span class="jetonomy-status-dot <?php echo esc_attr( $status_dot_class ); ?>"></span>
							<?php echo esc_html( ucfirst( $p->status ) ); ?>
						</td>
						<td data-colname="<?php esc_attr_e( 'Replies', 'jetonomy' ); ?>">
							<?php echo absint( $p->reply_count ?? 0 ); ?>
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

					<!-- ── Replies sub-row (initially hidden) ── -->
					<tr
						id="jt-replies-row-<?php echo absint( $p->id ); ?>"
						class="jt-replies-row"
						style="display:none;"
						aria-hidden="true"
					>
						<td colspan="8" class="jt-replies-cell">
							<div class="jt-replies-container" id="jt-replies-<?php echo absint( $p->id ); ?>">
								<span class="jt-replies-loading">
									<span class="spinner is-active" style="float:none;"></span>
									<?php esc_html_e( 'Loading replies\u2026', 'jetonomy' ); ?>
								</span>
							</div>
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
			confirmTrash   : <?php echo wp_json_encode( __( 'Move this to trash?', 'jetonomy' ) ); ?>,
			confirmSpam    : <?php echo wp_json_encode( __( 'Mark this as spam?', 'jetonomy' ) ); ?>,
			confirmBulk    : <?php echo wp_json_encode( __( 'Apply this action to all selected posts?', 'jetonomy' ) ); ?>,
			saved          : <?php echo wp_json_encode( __( 'Saved!', 'jetonomy' ) ); ?>,
			saveError      : <?php echo wp_json_encode( __( 'Save failed. Please try again.', 'jetonomy' ) ); ?>,
			loadError      : <?php echo wp_json_encode( __( 'Could not load replies. Please refresh and try again.', 'jetonomy' ) ); ?>,
			noReplies      : <?php echo wp_json_encode( __( 'No replies yet.', 'jetonomy' ) ); ?>,
			noneSelected   : <?php echo wp_json_encode( __( 'Please select at least one post.', 'jetonomy' ) ); ?>,
			noAction       : <?php echo wp_json_encode( __( 'Please choose a bulk action.', 'jetonomy' ) ); ?>,
			labelEditReply : <?php echo wp_json_encode( __( 'Reply content', 'jetonomy' ) ); ?>,
			labelSave      : <?php echo wp_json_encode( __( 'Save', 'jetonomy' ) ); ?>,
			labelCancel    : <?php echo wp_json_encode( __( 'Cancel', 'jetonomy' ) ); ?>,
			labelEdit      : <?php echo wp_json_encode( __( 'Edit', 'jetonomy' ) ); ?>,
			labelTrash     : <?php echo wp_json_encode( __( 'Trash', 'jetonomy' ) ); ?>,
			labelSpam      : <?php echo wp_json_encode( __( 'Spam', 'jetonomy' ) ); ?>,
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

	/**
	 * Build a reply item element from server data entirely via DOM APIs.
	 * No innerHTML used — all text set via textContent / setAttribute.
	 *
	 * @param {Object} reply  Reply data returned by jetonomy_get_replies AJAX.
	 * @returns {Element}
	 */
	function buildReplyEl( reply ) {
		var i18n = cfg.i18n;

		/* Wrapper */
		var item = document.createElement( 'div' );
		item.className = 'jt-reply-item';
		item.dataset.replyId = reply.id;

		/* Header */
		var header  = document.createElement( 'div' );
		header.className = 'jt-reply-header';

		var author  = document.createElement( 'strong' );
		author.className   = 'jt-reply-author';
		author.textContent = reply.author || '';

		var date = document.createElement( 'span' );
		date.className   = 'jt-reply-date';
		date.textContent = reply.date || '';

		var badge = document.createElement( 'span' );
		badge.className   = 'jetonomy-badge jetonomy-badge--' + ( reply.status || 'publish' );
		badge.textContent = reply.status_label || '';

		header.appendChild( author );
		header.appendChild( date );
		header.appendChild( badge );

		/* Body */
		var body = document.createElement( 'div' );
		body.className = 'jt-reply-body';

		var preview = document.createElement( 'p' );
		preview.className   = 'jt-reply-preview jt-reply-view';
		preview.textContent = reply.preview || '';

		/* Inline edit area */
		var editArea = document.createElement( 'div' );
		editArea.className = 'jt-reply-inline-edit';
		editArea.style.display = 'none';
		editArea.setAttribute( 'aria-hidden', 'true' );

		var textarea = document.createElement( 'textarea' );
		textarea.className   = 'jt-edit-reply-content large-text';
		textarea.rows        = 4;
		textarea.setAttribute( 'aria-label', i18n.labelEditReply );
		textarea.textContent = reply.content || '';

		var editActions = document.createElement( 'p' );
		editActions.className = 'jt-inline-edit-actions';

		var saveBtn = document.createElement( 'button' );
		saveBtn.type      = 'button';
		saveBtn.className = 'button button-primary button-small jt-save-reply';
		saveBtn.dataset.replyId = reply.id;
		saveBtn.textContent = i18n.labelSave;

		var cancelBtn = document.createElement( 'button' );
		cancelBtn.type      = 'button';
		cancelBtn.className = 'button button-small jt-cancel-reply-edit';
		cancelBtn.textContent = i18n.labelCancel;

		var spinner  = document.createElement( 'span' );
		spinner.className = 'spinner jt-save-spinner';
		spinner.style.cssFloat = 'none';

		var feedback = document.createElement( 'span' );
		feedback.className = 'jt-save-feedback';
		feedback.setAttribute( 'aria-live', 'polite' );

		editActions.appendChild( saveBtn );
		editActions.appendChild( cancelBtn );
		editActions.appendChild( spinner );
		editActions.appendChild( feedback );
		editArea.appendChild( textarea );
		editArea.appendChild( editActions );

		body.appendChild( preview );
		body.appendChild( editArea );

		/* Row actions */
		var actions = document.createElement( 'div' );
		actions.className = 'row-actions jt-reply-actions';

		var editSpan    = document.createElement( 'span' );
		editSpan.className = 'edit';
		var editLink    = document.createElement( 'a' );
		editLink.href   = '#';
		editLink.className = 'jt-edit-reply-trigger';
		editLink.dataset.replyId = reply.id;
		editLink.textContent = i18n.labelEdit;
		editSpan.appendChild( editLink );
		editSpan.appendChild( document.createTextNode( ' | ' ) );

		var trashSpan   = document.createElement( 'span' );
		trashSpan.className = 'trash';
		var trashLink   = document.createElement( 'a' );
		trashLink.href  = '#';
		trashLink.className = 'jt-reply-action-link';
		trashLink.dataset.replyId = reply.id;
		trashLink.dataset.action  = 'trash';
		trashLink.textContent = i18n.labelTrash;
		trashSpan.appendChild( trashLink );
		trashSpan.appendChild( document.createTextNode( ' | ' ) );

		var spamSpan    = document.createElement( 'span' );
		spamSpan.className = 'spam';
		var spamLink    = document.createElement( 'a' );
		spamLink.href   = '#';
		spamLink.className = 'jt-reply-action-link';
		spamLink.dataset.replyId = reply.id;
		spamLink.dataset.action  = 'spam';
		spamLink.textContent = i18n.labelSpam;
		spamSpan.appendChild( spamLink );

		actions.appendChild( editSpan );
		actions.appendChild( trashSpan );
		actions.appendChild( spamSpan );

		/* Assemble */
		item.appendChild( header );
		item.appendChild( body );
		item.appendChild( actions );

		return item;
	}

	/* ── Select-all checkbox ───────────────────────────────────────────── */

	var table = document.getElementById( 'jt-posts-table' );
	if ( ! table ) { return; }

	var selectAll = document.getElementById( 'jt-select-all' );

	if ( selectAll ) {
		selectAll.addEventListener( 'change', function () {
			var cbs = table.querySelectorAll( '.jt-row-cb' );
			cbs.forEach( function ( cb ) { cb.checked = selectAll.checked; } );
		} );

		// Keep the header checkbox in sync when individual rows are toggled.
		table.addEventListener( 'change', function ( e ) {
			if ( ! e.target.classList.contains( 'jt-row-cb' ) ) { return; }
			var all     = table.querySelectorAll( '.jt-row-cb' );
			var checked = table.querySelectorAll( '.jt-row-cb:checked' );
			selectAll.checked       = all.length > 0 && checked.length === all.length;
			selectAll.indeterminate = checked.length > 0 && checked.length < all.length;
		} );
	}

	// Mirror tfoot checkbox to thead.
	var tfootCb = table.querySelector( 'tfoot input[type="checkbox"]' );
	if ( tfootCb && selectAll ) {
		tfootCb.addEventListener( 'change', function () { selectAll.click(); } );
	}

	/* ── Inline edit: open ─────────────────────────────────────────────── */

	table.addEventListener( 'click', function ( e ) {
		var trigger = e.target.closest( '.jt-edit-trigger' );
		if ( ! trigger ) { return; }
		e.preventDefault();

		var postId  = trigger.dataset.postId;
		var row     = document.getElementById( 'jt-post-row-' + postId );
		if ( ! row ) { return; }

		var viewEl  = row.querySelector( '.jt-post-title-view' );
		var editEl  = row.querySelector( '.jt-inline-edit' );
		var isOpen  = 'true' === editEl.dataset.open;

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
			var titleInput = editEl.querySelector( '.jt-edit-title' );
			if ( titleInput ) { titleInput.focus(); }
		}
	} );

	/* ── Inline edit: cancel ───────────────────────────────────────────── */

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jt-cancel-edit' );
		if ( ! btn ) { return; }
		e.preventDefault();

		var editEl = btn.closest( '.jt-inline-edit' );
		var viewEl = editEl.closest( 'td' ).querySelector( '.jt-post-title-view' );

		editEl.style.display = 'none';
		editEl.setAttribute( 'aria-hidden', 'true' );
		editEl.dataset.open  = 'false';
		viewEl.style.display = '';
	} );

	/* ── Inline edit: save post ────────────────────────────────────────── */

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jt-save-post' );
		if ( ! btn ) { return; }
		e.preventDefault();

		var postId   = btn.dataset.id;
		var row      = document.getElementById( 'jt-post-row-' + postId );
		var editEl   = row.querySelector( '.jt-inline-edit' );
		var title    = editEl.querySelector( '.jt-edit-title' ).value.trim();
		var content  = editEl.querySelector( '.jt-edit-content' ).value;
		var spinner  = editEl.querySelector( '.jt-save-spinner' );
		var feedback = editEl.querySelector( '.jt-save-feedback' );

		if ( ! title ) { return; }

		btn.disabled = true;
		spinner.classList.add( 'is-active' );

		ajax( 'jetonomy_update_post', { post_id: postId, title: title, content: content } )
			.then( function ( res ) {
				if ( res.success ) {
					// Update the visible title without touching HTML — use textContent on the
					// anchor or strong, so no XSS vector is introduced.
					var viewEl = row.querySelector( '.jt-post-title-view' );
					var link   = viewEl.querySelector( 'a' );
					if ( link ) {
						link.textContent = title;
					} else {
						var strong = viewEl.querySelector( 'strong' ) || viewEl;
						strong.textContent = title;
					}

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
			.catch( function () {
				showFeedback( feedback, cfg.i18n.saveError, 'error' );
			} )
			.finally( function () {
				btn.disabled = false;
				spinner.classList.remove( 'is-active' );
			} );
	} );

	/* ── Row action links (Trash / Spam / Restore) ─────────────────────── */

	table.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.jt-action-link' );
		if ( ! link ) { return; }
		e.preventDefault();

		var action = link.dataset.action;
		var postId = link.dataset.id;
		var confirmMsg = 'trash' === action ? cfg.i18n.confirmTrash : cfg.i18n.confirmSpam;

		if ( 'trash' === action || 'spam' === action ) {
			if ( ! window.confirm( confirmMsg ) ) { return; }
		}

		var ajaxAction = 'approve' === action ? 'jetonomy_approve_content'
			: 'spam' === action              ? 'jetonomy_spam_content'
			: 'jetonomy_trash_content';

		ajax( ajaxAction, { type: 'post', id: postId } )
			.then( function ( res ) {
				if ( ! res.success ) { return; }

				var row        = document.getElementById( 'jt-post-row-' + postId );
				var repliesRow = document.getElementById( 'jt-replies-row-' + postId );

				if ( 'trash' === action || 'spam' === action ) {
					// Fade then remove the pair of rows.
					row.style.opacity        = '0.4';
					row.style.pointerEvents  = 'none';
					if ( repliesRow ) {
						repliesRow.style.display = 'none';
					}
					setTimeout( function () {
						row.remove();
						if ( repliesRow ) { repliesRow.remove(); }
					}, 700 );
				} else {
					// Restore: simplest to reload the page so PHP re-renders the status badge.
					window.location.reload();
				}
			} );
	} );

	/* ── Replies expand / collapse ─────────────────────────────────────── */

	// Track which post IDs have already had their replies fetched this page load.
	var repliesFetched = {};

	table.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '.jt-expand-toggle' );
		if ( ! btn ) { return; }
		e.preventDefault();

		var postId     = btn.dataset.postId;
		var repliesRow = document.getElementById( 'jt-replies-row-' + postId );
		var icon       = btn.querySelector( '.jt-expand-icon' );
		var isExpanded = 'true' === btn.getAttribute( 'aria-expanded' );

		if ( isExpanded ) {
			repliesRow.style.display = 'none';
			repliesRow.setAttribute( 'aria-hidden', 'true' );
			btn.setAttribute( 'aria-expanded', 'false' );
			icon.classList.remove( 'dashicons-arrow-down-alt2' );
			icon.classList.add( 'dashicons-arrow-right-alt2' );
			return;
		}

		// Expand.
		repliesRow.style.display = '';
		repliesRow.removeAttribute( 'aria-hidden' );
		btn.setAttribute( 'aria-expanded', 'true' );
		icon.classList.remove( 'dashicons-arrow-right-alt2' );
		icon.classList.add( 'dashicons-arrow-down-alt2' );

		if ( repliesFetched[ postId ] ) { return; }
		repliesFetched[ postId ] = true;

		var container = document.getElementById( 'jt-replies-' + postId );

		ajax( 'jetonomy_get_replies', { post_id: postId } )
			.then( function ( res ) {
				// Clear loading indicator via safe DOM removal.
				while ( container.firstChild ) {
					container.removeChild( container.firstChild );
				}

				if ( ! res.success || ! res.data || ! res.data.replies || ! res.data.replies.length ) {
					var empty = document.createElement( 'p' );
					empty.className   = 'description';
					empty.textContent = cfg.i18n.noReplies;
					container.appendChild( empty );
					return;
				}

				var list = document.createElement( 'div' );
				list.className = 'jt-replies-list';

				res.data.replies.forEach( function ( reply ) {
					list.appendChild( buildReplyEl( reply ) );
				} );

				container.appendChild( list );
			} )
			.catch( function () {
				while ( container.firstChild ) {
					container.removeChild( container.firstChild );
				}
				var errP = document.createElement( 'p' );
				errP.className   = 'jt-load-error';
				errP.textContent = cfg.i18n.loadError;
				container.appendChild( errP );
				// Allow a retry after error.
				delete repliesFetched[ postId ];
			} );
	} );

	/* ── Reply inline edit ─────────────────────────────────────────────── */

	// Delegate to document because replies are injected after page load.
	document.addEventListener( 'click', function ( e ) {

		// Open reply edit.
		var editTrigger = e.target.closest( '.jt-edit-reply-trigger' );
		if ( editTrigger ) {
			e.preventDefault();
			var item     = editTrigger.closest( '.jt-reply-item' );
			var preview  = item.querySelector( '.jt-reply-preview' );
			var editArea = item.querySelector( '.jt-reply-inline-edit' );

			preview.style.display  = 'none';
			editArea.style.display = '';
			editArea.removeAttribute( 'aria-hidden' );
			var ta = editArea.querySelector( '.jt-edit-reply-content' );
			if ( ta ) { ta.focus(); }
			return;
		}

		// Cancel reply edit.
		var cancelBtn = e.target.closest( '.jt-cancel-reply-edit' );
		if ( cancelBtn ) {
			e.preventDefault();
			var item     = cancelBtn.closest( '.jt-reply-item' );
			var preview  = item.querySelector( '.jt-reply-preview' );
			var editArea = item.querySelector( '.jt-reply-inline-edit' );

			editArea.style.display = 'none';
			editArea.setAttribute( 'aria-hidden', 'true' );
			preview.style.display  = '';
			return;
		}

		// Save reply.
		var saveBtn = e.target.closest( '.jt-save-reply' );
		if ( saveBtn ) {
			e.preventDefault();
			var replyId  = saveBtn.dataset.replyId;
			var item     = saveBtn.closest( '.jt-reply-item' );
			var editArea = item.querySelector( '.jt-reply-inline-edit' );
			var content  = editArea.querySelector( '.jt-edit-reply-content' ).value;
			var spinner  = editArea.querySelector( '.jt-save-spinner' );
			var feedback = editArea.querySelector( '.jt-save-feedback' );

			saveBtn.disabled = true;
			spinner.classList.add( 'is-active' );

			ajax( 'jetonomy_update_reply', { reply_id: replyId, content: content } )
				.then( function ( res ) {
					if ( res.success ) {
						// Update preview text safely via textContent.
						var newPreview = ( res.data && res.data.preview ) ? res.data.preview : content.slice( 0, 150 );
						var preview    = item.querySelector( '.jt-reply-preview' );
						preview.textContent = newPreview;

						showFeedback( feedback, cfg.i18n.saved, 'success' );
						setTimeout( function () {
							editArea.style.display = 'none';
							editArea.setAttribute( 'aria-hidden', 'true' );
							preview.style.display  = '';
						}, 1200 );
					} else {
						var msg = ( res.data && res.data.message ) ? res.data.message : cfg.i18n.saveError;
						showFeedback( feedback, msg, 'error' );
					}
				} )
				.catch( function () {
					showFeedback( feedback, cfg.i18n.saveError, 'error' );
				} )
				.finally( function () {
					saveBtn.disabled = false;
					spinner.classList.remove( 'is-active' );
				} );
			return;
		}

		// Reply row action links (Trash / Spam).
		var replyActionLink = e.target.closest( '.jt-reply-action-link' );
		if ( replyActionLink ) {
			e.preventDefault();
			var action  = replyActionLink.dataset.action;
			var replyId = replyActionLink.dataset.replyId;
			var item    = replyActionLink.closest( '.jt-reply-item' );
			var confirmMsg = 'trash' === action ? cfg.i18n.confirmTrash : cfg.i18n.confirmSpam;

			if ( ! window.confirm( confirmMsg ) ) { return; }

			var ajaxAction = 'spam' === action ? 'jetonomy_spam_content' : 'jetonomy_trash_content';

			ajax( ajaxAction, { type: 'reply', id: replyId } )
				.then( function ( res ) {
					if ( res.success && item ) {
						item.style.opacity       = '0.4';
						item.style.pointerEvents = 'none';
						setTimeout( function () { item.remove(); }, 700 );
					}
				} );
		}
	} );

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
