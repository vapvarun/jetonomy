<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="wrap jetonomy-admin">
	<h1><?php esc_html_e( 'Categories', 'jetonomy' ); ?></h1>

	<!-- Add New Category Form -->
	<div class="jetonomy-inline-form" id="jetonomy-add-category-form">
		<h2><?php esc_html_e( 'Add New Category', 'jetonomy' ); ?></h2>
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
			<div class="jetonomy-form-field">
				<label for="cat-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
				<input type="text" id="cat-icon" class="regular-text" placeholder="dashicons-category">
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
	<table class="wp-list-table widefat fixed striped" id="jetonomy-categories-table">
		<thead>
			<tr>
				<th class="column-drag" style="width:30px;"></th>
				<th class="column-name"><?php esc_html_e( 'Name', 'jetonomy' ); ?></th>
				<th class="column-slug"><?php esc_html_e( 'Slug', 'jetonomy' ); ?></th>
				<th class="column-spaces"><?php esc_html_e( 'Spaces', 'jetonomy' ); ?></th>
				<th class="column-visibility"><?php esc_html_e( 'Visibility', 'jetonomy' ); ?></th>
				<th class="column-order"><?php esc_html_e( 'Order', 'jetonomy' ); ?></th>
			</tr>
		</thead>
		<tbody id="jetonomy-categories-list">
			<?php if ( empty( $all_categories ) ) : ?>
				<tr class="jetonomy-no-items"><td colspan="6"><?php esc_html_e( 'No categories yet. Create your first one above.', 'jetonomy' ); ?></td></tr>
			<?php else : ?>
				<?php foreach ( $all_categories as $cat ) : ?>
					<tr data-id="<?php echo absint( $cat->id ); ?>" class="jetonomy-category-row">
						<td class="column-drag"><span class="dashicons dashicons-menu jetonomy-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'jetonomy' ); ?>"></span></td>
						<td class="column-name">
							<strong><?php echo esc_html( $cat->name ); ?></strong>
							<?php if ( $cat->description ) : ?>
								<br><span class="description"><?php echo esc_html( wp_trim_words( $cat->description, 10 ) ); ?></span>
							<?php endif; ?>
							<div class="row-actions">
								<span class="edit"><a href="#" class="jetonomy-edit-category" data-id="<?php echo absint( $cat->id ); ?>" data-name="<?php echo esc_attr( $cat->name ); ?>" data-slug="<?php echo esc_attr( $cat->slug ); ?>" data-description="<?php echo esc_attr( $cat->description ?? '' ); ?>" data-parent="<?php echo absint( $cat->parent_id ); ?>" data-icon="<?php echo esc_attr( $cat->icon ?? '' ); ?>" data-color="<?php echo esc_attr( $cat->color ?? '' ); ?>" data-visibility="<?php echo esc_attr( $cat->visibility ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
								<span class="delete"><a href="#" class="jetonomy-delete-category" data-id="<?php echo absint( $cat->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
							</div>
						</td>
						<td class="column-slug"><code><?php echo esc_html( $cat->slug ); ?></code></td>
						<td class="column-spaces"><?php echo absint( $cat->space_count ); ?></td>
						<td class="column-visibility">
							<span class="jetonomy-badge jetonomy-badge--<?php echo esc_attr( $cat->visibility ); ?>"><?php echo esc_html( ucfirst( $cat->visibility ) ); ?></span>
						</td>
						<td class="column-order"><?php echo absint( $cat->sort_order ); ?></td>
					</tr>
					<?php if ( ! empty( $cat->children ) ) : foreach ( $cat->children as $child ) : ?>
						<tr data-id="<?php echo absint( $child->id ); ?>" class="jetonomy-category-row jetonomy-category-child">
							<td class="column-drag"><span class="dashicons dashicons-menu jetonomy-drag-handle" title="<?php esc_attr_e( 'Drag to reorder', 'jetonomy' ); ?>"></span></td>
							<td class="column-name">
								<span class="jetonomy-child-indent"></span>
								<strong><?php echo esc_html( $child->name ); ?></strong>
								<div class="row-actions">
									<span class="edit"><a href="#" class="jetonomy-edit-category" data-id="<?php echo absint( $child->id ); ?>" data-name="<?php echo esc_attr( $child->name ); ?>" data-slug="<?php echo esc_attr( $child->slug ); ?>" data-description="<?php echo esc_attr( $child->description ?? '' ); ?>" data-parent="<?php echo absint( $child->parent_id ); ?>" data-icon="<?php echo esc_attr( $child->icon ?? '' ); ?>" data-color="<?php echo esc_attr( $child->color ?? '' ); ?>" data-visibility="<?php echo esc_attr( $child->visibility ); ?>"><?php esc_html_e( 'Edit', 'jetonomy' ); ?></a> | </span>
									<span class="delete"><a href="#" class="jetonomy-delete-category" data-id="<?php echo absint( $child->id ); ?>"><?php esc_html_e( 'Delete', 'jetonomy' ); ?></a></span>
								</div>
							</td>
							<td class="column-slug"><code><?php echo esc_html( $child->slug ); ?></code></td>
							<td class="column-spaces"><?php echo absint( $child->space_count ); ?></td>
							<td class="column-visibility">
								<span class="jetonomy-badge jetonomy-badge--<?php echo esc_attr( $child->visibility ); ?>"><?php echo esc_html( ucfirst( $child->visibility ) ); ?></span>
							</td>
							<td class="column-order"><?php echo absint( $child->sort_order ); ?></td>
						</tr>
					<?php endforeach; endif; ?>
				<?php endforeach; ?>
			<?php endif; ?>
		</tbody>
	</table>

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
				<div class="jetonomy-form-field">
					<label for="edit-cat-icon"><?php esc_html_e( 'Icon', 'jetonomy' ); ?></label>
					<input type="text" id="edit-cat-icon" class="regular-text">
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
