<?php
/**
 * Notification journey — trigger, list, unread count, mark read, test email.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Models\Notification;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper covering every notification interaction available from the
 * CLI: writing a notification row, listing a user's notifications, reading the
 * unread count, flipping a single or all notifications to read, and firing a
 * test email through `wp_mail()`.
 *
 * Pure PHP — no WP-CLI calls, no output side effects. Every method takes a
 * plain assoc array or primitives, delegates to the {@see Notification} model
 * or directly to `wp_mail()`, and returns a {@see Journey_Result}. Commands
 * format the result for the terminal; PHPUnit tests read the same fields and
 * assert on them.
 *
 * The `wp_jt_notifications` schema uses `type varchar(50)` (free-form) so this
 * class validates `type` as a non-empty string rather than enforcing an enum —
 * the production dispatch layer ({@see \Jetonomy\Notifications\Notifier}) emits
 * types like `new_post_in_sub`, `reply_to_post`, `reply_to_reply`, `vote_on_post`,
 * `accepted_answer`, `badge_earned`, `moderation`, `mention`, `join_request`.
 */
final class Notification_Journey {

	/**
	 * Allowed values for the `object_type` ENUM column on `wp_jt_notifications`.
	 *
	 * The schema declares `ENUM('post','reply','space','badge')` so any other
	 * value would fall back to the default (`post`) on write and silently
	 * corrupt the data payload. We validate it up-front instead.
	 */
	private const ALLOWED_OBJECT_TYPES = [ 'post', 'reply', 'space', 'badge' ];

