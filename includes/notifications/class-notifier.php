<?php
namespace Jetonomy\Notifications;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Notification;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\Space;
use Jetonomy\Models\UserProfile;
use Jetonomy\Adapters\Adapter_Registry;
use function Jetonomy\now;

class Notifier {

	public function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		// Post created — notify space subscribers
		add_action( 'jetonomy_after_create_post', [ $this, 'on_post_created' ], 10, 2 );

		// Reply created — notify post author + subscribers
		add_action( 'jetonomy_after_create_reply', [ $this, 'on_reply_created' ], 10, 2 );

		// Vote received — notify content author
		add_action( 'jetonomy_after_vote', [ $this, 'on_vote' ], 10, 3 );

		// Reply accepted — notify reply author
		add_action( 'jetonomy_reply_accepted', [ $this, 'on_reply_accepted' ], 10, 2 );

		// Trust level changed
		add_action( 'jetonomy_trust_level_changed', [ $this, 'on_trust_change' ], 10, 3 );

		// Moderator action on content
		add_action( 'jetonomy_content_moderated', [ $this, 'on_content_moderated' ], 10, 4 );

		// Flag created — notify moderators
		add_action( 'jetonomy_flag_created', [ $this, 'on_flag_created' ], 10, 2 );
	}

	/**
	 * Notify space subscribers when a new post is created.
	 */
	public function on_post_created( int $post_id, int $space_id ): void {
		$post = Post::find( $post_id );
		if ( ! $post || 'publish' !== ( $post->status ?? '' ) ) {
			return;
		}

		$space       = Space::find( $space_id );
		$space_name  = $space ? $space->title : __( 'a space', 'jetonomy' );
		$actor_id    = (int) $post->author_id;
		$subscribers = Subscription::get_subscribers( 'space', $space_id );

		foreach ( $subscribers as $sub_user_id ) {
			if ( $sub_user_id === $actor_id ) {
				continue;
			}
			$this->create_and_maybe_email(
				$sub_user_id,
				$actor_id,
				'new_post_in_sub',
				'post',
				$post_id,
				sprintf(
					/* translators: 1: space name, 2: post title */
					__( 'New post in %1$s: %2$s', 'jetonomy' ),
					$space_name,
					mb_substr( $post->title, 0, 50 )
				)
			);
		}
	}

	/**
	 * Notify when someone replies to a post.
	 */
	public function on_reply_created( int $reply_id, int $post_id ): void {
		$reply = Reply::find( $reply_id );
		$post  = Post::find( $post_id );
		if ( ! $reply || ! $post ) {
			return;
		}

		$actor_id = (int) $reply->author_id;

		// 1. Notify post author (if not the replier)
		if ( (int) $post->author_id !== $actor_id ) {
			$this->create_and_maybe_email(
				(int) $post->author_id,
				$actor_id,
				'reply_to_post',
				'post',
				$post_id,
				sprintf(
					__( '%1$s replied to your post "%2$s"', 'jetonomy' ),
					$this->get_display_name( $actor_id ),
					mb_substr( $post->title, 0, 50 )
				)
			);
		}

		// 2. Notify subscribers (excluding author and replier)
		$subscribers = Subscription::get_subscribers( 'post', $post_id );
		foreach ( $subscribers as $sub_user_id ) {
			if ( $sub_user_id === $actor_id || $sub_user_id === (int) $post->author_id ) {
				continue;
			}
			$this->create_and_maybe_email(
				$sub_user_id,
				$actor_id,
				'reply_to_post',
				'post',
				$post_id,
				sprintf(
					__( '%1$s replied in "%2$s"', 'jetonomy' ),
					$this->get_display_name( $actor_id ),
					mb_substr( $post->title, 0, 50 )
				)
			);
		}

		// 3. Notify parent reply author (reply-to-reply)
		if ( ! empty( $reply->parent_id ) ) {
			$parent_reply = Reply::find( (int) $reply->parent_id );
			if ( $parent_reply && (int) $parent_reply->author_id !== $actor_id ) {
				$this->create_and_maybe_email(
					(int) $parent_reply->author_id,
					$actor_id,
					'reply_to_reply',
					'reply',
					$reply_id,
					sprintf(
						__( '%1$s replied to your comment in "%2$s"', 'jetonomy' ),
						$this->get_display_name( $actor_id ),
						mb_substr( $post->title, 0, 50 )
					)
				);
			}
		}
	}

	/**
	 * Notify when content gets voted on — batches votes within the last hour.
	 */
	public function on_vote( string $object_type, int $object_id, int $voter_id ): void {
		if ( 'post' === $object_type ) {
			$obj = Post::find( $object_id );
			if ( ! $obj || (int) $obj->author_id === $voter_id ) {
				return;
			}
			$author_id = (int) $obj->author_id;
			$title     = mb_substr( $obj->title, 0, 50 );
		} elseif ( 'reply' === $object_type ) {
			$obj = Reply::find( $object_id );
			if ( ! $obj || (int) $obj->author_id === $voter_id ) {
				return;
			}
			$author_id = (int) $obj->author_id;
			$title     = __( 'your reply', 'jetonomy' );
		} else {
			return;
		}

		// Check for existing vote notification on same object within last hour.
		global $wpdb;
		$table        = \Jetonomy\table( 'notifications' );
		$one_hour_ago = gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, message FROM {$table} WHERE user_id = %d AND type = 'vote_on_post' AND object_type = %s AND object_id = %d AND created_at > %s ORDER BY created_at DESC LIMIT 1",
				$author_id,
				$object_type,
				$object_id,
				$one_hour_ago
			)
		);

		if ( $existing ) {
			// Update existing — show current total vote count.
			$votes_table = \Jetonomy\table( 'votes' );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$vote_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT v.user_id) FROM {$votes_table} v WHERE v.object_type = %s AND v.object_id = %d",
					$object_type,
					$object_id
				)
			);

			$message = sprintf(
				// translators: 1: vote count, 2: content title.
				_n( '%1$d person voted on %2$s', '%1$d people voted on %2$s', $vote_count, 'jetonomy' ),
				$vote_count,
				'"' . $title . '"'
			);

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$table,
				[
					'message'    => $message,
					'created_at' => now(),
				],
				[ 'id' => (int) $existing->id ]
			);
		} else {
			// Create new notification via email-aware path.
			$this->create_and_maybe_email(
				$author_id,
				$voter_id,
				'vote_on_post',
				$object_type,
				$object_id,
				sprintf(
					// translators: %s: content title.
					__( 'Someone voted on %s', 'jetonomy' ),
					'"' . $title . '"'
				)
			);
		}
	}

	/**
	 * Notify when a reply is accepted as answer.
	 */
	public function on_reply_accepted( int $reply_id, int $post_id ): void {
		$reply = Reply::find( $reply_id );
		$post  = Post::find( $post_id );
		if ( ! $reply || ! $post ) {
			return;
		}

		if ( (int) $reply->author_id !== (int) $post->author_id ) {
			$this->create_and_maybe_email(
				(int) $reply->author_id,
				(int) $post->author_id,
				'accepted_answer',
				'reply',
				$reply_id,
				sprintf(
					__( 'Your answer was accepted in "%s"', 'jetonomy' ),
					mb_substr( $post->title, 0, 50 )
				)
			);
		}
	}

	/**
	 * Notify on trust level promotion.
	 */
	public function on_trust_change( int $user_id, int $old_level, int $new_level ): void {
		if ( $new_level <= $old_level ) {
			return; // Only notify on promotion
		}

		$level_names = [
			1 => __( 'Member', 'jetonomy' ),
			2 => __( 'Regular', 'jetonomy' ),
			3 => __( 'Trusted', 'jetonomy' ),
			4 => __( 'Leader', 'jetonomy' ),
			5 => __( 'Moderator', 'jetonomy' ),
		];

		$name = $level_names[ $new_level ] ?? __( 'Unknown', 'jetonomy' );

		$this->create_and_maybe_email(
			$user_id,
			0, // system notification
			'badge_earned',
			'badge',
			$new_level,
			sprintf(
				__( 'Congratulations! You have been promoted to %1$s (Level %2$d)', 'jetonomy' ),
				$name,
				$new_level
			)
		);
	}

	/**
	 * Notify content author when a moderator acts on their content.
	 */
	public function on_content_moderated( string $action, string $object_type, int $object_id, int $moderator_id ): void {
		if ( 'post' === $object_type ) {
			$obj = Post::find( $object_id );
		} else {
			$obj = Reply::find( $object_id );
		}
		if ( ! $obj ) {
			return;
		}

		$author_id = (int) $obj->author_id;
		if ( $author_id === $moderator_id ) {
			return;
		}

		$action_labels = [
			'approved' => __( 'approved', 'jetonomy' ),
			'spam'     => __( 'marked as spam', 'jetonomy' ),
			'trash'    => __( 'removed', 'jetonomy' ),
		];

		$this->create_and_maybe_email(
			$author_id,
			$moderator_id,
			'moderation',
			$object_type,
			$object_id,
			sprintf(
				/* translators: 1: object type (post/reply), 2: action label */
				__( 'Your %1$s was %2$s by a moderator', 'jetonomy' ),
				$object_type,
				$action_labels[ $action ] ?? $action
			)
		);
	}

	/**
	 * Notify moderators when a flag is created.
	 */
	public function on_flag_created( int $flag_id, string $object_type ): void {
		$moderators = get_users(
			[
				'capability__in' => [ 'jetonomy_moderate', 'manage_options' ],
				'fields'         => 'ID',
			]
		);

		foreach ( $moderators as $mod_id ) {
			Notification::create(
				[
					'user_id'     => (int) $mod_id,
					'actor_id'    => 0,
					'type'        => 'flag',
					'object_type' => $object_type,
					'object_id'   => $flag_id,
					'message'     => __( 'New content flag requires review', 'jetonomy' ),
					'created_at'  => now(),
				]
			);
		}
	}

	/**
	 * Create notification and optionally send email.
	 */
	private function create_and_maybe_email( int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message ): void {
		// Load user preferences and global defaults.
		$profile         = UserProfile::find_by_user( $user_id );
		$settings        = $profile ? json_decode( $profile->settings ?? '{}', true ) : [];
		$user_prefs      = $settings['notifications'] ?? [];
		$global_defaults = get_option( 'jetonomy_settings', [] )['notification_defaults'] ?? [];

		// Check web preference before creating notification.
		$web_enabled = $user_prefs[ $type ]['web'] ?? $global_defaults[ $type ]['web'] ?? true;
		if ( $web_enabled ) {
			$notification_id = Notification::create(
				[
					'user_id'     => $user_id,
					'actor_id'    => $actor_id,
					'type'        => $type,
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'message'     => $message,
					'created_at'  => now(),
				]
			);

			do_action( 'jetonomy_notification_created', $notification_id, $user_id, $type, $object_type, $object_id );
		}

		// Check email preference.
		if ( isset( $user_prefs[ $type ]['email'] ) ) {
			$send_email = ! empty( $user_prefs[ $type ]['email'] );
		} else {
			$send_email = ! empty( $global_defaults[ $type ]['email'] );
		}

		if ( $send_email ) {
			$this->send_email_notification( $user_id, $type, $message, $object_type, $object_id );
		}
	}

	private function send_email_notification( int $user_id, string $type, string $message, string $object_type = '', int $object_id = 0 ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$email_adapter = Adapter_Registry::get_email();
		if ( ! $email_adapter ) {
			return;
		}

		// Build unsubscribe URL.
		$unsub_token = wp_hash( $user_id . ':' . $type . ':unsubscribe' );
		$unsub_url   = add_query_arg(
			[
				'jetonomy_unsubscribe' => $unsub_token,
				'uid'                  => $user_id,
				'type'                 => $type,
			],
			home_url( '/' )
		);

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] %s', $site_name, wp_strip_all_tags( $message ) );
		$html      = $this->render_email_template( $type, $message, $user, $unsub_url );
		$plain     = wp_strip_all_tags( $message );

		// Add List-Unsubscribe headers (RFC 8058).
		$headers = [
			'List-Unsubscribe: <' . $unsub_url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		];

		$email_adapter->send( $user->user_email, $subject, $html, $plain, $headers );
	}

	private function render_email_template( string $type, string $message, \WP_User $user, string $unsub_url = '' ): string {
		$site_name     = esc_html( get_bloginfo( 'name' ) );
		$community_url = esc_url( \Jetonomy\base_url() . '/' );
		$notif_url     = esc_url( \Jetonomy\base_url() . '/notifications/' );
		$unsub_link    = $unsub_url ? esc_url( $unsub_url ) : '';

		$footer = "<a href='{$notif_url}' style='color: #999;'>Manage preferences</a>";
		if ( $unsub_link ) {
			$footer .= " &middot; <a href='{$unsub_link}' style='color: #999;'>Unsubscribe from these emails</a>";
		}

		return "
		<div style='font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 560px; margin: 0 auto; padding: 20px;'>
			<div style='border-bottom: 1px solid #e5e5e5; padding-bottom: 12px; margin-bottom: 20px;'>
				<strong style='font-size: 16px;'>{$site_name}</strong>
			</div>
			<p style='font-size: 15px; line-height: 1.6; color: #1a1a1a;'>" . esc_html( $message ) . "</p>
			<p style='margin-top: 20px;'>
				<a href='{$community_url}' style='display: inline-block; padding: 8px 20px; background: #3B82F6; color: white; border-radius: 6px; text-decoration: none; font-weight: 600;'>View in Community</a>
			</p>
			<hr style='border: none; border-top: 1px solid #e5e5e5; margin: 24px 0;'>
			<p style='font-size: 12px; color: #999;'>{$footer}</p>
		</div>";
	}

	private function get_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Someone', 'jetonomy' );
	}
}
