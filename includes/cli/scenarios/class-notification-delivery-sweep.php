<?php
/**
 * Scenario: fire one notification of every supported type to user 1.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Scenarios;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\CLI\Journeys\Notification_Journey;
use Jetonomy\Models\Notification;

defined( 'ABSPATH' ) || exit;

/**
 * Seeds one notification of every production type against user 1 and verifies
 * the unread count delta, so QA can smoke-test the notification row writer
 * plus any downstream consumers (bell dropdown, email dispatch) without
 * having to trigger each notification organically.
 *
 * Object types are chosen to satisfy the `wp_jt_notifications.object_type`
 * ENUM ('post','reply','space','badge'): `badge_earned` → badge, `join_request`
 * → space, everything else → post.
 */
final class Notification_Delivery_Sweep extends Abstract_Scenario {

	/**
	 * Notification types paired with the object_type enum value the schema
	 * requires for each. The map matches the production dispatcher's usage in
	 * {@see \Jetonomy\Notifications\Notifier}.
	 *
	 * @var array<int,array{type:string,object_type:string}>
	 */
	private const TYPES = array(
		array(
			'type'        => 'reply_to_post',
			'object_type' => 'post',
		),
		array(
			'type'        => 'reply_to_reply',
			'object_type' => 'post',
		),
		array(
			'type'        => 'mention',
			'object_type' => 'post',
		),
		array(
			'type'        => 'vote_on_post',
			'object_type' => 'post',
		),
		array(
			'type'        => 'accepted_answer',
			'object_type' => 'post',
		),
		array(
			'type'        => 'new_post_in_sub',
			'object_type' => 'post',
		),
		array(
			'type'        => 'badge_earned',
			'object_type' => 'badge',
		),
		array(
			'type'        => 'moderation',
			'object_type' => 'post',
		),
		array(
			'type'        => 'join_request',
			'object_type' => 'space',
		),
	);

	public static function name(): string {
		return 'notification-delivery-sweep';
	}

	public static function description(): string {
		return 'Fires one notification of every supported type to user 1 and reports the unread-count delta.';
	}

	public function run( array $options = array() ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		$journey = new Notification_Journey();

		$fixtures = array(
			'notification_ids' => array(),
			'unread_before'    => 0,
			'unread_after'     => 0,
			'unread_delta'     => 0,
		);

		$before = $this->step(
			'unread-count-before',
			static fn (): Journey_Result => $journey->unread_count( 1 )
		);
		if ( null === $before ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['unread_before'] = (int) $before->data['unread'];

		$ids = array();
		foreach ( self::TYPES as $idx => $entry ) {
			$type        = $entry['type'];
			$object_type = $entry['object_type'];
			$result      = $this->step(
				sprintf( 'trigger-%s', $type ),
				static fn (): Journey_Result => $journey->trigger(
					array(
						'type'        => $type,
						'user_id'     => 1,
						'actor_id'    => 1,
						'object_type' => $object_type,
						'object_id'   => 1,
						'message'     => sprintf( 'Scenario sweep: %s', $type ),
					)
				)
			);
			if ( null === $result ) {
				$fixtures['notification_ids'] = $ids;
				return $this->finalize( $fixtures, $start );
			}
			$ids[] = (int) $result->data['id'];
		}
		$fixtures['notification_ids'] = $ids;

		$after = $this->step(
			'unread-count-after',
			static fn (): Journey_Result => $journey->unread_count( 1 )
		);
		if ( null === $after ) {
			return $this->finalize( $fixtures, $start );
		}
		$fixtures['unread_after'] = (int) $after->data['unread'];
		$fixtures['unread_delta'] = $fixtures['unread_after'] - $fixtures['unread_before'];

		return $this->finalize( $fixtures, $start );
	}

	public function cleanup( array $fixtures ): Scenario_Result {
		$this->reset();
		$start = microtime( true );

		// Remove the rows run() created, not just mark them read: cleanup must
		// leave user 1's notification list as it found it.
		$ids = array_values( array_filter( array_map( 'intval', (array) ( $fixtures['notification_ids'] ?? array() ) ) ) );
		if ( $ids ) {
			$this->step(
				'delete-notifications',
				static function () use ( $ids ): Journey_Result {
					$deleted = Notification::delete_for_user( 1, $ids );
					return $deleted === count( $ids )
						? Journey_Result::ok( array( 'deleted' => $deleted ) )
						: Journey_Result::fail( sprintf( 'Deleted %1$d of %2$d scenario notifications.', $deleted, count( $ids ) ) );
				}
			);
		}

		return $this->finalize( $fixtures, $start );
	}
}
