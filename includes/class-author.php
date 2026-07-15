<?php
/**
 * Author display resolver.
 *
 * The single seam every author-render surface routes through so an anonymous
 * post/reply masks its real author. The real author_id is always stored on the
 * row; this only controls what is *shown*.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Author {

	/**
	 * Resolve the identity to display for an author.
	 *
	 * When $object carries a truthy `is_anonymous` flag and no reveal filter
	 * grants access to the current viewer, the generic anonymous identity is
	 * returned (id 0, "Anonymous", no avatar URL, no profile URL) so the caller
	 * renders the silhouette + "Anonymous". In every other case the real
	 * author's identity is returned, with the avatar routed through
	 * Avatar::display_url() (no forked avatar logic).
	 *
	 * @param int               $author_id      Real author user ID.
	 * @param object|null       $object    The post/reply row (must expose
	 *                                     ->is_anonymous to be maskable; null =
	 *                                     always real identity).
	 * @param object|null|false $preloaded_user Optional. Skips the internal
	 *                               get_userdata() call when the caller already
	 *                               has the wp_users row batch-loaded (e.g.
	 *                               Base_Controller::enrich_with_author()
	 *                               iterating a list) — pass the row, or `null`
	 *                               if the batch lookup came back empty. Leave
	 *                               unset (`false`, the default) to fetch fresh,
	 *                               same as before.
	 * @return array{id:int,name:string,avatar:string,url:string}
	 */
	public static function for_display( int $author_id, ?object $object = null, $preloaded_user = false ): array {
		if ( $object && ! empty( $object->is_anonymous ) ) {
			/**
			 * Whether the current viewer may reveal an anonymous author.
			 *
			 * Free ships this as always-false — anonymous stays masked for
			 * everyone. The Pro anonymous-posting extension hooks it to grant
			 * an explicit-reveal context to site admins only.
			 *
			 * @param bool        $can_reveal Default false.
			 * @param object|null $object     The post/reply row being rendered.
			 * @param int         $viewer_id  Current user ID.
			 */
			$can_reveal = (bool) apply_filters( 'jetonomy_author_can_reveal', false, $object, get_current_user_id() );

			if ( ! $can_reveal ) {
				return array(
					'id'     => 0,
					'name'   => __( 'Anonymous', 'jetonomy' ),
					'avatar' => '',
					'url'    => '',
				);
			}
		}

		// author_id 0 on a row that is NOT anonymous-masked (the branch above
		// already returned if it were) means the account behind this content
		// was deleted — Privacy::on_user_delete() / erase_data() reassign
		// authored posts/replies/revisions to author_id = 0 as a tombstone
		// rather than deleting the row (see class-privacy.php ANON_TABLES).
		// Render it distinctly from a deliberately-anonymous Pro post ("[deleted]"
		// vs "Anonymous") and never as a blank name.
		if ( 0 === $author_id ) {
			return array(
				'id'     => 0,
				'name'   => __( '[deleted]', 'jetonomy' ),
				'avatar' => '',
				'url'    => '',
			);
		}

		$user = ( false === $preloaded_user ) ? get_userdata( $author_id ) : $preloaded_user;

		return array(
			'id'     => $author_id,
			'name'   => $user ? $user->display_name : '',
			'avatar' => Avatar::display_url( $author_id, 64 ),
			'url'    => get_profile_url( $author_id ),
		);
	}
}
