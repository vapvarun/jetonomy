<?php
/**
 * Notification dispatcher.
 *
 * @package Jetonomy
 */

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

		// Join request — notify space admins
		add_action( 'jetonomy_join_request_created', [ $this, 'on_join_request' ], 10, 3 );

		// New user registered through the Login block — branded welcome email.
		// Intentionally registered at priority 20 so integrators can short-
		// circuit earlier and swap in their own welcome without double-sending.
		add_action( 'jetonomy_user_registered', [ $this, 'on_user_registered' ], 20, 1 );
	}

	/**
	 * Branded welcome email for members who sign up through the Login block.
	 * Uses the `user_welcome` notification type so admins can override the
	 * subject + body in Settings → Email → Email Templates.
	 *
	 * @param int $user_id
	 */
	public function on_user_registered( int $user_id ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}

		$site    = get_bloginfo( 'name' );
		$message = sprintf(
			/* translators: 1: display name, 2: site name */
			__( 'Welcome to %2$s, %1$s — your account is ready. Jump into the community to introduce yourself, ask a question, or browse existing discussions.', 'jetonomy' ),
			$user->display_name,
			$site
		);

		$this->send_email_notification(
			$user_id,
			'user_welcome',
			$message,
			'user',
			$user_id,
			\Jetonomy\base_url() . '/'
		);
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
		$post_url    = $this->get_post_url( $post );
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
				),
				$post_url
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
		$post_url = $this->get_post_url( $post );

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
				),
				$post_url
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
				),
				$post_url
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
					),
					$post_url
				);
			}
		}
	}

	/**
	 * Notify when content gets voted on — batches votes within the last hour.
	 */
	public function on_vote( string $object_type, int $object_id, int $voter_id ): void {
		$content_url = '';
		if ( 'post' === $object_type ) {
			$obj = Post::find( $object_id );
			if ( ! $obj || (int) $obj->author_id === $voter_id ) {
				return;
			}
			$author_id   = (int) $obj->author_id;
			$title       = mb_substr( $obj->title, 0, 50 );
			$content_url = $this->get_post_url( $obj );
		} elseif ( 'reply' === $object_type ) {
			$obj = Reply::find( $object_id );
			if ( ! $obj || (int) $obj->author_id === $voter_id ) {
				return;
			}
			$author_id = (int) $obj->author_id;
			$title     = __( 'your reply', 'jetonomy' );
			$parent    = $obj->post_id ? Post::find( (int) $obj->post_id ) : null;
			if ( $parent ) {
				$content_url = $this->get_post_url( $parent );
			}
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
				),
				$content_url
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
		// Resolve the flagged content's real object ID from the flag row.
		$flag      = \Jetonomy\Models\Flag::find( $flag_id );
		$object_id = $flag ? (int) $flag->object_id : $flag_id;

		$moderators = get_users(
			[
				'capability__in' => [ 'jetonomy_moderate', 'manage_options' ],
				'fields'         => 'ID',
			]
		);

		foreach ( $moderators as $mod_id ) {
			$this->create_and_maybe_email(
				(int) $mod_id,
				0,
				'moderation',
				$object_type,
				$object_id,
				__( 'New content flag requires review', 'jetonomy' )
			);
		}
	}

	/**
	 * Create notification and optionally send email.
	 */
	private function create_and_maybe_email( int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message, string $url = '' ): void {
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
			$this->send_email_notification( $user_id, $type, $message, $object_type, $object_id, $url );
		}
	}

	private function send_email_notification( int $user_id, string $type, string $message, string $object_type = '', int $object_id = 0, string $url = '' ): void {
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

		// Admin overrides from Settings → Emails. Each type may define a
		// custom subject + intro message. Placeholders: {site}, {user},
		// {message}, {type}, {url}. If no override set, fall back to the
		// default `[Site] message` subject and the raw $message body.
		$templates    = get_option( 'jetonomy_email_templates', [] );
		$tpl          = isset( $templates[ $type ] ) && is_array( $templates[ $type ] ) ? $templates[ $type ] : [];
		$placeholders = [
			'{site}'    => $site_name,
			'{user}'    => $user->display_name,
			'{message}' => wp_strip_all_tags( $message ),
			'{type}'    => $type,
			'{url}'     => $url,
		];

		$subject_tpl = isset( $tpl['subject'] ) && '' !== $tpl['subject'] ? (string) $tpl['subject'] : '[{site}] {message}';
		$subject     = strtr( $subject_tpl, $placeholders );

		$body_tpl = isset( $tpl['body'] ) && '' !== $tpl['body'] ? (string) $tpl['body'] : $message;
		$body     = strtr( $body_tpl, $placeholders );

		/**
		 * Filter the email subject before sending. Use to tweak specific
		 * types or inject routing prefixes per integration.
		 *
		 * @param string    $subject Computed subject after placeholder substitution.
		 * @param string    $type    Notification type (e.g. reply_to_post).
		 * @param \WP_User  $user    Recipient.
		 */
		$subject = (string) apply_filters( 'jetonomy_email_subject', $subject, $type, $user );

		/**
		 * Filter the email body/intro text. Placeholder replacement has
		 * already happened; this is the final sentence shown above the CTA.
		 *
		 * @param string    $body The rendered body text.
		 * @param string    $type Notification type.
		 * @param \WP_User  $user Recipient.
		 */
		$body = (string) apply_filters( 'jetonomy_email_body', $body, $type, $user );

		$html  = self::render_email_template( $type, $body, $user, $unsub_url, $url );
		$plain = wp_strip_all_tags( $body );

		// Add List-Unsubscribe headers (RFC 8058).
		$headers = [
			'List-Unsubscribe: <' . $unsub_url . '>',
			'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
		];

		/**
		 * Filter the headers before sending. Integrators can append
		 * additional headers (tracking, tagging) here.
		 *
		 * @param string[]  $headers Headers array ready for wp_mail.
		 * @param string    $type    Notification type.
		 * @param \WP_User  $user    Recipient.
		 */
		$headers = (array) apply_filters( 'jetonomy_email_headers', $headers, $type, $user );

		$email_adapter->send( $user->user_email, $subject, $html, $plain, $headers );
	}

	/**
	 * Render a branded notification email.
	 *
	 * Static so Mentions::notify() and other callers can reuse the same template.
	 */
	public static function render_email_template( string $type, string $message, \WP_User $user, string $unsub_url = '', string $content_url = '' ): string {
		$site_name     = esc_html( get_bloginfo( 'name' ) );
		$community_url = '' !== $content_url ? esc_url( $content_url ) : esc_url( \Jetonomy\base_url() . '/' );
		$notif_url     = esc_url( \Jetonomy\base_url() . '/notifications/' );
		$unsub_link    = $unsub_url ? esc_url( $unsub_url ) : '';
		$home_url      = esc_url( home_url( '/' ) );

		$settings = get_option( 'jetonomy_settings', [] );

		/**
		 * Filter the accent color used in the email header accent-bar and CTA.
		 * Default reads from settings `accent_color`, falls back to #3B82F6.
		 *
		 * @param string $accent Hex color.
		 * @param string $type   Notification type.
		 */
		$accent      = (string) apply_filters( 'jetonomy_email_accent_color', (string) ( $settings['accent_color'] ?? '#3B82F6' ), $type );
		$accent_safe = esc_attr( $accent );

		/**
		 * Filter the logo URL shown in the email header. Return '' to fall
		 * back to the site name text.
		 *
		 * @param string $logo_url URL of the logo image.
		 * @param string $type     Notification type.
		 */
		$logo_url = (string) apply_filters( 'jetonomy_email_logo_url', (string) ( $settings['email_logo_url'] ?? '' ), $type );

		$type_labels = [
			'reply_to_post'   => __( 'New Reply', 'jetonomy' ),
			'reply_to_reply'  => __( 'New Reply', 'jetonomy' ),
			'mention'         => __( 'Mention', 'jetonomy' ),
			'vote_on_post'    => __( 'Vote', 'jetonomy' ),
			'accepted_answer' => __( 'Answer Accepted', 'jetonomy' ),
			'new_post_in_sub' => __( 'New Post', 'jetonomy' ),
			'badge_earned'    => __( 'Achievement', 'jetonomy' ),
			'moderation'      => __( 'Moderation', 'jetonomy' ),
			'join_request'    => __( 'Join Request', 'jetonomy' ),
			'user_welcome'    => __( 'Welcome', 'jetonomy' ),
		];
		$type_label  = esc_html( $type_labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) );

		$cta_labels  = [
			'reply_to_post'   => __( 'View Post', 'jetonomy' ),
			'reply_to_reply'  => __( 'View Reply', 'jetonomy' ),
			'mention'         => __( 'View Post', 'jetonomy' ),
			'vote_on_post'    => __( 'View Post', 'jetonomy' ),
			'accepted_answer' => __( 'View Answer', 'jetonomy' ),
			'new_post_in_sub' => __( 'View Post', 'jetonomy' ),
			'moderation'      => __( 'Review in Mod Queue', 'jetonomy' ),
			'join_request'    => __( 'Review Request', 'jetonomy' ),
			'user_welcome'    => __( 'Open the Community', 'jetonomy' ),
		];
		$cta_text    = esc_html( $cta_labels[ $type ] ?? __( 'View in Community', 'jetonomy' ) );
		$cta_url     = esc_url( $community_url );
		$message_esc = esc_html( $message );

		// Header: logo image or text fallback.
		if ( '' !== $logo_url ) {
			$logo_safe   = esc_url( $logo_url );
			$header_html = "<a href=\"{$home_url}\" style=\"text-decoration:none;\"><img src=\"{$logo_safe}\" alt=\"{$site_name}\" style=\"max-height:40px;max-width:200px;height:auto;width:auto;\" /></a>";
		} else {
			$header_html = "<a href=\"{$home_url}\" style=\"text-decoration:none;color:#111827;font-size:18px;font-weight:700;letter-spacing:-0.02em;\">{$site_name}</a>";
		}

		$footer_links = "<a href=\"{$notif_url}\" style=\"color:#6B7280;text-decoration:underline;\">" . esc_html__( 'Notification preferences', 'jetonomy' ) . '</a>';
		if ( $unsub_link ) {
			$footer_links .= " &nbsp;&middot;&nbsp; <a href=\"{$unsub_link}\" style=\"color:#6B7280;text-decoration:underline;\">" . esc_html__( 'Unsubscribe', 'jetonomy' ) . '</a>';
		}

		$footer_text = '';
		$settings_footer = (string) ( $settings['email_footer_text'] ?? '' );
		if ( '' !== $settings_footer ) {
			$footer_text = '<p style="margin:0 0 6px;font-size:11px;color:#9CA3AF;">' . esc_html( $settings_footer ) . '</p>';
		}

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"></head>
<body style="margin:0;padding:0;background-color:#F3F4F6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#F3F4F6;">
<tr><td align="center" style="padding:32px 16px;">

