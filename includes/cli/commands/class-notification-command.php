<?php
/**
 * wp jetonomy notification — notification CRUD via Notification_Journey.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\CLI\Journeys\Notification_Journey;

defined( 'ABSPATH' ) || exit;

/**
 * Manage Jetonomy notifications from the terminal.
 *
 * All subcommands delegate to {@see Notification_Journey} for the actual work
 * so PHPUnit tests can assert on the same code path the CLI runs. The `--to`
 * flag (not `--user`) is used for the recipient user ID because `--user` is a
 * reserved WP-CLI global that gets stripped before reaching commands.
 */
final class Notification_Command extends Base_Command {

	/**
	 * Write a notification row directly (bypasses the hook-driven dispatcher).
	 *
	 * ## OPTIONS
	 *
	 * --type=<type>
	 * : Notification type slug (free-form varchar(50)). Examples: reply_to_post,
	 *   new_post_in_sub, vote_on_post, accepted_answer, badge_earned, mention,
	 *   moderation, join_request.
	 *
	 * --to=<user_id>
	 * : Recipient user ID.
	 *
	 * --actor=<user_id>
	 * : Acting user ID (use 0 for system notifications).
	 *
	 * --object-type=<type>
	 * : Type of the object the notification points at.
	 * ---
	 * options:
	 *   - post
	 *   - reply
	 *   - space
	 *   - badge
	 * ---
	 *
	 * --object-id=<id>
	 * : ID of the object the notification points at.
	 *
	 * --message=<text>
	 * : Human-readable notification message.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification trigger --type=reply_to_post --to=1 --actor=2 --object-type=post --object-id=5 --message="Someone replied to your post"
	 */
	public function trigger( $args, $assoc ): void {
		$result = ( new Notification_Journey() )->trigger(
			[
				'type'        => (string) ( $assoc['type'] ?? '' ),
				'user_id'     => (int) ( $assoc['to'] ?? 0 ),
				'actor_id'    => (int) ( $assoc['actor'] ?? 0 ),
				'object_type' => (string) ( $assoc['object-type'] ?? '' ),
				'object_id'   => (int) ( $assoc['object-id'] ?? 0 ),
				'message'     => (string) ( $assoc['message'] ?? '' ),
			]
		);
		$this->render( $result, $assoc );
	}

	/**
	 * List notifications for a user, newest first.
	 *
	 * ## OPTIONS
	 *
	 * --to=<user_id>
	 * : Recipient user ID.
	 *
	 * [--limit=<n>]
	 * : Page size.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--offset=<n>]
	 * : Offset into the result set.
	 * ---
	 * default: 0
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Limit output to these columns.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification list --to=1
	 *     wp jetonomy notification list --to=1 --limit=5 --format=json
	 */
	public function list( $args, $assoc ): void {
		$result = ( new Notification_Journey() )->list_for_user(
			(int) ( $assoc['to'] ?? 0 ),
			(int) ( $assoc['limit'] ?? 20 ),
			(int) ( $assoc['offset'] ?? 0 )
		);
		$this->render_list( $result, $assoc );
	}

	/**
	 * Return the unread notification count for a user.
	 *
	 * ## OPTIONS
	 *
	 * --to=<user_id>
	 * : Recipient user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification unread-count --to=1
	 *
	 * @subcommand unread-count
	 */
	public function unread_count( $args, $assoc ): void {
		$result = ( new Notification_Journey() )->unread_count(
			(int) ( $assoc['to'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Flip a single notification to read.
	 *
	 * ## OPTIONS
	 *
	 * <notification_id>
	 * : Notification row ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification mark-read 42
	 *
	 * @subcommand mark-read
	 */
	public function mark_read( $args, $assoc ): void {
		$id     = (int) ( $args[0] ?? 0 );
		$result = ( new Notification_Journey() )->mark_read( $id );
		$this->render( $result, $assoc );
	}

	/**
	 * Flip every unread notification for a user to read.
	 *
	 * ## OPTIONS
	 *
	 * --to=<user_id>
	 * : Recipient user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification mark-all-read --to=1
	 *
	 * @subcommand mark-all-read
	 */
	public function mark_all_read( $args, $assoc ): void {
		$result = ( new Notification_Journey() )->mark_all_read(
			(int) ( $assoc['to'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}

	/**
	 * Send a plain test email to a user via wp_mail().
	 *
	 * ## OPTIONS
	 *
	 * --to=<user_id>
	 * : Recipient user ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *     wp jetonomy notification test-email --to=1
	 *
	 * @subcommand test-email
	 */
	public function test_email( $args, $assoc ): void {
		$result = ( new Notification_Journey() )->test_email(
			(int) ( $assoc['to'] ?? 0 )
		);
		$this->render( $result, $assoc );
	}
}
