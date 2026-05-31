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

	/**
	 * Single source of truth for the default subject + body shipped with each
	 * notification type. Both the activation seeder (which pre-populates the
	 * `jetonomy_email_templates` option for the `verification_reminder` row,
	 * because the cron needs SOMETHING to render before the admin saves an
	 * override) AND the Settings → Notifications "Reset to default" button
	 * read from this map, so the strings can never drift between the two
	 * sites.
	 *
	 * Subjects are wrapped in `[{site}]` to match the legacy `'[{site}] {message}'`
	 * fallback used by Notifier::send_email_notification when no override is
	 * saved — admins see consistent default copy whether they clear the field
	 * (falls back at send time) or click Reset (writes the same template into
	 * the field).
	 *
	 * Bodies use the documented placeholder set: {site}, {user}, {message},
	 * {type}, {url}, plus the 1.3.6 enriched placeholders {post_title},
	 * {actor_display_name}, {reply_excerpt}, {space_title}. Don't add new
	 * placeholders here without a matching substitution in
	 * send_email_notification().
	 *
	 * @param string $type Notification type key (must match the
	 *                     sanitize_email_templates() allowlist).
	 * @return array{subject: string, body: string} Empty pair on unknown type.
	 */
	public static function get_default_template( string $type ): array {
		$defaults = array(
			'user_welcome'          => array(
				'subject' => __( '[{site}] Welcome to the community', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\nWelcome to {site}. Your account is ready. Jump in and introduce yourself, ask a question, or browse the latest discussions.\n\n{message}", 'jetonomy' ),
			),
			'reply_to_post'         => array(
				'subject' => __( '[{site}] {actor_display_name} replied to your post', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nOpen the discussion to read the full reply and join the conversation.", 'jetonomy' ),
			),
			'reply_to_reply'        => array(
				'subject' => __( '[{site}] {actor_display_name} replied to your comment', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nClick through to read the full thread.", 'jetonomy' ),
			),
			'mention'               => array(
				'subject' => __( '[{site}] You were mentioned by {actor_display_name}', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nOpen the discussion to respond.", 'jetonomy' ),
			),
			'accepted_answer'       => array(
				'subject' => __( '[{site}] Your answer was accepted', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nNice work. Your reputation just went up.", 'jetonomy' ),
			),
			'idea_status_changed'   => array(
				'subject' => __( '[{site}] Your idea was updated', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nThanks for sharing your idea. Open the post to see the latest updates.", 'jetonomy' ),
			),
			'new_post_in_sub'       => array(
				'subject' => __( '[{site}] New post in {space_title}', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nOpen the post to read more.", 'jetonomy' ),
			),
			'badge_earned'          => array(
				'subject' => __( '[{site}] You earned a new badge', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nKeep contributing to unlock more.", 'jetonomy' ),
			),
			'vote_on_post'          => array(
				'subject' => __( '[{site}] Your post received a vote', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nOpen the post to see the discussion.", 'jetonomy' ),
			),
			'moderation'            => array(
				'subject' => __( '[{site}] A moderator reviewed your content', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nIf you think this was a mistake, reply to a moderator in the community.", 'jetonomy' ),
			),
			'join_request'          => array(
				'subject' => __( '[{site}] New space join request', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\n{message}\n\nReview the request and approve or decline it.", 'jetonomy' ),
			),
			'verification_reminder' => array(
				'subject' => __( '[{site}] Confirm your email to finish signing up', 'jetonomy' ),
				'body'    => __( "Hi {user},\n\nWe noticed you haven't confirmed your email yet at {site}. Click the link below to verify your account and start participating.\n\nThis link expires in 24 hours.", 'jetonomy' ),
			),
		);

		return $defaults[ $type ] ?? array(
			'subject' => '',
			'body'    => '',
		);
	}

	/**
	 * Send the email-confirmation message to a user who just signed up
	 * while `require_email_verification` was on.
	 *
	 * Static because callers (Auth_Controller) don't need an instance —
	 * the welcome flow is stateless. Subject + body are wrapped in the
	 * standard email template so customer branding (logo, footer text,
	 * accent color) all carry over from Jetonomy → Settings → Email.
	 *
	 * @param int    $user_id Newly-created user.
	 * @param string $token   Plain (un-hashed) verification token; goes into the link.
	 */
	public static function send_verification_email( int $user_id, string $token ): void {
		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return;
		}

		$verify_url = add_query_arg(
			array(
				'user_id' => $user_id,
				'token'   => rawurlencode( $token ),
			),
			rest_url( 'jetonomy/v1/auth/verify-email' )
		);

		$site_name    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$display_name = $user->display_name ? $user->display_name : $user->user_login;

		/* translators: %s: site name */
		$subject = sprintf( __( 'Confirm your email to finish signing up at %s', 'jetonomy' ), $site_name );

		$plain = sprintf(
			/* translators: 1: display name, 2: site name */
			__( "Hi %1\$s,\n\nThanks for joining %2\$s. Click the link below to confirm your email and finish creating your account:\n\n%3\$s\n\nThis link expires in 24 hours.\n\nIf you didn't sign up, you can ignore this email.\n\nThe %2\$s team", 'jetonomy' ),
			$display_name,
			$site_name,
			$verify_url
		);

		ob_start();
		?>
		<p style="margin:0 0 16px;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: display name */
					__( 'Hi %s,', 'jetonomy' ),
					$display_name
				)
			);
			?>
		</p>
		<p style="margin:0 0 16px;">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: site name */
					__( 'Thanks for joining %s. Confirm your email to finish creating your account.', 'jetonomy' ),
					$site_name
				)
			);
			?>
		</p>
		<p style="margin:24px 0;">
			<a href="<?php echo esc_url( $verify_url ); ?>" style="display:inline-block;background:#3B82F6;color:#fff;text-decoration:none;padding:12px 24px;border-radius:6px;font-weight:600;">
				<?php esc_html_e( 'Confirm email', 'jetonomy' ); ?>
			</a>
		</p>
		<p style="margin:0 0 16px;color:#6B7280;font-size:14px;">
			<?php esc_html_e( 'This link expires in 24 hours.', 'jetonomy' ); ?>
		</p>
		<p style="margin:0 0 16px;color:#6B7280;font-size:14px;">
			<?php esc_html_e( "If you didn't sign up, you can ignore this email.", 'jetonomy' ); ?>
		</p>
		<?php
		$html = (string) ob_get_clean();

		// Route through the registered Email_Adapter (same path as the rest of
		// the notifier — see send_email_notification() at line 659). Earlier
		// versions reached for an undefined `jetonomy_get_email_adapter()`
		// helper and fell back to direct wp_mail(), bypassing any Pro
		// Mailgun / SES / Postmark adapter that might be registered.
		$adapter = Adapter_Registry::get_email();
		if ( $adapter ) {
			$adapter->send(
				$user->user_email,
				$subject,
				$html,
				$plain,
				array( 'Content-Type: text/html; charset=UTF-8' )
			);
			return;
		}

		// No email adapter at all (defensive — wp-mail-adapter is registered
		// at boot in init_defaults()). Fall back to wp_mail so the
		// verification reminder still has a chance of going out.
		wp_mail(
			$user->user_email,
			$subject,
			$html,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	private function register_hooks(): void {
		// Post created — notify space subscribers
		add_action( 'jetonomy_after_create_post', [ $this, 'on_post_created' ], 10, 2 );

		// Reply created — notify post author + subscribers
		add_action( 'jetonomy_after_create_reply', [ $this, 'on_reply_created' ], 10, 2 );

		// Reply submitted by email (Reply-by-Email Pro extension fires this).
		// Nothing in free listened before, so emailed replies were silently lost.
		add_action( 'jetonomy_create_reply_from_email', [ $this, 'on_reply_from_email' ], 10, 4 );

		// Vote received — notify content author
		add_action( 'jetonomy_after_vote', [ $this, 'on_vote' ], 10, 4 );

		// Reply accepted — notify reply author
		add_action( 'jetonomy_reply_accepted', [ $this, 'on_reply_accepted' ], 10, 2 );

		// Idea roadmap status changed — notify idea author
		add_action( 'jetonomy_idea_status_changed', [ $this, 'on_idea_status_changed' ], 10, 4 );

		// Trust level changed
		add_action( 'jetonomy_trust_level_changed', [ $this, 'on_trust_change' ], 10, 3 );

		// Moderator action on content
		add_action( 'jetonomy_content_moderated', [ $this, 'on_content_moderated' ], 10, 4 );

		// Flag created — notify moderators
		add_action( 'jetonomy_flag_created', [ $this, 'on_flag_created' ], 10, 2 );

		// Join request — notify space admins
		add_action( 'jetonomy_join_request_created', [ $this, 'on_join_request' ], 10, 3 );
		add_action( 'jetonomy_join_request_approved', [ $this, 'on_join_request_approved' ], 10, 3 );
		add_action( 'jetonomy_join_request_denied', [ $this, 'on_join_request_denied' ], 10, 3 );

		// New user registered through the Login block — branded welcome email.
		// Intentionally registered at priority 20 so integrators can short-
		// circuit earlier and swap in their own welcome without double-sending.
		add_action( 'jetonomy_user_registered', [ $this, 'on_user_registered' ], 20, 1 );
	}

	/**
	 * Create a forum reply from an inbound email.
	 *
	 * The Reply-by-Email Pro extension fires `jetonomy_create_reply_from_email`
	 * after it parses + validates an inbound message, but nothing in free
	 * listened, so emailed replies were silently discarded. This mirrors the
	 * REST controller's canonical post-create side-effects: row creation via
	 * Reply::create() (counters + content_plain handled there), the
	 * `jetonomy_after_create_reply` action (notifications), and @mention parsing.
	 *
	 * @param int    $post_id Forum post ID.
	 * @param int    $user_id Author user ID.
	 * @param string $content Sanitized reply content.
	 * @param string $source  Origin marker (e.g. 'reply_by_email').
	 */
	public function on_reply_from_email( int $post_id, int $user_id, string $content, string $source = '' ): void {
		if ( $post_id <= 0 || $user_id <= 0 || '' === trim( $content ) ) {
			return;
		}

		$reply_id = \Jetonomy\Models\Reply::create(
			[
				'post_id'       => $post_id,
				'author_id'     => $user_id,
				'content'       => $content,
				'content_plain' => wp_strip_all_tags( $content ),
			]
		);

		if ( is_wp_error( $reply_id ) || ! $reply_id ) {
			return;
		}

		// Canonical post-create side-effects (same as the REST controller).
		do_action( 'jetonomy_after_create_reply', $reply_id, $post_id );

		$mentioned = \Jetonomy\Mentions::extract_user_ids( $content );
		if ( ! empty( $mentioned ) ) {
			$post = \Jetonomy\Models\Post::find( $post_id );
			\Jetonomy\Mentions::notify( $mentioned, $user_id, 'reply', $reply_id, $post->title ?? __( 'your reply', 'jetonomy' ) );
		}
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
			__( 'Welcome to %2$s, %1$s. Your account is ready. Jump into the community to introduce yourself, ask a question, or browse existing discussions.', 'jetonomy' ),
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

		// Build enriched context so the reply-to-post template (and any
		// admin-customised subject/body) can reference post_title /
		// actor_display_name / reply_excerpt / space_title directly.
		$space         = $post->space_id ? Space::find( (int) $post->space_id ) : null;
		$reply_plain   = isset( $reply->content_plain ) && '' !== (string) $reply->content_plain
			? (string) $reply->content_plain
			: wp_strip_all_tags( (string) ( $reply->content ?? '' ) );
		$reply_excerpt = wp_trim_words( $reply_plain, 30, '…' );
		// Feed-space posts (1.4.3 WS1) are stored untitled — fall back to
		// the post-title-or-excerpt helper so the email subject line and
		// the "On topic" block in templates/emails/reply-to-post.php always
		// have a human-readable label.
		$ctx_extra = array(
			'post_title'         => jetonomy_post_title_or_excerpt( $post ),
			'actor_display_name' => $this->get_display_name( $actor_id ),
			'reply_excerpt'      => $reply_excerpt,
			'space_title'        => $space ? (string) $space->title : '',
		);

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
				$post_url,
				$ctx_extra
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
				$post_url,
				$ctx_extra
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
					$post_url,
					$ctx_extra
				);
			}
		}
	}

	/**
	 * Notify when content gets voted on — batches votes within the last hour.
	 */
	public function on_vote( string $object_type, int $object_id, int $voter_id, int $value = 1 ): void {
		// Only an upvote earns the author a "voted on your post" notification.
		// Downvotes (-1) and vote removals (0) must not ping the author with an
		// encouraging-sounding message. Default 1 keeps legacy 3-arg callers safe.
		if ( $value < 1 ) {
			return;
		}

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
	 * Notify the idea author when a moderator changes their roadmap status.
	 *
	 * Self-changes (a moderator setting status on their own idea) are
	 * skipped — same pattern as on_reply_accepted skipping self-accepts.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $new_status The new idea_status value.
	 * @param string $old_status The previous idea_status value (or '').
	 * @param int    $actor_id   Moderator who triggered the change.
	 */
	public function on_idea_status_changed( int $post_id, string $new_status, string $old_status, int $actor_id ): void {
		$post = Post::find( $post_id );
		if ( ! $post ) {
			return;
		}
		$author_id = (int) $post->author_id;
		if ( $author_id <= 0 || $author_id === $actor_id ) {
			return;
		}
		if ( $new_status === $old_status ) {
			return;
		}

		$this->create_and_maybe_email(
			$author_id,
			$actor_id,
			'idea_status_changed',
			'post',
			$post_id,
			sprintf(
				/* translators: 1: idea title, 2: new status label */
				__( 'Your idea "%1$s" is now %2$s', 'jetonomy' ),
				mb_substr( (string) $post->title, 0, 60 ),
				jetonomy_idea_status_label( $new_status )
			)
		);
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
	 *
	 * $extra lets callers pass richer context (post_title, actor_display_name,
	 * reply_excerpt, space_title) that the email template and the admin-
	 * configured subject/body overrides can consume as placeholders.
	 *
	 * @param int    $user_id     Recipient.
	 * @param int    $actor_id    Who triggered the notification (0 for system).
	 * @param string $type        Notification type key.
	 * @param string $object_type 'post' | 'reply' | 'user' | 'space' | '' .
	 * @param int    $object_id   Target object ID.
	 * @param string $message     Short notification sentence.
	 * @param string $url         Deep-link for the CTA.
	 * @param array  $extra       Optional enriched context; see render_email_template().
	 */
	private function create_and_maybe_email( int $user_id, int $actor_id, string $type, string $object_type, int $object_id, string $message, string $url = '', array $extra = array() ): void {
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
			$this->send_email_notification( $user_id, $type, $message, $object_type, $object_id, $url, $extra );
		}
	}

	private function send_email_notification( int $user_id, string $type, string $message, string $object_type = '', int $object_id = 0, string $url = '', array $extra = array() ): void {
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
		// custom subject + intro message. Placeholders below — the legacy
		// five are still supported; 1.3.6 adds richer per-notification
		// context so admin templates can reference post titles, excerpts,
		// etc. without needing a code override.
		$templates    = get_option( 'jetonomy_email_templates', [] );
		$tpl          = isset( $templates[ $type ] ) && is_array( $templates[ $type ] ) ? $templates[ $type ] : [];
		$placeholders = [
			'{site}'               => $site_name,
			'{user}'               => $user->display_name,
			'{message}'            => wp_strip_all_tags( $message ),
			'{type}'               => $type,
			'{url}'                => $url,
			// Enriched placeholders (1.3.6 — Basecamp 9725671512). Safe
			// fallback to empty string when the caller didn't provide them,
			// so existing templates don't render literal "{post_title}" text.
			'{post_title}'         => (string) ( $extra['post_title'] ?? '' ),
			'{actor_display_name}' => (string) ( $extra['actor_display_name'] ?? '' ),
			'{reply_excerpt}'      => (string) ( $extra['reply_excerpt'] ?? '' ),
			'{space_title}'        => (string) ( $extra['space_title'] ?? '' ),
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

		$html  = self::render_email_template( $type, $body, $user, $unsub_url, $url, $extra );
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
	 * Resolve an email template file for a given notification type.
	 *
	 * Lookup order:
	 *   1. Active (child) theme: `yourtheme/jetonomy/emails/{type}.php`
	 *   2. Plugin per-type override: `plugin/templates/emails/{type}.php`
	 *   3. Active theme base: `yourtheme/jetonomy/emails/base.php`
	 *   4. Plugin base: `plugin/templates/emails/base.php`
	 *
	 * Type is sanitized to `[a-z0-9_-]+` before joining the path, so it can
	 * never traverse outside the templates directory.
	 *
	 * Filter `jetonomy_email_template_path` receives the resolved path and
	 * the type — integrators can swap in a fully custom template.
	 *
	 * @param string $type Notification type key (e.g. reply_to_post).
	 * @return string Absolute filesystem path to the template to include.
	 */
	public static function locate_email_template( string $type ): string {
		$safe_type = preg_replace( '/[^a-z0-9_-]/i', '', $type );
		// Type keys use snake_case (reply_to_post); convention for template
		// filenames is hyphen-case (reply-to-post.php). Try both so site
		// builders can use either style.
		$hyphen_type = '' !== $safe_type ? str_replace( '_', '-', $safe_type ) : '';

		$theme_dir  = get_stylesheet_directory() . '/jetonomy/emails/';
		$plugin_dir = JETONOMY_DIR . 'templates/emails/';

		$candidates = array();
		if ( '' !== $hyphen_type ) {
			$candidates[] = $theme_dir . $hyphen_type . '.php';
			$candidates[] = $plugin_dir . $hyphen_type . '.php';
		}
		if ( '' !== $safe_type && $safe_type !== $hyphen_type ) {
			$candidates[] = $theme_dir . $safe_type . '.php';
			$candidates[] = $plugin_dir . $safe_type . '.php';
		}
		$candidates[] = $theme_dir . 'base.php';
		$candidates[] = $plugin_dir . 'base.php';

		$resolved = $plugin_dir . 'base.php'; // last-resort default.
		foreach ( $candidates as $candidate ) {
			if ( file_exists( $candidate ) ) {
				$resolved = $candidate;
				break;
			}
		}

		/**
		 * Filter the resolved email template path.
		 *
		 * @param string $resolved Absolute path to the template file about to be loaded.
		 * @param string $type     Notification type key.
		 */
		return (string) apply_filters( 'jetonomy_email_template_path', $resolved, $type );
	}

	/**
	 * Render a branded notification email.
	 *
	 * Static so Mentions::notify() and other callers can reuse the same template.
	 *
	 * @param string   $type        Notification type key.
	 * @param string   $message     Sentence shown above the CTA (plain text).
	 * @param \WP_User $user        Recipient.
	 * @param string   $unsub_url   One-click unsubscribe URL (optional).
	 * @param string   $content_url Deep-link URL for the CTA (optional, falls back to community home).
	 * @param array    $extra       Optional extra context:
	 *                              - post_title, actor_display_name, reply_excerpt, space_title
	 *                              - any additional keys — forwarded to templates + filter hooks.
	 */
	public static function render_email_template( string $type, string $message, \WP_User $user, string $unsub_url = '', string $content_url = '', array $extra = array() ): string {
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

		// Generic Jetonomy header logo filter — surfaces with no email-specific
		// override (Pro white-label, integrations) can drive both via the
		// shared `jetonomy_header_logo` contract.
		$logo_url = \Jetonomy\header_logo( $logo_url );

		$type_labels = [
			'reply_to_post'       => __( 'New Reply', 'jetonomy' ),
			'reply_to_reply'      => __( 'New Reply', 'jetonomy' ),
			'mention'             => __( 'Mention', 'jetonomy' ),
			'vote_on_post'        => __( 'Vote', 'jetonomy' ),
			'accepted_answer'     => __( 'Answer Accepted', 'jetonomy' ),
			'idea_status_changed' => __( 'Roadmap Update', 'jetonomy' ),
			'new_post_in_sub'     => __( 'New Post', 'jetonomy' ),
			'badge_earned'        => __( 'Achievement', 'jetonomy' ),
			'moderation'          => __( 'Moderation', 'jetonomy' ),
			'join_request'        => __( 'Join Request', 'jetonomy' ),
			'user_welcome'        => __( 'Welcome', 'jetonomy' ),
		];
		$type_label  = esc_html( $type_labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) ) );

		$cta_labels = [
			'reply_to_post'       => __( 'View Post', 'jetonomy' ),
			'reply_to_reply'      => __( 'View Reply', 'jetonomy' ),
			'mention'             => __( 'View Post', 'jetonomy' ),
			'vote_on_post'        => __( 'View Post', 'jetonomy' ),
			'accepted_answer'     => __( 'View Answer', 'jetonomy' ),
			'idea_status_changed' => __( 'View Idea', 'jetonomy' ),
			'new_post_in_sub'     => __( 'View Post', 'jetonomy' ),
			'badge_earned'        => __( 'View Your Badges', 'jetonomy' ),
			'moderation'          => __( 'Review in Mod Queue', 'jetonomy' ),
			'join_request'        => __( 'Review Request', 'jetonomy' ),
			'user_welcome'        => __( 'Open the Community', 'jetonomy' ),
		];
		$cta_text   = $cta_labels[ $type ] ?? __( 'View in Community', 'jetonomy' );

		// Build the $ctx array passed into the template file. Each template
		// (base.php + optional per-type overrides) reads from this. Keys
		// are documented in templates/emails/base.php.
		$ctx = array(
			'type'               => $type,
			'type_label'         => $type_label,
			'site_name'          => $site_name,        // already esc_html'd, safe inside attribute-less nodes.
			'site_name_text'     => get_bloginfo( 'name' ), // raw for esc_html() at print site.
			'home_url'           => $home_url,         // already esc_url'd.
			'home_url_text'      => home_url( '/' ),   // raw, re-escape at print site.
			'community_url'      => $community_url,
			'notif_url'          => $notif_url,        // already esc_url'd.
			'unsub_url'          => $unsub_link,       // already esc_url'd, or '' when missing.
			'accent'             => $accent,           // raw hex (attribute-escaped in template).
			'logo_url'           => '' !== $logo_url ? esc_url( $logo_url ) : '',
			'cta_text'           => $cta_text,
			'cta_url'            => $community_url,
			'message'            => $message,          // raw — template esc_html's it.
			'footer_text'        => \Jetonomy\footer_text( (string) ( $settings['email_footer_text'] ?? '' ) ),
			'post_title'         => (string) ( $extra['post_title'] ?? '' ),
			'actor_display_name' => (string) ( $extra['actor_display_name'] ?? '' ),
			'reply_excerpt'      => (string) ( $extra['reply_excerpt'] ?? '' ),
			'space_title'        => (string) ( $extra['space_title'] ?? '' ),
			'user'               => $user,
		);

		/**
		 * Filter the full context passed into email templates. Add new keys
		 * here to make them available inside base.php and type-specific
		 * templates. Don't drop required keys — templates expect them.
		 *
		 * @param array    $ctx  Template context.
		 * @param string   $type Notification type.
		 * @param \WP_User $user Recipient.
		 */
		$ctx = (array) apply_filters( 'jetonomy_email_template_context', $ctx, $type, $user );

		$template_file = self::locate_email_template( $type );

		ob_start();
		include $template_file;
		$html = (string) ob_get_clean();

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
	 * Notify the requester that their join request was approved.
	 */
	public function on_join_request_approved( int $space_id, int $user_id, int $reviewed_by ): void {
		$space = Space::find( $space_id );
		$name  = $space ? $space->title : __( 'the space', 'jetonomy' );
		$url   = $space ? \Jetonomy\base_url() . '/s/' . $space->slug . '/' : '';
		$this->create_and_maybe_email(
			$user_id,
			$reviewed_by,
			'join_request_result',
			'space',
			$space_id,
			/* translators: %s: space name */
			sprintf( __( 'Your request to join %s was approved', 'jetonomy' ), $name ),
			$url
		);
	}

	/**
	 * Notify the requester that their join request was declined.
	 */
	public function on_join_request_denied( int $space_id, int $user_id, int $reviewed_by ): void {
		$space = Space::find( $space_id );
		$name  = $space ? $space->title : __( 'the space', 'jetonomy' );
		$this->create_and_maybe_email(
			$user_id,
			$reviewed_by,
			'join_request_result',
			'space',
			$space_id,
			/* translators: %s: space name */
			sprintf( __( 'Your request to join %s was not approved', 'jetonomy' ), $name )
		);
	}

	/**
	 * Notify space admins/moderators when a join request is submitted.
	 */
	public function on_join_request( int $space_id, int $user_id, string $message ): void {
		$space      = Space::find( $space_id );
		$space_name = $space ? $space->title : __( 'a space', 'jetonomy' );

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
			// 1.4.0 C.2 fix: build the action URL per-recipient. Space mods
			// without WP admin caps were getting an `admin_url(...)` link that
			// 403'd on click — they own the space but not wp-admin. Front-end
			// space-mod queue at /community/s/:slug/mod/ now carries the same
			// approve / decline UI for them.
			$space_url = $this->build_join_request_url_for( $mod_id, $space );
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

	/**
	 * Per-recipient join-request action URL.
	 *
	 * WP admin / `jetonomy_manage_spaces` cap-holders go to the wp-admin
	 * space-edit `join_requests` tab. Everyone else (space-level admins +
	 * moderators without those caps) gets the front-end space-mod queue.
	 *
	 * @param int         $recipient_id Recipient WP user id.
	 * @param object|null $space        Space row (may be null).
	 */
	private function build_join_request_url_for( int $recipient_id, $space ): string {
		if ( ! $space ) {
			return '';
		}
		if ( user_can( $recipient_id, 'jetonomy_manage_spaces' ) || user_can( $recipient_id, 'manage_options' ) ) {
			return admin_url( 'admin.php?page=jetonomy-spaces&action=edit&space_id=' . (int) $space->id . '&tab=join_requests' );
		}
		return \Jetonomy\base_url() . '/s/' . $space->slug . '/mod/';
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
