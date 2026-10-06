<?php
/**
 * Admin AJAX handler — categories.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;

class Categories_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_create_category', [ $this, 'ajax_create_category' ] );
		add_action( 'wp_ajax_jetonomy_update_category', [ $this, 'ajax_update_category' ] );
		add_action( 'wp_ajax_jetonomy_delete_category', [ $this, 'ajax_delete_category' ] );
		add_action( 'wp_ajax_jetonomy_reorder_categories', [ $this, 'ajax_reorder_categories' ] );
	}

	public function ajax_create_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_categories' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$name       = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug       = sanitize_title( wp_unslash( $_POST['slug'] ?? $name ) );
		$desc       = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
		$parent_id  = absint( $_POST['parent_id'] ?? 0 );
		$icon       = sanitize_text_field( wp_unslash( $_POST['icon'] ?? '' ) );
		$color      = sanitize_hex_color( $_POST['color'] ?? '' );
		$visibility = sanitize_text_field( wp_unslash( $_POST['visibility'] ?? 'public' ) );

		if ( empty( $name ) ) {
			wp_send_json_error( __( 'Name is required.', 'jetonomy' ) );
		}

		if ( ! in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
			$visibility = 'public';
		}

		$parent_error = Category::parent_error( 0, $parent_id );
		if ( $parent_error ) {
			wp_send_json_error( $parent_error->get_error_message() );
		}

		$id = Category::create(
			[
				'name'        => $name,
				'slug'        => $slug,
				'description' => $desc,
				'parent_id'   => $parent_id,
				'icon'        => $icon ?: null,
				'color'       => $color ?: null,
				'visibility'  => $visibility,
			]
		);

		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create category.', 'jetonomy' ) );
		}

		$category = Category::find( $id );
		wp_send_json_success(
			[
				'id'       => $id,
				'category' => $category,
				'message'  => __( 'Category created.', 'jetonomy' ),
			]
		);
	}

	public function ajax_update_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_categories' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid category ID.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['name'] ) ) {
			$data['name'] = sanitize_text_field( wp_unslash( $_POST['name'] ) );
		}
		if ( isset( $_POST['slug'] ) ) {
			$data['slug'] = sanitize_title( wp_unslash( $_POST['slug'] ) );
		}
		if ( isset( $_POST['description'] ) ) {
			$data['description'] = wp_kses_post( wp_unslash( $_POST['description'] ) );
		}
		if ( isset( $_POST['parent_id'] ) ) {
			$data['parent_id'] = absint( $_POST['parent_id'] );
		}
		if ( isset( $_POST['icon'] ) ) {
			$data['icon'] = sanitize_text_field( wp_unslash( $_POST['icon'] ) ) ?: null;
		}
		if ( isset( $_POST['color'] ) ) {
			$data['color'] = sanitize_hex_color( $_POST['color'] ) ?: null;
		}
		if ( isset( $_POST['visibility'] ) ) {
			$visibility = sanitize_text_field( wp_unslash( $_POST['visibility'] ) );
			if ( in_array( $visibility, [ 'public', 'private', 'hidden' ], true ) ) {
				$data['visibility'] = $visibility;
			}
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'No data to update.', 'jetonomy' ) );
		}

		$result = Category::update( $id, $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to update category.', 'jetonomy' ) );
		}

		$category = Category::find( $id );
		wp_send_json_success(
			[
				'category' => $category,
				'message'  => __( 'Category updated.', 'jetonomy' ),
			]
		);
	}

	public function ajax_delete_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_categories' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid category ID.', 'jetonomy' ) );
		}

		// Category::delete() refuses while spaces or sub-categories remain.
		$result = Category::delete( $id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete category.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Category deleted.', 'jetonomy' ) ] );
	}

	public function ajax_reorder_categories(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_categories' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$order = array_map( 'absint', (array) wp_unslash( $_POST['order'] ?? [] ) );
		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order data.', 'jetonomy' ) );
		}

		// A batch reorders ONE sibling group, and the server decides which ids
		// belong to it rather than trusting the batch: the top-level
		// categories (parent_id 0), or the sub-categories of one parent.
		// Positions are only ever compared within a sibling group
		// (list_top_level() / list_children() order by sort_order), so mixing
		// groups would write meaningless numbers. A stale cached admin script
		// that still sends children in a top-level batch therefore cannot
		// corrupt the order - they are simply dropped (Basecamp 10210539659).
		$parent_id = absint( $_POST['parent_id'] ?? 0 );
		$siblings  = $parent_id
			? Category::list_children( $parent_id, get_current_user_id() )
			: Category::list_top_level( get_current_user_id() );
		$order     = array_values( array_intersect( $order, array_map( 'intval', array_column( $siblings, 'id' ) ) ) );

		if ( ! $order ) {
			wp_send_json_error( __( 'Invalid order data.', 'jetonomy' ) );
		}

		// Absolute positions, never the batch index. The browser only submits
		// the rows it rendered, so on page 2 the index restarts at 0 and would
		// renumber those rows over the top of page 1 (Basecamp 10210539659).
		// Sub-categories always render in full under their parent, so their
		// batch is the whole group and starts at 0.
		$offset = $parent_id ? 0 : jetonomy_reorder_offset(
			absint( $_POST['paged'] ?? 1 ),
			absint( $_POST['per_page'] ?? 20 )
		);

		jetonomy_apply_manual_order(
			$order,
			$offset,
			static function ( int $cat_id, int $position ): void {
				Category::update( $cat_id, [ 'sort_order' => $position ] );
			}
		);

		wp_send_json_success( [ 'message' => __( 'Order saved.', 'jetonomy' ) ] );
	}
}
