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
		// Personal data that is erased but was previously not exported (GDPR
		// export completeness). All paginated via the shared export_table() helper.
		$exporters['jetonomy-bookmarks']     = [
			'exporter_friendly_name' => __( 'Jetonomy Bookmarks', 'jetonomy' ),
			'callback'               => [ $this, 'export_bookmarks' ],
		];
		$exporters['jetonomy-votes']         = [
			'exporter_friendly_name' => __( 'Jetonomy Votes', 'jetonomy' ),
			'callback'               => [ $this, 'export_votes' ],
		];
		$exporters['jetonomy-subscriptions'] = [
			'exporter_friendly_name' => __( 'Jetonomy Subscriptions', 'jetonomy' ),
			'callback'               => [ $this, 'export_subscriptions' ],
		];
		$exporters['jetonomy-notifications'] = [
			'exporter_friendly_name' => __( 'Jetonomy Notifications', 'jetonomy' ),
			'callback'               => [ $this, 'export_notifications' ],
		];
		$exporters['jetonomy-activity']      = [
			'exporter_friendly_name' => __( 'Jetonomy Activity Log', 'jetonomy' ),
			'callback'               => [ $this, 'export_activity' ],
		];
		return $exporters;
	}

	/**
	 * Shared paginated exporter. WP re-invokes with an incrementing $page until
	 * done=true, so a heavy user exports in bounded chunks (no giant query).
	 *
	 * @param string                $email     Subject email.
	 * @param int                   $page      1-based page.
	 * @param string                $group_id  WP exporter group id.
	 * @param string                $label     Group label.
	 * @param string                $table_key Model table key.
	 * @param string                $user_col  User-id column on the table.
	 * @param array<string, string> $fields    column => human label.
	 * @return array{data: array, done: bool}
	 */
	private function export_table( string $email, int $page, string $group_id, string $label, string $table_key, string $user_col, array $fields, string $key_col = 'id' ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [
				'data' => [],
				'done' => true,
			];
		}

		global $wpdb;
		$limit    = 100;
		$offset   = ( max( 1, $page ) - 1 ) * $limit;
		$key_col  = sanitize_key( $key_col );
		$user_col = sanitize_key( $user_col );
		// $key_col drives pagination + the item id ('id' for most tables; some
		// like jt_bookmarks have a composite PK and no single id column).
		$cols = implode( ', ', array_unique( array_map( 'sanitize_key', array_merge( [ $key_col ], array_keys( $fields ) ) ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . $cols . ' FROM ' . table( $table_key ) . ' WHERE ' . $user_col . ' = %d ORDER BY ' . $key_col . ' ASC LIMIT %d OFFSET %d',
				$user->ID,
				$limit,
				$offset
			)
		);

		$data = [];
		foreach ( (array) $rows as $row ) {
			$items = [];
			foreach ( $fields as $col => $lbl ) {
				$items[] = [
					'name'  => $lbl,
					'value' => (string) ( $row->$col ?? '' ),
				];
			}
			$data[] = [
				'group_id'    => $group_id,
				'group_label' => $label,
				'item_id'     => $group_id . '-' . (string) ( $row->$key_col ?? '' ),
				'data'        => $items,
			];
		}

		return [
			'data' => $data,
			'done' => count( $rows ) < $limit,
		];
	}

	public function export_bookmarks( string $email, int $page = 1 ): array {
		return $this->export_table( $email, $page, 'jetonomy-bookmarks', __( 'Jetonomy Bookmarks', 'jetonomy' ), 'bookmarks', 'user_id', [
			'post_id'    => __( 'Post ID', 'jetonomy' ),
			'created_at' => __( 'Bookmarked At', 'jetonomy' ),
		], 'post_id' );
	}

	public function export_votes( string $email, int $page = 1 ): array {
		return $this->export_table( $email, $page, 'jetonomy-votes', __( 'Jetonomy Votes', 'jetonomy' ), 'votes', 'user_id', [
			'object_id'  => __( 'On (object ID)', 'jetonomy' ),
			'value'      => __( 'Vote', 'jetonomy' ),
			'created_at' => __( 'Voted At', 'jetonomy' ),
		] );
	}

	public function export_subscriptions( string $email, int $page = 1 ): array {
		return $this->export_table( $email, $page, 'jetonomy-subscriptions', __( 'Jetonomy Subscriptions', 'jetonomy' ), 'subscriptions', 'user_id', [
			'object_id'  => __( 'Subscribed To (object ID)', 'jetonomy' ),
			'created_at' => __( 'Since', 'jetonomy' ),
		] );
	}

	public function export_notifications( string $email, int $page = 1 ): array {
		return $this->export_table( $email, $page, 'jetonomy-notifications', __( 'Jetonomy Notifications', 'jetonomy' ), 'notifications', 'user_id', [
			'type'       => __( 'Type', 'jetonomy' ),
			'message'    => __( 'Message', 'jetonomy' ),
			'created_at' => __( 'Received', 'jetonomy' ),
		] );
	}

	public function export_activity( string $email, int $page = 1 ): array {
		return $this->export_table( $email, $page, 'jetonomy-activity', __( 'Jetonomy Activity Log', 'jetonomy' ), 'activity_log', 'user_id', [
			'action'      => __( 'Action', 'jetonomy' ),
			'object_type' => __( 'Object Type', 'jetonomy' ),
			'object_id'   => __( 'Object ID', 'jetonomy' ),
			'created_at'  => __( 'At', 'jetonomy' ),
		] );
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

		$uid       = $user->ID;
		$batch     = max( 1, (int) apply_filters( 'jetonomy_erase_batch_size', 1000 ) );
		$state_key = 'jetonomy_erase_' . $uid;
		$work      = 0; // Rows touched (anonymized or deleted) this page.
		$removed   = 0;

		// Page 1: capture the recompute targets BEFORE any vote / membership row
		// is deleted, and stash them for the final page (the deletes below drain
		// those tables over several pages, so "capture then recompute" can no
		// longer happen in a single call).
		if ( 1 === $page ) {
			set_transient(
				$state_key,
				[
					'votes'  => $this->collect_vote_objects( $uid ),
					'spaces' => $this->collect_member_spaces( $uid ),
				],
				HOUR_IN_SECONDS
			);
		}

		// Anonymize authored content (kept — preserves community threads and the
		// denormalized counters since the rows still exist), bounded per table.
		foreach ( [ 'posts', 'replies', 'revisions', 'spaces' ] as $authored ) {
			$work += $this->batch_anonymize_author( $authored, $uid, $batch );
		}

		// Delete personal data, bounded per table.
		$purge = [
			[ 'user_profiles', 'user_id' ],
			[ 'notifications', 'user_id' ],
			[ 'subscriptions', 'user_id' ],
			[ 'read_status', 'user_id' ],
			[ 'space_members', 'user_id' ],
			[ 'votes', 'user_id' ],
			[ 'activity_log', 'user_id' ],
			[ 'restrictions', 'user_id' ],
			[ 'flags', 'reporter_id' ],
			[ 'join_requests', 'user_id' ],
			[ 'bookmarks', 'user_id' ],
		];
		foreach ( $purge as [ $table_key, $user_col ] ) {
			$n        = $this->batch_delete( $table_key, $user_col, $uid, $batch );
			$work    += $n;
			$removed += $n;
		}

		// Drained once a full pass touched nothing. Recompute the denormalized
		// counters exactly once, on that final page, from the page-1 capture.
		$done = ( 0 === $work );
		if ( $done ) {
			$state = get_transient( $state_key );
			if ( is_array( $state ) ) {
				$this->recompute_counters_after_purge(
					(array) ( $state['votes'] ?? [] ),
					(array) ( $state['spaces'] ?? [] )
				);
			}
			delete_transient( $state_key );
		}

		return [
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => $done ? [ __( 'Jetonomy: Posts and replies anonymized. Personal data deleted.', 'jetonomy' ) ] : [],
			'done'           => $done,
		];
	}

	/** Anonymize up to $limit authored rows in one table; returns rows affected. */
	private function batch_anonymize_author( string $table_key, int $uid, int $limit ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . table( $table_key ) . ' SET author_id = 0 WHERE author_id = %d LIMIT %d',
				$uid,
				$limit
			)
		);
	}

	/** Delete up to $limit of a user's rows from one table; returns rows deleted. */
	private function batch_delete( string $table_key, string $user_col, int $uid, int $limit ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . table( $table_key ) . " WHERE {$user_col} = %d LIMIT %d",
				$uid,
				$limit
			)
		);
	}

	/** Distinct (object_type, object_id) rows a user voted on. */
	private function collect_vote_objects( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT DISTINCT object_type, object_id FROM ' . table( 'votes' ) . ' WHERE user_id = %d', $user_id )
		);
	}

	/** Space ids a user is a member of. */
	private function collect_member_spaces( int $user_id ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return array_map( 'intval', (array) $wpdb->get_col(
			$wpdb->prepare( 'SELECT space_id FROM ' . table( 'space_members' ) . ' WHERE user_id = %d', $user_id )
		) );
	}

	/**
	 * Recompute vote_score (posts + replies) and member_count (spaces) for the
	 * rows a user's now-deleted votes / memberships touched. Set-based, bounded
	 * to the captured ids — no per-row loop.
	 *
	 * @param object[] $vote_objects Rows from collect_vote_objects().
	 * @param int[]    $space_ids    Space ids from collect_member_spaces().
	 */
	private function recompute_counters_after_purge( array $vote_objects, array $space_ids ): void {
		global $wpdb;

		$post_ids  = [];
		$reply_ids = [];
		foreach ( $vote_objects as $vo ) {
			if ( 'reply' === ( $vo->object_type ?? 'post' ) ) {
				$reply_ids[] = (int) $vo->object_id;
			} else {
				$post_ids[] = (int) $vo->object_id;
			}
		}
		$this->recompute_vote_score( table( 'posts' ), 'post', $post_ids );
		$this->recompute_vote_score( table( 'replies' ), 'reply', $reply_ids );

		$space_ids = array_values( array_unique( array_filter( array_map( 'intval', $space_ids ) ) ) );
		if ( $space_ids ) {
			$sp = table( 'spaces' );
			$sm = table( 'space_members' );
			$in = implode( ',', $space_ids );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( "UPDATE {$sp} SET member_count = ( SELECT COUNT(*) FROM {$sm} sm WHERE sm.space_id = {$sp}.id ) WHERE id IN ({$in})" );
		}
	}

	/** Recompute vote_score for a set of post/reply ids from the surviving votes. */
	private function recompute_vote_score( string $target_table, string $object_type, array $ids ): void {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		global $wpdb;
		$in = implode( ',', $ids );
		$vt = table( 'votes' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$target_table} t SET t.vote_score = ( SELECT COALESCE(SUM(v.value),0) FROM {$vt} v WHERE v.object_type = %s AND v.object_id = t.id ) WHERE t.id IN ({$in})",
				$object_type
			)
		);
	}

	/**
	 * Clean up when a WP user is deleted.
	 */
	public function on_user_delete( int $user_id ): void {
		global $wpdb;

		// Anonymize content (rows kept so threads + denormalized counters stay intact)
		$wpdb->update( table( 'posts' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );
		$wpdb->update( table( 'replies' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );
		$wpdb->update( table( 'revisions' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );
		$wpdb->update( table( 'spaces' ), [ 'author_id' => 0 ], [ 'author_id' => $user_id ] );

		// Capture vote/membership targets before deleting so the denormalized
		// counters can be recomputed afterwards (same as erase_data()).
		$vote_objects  = $this->collect_vote_objects( $user_id );
		$member_spaces = $this->collect_member_spaces( $user_id );

		// Delete user-specific data
		$tables = [
			'user_profiles',
			'notifications',
			'subscriptions',
			'read_status',
			'space_members',
			'votes',
			'activity_log',
			'restrictions',
			'join_requests',
			'bookmarks',
		];

		foreach ( $tables as $t ) {
			$wpdb->delete( table( $t ), [ 'user_id' => $user_id ] );
		}

		$wpdb->delete( table( 'flags' ), [ 'reporter_id' => $user_id ] );

		$this->recompute_counters_after_purge( $vote_objects, $member_spaces );
	}
}
