<?php
namespace Jetonomy\Notifications;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Notification;
use Jetonomy\Models\Subscription;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use Jetonomy\Adapters\Adapter_Registry;
use function Jetonomy\now;

class Notifier {

	public function __construct() {
		$this->register_hooks();
	}

	private function register_hooks(): void {
		// Reply created — notify post author + subscribers
		add_action( 'jetonomy_after_create_reply', [ $this, 'on_reply_created' ], 10, 2 );

		// Vote received — notify content author
		add_action( 'jetonomy_after_vote', [ $this, 'on_vote' ], 10, 3 );

		// Reply accepted — notify reply author
		add_action( 'jetonomy_reply_accepted', [ $this, 'on_reply_accepted' ], 10, 2 );

		// Trust level changed
		add_action( 'jetonomy_trust_level_changed', [ $this, 'on_trust_change' ], 10, 3 );
	}

	/**
	 * Notify when someone replies to a post.
	 */
	public function on_reply_created( int $reply_id, int $post_id ): void {
		$reply = Reply::find( $reply_id );
		$post  = Post::find( $post_id );
		if ( ! $reply || ! $post ) return;

		$actor_id = (int) $reply->author_id;

		// 1. Notify post author (if not the replier)
		if ( (int) $post->author_id !== $actor_id ) {
			$this->create_and_maybe_email(
				(int) $post->author_id,
				$actor_id,
				'reply',
				'post',
				$post_id,
				sprintf(
					__( '%s replied to your post "%s"', 'jetonomy' ),
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
				'reply',
				'post',
				$post_id,
				sprintf(
					__( '%s replied in "%s"', 'jetonomy' ),
					$this->get_display_name( $actor_id ),
					mb_substr( $post->title, 0, 50 )
				)
			);
		}
	}

	/**
	 * Notify when content gets voted on.
	 */
	public function on_vote( string $object_type, int $object_id, int $voter_id ): void {
		if ( 'post' === $object_type ) {
			$post = Post::find( $object_id );
			if ( ! $post || (int) $post->author_id === $voter_id ) return;

			Notification::create( [
				'user_id'     => (int) $post->author_id,
				'actor_id'    => $voter_id,
				'type'        => 'vote',
				'object_type' => 'post',
				'object_id'   => $object_id,
				'message'     => sprintf(
					__( 'Someone voted on your post "%s"', 'jetonomy' ),
					mb_substr( $post->title, 0, 50 )
				),
				'created_at'  => now(),
			] );
		} elseif ( 'reply' === $object_type ) {
			$reply = Reply::find( $object_id );
			if ( ! $reply || (int) $reply->author_id === $voter_id ) return;

			Notification::create( [
				'user_id'     => (int) $reply->author_id,
				'actor_id'    => $voter_id,
				'type'        => 'vote',
				'object_type' => 'reply',
				'object_id'   => $object_id,
				'message'     => __( 'Someone voted on your reply', 'jetonomy' ),
				'created_at'  => now(),
			] );
		}
	}

	/**
	 * Notify when a reply is accepted as answer.
	 */
	public function on_reply_accepted( int $reply_id, int $post_id ): void {
		$reply = Reply::find( $reply_id );
		$post  = Post::find( $post_id );
		if ( ! $reply || ! $post ) return;

		if ( (int) $reply->author_id !== (int) $post->author_id ) {
			$this->create_and_maybe_email(
				(int) $reply->author_id,
				(int) $post->author_id,
				'accepted',
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
		if ( $new_level <= $old_level ) return; // Only notify on promotion

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
			'trust_promotion',
			'badge',
			$new_level,
			sprintf(
				__( 'Congratulations! You have been promoted to %s (Level %d)', 'jetonomy' ),
				$name,
				$new_level
			)
		);
	}

	/**
	 * Create notification and optionally send email.
	 */
	private function create_and_maybe_email( int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message ): void {
		Notification::create( [
			'user_id'     => $user_id,
			'actor_id'    => $actor_id,
			'type'        => $type,
			'object_type' => $object_type,
			'object_id'   => $object_id,
			'message'     => $message,
			'created_at'  => now(),
		] );

		// Check user's email preference
		$profile   = UserProfile::find_by_user( $user_id );
		$settings  = $profile ? json_decode( $profile->settings ?? '{}', true ) : [];
		$email_pref = $settings['notifications'][ $type ]['email'] ?? 'none';

		if ( 'immediate' === $email_pref || 'both' === $email_pref ) {
			$this->send_email_notification( $user_id, $type, $message );
		}
	}

	private function send_email_notification( int $user_id, string $type, string $message ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) return;

		$email_adapter = Adapter_Registry::get_email();
		if ( ! $email_adapter ) return;

		$site_name = get_bloginfo( 'name' );
		$subject   = sprintf( '[%s] %s', $site_name, wp_strip_all_tags( $message ) );
		$html      = $this->render_email_template( $type, $message, $user );
		$plain     = wp_strip_all_tags( $message );

		$email_adapter->send( $user->user_email, $subject, $html, $plain );
	}

	private function render_email_template( string $type, string $message, \WP_User $user ): string {
		$site_name     = esc_html( get_bloginfo( 'name' ) );
		$community_url = esc_url( home_url( '/community/' ) );
		$notif_url     = esc_url( home_url( '/community/notifications/' ) );

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
			<p style='font-size: 12px; color: #999;'>
				<a href='{$notif_url}' style='color: #999;'>Manage notification preferences</a>
			</p>
		</div>";
	}

	private function get_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Someone', 'jetonomy' );
	}
}