<!-- Container -->
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">

<!-- Accent bar -->
<tr><td style="height:4px;background-color:{$accent_safe};border-radius:8px 8px 0 0;font-size:0;line-height:0;">&nbsp;</td></tr>

<!-- Main card -->
<tr><td style="background-color:#FFFFFF;padding:32px 32px 24px;border-left:1px solid #E5E7EB;border-right:1px solid #E5E7EB;">

<!-- Header -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr>
<td style="padding-bottom:24px;">
	{$header_html}
</td>
<td align="right" style="padding-bottom:24px;">
	<span style="display:inline-block;padding:3px 10px;background-color:{$accent_safe}1A;color:{$accent_safe};font-size:11px;font-weight:600;border-radius:12px;letter-spacing:0.04em;text-transform:uppercase;">{$type_label}</span>
</td>
</tr>
</table>

<!-- Divider -->
<div style="border-top:1px solid #E5E7EB;margin-bottom:24px;"></div>

<!-- Message -->
<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#374151;">{$message_esc}</p>

<!-- CTA Button -->
<table role="presentation" cellpadding="0" cellspacing="0">
<tr><td style="border-radius:6px;background-color:{$accent_safe};">
	<a href="{$cta_url}" style="display:inline-block;padding:12px 28px;color:#FFFFFF;font-size:14px;font-weight:600;text-decoration:none;border-radius:6px;">{$cta_text}</a>