	/**
	 * Create a notification row directly via {@see Notification::create()}.
	 *
	 * The production dispatch layer in {@see \Jetonomy\Notifications\Notifier}
	 * is hook-driven (one private method per `do_action` hook) and has no
	 * public entry point for arbitrary notifications, so the CLI talks to the
	 * model directly — the same path the dispatcher takes internally.
	 *
	 * Required input keys: `type`, `user_id`, `actor_id`, `object_type`,
	 * `object_id`, `message`.
	 *
	 * @param array<string,mixed> $input Notification payload.
	 */
	public function trigger( array $input ): Journey_Result {
		$start = microtime( true );

		$missing = $this->require_keys( $input, [ 'type', 'user_id', 'actor_id', 'object_type', 'object_id', 'message' ] );
		if ( $missing ) {
			return Journey_Result::fail( sprintf( 'Missing required fields: %s', implode( ', ', $missing ) ) );
		}

		$user_id = (int) $input['user_id'];
		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$type = trim( (string) $input['type'] );
		if ( '' === $type ) {
			return Journey_Result::fail( 'type must be a non-empty string.' );
		}

		$object_type = (string) $input['object_type'];
		if ( ! in_array( $object_type, self::ALLOWED_OBJECT_TYPES, true ) ) {
			return Journey_Result::fail( 'object_type must be one of: ' . implode( ', ', self::ALLOWED_OBJECT_TYPES ) . '.' );
		}

		$id = Notification::create(
			[
				'user_id'     => $user_id,
				'actor_id'    => (int) $input['actor_id'],
				'type'        => $type,
				'object_type' => $object_type,
				'object_id'   => (int) $input['object_id'],
				'message'     => (string) $input['message'],
			]
		);

		if ( ! $id ) {
			return Journey_Result::fail( 'Notification::create() returned 0 — insert failed.' );
		}

		return Journey_Result::ok(
			[
				'id'          => (int) $id,
				'user_id'     => $user_id,
				'actor_id'    => (int) $input['actor_id'],
				'type'        => $type,
				'object_type' => $object_type,
				'object_id'   => (int) $input['object_id'],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * List notifications for a user, newest first, shaped for render_list().
	 *
	 * @param int $user_id Recipient user ID.
	 * @param int $limit   Page size (default 20).
	 * @param int $offset  Offset into the result set.
	 */
	public function list_for_user( int $user_id, int $limit = 20, int $offset = 0 ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		if ( $offset < 0 ) {
			$offset = 0;
		}

		$rows  = Notification::list_for_user( $user_id, $limit, $offset );
		$items = [];
		foreach ( $rows as $row ) {
			$items[] = [
				'id'          => (int) $row->id,
				'user_id'     => (int) $row->user_id,
				'actor_id'    => (int) $row->actor_id,
				'type'        => (string) ( $row->type ?? '' ),
				'object_type' => (string) ( $row->object_type ?? '' ),
				'object_id'   => (int) $row->object_id,
				'message'     => (string) ( $row->message ?? '' ),
				'is_read'     => (int) $row->is_read,
				'created_at'  => (string) ( $row->created_at ?? '' ),
			];
		}

		return Journey_Result::ok(
			[
				'items'   => $items,
				'columns' => [ 'id', 'user_id', 'actor_id', 'type', 'object_type', 'object_id', 'message', 'is_read', 'created_at' ],
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return the unread notification count for a user.
	 *
	 * @param int $user_id Recipient user ID.
	 */
	public function unread_count( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$count = Notification::unread_count( $user_id );

		return Journey_Result::ok(
			[
				'user_id' => $user_id,
				'unread'  => (int) $count,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Flip a single notification row to read.
	 *
	 * @param int $notification_id Notification row ID.
	 */
	public function mark_read( int $notification_id ): Journey_Result {
		$start = microtime( true );

		if ( $notification_id <= 0 ) {
			return Journey_Result::fail( 'notification_id must be positive.' );
		}

		$row = Notification::find( $notification_id );
		if ( ! $row ) {
			return Journey_Result::fail( sprintf( 'Notification %d not found.', $notification_id ) );
		}

		Notification::mark_read( $notification_id );

		return Journey_Result::ok(
			[
				'id'      => $notification_id,
				'user_id' => (int) $row->user_id,
				'is_read' => 1,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Flip every unread notification for a user to read.
	 *
	 * {@see Notification::mark_all_read()} is `void`, so we count the unread
	 * rows before the update to report how many rows the caller actually
	 * flipped.
	 *
	 * @param int $user_id Recipient user ID.
	 */
	public function mark_all_read( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$before = Notification::unread_count( $user_id );
		Notification::mark_all_read( $user_id );

		return Journey_Result::ok(
			[
				'user_id'  => $user_id,
				'affected' => (int) $before,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Send a plain test email through {@see wp_mail()} so operators can verify
	 * the site's mail transport. {@see \Jetonomy\Notifications\Notifier} has
	 * no public send-test helper — its surface is entirely `do_action`-driven —
	 * so we invoke `wp_mail()` directly rather than faking a fake hook payload.
	 *
	 * @param int $user_id Recipient user ID.
	 */
	public function test_email( int $user_id ): Journey_Result {
		$start = microtime( true );

		if ( $user_id <= 0 ) {
			return Journey_Result::fail( 'user_id must be positive.' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user || empty( $user->user_email ) ) {
			return Journey_Result::fail( sprintf( 'User %d not found or has no email.', $user_id ) );
		}

		$sent = wp_mail(
			$user->user_email,
			'Jetonomy test email',
			'This is a test email from the Notification journey.'
		);

		return Journey_Result::ok(
			[
				'to'   => (string) $user->user_email,
				'sent' => (bool) $sent,
			],
			[],
			$this->duration_ms( $start )
		);
	}

	/**
	 * Return any required keys that are missing or empty in the input array.
	 *
	 * @param array<string,mixed> $input Input payload.
	 * @param array<int,string>   $keys  Required key names.
	 * @return array<int,string> Missing key names; empty if all present.
	 */
	private function require_keys( array $input, array $keys ): array {
		$missing = [];
		foreach ( $keys as $key ) {
			if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
				$missing[] = $key;
			}
		}
		return $missing;
	}

	/**
	 * Elapsed time in whole milliseconds since the given start (microtime(true)).
	 */
	private function duration_ms( float $start ): int {
		return (int) round( ( microtime( true ) - $start ) * 1000 );
	}
}
