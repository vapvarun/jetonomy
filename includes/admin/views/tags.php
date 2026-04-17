<?php
/**
 * Admin tags management view.
 *
 * Intentionally reuses the Categories split layout (.jetonomy-categories-layout)
 * — same sticky form on the left, scrollable table on the right. Tag-specific
 * additions over the Categories page: search, per-page picker, bulk delete,
 * pagination (tags scale to 1000s so we can't ship the flat list pattern).
 *
 * Scoped variables (set by Admin::render_tags):
 *
 * @var object[] $tags         Current page rows.
 * @var int      $tags_total   Total rows across all pages.
 * @var int      $paged        Current 1-based page number.
 * @var int      $per_page     Rows per page (20|50|100).
 * @var string   $search       Current search filter.
 * @var string   $orderby      Current sort column (id|name|slug|post_count).
 * @var string   $order        Current sort direction (ASC|DESC).
 * @var int      $total_pages  ceil($tags_total / $per_page).
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;

$sort_link = function ( $col, $label ) use ( $orderby, $order, $search, $per_page ) {
	$next_order = ( $orderby === $col && 'ASC' === $order ) ? 'DESC' : 'ASC';
	$url        = add_query_arg(
		array(
			'page'     => 'jetonomy-tags',
			's'        => $search,
			'orderby'  => $col,
			'order'    => $next_order,
			'per_page' => $per_page,
		),
		admin_url( 'admin.php' )
	);
	$indicator = '';
	if ( $orderby === $col ) {
		$indicator = 'ASC' === $order ? ' <span class="dashicons dashicons-arrow-up"></span>' : ' <span class="dashicons dashicons-arrow-down"></span>';
	}
	return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $indicator . '</a>';
};
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Tags', 'jetonomy' ); ?></h1>

	<div class="jetonomy-categories-layout">

		<!-- Add New Tag Form -->
		<div class="jt-settings-card" id="jetonomy-add-tag-form">
			<div class="jt-settings-card__head">
				<h2 class="jt-settings-card__title"><?php esc_html_e( 'Add New Tag', 'jetonomy' ); ?></h2>
			</div>
			<div class="jetonomy-form-grid">
				<div class="jetonomy-form-field">
					<label for="tag-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?> <span class="required">*</span></label>
					<input type="text" id="tag-name" class="regular-text" required>
				</div>
				<div class="jetonomy-form-field">
					<label for="tag-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label>
					<input type="text" id="tag-slug" class="regular-text" placeholder="<?php esc_attr_e( 'Auto-generated from name', 'jetonomy' ); ?>">
				</div>
			</div>
			<p>
				<button type="button" class="button button-primary" id="jetonomy-save-tag"><?php esc_html_e( 'Add Tag', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>

		<!-- Tags Table -->
		<div class="jt-content-table-wrap">

			<!-- Tablenav top: search + bulk + per-page -->
			<form method="get" class="tablenav top" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
				<input type="hidden" name="page" value="jetonomy-tags">

				<div class="alignleft actions bulkactions">
					<select id="jetonomy-tags-bulk-action" aria-label="<?php esc_attr_e( 'Bulk actions', 'jetonomy' ); ?>">
						<option value=""><?php esc_html_e( 'Bulk actions', 'jetonomy' ); ?></option>
						<option value="delete"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></option>
					</select>
					<button type="button" class="button action" id="jetonomy-tags-bulk-apply"><?php esc_html_e( 'Apply', 'jetonomy' ); ?></button>
				</div>

				<div class="alignleft actions">
					<label for="tags-per-page" class="screen-reader-text"><?php esc_html_e( 'Rows per page', 'jetonomy' ); ?></label>
					<select id="tags-per-page" name="per_page">
						<?php foreach ( array( 20, 50, 100 ) as $pp ) : ?>
							<option value="<?php echo (int) $pp; ?>" <?php selected( (int) $per_page, $pp ); ?>>
								<?php
								/* translators: %d: per-page count */
								echo esc_html( sprintf( __( '%d per page', 'jetonomy' ), $pp ) );
								?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<p class="search-box">
					<label class="screen-reader-text" for="tags-search"><?php esc_html_e( 'Search tags', 'jetonomy' ); ?></label>
					<input type="search" id="tags-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search tags…', 'jetonomy' ); ?>">
					<button type="submit" class="button"><?php esc_html_e( 'Search', 'jetonomy' ); ?></button>
				</p>
			</form>

			<table class="wp-list-table widefat fixed striped" id="jetonomy-tags-table">
				<thead>
					<tr>
						<td id="cb" class="manage-column column-cb check-column">
							<input type="checkbox" id="jetonomy-tags-cb-all" aria-label="<?php esc_attr_e( 'Select all', 'jetonomy' ); ?>">
						</td>
						<th class="column-name column-primary"><?php echo $sort_link( 'name', __( 'Name', 'jetonomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th class="column-slug"><?php echo $sort_link( 'slug', __( 'Slug', 'jetonomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
						<th class="column-count"><?php echo $sort_link( 'post_count', __( 'Posts', 'jetonomy' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></th>
					</tr>
				</thead>
				<tbody id="jetonomy-tags-list">
					<?php if ( empty( $tags ) ) : ?>
						<tr class="jetonomy-no-items">
							<td colspan="4">
								<?php if ( '' !== $search ) : ?>
									<?php esc_html_e( 'No tags match that search.', 'jetonomy' ); ?>
								<?php else : ?>
									<?php esc_html_e( 'No tags yet. Create your first one on the left.', 'jetonomy' ); ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $tags as $tag ) : ?>
							<tr data-id="<?php echo absint( $tag->id ); ?>" data-post-count="<?php echo absint( $tag->post_count ?? 0 ); ?>" class="jetonomy-tag-row">
								<th scope="row" class="check-column">
									<input type="checkbox" class="jetonomy-tag-cb" value="<?php echo absint( $tag->id ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Select %s', 'jetonomy' ), $tag->name ) ); ?>">
								</th>
								<td class="column-name column-primary">
									<strong><?php echo esc_html( $tag->name ); ?></strong>
									<div class="row-actions">
										<span class="edit"><a href="#" class="jetonomy-edit-tag" data-id="<?php echo absint( $tag->id ); ?>" data-name="<?php echo esc_attr( $tag->name ); ?>" data-slug="<?php echo esc_attr( $tag->slug ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
										<span class="delete"><a href="#" class="jetonomy-delete-tag" data-id="<?php echo absint( $tag->id ); ?>" data-name="<?php echo esc_attr( $tag->name ); ?>" data-count="<?php echo absint( $tag->post_count ?? 0 ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
									</div>
								</td>
								<td class="column-slug"><code><?php echo esc_html( $tag->slug ); ?></code></td>
								<td class="column-count">
									<?php
									$count = absint( $tag->post_count ?? 0 );
									if ( $count > 0 ) {
										$tag_url = \Jetonomy\base_url() . '/tag/' . rawurlencode( $tag->slug ) . '/';
										echo '<a href="' . esc_url( $tag_url ) . '" target="_blank" rel="noopener">' . esc_html( $count ) . '</a>';
									} else {
										echo '<span class="jetonomy-count-zero">0</span>';
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $total_pages > 1 || $tags_total > 0 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %d: total items */
								esc_html( _n( '%d item', '%d items', (int) $tags_total, 'jetonomy' ) ),
								(int) $tags_total
							);
							?>
						</span>
						<?php
						if ( $total_pages > 1 ) :
							$base_args = array(
								'page'     => 'jetonomy-tags',
								's'        => $search,
								'orderby'  => $orderby,
								'order'    => $order,
								'per_page' => $per_page,
							);
							$base_url  = admin_url( 'admin.php' );
							echo wp_kses_post(
								paginate_links(
									array(
										'base'      => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), $base_url ),
										'format'    => '',
										'current'   => max( 1, $paged ),
										'total'     => $total_pages,
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
										'type'      => 'plain',
									)
								)
							);
						endif;
						?>
					</div>
				</div>
			<?php endif; ?>

		</div><!-- /.jt-content-table-wrap -->

	</div><!-- /.jetonomy-categories-layout -->

	<!-- Edit Tag Modal -->
	<div class="jetonomy-modal" id="jetonomy-edit-tag-modal" style="display:none;">
		<div class="jetonomy-modal__overlay"></div>
		<div class="jetonomy-modal__content">
			<h2><?php esc_html_e( 'Edit Tag', 'jetonomy' ); ?></h2>
			<input type="hidden" id="edit-tag-id">
			<div class="jetonomy-form-grid">
				<div class="jetonomy-form-field">
					<label for="edit-tag-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?> <span class="required">*</span></label>
					<input type="text" id="edit-tag-name" class="regular-text" required>
				</div>
				<div class="jetonomy-form-field">
					<label for="edit-tag-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label>
					<input type="text" id="edit-tag-slug" class="regular-text">
				</div>
			</div>
			<p class="jetonomy-modal__actions">
				<button type="button" class="button button-primary" id="jetonomy-update-tag"><?php esc_html_e( 'Update Tag', 'jetonomy' ); ?></button>
				<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>
	</div>
</div>

<script>
( function () {
	const ajax   = window.ajaxurl;
	const nonce  = window.jetonomyAdmin && window.jetonomyAdmin.nonce;

	function post( action, data ) {
		const body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', nonce );
		Object.keys( data || {} ).forEach( k => {
			if ( Array.isArray( data[ k ] ) ) {
				data[ k ].forEach( v => body.append( k + '[]', v ) );
			} else {
				body.append( k, data[ k ] );
			}
		} );
		return fetch( ajax, { method: 'POST', credentials: 'same-origin', body } )
			.then( r => r.json() );
	}

	document.getElementById( 'tags-per-page' )?.addEventListener( 'change', function () {
		this.form.submit();
	} );

	document.getElementById( 'jetonomy-save-tag' )?.addEventListener( 'click', function () {
		const name = document.getElementById( 'tag-name' ).value.trim();
		const slug = document.getElementById( 'tag-slug' ).value.trim();
		if ( ! name ) {
			window.alert( '<?php echo esc_js( __( 'Name is required.', 'jetonomy' ) ); ?>' );
			return;
		}
		post( 'jetonomy_create_tag', { name, slug } ).then( res => {
			if ( res.success ) { window.location.reload(); }
			else { window.alert( res.data && res.data.message ? res.data.message : res.data ); }
		} );
	} );

	const modal = document.getElementById( 'jetonomy-edit-tag-modal' );
	function openModal() { modal.style.display = ''; }
	function closeModal() { modal.style.display = 'none'; }

	document.querySelectorAll( '.jetonomy-edit-tag' ).forEach( a => {
		a.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			document.getElementById( 'edit-tag-id' ).value   = this.dataset.id;
			document.getElementById( 'edit-tag-name' ).value = this.dataset.name;
			document.getElementById( 'edit-tag-slug' ).value = this.dataset.slug;
			openModal();
		} );
	} );
	document.querySelectorAll( '.jetonomy-modal-close, #jetonomy-edit-tag-modal .jetonomy-modal__overlay' ).forEach( el => {
		el.addEventListener( 'click', closeModal );
	} );
	document.addEventListener( 'keydown', e => {
		if ( 'Escape' === e.key && 'none' !== modal.style.display ) { closeModal(); }
	} );

	document.getElementById( 'jetonomy-update-tag' )?.addEventListener( 'click', function () {
		const id   = document.getElementById( 'edit-tag-id' ).value;
		const name = document.getElementById( 'edit-tag-name' ).value.trim();
		const slug = document.getElementById( 'edit-tag-slug' ).value.trim();
		post( 'jetonomy_update_tag', { id, name, slug } ).then( res => {
			if ( res.success ) { window.location.reload(); }
			else { window.alert( res.data && res.data.message ? res.data.message : res.data ); }
		} );
	} );

	document.querySelectorAll( '.jetonomy-delete-tag' ).forEach( a => {
		a.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			const id    = this.dataset.id;
			const count = parseInt( this.dataset.count || '0', 10 );
			let msg     = '<?php echo esc_js( __( 'Delete this tag?', 'jetonomy' ) ); ?>';
			if ( count > 0 ) {
				msg = '<?php echo esc_js( __( 'This tag is attached to', 'jetonomy' ) ); ?> ' + count + ' <?php echo esc_js( __( 'posts. Delete it and detach from all posts?', 'jetonomy' ) ); ?>';
			}
			if ( ! window.confirm( msg ) ) { return; }
			post( 'jetonomy_delete_tag', { id, force: 1 } ).then( res => {
				if ( res.success ) { window.location.reload(); }
				else { window.alert( res.data && res.data.message ? res.data.message : res.data ); }
			} );
		} );
	} );

	document.getElementById( 'jetonomy-tags-cb-all' )?.addEventListener( 'change', function () {
		document.querySelectorAll( '.jetonomy-tag-cb' ).forEach( cb => { cb.checked = this.checked; } );
	} );
	document.getElementById( 'jetonomy-tags-bulk-apply' )?.addEventListener( 'click', function () {
		const action = document.getElementById( 'jetonomy-tags-bulk-action' ).value;
		if ( 'delete' !== action ) { return; }
		const ids = Array.from( document.querySelectorAll( '.jetonomy-tag-cb:checked' ) ).map( cb => cb.value );
		if ( ids.length === 0 ) {
			window.alert( '<?php echo esc_js( __( 'Select at least one tag.', 'jetonomy' ) ); ?>' );
			return;
		}
		if ( ! window.confirm( '<?php echo esc_js( __( 'Delete the selected tags?', 'jetonomy' ) ); ?>' ) ) { return; }
		post( 'jetonomy_bulk_delete_tags', { ids } ).then( res => {
			if ( res.success ) { window.location.reload(); }
			else { window.alert( res.data && res.data.message ? res.data.message : res.data ); }
		} );
	} );
} )();
</script>
