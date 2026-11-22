<?php
/**
 * QA helper: run a check that needs an empty queue for a real job without losing the site's own queued copies.
 *
 * qa-actions runs on live sites. A check that cancels a real hook (the publisher, the due-time job, a push or lock
 * run) would otherwise drop work the site had queued. The site's pending copies are set aside, the check runs, its
 * own copies are cancelled, and the set-aside copies are queued again at their original time.
 *
 * @package Jetonomy
 * @since   2.0.8
 */

namespace Jetonomy\QA;

use Jetonomy\Queue;

defined( 'ABSPATH' ) || exit;

/**
 * Sets a hook's real pending jobs aside around a check.
 */
final class Queue_Isolation {

	/**
	 * Run $check with these hooks' pending copies set aside, then put them back.
	 *
	 * @since 2.0.8
	 *
	 * @param string[] $hooks Real job hooks the check cancels or counts.
	 * @param callable $check The check.
	 */
	public static function run( array $hooks, callable $check ): void {
		$saved = array();
		foreach ( $hooks as $hook ) {
			$saved[ $hook ] = self::take( $hook );
		}
		try {
			$check();
		} finally {
			foreach ( $saved as $hook => $rows ) {
				Queue::cancel( $hook );
				foreach ( $rows as $row ) {
					self::put_back( $hook, $row );
				}
			}
		}
	}

	/**
	 * Read and cancel a hook's pending copies (up to Queue::PENDING_READ_LIMIT in Action Scheduler).
	 *
	 * @param string $hook Job hook.
	 * @return array[] One { args, at, every, cron } entry per copy.
	 */
	private static function take( string $hook ): array {
		$rows = array();
		if ( Queue::has_as() ) {
			$query = array(
				'hook'     => $hook,
				'status'   => 'pending',
				'per_page' => Queue::PENDING_READ_LIMIT,
			);
			foreach ( as_get_scheduled_actions( $query, 'objects' ) as $action ) {
				$schedule = $action->get_schedule();
				$date     = $schedule->get_date();
				$every    = $schedule->is_recurring() && method_exists( $schedule, 'get_recurrence' ) ? $schedule->get_recurrence() : 0;
				$rows[]   = array(
					'args'  => $action->get_args(),
					'at'    => $date ? $date->getTimestamp() : 0,
					'every' => is_numeric( $every ) ? (int) $every : 0,
					'cron'  => false,
				);
			}
		}
		foreach ( (array) _get_cron_array() as $at => $events ) {
			foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
				$rows[] = array(
					'args'  => (array) $event['args'],
					'at'    => (int) $at,
					'every' => (string) ( $event['schedule'] ?? '' ),
					'cron'  => true,
				);
			}
		}
		Queue::cancel( $hook );
		return $rows;
	}

	/**
	 * Queue one set-aside copy again, in the scheduler it came from.
	 *
	 * @param string $hook Job hook.
	 * @param array  $row  Entry from take().
	 */
	private static function put_back( string $hook, array $row ): void {
		if ( $row['cron'] ) {
			if ( $row['every'] ) {
				wp_schedule_event( $row['at'], $row['every'], $hook, $row['args'] );
			} else {
				wp_schedule_single_event( $row['at'], $hook, $row['args'] );
			}
			return;
		}
		if ( $row['every'] > 0 ) {
			as_schedule_recurring_action( max( $row['at'], time() ), $row['every'], $hook, $row['args'], Queue::GROUP );
		} elseif ( $row['at'] > 0 ) {
			as_schedule_single_action( $row['at'], $hook, $row['args'], Queue::GROUP );
		} else {
			as_enqueue_async_action( $hook, $row['args'], Queue::GROUP );
		}
	}
}
