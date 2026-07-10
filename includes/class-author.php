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
	 * @param int         $author_id Real author user ID.
	 * @param object|null $object    The post/reply row (must expose ->is_anonymous
	 *                               to be maskable; null = always real identity).
	 * @return array{id:int,name:string,avatar:string,url:string}
	 */
	public static function for_display( int $author_id, ?object $object = null ): array {
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

		$user = $author_id > 0 ? get_userdata( $author_id ) : null;

		return array(
			'id'     => $author_id,
			'name'   => $user ? $user->display_name : '',
			'avatar' => Avatar::display_url( $author_id, 64 ),
			'url'    => $author_id > 0 ? get_profile_url( $author_id ) : '',
		);
	}
}
