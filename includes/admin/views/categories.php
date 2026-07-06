<?php
/**
 * Admin categories management view.
 *
 * Variables seeded by Admin::render_categories() before include.
 *
 * @var object[] $all_categories
 *
 * @package Jetonomy
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Categories', 'jetonomy' ); ?></h1>

	<div class="jetonomy-categories-layout">

	<!-- Add New Category Form -->
	<div class="jt-settings-card" id="jetonomy-add-category-form">
		<div class="jt-settings-card__head">
			<h2 class="jt-settings-card__title"><?php esc_html_e( 'Add New Category', 'jetonomy' ); ?></h2>
		</div>
		<div class="jetonomy-form-grid">
			<div class="jetonomy-form-field">
				<label for="cat-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?> <span class="required">*</span></label>
				<input type="text" id="cat-name" class="regular-text" required>
			</div>
			<div class="jetonomy-form-field">
				<label for="cat-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label>
				<input type="text" id="cat-slug" class="regular-text" placeholder="<?php esc_attr_e( 'Auto-generated from name', 'jetonomy' ); ?>">
			</div>
			<div class="jetonomy-form-field jetonomy-form-field--wide">
				<label for="cat-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
				<textarea id="cat-description" rows="2" class="large-text"></textarea>
			</div>
			<div class="jetonomy-form-field">
				<label for="cat-parent"><?php esc_html_e( 'Parent Category', 'jetonomy' ); ?></label>
				<select id="cat-parent">
					<option value="0"><?php esc_html_e( '(None - Top Level)', 'jetonomy' ); ?></option>
					<?php foreach ( $all_categories as $cat ) : ?>
						<option value="<?php echo absint( $cat->id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="jetonomy-form-field">
				<label for="cat-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
				<select id="cat-visibility">
					<option value="public"><?php esc_html_e( 'Public', 'jetonomy' ); ?></option>
					<option value="private"><?php esc_html_e( 'Private', 'jetonomy' ); ?></option>
					<option value="hidden"><?php esc_html_e( 'Hidden', 'jetonomy' ); ?></option>
				</select>
			</div>
			<div class="jetonomy-form-field jetonomy-form-field--wide">
				<label><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
				<?php
				\Jetonomy\Template_Loader::partial(
					'icon-picker',
					array(
						'field_name'    => 'icon',
						'current_value' => 'folder',
						'id_prefix'     => 'jt-admin-new-cat-icon',
						'label'         => '',
					)
				);
				?>
			</div>
			<div class="jetonomy-form-field">
				<label for="cat-color"><?php esc_html_e( 'Color', 'jetonomy' ); ?></label>
				<input type="text" id="cat-color" class="jetonomy-color-picker" value="">
			</div>
		</div>
		<p>
			<button type="button" class="button button-primary" id="jetonomy-save-category"><?php esc_html_e( 'Add Category', 'jetonomy' ); ?></button>
			<span class="spinner"></span>
		</p>
	</div>

	<!-- Categories Table -->
	<div class="jt-content-table-wrap">

		<!-- Toolbar: search + per-page -->
		<form method="get" class="tablenav top" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="jetonomy-categories">

			<div class="alignleft actions">
				<label for="cats-per-page" class="screen-reader-text"><?php esc_html_e( 'Rows per page', 'jetonomy' ); ?></label>
				<select id="cats-per-page" name="per_page" onchange="this.form.submit()">
					<?php
					$current_per_page = isset( $per_page ) ? (int) $per_page : 20;
					foreach ( array( 20, 50, 100 ) as $pp ) :
						?>
						<option value="<?php echo (int) $pp; ?>" <?php selected( $current_per_page, $pp ); ?>>
							<?php /* translators: %d per-page count */ echo esc_html( sprintf( __( '%d per page', 'jetonomy' ), $pp ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>

			<p class="search-box">
				<label class="screen-reader-text" for="cats-search"><?php esc_html_e( 'Search categories', 'jetonomy' ); ?></label>
				<input type="search" id="cats-search" name="s" value="<?php echo esc_attr( isset( $search ) ? $search : '' ); ?>" placeholder="<?php esc_attr_e( 'Search categories…', 'jetonomy' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'jetonomy' ); ?></button>
			</p>
		</form>

		<table class="wp-list-table widefat fixed striped" id="jetonomy-categories-table">
		<thead>
			<tr>
				<th class="column-drag" style="width:30px;"></th>
				<th class="column-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?></th>
				<th class="column-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></th>
				<th class="column-spaces"><?php echo esc_html( \Jetonomy\space_label( true ) ); ?></th>
				<th class="column-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></th>
			</tr>
		</thead>
		<tbody id="jetonomy-categories-list">
			<?php if ( empty( $categories ) ) : ?>
				<?php if ( ! empty( $search ) ) : ?>
					<?php
					jetonomy_admin_empty_state(
						array(
							'colspan' => 5,
							'icon'    => 'search',
							'title'   => __( 'No categories match that search', 'jetonomy' ),
							'body'    => __( 'Try a different keyword or clear the search to see all categories.', 'jetonomy' ),
						)
					);
					?>
				<?php else : ?>
					<?php
					jetonomy_admin_empty_state(
						array(
							'colspan' => 5,
							'icon'    => 'category',
							'title'   => __( 'No categories yet', 'jetonomy' ),
							'body'    => __( 'Categories let you group related spaces. Create your first one using the form above.', 'jetonomy' ),
						)
					);
					?>
				<?php endif; ?>
			<?php else : ?>
				<?php foreach ( $categories as $cat ) : ?>
					<tr data-id="<?php echo absint( $cat->id ); ?>" class="jetonomy-category-row">
						<td class="column-drag"><span class="dashicons dashicons-menu jetonomy-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'jetonomy' ); ?>"></span></td>
						<td class="column-name">
							<strong><?php echo esc_html( $cat->name ); ?></strong>
							<?php if ( $cat->description ) : ?>
								<span class="description"><?php echo esc_html( wp_trim_words( $cat->description, 12 ) ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="#" class="jetonomy-edit-category" data-id="<?php echo absint( $cat->id ); ?>" data-name="<?php echo esc_attr( $cat->name ); ?>" data-slug="<?php echo esc_attr( $cat->slug ); ?>" data-description="<?php echo esc_attr( $cat->description ?? '' ); ?>" data-parent="<?php echo absint( $cat->parent_id ); ?>" data-icon="<?php echo esc_attr( $cat->icon ?? '' ); ?>" data-color="<?php echo esc_attr( $cat->color ?? '' ); ?>" data-visibility="<?php echo esc_attr( $cat->visibility ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
								<span class="view"><a href="<?php echo esc_url( \Jetonomy\base_url() . '/category/' . $cat->slug . '/' ); ?>" target="_blank"><?php esc_html_e( 'View', 'jetonomy' ); ?></a> | </span>
								<span class="delete"><a href="#" class="jetonomy-delete-category" data-id="<?php echo absint( $cat->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
							</div>
						</td>
						<td class="column-slug"><code><?php echo esc_html( wp_parse_url( \Jetonomy\base_url(), PHP_URL_PATH ) . '/category/' . $cat->slug . '/' ); ?></code></td>
						<td class="column-spaces"><?php echo absint( $cat->space_count ); ?></td>
						<td class="column-visibility">
							<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $cat->visibility ); ?>"><?php echo esc_html( ucfirst( $cat->visibility ) ); ?></span>
						</td>
					</tr>
					<?php
					if ( ! empty( $cat->children ) ) :
						foreach ( $cat->children as $child ) :
							?>
						<tr data-id="<?php echo absint( $child->id ); ?>" class="jetonomy-category-row jetonomy-category-child">
							<td class="column-drag"><span class="dashicons dashicons-menu jetonomy-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'jetonomy' ); ?>"></span></td>
							<td class="column-name">
								<span class="jetonomy-child-indent"></span>
								<strong><?php echo esc_html( $child->name ); ?></strong>
								<div class="row-actions">
									<span class="edit"><a href="#" class="jetonomy-edit-category" data-id="<?php echo absint( $child->id ); ?>" data-name="<?php echo esc_attr( $child->name ); ?>" data-slug="<?php echo esc_attr( $child->slug ); ?>" data-description="<?php echo esc_attr( $child->description ?? '' ); ?>" data-parent="<?php echo absint( $child->parent_id ); ?>" data-icon="<?php echo esc_attr( $child->icon ?? '' ); ?>" data-color="<?php echo esc_attr( $child->color ?? '' ); ?>" data-visibility="<?php echo esc_attr( $child->visibility ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
									<span class="view"><a href="<?php echo esc_url( \Jetonomy\base_url() . '/category/' . $child->slug . '/' ); ?>" target="_blank"><?php esc_html_e( 'View', 'jetonomy' ); ?></a> | </span>
									<span class="delete"><a href="#" class="jetonomy-delete-category" data-id="<?php echo absint( $child->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
								</div>
							</td>
							<td class="column-slug"><code><?php echo esc_html( wp_parse_url( \Jetonomy\base_url(), PHP_URL_PATH ) . '/category/' . $child->slug . '/' ); ?></code></td>
							<td class="column-spaces"><?php echo absint( $child->space_count ); ?></td>
							<td class="column-visibility">
								<span class="jt-status-badge jt-status-badge--<?php echo esc_attr( $child->visibility ); ?>"><?php echo esc_html( ucfirst( $child->visibility ) ); ?></span>
							</td>
						</tr>
											<?php
					endforeach;
endif;
					?>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

	<?php
	$_cats_pages = isset( $categories_pages ) ? (int) $categories_pages : 0;
	$_cats_total = isset( $categories_total ) ? (int) $categories_total : 0;
	if ( $_cats_pages > 1 ) :
		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %d: total items */
						esc_html( _n( '%d item', '%d items', $_cats_total, 'jetonomy' ) ),
						$_cats_total
					);
					?>
				</span>
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg(
								array(
									'page'     => 'jetonomy-categories',
									's'        => isset( $search ) ? $search : '',
									'orderby'  => isset( $orderby ) ? $orderby : '',
									'order'    => isset( $order ) ? $order : '',
									'per_page' => isset( $per_page ) ? $per_page : 20,
									'paged'    => '%#%',
								),
								admin_url( 'admin.php' )
							),
							'format'    => '',
							'current'   => max( 1, isset( $paged ) ? (int) $paged : 1 ),
							'total'     => $_cats_pages,
							'prev_text' => '&lsaquo;',
							'next_text' => '&rsaquo;',
							'end_size'  => 2,
							'mid_size'  => 2,
							'type'      => 'plain',
						)
					)
				);
				?>
			</div>
		</div>
	<?php endif; ?>
	</div><!-- /.jt-content-table-wrap -->

	</div><!-- /.jetonomy-categories-layout -->

	<!-- Edit Category Modal -->
	<div class="jetonomy-modal" id="jetonomy-edit-category-modal" style="display:none;">
		<div class="jetonomy-modal__overlay"></div>
		<div class="jetonomy-modal__content">
			<h2><?php esc_html_e( 'Edit Category', 'jetonomy' ); ?></h2>
			<input type="hidden" id="edit-cat-id">
			<div class="jetonomy-form-grid">
				<div class="jetonomy-form-field">
					<label for="edit-cat-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?> <span class="required">*</span></label>
					<input type="text" id="edit-cat-name" class="regular-text" required>
				</div>
				<div class="jetonomy-form-field">
					<label for="edit-cat-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></label>
					<input type="text" id="edit-cat-slug" class="regular-text">
				</div>
				<div class="jetonomy-form-field jetonomy-form-field--wide">
					<label for="edit-cat-description"><?php esc_html_e( 'Description', 'jetonomy' ); ?></label>
					<textarea id="edit-cat-description" rows="2" class="large-text"></textarea>
				</div>
				<div class="jetonomy-form-field">
					<label for="edit-cat-parent"><?php esc_html_e( 'Parent Category', 'jetonomy' ); ?></label>
					<select id="edit-cat-parent">
						<option value="0"><?php esc_html_e( '(None - Top Level)', 'jetonomy' ); ?></option>
						<?php foreach ( $all_categories as $cat ) : ?>
							<option value="<?php echo absint( $cat->id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="jetonomy-form-field">
					<label for="edit-cat-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></label>
					<select id="edit-cat-visibility">
						<option value="public"><?php esc_html_e( 'Public', 'jetonomy' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'jetonomy' ); ?></option>
						<option value="hidden"><?php esc_html_e( 'Hidden', 'jetonomy' ); ?></option>
					</select>
				</div>
				<div class="jetonomy-form-field jetonomy-form-field--wide">
					<label><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
					<?php
					\Jetonomy\Template_Loader::partial(
						'icon-picker',
						array(
							'field_name'    => 'icon',
							'current_value' => 'folder',
							'id_prefix'     => 'jt-admin-edit-cat-icon',
							'label'         => '',
						)
					);
					?>
				</div>
				<div class="jetonomy-form-field">
					<label for="edit-cat-color"><?php esc_html_e( 'Color', 'jetonomy' ); ?></label>
					<input type="text" id="edit-cat-color" class="jetonomy-color-picker" value="">
				</div>
			</div>
			<p class="jetonomy-modal__actions">
				<button type="button" class="button button-primary" id="jetonomy-update-category"><?php esc_html_e( 'Update Category', 'jetonomy' ); ?></button>
				<button type="button" class="button jetonomy-modal-close"><?php esc_html_e( 'Cancel', 'jetonomy' ); ?></button>
				<span class="spinner"></span>
			</p>
		</div>
	</div>
</div>
