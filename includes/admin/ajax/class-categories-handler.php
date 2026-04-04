<?php
/**
 * Admin AJAX handler — categories.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;

class Categories_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_create_category', [ $this, 'ajax_create_category' ] );
		add_action( 'wp_ajax_jetonomy_update_category', [ $this, 'ajax_update_category' ] );
		add_action( 'wp_ajax_jetonomy_delete_category', [ $this, 'ajax_delete_category' ] );
		add_action( 'wp_ajax_jetonomy_reorder_categories', [ $this, 'ajax_reorder_categories' ] );
	}

	public function ajax_create_category(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
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
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
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
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid category ID.', 'jetonomy' ) );
		}

		// Check for spaces in this category
		$spaces = Space::list_by_category( $id );
		if ( ! empty( $spaces ) ) {
			wp_send_json_error( __( 'Cannot delete a category that contains spaces. Move or delete the spaces first.', 'jetonomy' ) );
		}

		// Check for child categories
		$children = Category::list_children( $id );
		if ( ! empty( $children ) ) {
			wp_send_json_error( __( 'Cannot delete a category that has sub-categories. Delete them first.', 'jetonomy' ) );
		}

		$result = Category::delete( $id );
		if ( ! $result ) {
			wp_send_json_error( __( 'Failed to delete category.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Category deleted.', 'jetonomy' ) ] );
	}

	public function ajax_reorder_categories(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$order = array_map( 'absint', wp_unslash( $_POST['order'] ?? [] ) );
		if ( ! is_array( $order ) ) {
			wp_send_json_error( __( 'Invalid order data.', 'jetonomy' ) );
		}

		foreach ( $order as $index => $cat_id ) {
			Category::update( absint( $cat_id ), [ 'sort_order' => absint( $index ) ] );
		}

		wp_send_json_success( [ 'message' => __( 'Order saved.', 'jetonomy' ) ] );
	}
}
