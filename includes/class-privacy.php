<?php
/**
 * Privacy and data export handler.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Privacy {

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', [ $this, 'register_exporters' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ $this, 'register_erasers' ] );
		add_action( 'delete_user', [ $this, 'on_user_delete' ] );
	}

	public function register_exporters( array $exporters ): array {
		$exporters['jetonomy-profile'] = [
			'exporter_friendly_name' => __( 'Jetonomy Profile', 'jetonomy' ),
			'callback'               => [ $this, 'export_profile' ],
		];
		$exporters['jetonomy-posts']   = [
			'exporter_friendly_name' => __( 'Jetonomy Posts', 'jetonomy' ),
			'callback'               => [ $this, 'export_posts' ],
		];
		$exporters['jetonomy-replies'] = [
			'exporter_friendly_name' => __( 'Jetonomy Replies', 'jetonomy' ),
			'callback'               => [ $this, 'export_replies' ],
		];
		return $exporters;
	}

	public function register_erasers( array $erasers ): array {
		$erasers['jetonomy'] = [
			'eraser_friendly_name' => __( 'Jetonomy Data', 'jetonomy' ),
			'callback'             => [ $this, 'erase_data' ],
		];
		return $erasers;
	}

	public function export_profile( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		global $wpdb;
		$profile = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . table( 'user_profiles' ) . ' WHERE user_id = %d',
				$user->ID
			)
		);

		$data = [];
		if ( $profile ) {
			$data[] = [
				'group_id'    => 'jetonomy-profile',
				'group_label' => __( 'Jetonomy Profile', 'jetonomy' ),
				'item_id'     => 'profile-' . $user->ID,
				'data'        => [
					[
						'name'  => __( 'Display Name', 'jetonomy' ),
						// jt_user_profiles has no display_name column — it lives
						// on wp_users. Reading from $profile here returned empty
						// for every export (Phase C audit, 1.4.0 fix).
						'value' => (string) $user->display_name,
					],
					[
						'name'  => __( 'Bio', 'jetonomy' ),
						'value' => $profile->bio ?? '',
					],
					[
						'name'  => __( 'Trust Level', 'jetonomy' ),
						'value' => (string) $profile->trust_level,
					],
					[
						'name'  => __( 'Reputation', 'jetonomy' ),
						'value' => (string) $profile->reputation,
					],
					[
						'name'  => __( 'Post Count', 'jetonomy' ),
						'value' => (string) $profile->post_count,
					],
					[
						'name'  => __( 'Reply Count', 'jetonomy' ),
						'value' => (string) $profile->reply_count,
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => true,
		];
	}

	public function export_posts( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		global $wpdb;
		$limit  = 100;
		$offset = ( $page - 1 ) * $limit;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, title, content, created_at FROM ' . table( 'posts' ) . ' WHERE author_id = %d ORDER BY id LIMIT %d OFFSET %d',
				$user->ID,
				$limit,
				$offset
			)
		);

		$data = [];
		foreach ( $posts as $post ) {
			$data[] = [
				'group_id'    => 'jetonomy-posts',
				'group_label' => __( 'Jetonomy Posts', 'jetonomy' ),
				'item_id'     => 'post-' . $post->id,
				'data'        => [
					[
						'name'  => __( 'Title', 'jetonomy' ),
						'value' => $post->title,
					],
					[
						'name'  => __( 'Content', 'jetonomy' ),
						'value' => $post->content,
					],
					[
						'name'  => __( 'Date', 'jetonomy' ),
						'value' => $post->created_at,
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => count( $posts ) < $limit,
		];
	}

	public function export_replies( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		global $wpdb;
		$limit  = 100;
		$offset = ( $page - 1 ) * $limit;

		$replies = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, content, created_at FROM ' . table( 'replies' ) . ' WHERE author_id = %d ORDER BY id LIMIT %d OFFSET %d',
				$user->ID,
				$limit,
				$offset
			)
		);

		$data = [];
		foreach ( $replies as $reply ) {
			$data[] = [
				'group_id'    => 'jetonomy-replies',
				'group_label' => __( 'Jetonomy Replies', 'jetonomy' ),
				'item_id'     => 'reply-' . $reply->id,
				'data'        => [
					[
						'name'  => __( 'Content', 'jetonomy' ),
						'value' => $reply->content,
					],
					[
						'name'  => __( 'Date', 'jetonomy' ),
						'value' => $reply->created_at,
					],
				],
			];
		}

		return [
			'data' => $data,
			'done' => count( $replies ) < $limit,
		];
	}

	public function erase_data( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => [],
				'done'           => true,
			];
		}

		global $wpdb;
		$uid     = $user->ID;
		$removed = 0;

		// Anonymize posts (don't delete — preserve community content)
		$removed += (int) $wpdb->update( table( 'posts' ), [ 'author_id' => 0 ], [ 'author_id' => $uid ] );
		$removed += (int) $wpdb->update( table( 'replies' ), [ 'author_id' => 0 ], [ 'author_id' => $uid ] );

		// Delete personal data
		$wpdb->delete( table( 'user_profiles' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'notifications' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'subscriptions' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'read_status' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'space_members' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'votes' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'user_interests' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'activity_log' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'restrictions' ), [ 'user_id' => $uid ] );
		$wpdb->delete( table( 'flags' ), [ 'reporter_id' => $uid ] );
		$wpdb->delete( table( 'join_requests' ), [ 'user_id' => $uid ] );

		$removed += 11; // Tables cleaned

		return [
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => [ __( 'Jetonomy: Posts and replies anonymized. Personal data deleted.', 'jetonomy' ) ],
			'done'           => true,
		];
	}

	/**
	 * Clean up when a WP user is deleted.
	 */
	public function on_user_delete( int $user_id ): void {
		global $wpdb;

		// Anonymize content
		$wpdb->update( table( 'posts' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );
		$wpdb->update( table( 'replies' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );

		// Delete user-specific data
		$tables = [
			'user_profiles',
			'notifications',
			'subscriptions',
			'read_status',
			'space_members',
			'votes',
			'user_interests',
			'activity_log',
			'restrictions',
			'join_requests',
		];

		foreach ( $tables as $t ) {
			$wpdb->delete( table( $t ), [ 'user_id' => $user_id ] );
		}

		$wpdb->delete( table( 'flags' ), [ 'reporter_id' => $user_id ] );
	}
}
