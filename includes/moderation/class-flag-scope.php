<?php
/**
 * Flag scope resolver.
 *
 * Single source of truth for mapping a flag to the space that owns it.
 * Keeps this logic out of controllers, templates, and permission helpers
 * so every surface agrees on what "this flag belongs to" means.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Moderation;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

class Flag_Scope {

	/**
	 * Resolve the space ID a flag belongs to.
	 *
	 * Rules:
	 *   post  → jt_posts.space_id
	 *   reply → jt_replies.post_id → jt_posts.space_id
	 *   user  → null  (user reports have no space — admin jurisdiction only)
	 *
	 * @param object $flag Flag row (must have object_type + object_id).
	 * @return int|null Space ID or null for global-scoped flags / missing objects.
	 */
	public static function space_id( object $flag ): ?int {
		$type = (string) ( $flag->object_type ?? '' );
		$id   = (int) ( $flag->object_id ?? 0 );

		if ( ! $id ) {
			return null;
		}

		if ( 'post' === $type ) {
			$post = Post::find( $id );
			return $post ? (int) $post->space_id : null;
		}

		if ( 'reply' === $type ) {
			$reply = Reply::find( $id );
			if ( ! $reply ) {
				return null;
			}
			$post = Post::find( (int) $reply->post_id );
			return $post ? (int) $post->space_id : null;
		}

		return null;
	}

	/**
	 * Resolve the space ID for a post or reply object reference.
	 *
	 * Shared helper for callers that already have (type, id) — e.g. approve /
	 * spam / trash endpoints which operate on an object, not a flag.
	 *
	 * @param string $type 'post' or 'reply'.
	 * @param int    $id   Object row ID.
	 * @return int|null
	 */
	public static function space_id_for_object( string $type, int $id ): ?int {
		if ( $id <= 0 ) {
			return null;
		}

		if ( 'post' === $type ) {
			$post = Post::find( $id );
			return $post ? (int) $post->space_id : null;
		}

		if ( 'reply' === $type ) {
			$reply = Reply::find( $id );
			if ( ! $reply ) {
				return null;
			}
			$post = Post::find( (int) $reply->post_id );
			return $post ? (int) $post->space_id : null;
		}

		return null;
	}
}
