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
		add_action( 'jetonomy_after_create_post', array( $this, 'on_post_created' ), 5, 2 );

		// Reply events.
		add_action( 'jetonomy_after_create_reply', array( $this, 'on_reply_created' ), 5, 2 );

		// Vote events.
		add_action( 'jetonomy_after_vote', array( $this, 'on_vote' ), 5, 3 );

		// Trust level changes.
		add_action( 'jetonomy_trust_level_changed', array( $this, 'on_trust_change' ), 5, 3 );

		// Moderation actions.
		add_action( 'jetonomy_content_moderated', array( $this, 'on_moderation' ), 5, 4 );

		// Reputation changes.
		add_action( 'jetonomy_reputation_changed', array( $this, 'on_reputation' ), 5, 4 );

		// Space membership.
		add_action( 'jetonomy_user_joined_space', array( $this, 'on_space_join' ), 5, 3 );

		// Idea roadmap status changes (Ideas spaces only).
		add_action( 'jetonomy_idea_status_changed', array( $this, 'on_idea_status_changed' ), 5, 4 );
	}

	/**
	 * Log the post and award the AUTHOR participation rep - once, when the post
	 * is published. The create hook also fires for content held for review and
	 * again when a moderator approves it; only the published moment counts, and
	 * the author (not the approving moderator, nor nobody on a cron publish) is
	 * the actor.
	 */
	public function on_post_created( int $post_id, int $space_id ): void {
		$post   = Post::find( $post_id );
		$author = (int) ( $post->author_id ?? 0 );
		if ( ! $post || 'publish' !== ( $post->status ?? '' ) || ! $author ) {
			return;
		}

		ActivityLog::log( $author, 'created_post', 'post', $post_id, array( 'space_id' => $space_id ) );
		\Jetonomy\Trust\Reputation::award(
			$author,
			'post_created',
			array(
				'object_type' => 'post',
				'object_id'   => $post_id,
			)
		);
	}

	/**
	 * Reply twin of on_post_created(): published replies only, credited to the author.
	 */
	public function on_reply_created( int $reply_id, int $post_id ): void {
		$reply  = Reply::find( $reply_id );
		$author = (int) ( $reply->author_id ?? 0 );
		if ( ! $reply || 'publish' !== ( $reply->status ?? '' ) || ! $author ) {
			return;
		}

		ActivityLog::log( $author, 'created_reply', 'reply', $reply_id, array( 'post_id' => $post_id ) );
		\Jetonomy\Trust\Reputation::award(
			$author,
			'reply_created',
			array(
				'object_type' => 'reply',
				'object_id'   => $reply_id,
			)
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
			array(
				'old' => $old_level,
				'new' => $new_level,
			)
		);
	}

	public function on_moderation( string $action, string $object_type, int $object_id, int $moderator_id ): void {
		ActivityLog::log( $moderator_id, 'moderated_' . $action, $object_type, $object_id );
	}

	/**
	 * Log a reputation change against the content that caused it when there is
	 * one, so the Activity Log, Dashboard and exports mask an anonymous author's
	 * reputation row the same way as the content row beside it.
	 *
	 * @param int    $user_id Member whose reputation changed.
	 * @param string $reason  Action key.
	 * @param int    $delta   Points applied.
	 * @param array  $context Source: object_type ('post' or 'reply') and object_id.
	 */
	public function on_reputation( int $user_id, string $reason, int $delta, array $context = array() ): void {
		$source = in_array( $context['object_type'] ?? '', array( 'post', 'reply' ), true ) && ! empty( $context['object_id'] );
		ActivityLog::log(
			$user_id,
			'reputation_changed',
			$source ? (string) $context['object_type'] : 'user',
			$source ? (int) $context['object_id'] : $user_id,
			array(
				'delta'  => $delta,
				'reason' => $reason,
			)
		);
	}

	public function on_space_join( int $space_id, int $user_id, string $role ): void {
		ActivityLog::log(
			$user_id,
			'joined_space',
			'space',
			$space_id,
			array(
				'role' => $role,
			)
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
