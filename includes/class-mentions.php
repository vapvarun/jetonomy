<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Mentions {

	/**
	 * Parse content for @username mentions.
	 * Returns content with mentions wrapped in profile links.
	 */
	public static function parse( string $content ): string {
		return preg_replace_callback(
			'/@([a-zA-Z0-9_\-\.]+)/',
			function ( $matches ) {
				$username = $matches[1];
				$user     = get_user_by( 'login', $username );
				if ( ! $user ) return $matches[0]; // Not a valid user, leave as-is

				$url = get_profile_url( $user->ID );
				return '<a href="' . esc_url( $url ) . '" class="jt-mention">@' . esc_html( $username ) . '</a>';
			},
			$content
		);
	}

	/**
	 * Extract mentioned user IDs from content.
	 */
	public static function extract_user_ids( string $content ): array {
		preg_match_all( '/@([a-zA-Z0-9_\-\.]+)/', $content, $matches );
		if ( empty( $matches[1] ) ) return [];

		$ids = [];
		foreach ( array_unique( $matches[1] ) as $username ) {
			$user = get_user_by( 'login', $username );
			if ( $user ) $ids[] = (int) $user->ID;
		}
		return $ids;
	}

	/**
	 * Notify mentioned users.
	 */
	public static function notify( array $user_ids, int $actor_id, string $object_type, int $object_id, string $context_title ): void {
		$actor      = get_userdata( $actor_id );
		$actor_name = $actor ? $actor->display_name : __( 'Someone', 'jetonomy' );

		foreach ( $user_ids as $uid ) {
			if ( $uid === $actor_id ) continue; // Don't notify yourself

			Models\Notification::create( [
				'user_id'     => $uid,
				'actor_id'    => $actor_id,
				'type'        => 'mention',
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'message'     => sprintf(
					/* translators: 1: actor display name, 2: post/reply title */
					__( '%1$s mentioned you in "%2$s"', 'jetonomy' ),
					$actor_name,
					mb_substr( $context_title, 0, 50 )
				),
				'created_at'  => now(),
			] );
		}
	}
}