</td></tr>
</table>

</td></tr>

<!-- Footer -->
<tr><td style="background-color:#F9FAFB;padding:20px 32px;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 8px 8px;">
<p style="margin:0 0 8px;font-size:12px;line-height:1.5;color:#6B7280;">{$footer_links}</p>
{$footer_text}
<p style="margin:0;font-size:11px;color:#9CA3AF;">
	{$site_name} &middot; <a href="{$home_url}" style="color:#9CA3AF;text-decoration:none;">{$home_url}</a>
</p>
</td></tr>

</table>
<!-- /Container -->

</td></tr>
</table>
</body>
</html>
HTML;

		/**
		 * Final filter on the rendered HTML. Integrators can inject tracking
		 * pixels, rewrite links, or A/B-test templates by returning a new
		 * HTML string.
		 *
		 * @param string    $html The rendered branded email HTML.
		 * @param string    $type Notification type.
		 * @param \WP_User  $user Recipient.
		 */
		return (string) apply_filters( 'jetonomy_email_html', $html, $type, $user );
	}

	/**
	 * Notify space admins/moderators when a join request is submitted.
	 */
	public function on_join_request( int $space_id, int $user_id, string $message ): void {
		$space      = Space::find( $space_id );
		$space_name = $space ? $space->title : __( 'a space', 'jetonomy' );
		$space_url  = $space
			? admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $space->id . '&tab=join_requests' )
			: '';

		// Collect recipients: space-level admins/moderators + WP-level admins.
		$recipient_ids = [];

		// 1. Space-level admins and moderators from jt_space_members.
		global $wpdb;
		$members_table = \Jetonomy\table( 'space_members' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$space_admins = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$members_table} WHERE space_id = %d AND role IN ('admin', 'moderator')",
				$space_id
			)
		);
		foreach ( $space_admins as $admin_id ) {
			$recipient_ids[ (int) $admin_id ] = true;
		}

		// 2. WP-level admins who can manage spaces globally.
		$wp_admins = get_users(
			[
				'capability__in' => [ 'jetonomy_manage_spaces', 'manage_options' ],
				'fields'         => 'ID',
			]
		);
		foreach ( $wp_admins as $admin_id ) {
			$recipient_ids[ (int) $admin_id ] = true;
		}

		$notify_message = sprintf(
			/* translators: 1: user display name, 2: space name */
			__( '%1$s requested to join %2$s', 'jetonomy' ),
			$this->get_display_name( $user_id ),
			$space_name
		);

		foreach ( array_keys( $recipient_ids ) as $mod_id ) {
			if ( $mod_id === $user_id ) {
				continue;
			}
			$this->create_and_maybe_email(
				$mod_id,
				$user_id,
				'join_request',
				'space',
				$space_id,
				$notify_message,
				$space_url
			);
		}
	}

	private function get_display_name( int $user_id ): string {
		$user = get_userdata( $user_id );
		return $user ? $user->display_name : __( 'Someone', 'jetonomy' );
	}

	private function get_post_url( object $post ): string {
		$space = $post->space_id ? Space::find( (int) $post->space_id ) : null;
		if ( ! $space ) {
			return \Jetonomy\base_url() . '/';
		}
		return \Jetonomy\base_url() . '/s/' . $space->slug . '/t/' . $post->slug . '/';
	}
}
