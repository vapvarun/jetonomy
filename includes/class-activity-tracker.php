<?php
/**
 * Tracks all community activity into the activity_log table.
 * This feeds the admin dashboard "Recent Activity" section and the /updates polling endpoint.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\ActivityLog;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;

class Activity_Tracker {

	public function __construct() {
		// Post events.
		add_action( 'jetonomy_after_create_post', [ $this, 'on_post_created' ], 5, 2 );

		// Reply events.
		add_action( 'jetonomy_after_create_reply', [ $this, 'on_reply_created' ], 5, 2 );

		// Vote events.
		add_action( 'jetonomy_after_vote', [ $this, 'on_vote' ], 5, 3 );

		// Trust level changes.
		add_action( 'jetonomy_trust_level_changed', [ $this, 'on_trust_change' ], 5, 3 );

		// Moderation actions.
		add_action( 'jetonomy_content_moderated', [ $this, 'on_moderation' ], 5, 4 );

		// Reputation changes.
		add_action( 'jetonomy_reputation_changed', [ $this, 'on_reputation' ], 5, 3 );

		// Space membership.
		add_action( 'jetonomy_user_joined_space', [ $this, 'on_space_join' ], 5, 3 );

		// Idea roadmap status changes (Ideas spaces only).
		add_action( 'jetonomy_idea_status_changed', [ $this, 'on_idea_status_changed' ], 5, 4 );
	}

	public function on_post_created( int $post_id, int $space_id ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		ActivityLog::log(
			$user_id,
			'created_post',
			'post',
			$post_id,
			[
				'space_id' => $space_id,
			]
		);
	}

	public function on_reply_created( int $reply_id, int $post_id ): void {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		ActivityLog::log(
			$user_id,
			'created_reply',
			'reply',
			$reply_id,
			[
				'post_id' => $post_id,
			]
		);
	}

	public function on_vote( string $object_type, int $object_id, int $voter_id ): void {
		ActivityLog::log( $voter_id, 'voted', $object_type, $object_id );
	}

	public function on_trust_change( int $user_id, int $old_level, int $new_level ): void {
		ActivityLog::log(
			$user_id,
			'trust_level_changed',
			'user',
			$user_id,
			[
				'old' => $old_level,
				'new' => $new_level,
			]
		);
	}

	public function on_moderation( string $action, string $object_type, int $object_id, int $moderator_id ): void {
		ActivityLog::log( $moderator_id, 'moderated_' . $action, $object_type, $object_id );
	}

	public function on_reputation( int $user_id, string $reason, int $delta ): void {
		ActivityLog::log(
			$user_id,
			'reputation_changed',
			'user',
			$user_id,
			[
				'delta'  => $delta,
				'reason' => $reason,
			]
		);
	}

	public function on_space_join( int $space_id, int $user_id, string $role ): void {
		ActivityLog::log(
			$user_id,
			'joined_space',
			'space',
			$space_id,
			[
				'role' => $role,
			]
		);
	}

	/**
	 * Log roadmap-status changes on Ideas spaces. Helps owners audit who
	 * curated which idea, and the same row drives the optional notification
	 * to the idea author (handled in the Notifier).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $new_status The new idea_status value.
	 * @param string $old_status The previous idea_status value (or '').
	 * @param int    $actor_id   Moderator who triggered the change.
	 */
	public function on_idea_status_changed( int $post_id, string $new_status, string $old_status, int $actor_id ): void {
		ActivityLog::log(
			$actor_id,
			'idea_status_changed',
			'post',
			$post_id,
			array(
				'old' => $old_status,
				'new' => $new_status,
			)
		);
	}
}
