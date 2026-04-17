<?php
/**
 * Admin AJAX handler — tags.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Tag;

class Tags_Handler {

	public function __construct() {
		add_action( 'wp_ajax_jetonomy_create_tag', [ $this, 'ajax_create_tag' ] );
		add_action( 'wp_ajax_jetonomy_update_tag', [ $this, 'ajax_update_tag' ] );
		add_action( 'wp_ajax_jetonomy_delete_tag', [ $this, 'ajax_delete_tag' ] );
		add_action( 'wp_ajax_jetonomy_bulk_delete_tags', [ $this, 'ajax_bulk_delete_tags' ] );
	}

	public function ajax_create_tag(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$slug = sanitize_title( wp_unslash( $_POST['slug'] ?? $name ) );

		if ( '' === $name ) {
			wp_send_json_error( __( 'Name is required.', 'jetonomy' ) );
		}
		if ( '' === $slug ) {
			$slug = sanitize_title( $name );
		}
		if ( Tag::exists( $slug ) ) {
			wp_send_json_error( __( 'A tag with that slug already exists.', 'jetonomy' ) );
		}

		$id = Tag::insert(
			[
				'name' => $name,
				'slug' => $slug,
			]
		);
		if ( ! $id ) {
			wp_send_json_error( __( 'Failed to create tag.', 'jetonomy' ) );
		}

		wp_send_json_success(
			[
				'id'      => $id,
				'tag'     => Tag::find( $id ),
				'message' => __( 'Tag created.', 'jetonomy' ),
			]
		);
	}

	public function ajax_update_tag(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid tag ID.', 'jetonomy' ) );
		}
		if ( ! Tag::find( $id ) ) {
			wp_send_json_error( __( 'Tag not found.', 'jetonomy' ) );
		}

		$data = [];
		if ( isset( $_POST['name'] ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['name'] ) );
			if ( '' === $name ) {
				wp_send_json_error( __( 'Name cannot be empty.', 'jetonomy' ) );
			}
			$data['name'] = $name;
		}
		if ( isset( $_POST['slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_POST['slug'] ) );
			if ( '' === $slug ) {
				wp_send_json_error( __( 'Slug cannot be empty.', 'jetonomy' ) );
			}
			$existing = Tag::find_by_slug( $slug );
			if ( $existing && (int) $existing->id !== $id ) {
				wp_send_json_error( __( 'Another tag already uses that slug.', 'jetonomy' ) );
			}
			$data['slug'] = $slug;
		}

		if ( empty( $data ) ) {
			wp_send_json_error( __( 'No data to update.', 'jetonomy' ) );
		}

		$ok = Tag::update( $id, $data );
		if ( ! $ok ) {
			wp_send_json_error( __( 'Failed to update tag.', 'jetonomy' ) );
		}

		wp_send_json_success(
			[
				'tag'     => Tag::find( $id ),
				'message' => __( 'Tag updated.', 'jetonomy' ),
			]
		);
	}

	public function ajax_delete_tag(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$id = absint( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( __( 'Invalid tag ID.', 'jetonomy' ) );
		}

		$tag = Tag::find( $id );
		if ( ! $tag ) {
			wp_send_json_error( __( 'Tag not found.', 'jetonomy' ) );
		}

		// Allow force delete even when posts are attached — the admin has
		// confirmed in the UI. The detach is part of the transaction so the
		// post_count denorms on other tags remain consistent.
		$force = ! empty( $_POST['force'] );
		if ( (int) ( $tag->post_count ?? 0 ) > 0 && ! $force ) {
			wp_send_json_error(
				[
					'message'    => __( 'This tag is still attached to posts.', 'jetonomy' ),
					'post_count' => (int) $tag->post_count,
					'needs_confirm' => true,
				]
			);
		}

		$ok = Tag::delete_with_relations( $id );
		if ( ! $ok ) {
			wp_send_json_error( __( 'Failed to delete tag.', 'jetonomy' ) );
		}

		wp_send_json_success( [ 'message' => __( 'Tag deleted.', 'jetonomy' ) ] );
	}

	public function ajax_bulk_delete_tags(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$raw = wp_unslash( $_POST['ids'] ?? [] );
		$ids = is_array( $raw ) ? array_map( 'absint', $raw ) : [];
		$ids = array_values( array_filter( $ids ) );
		if ( empty( $ids ) ) {
			wp_send_json_error( __( 'No tags selected.', 'jetonomy' ) );
		}

		$deleted = 0;
		foreach ( $ids as $id ) {
			if ( Tag::delete_with_relations( $id ) ) {
				++$deleted;
			}
		}

		wp_send_json_success(
			[
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of deleted tags */
					_n( '%d tag deleted.', '%d tags deleted.', $deleted, 'jetonomy' ),
					$deleted
				),
			]
		);
	}
}
