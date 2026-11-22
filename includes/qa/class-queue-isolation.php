<?php
/**
 * QA helper: run a check that needs an empty queue for a real job without losing the site's own queued copies.
 *
 * qa-actions runs on live sites. A check that cancels a real hook (the publisher, the due-time job, a push or lock
 * run) would otherwise drop work the site had queued. The site's pending copies are set aside (each one saved, then
 * cancelled by id, every page of them), the check runs, its own copies are cancelled, and the set-aside copies are
 * queued again at their original time. The saved copies are kept in a non-autoloaded option until they are put back,
 * so a run killed by a fatal error is repaired by the next run: run() puts back whatever an earlier run left aside
 * before it sets anything aside itself.
 *
 * ponytail: a copy the live site queues for the same hook while a check runs (milliseconds) cannot be told apart from
 * the check's own copies and is cancelled with them; isolate per-row by id if a check ever runs for seconds.
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

	/** Non-autoloaded option holding the copies set aside, until they are put back. */
	public const OPTION = 'jetonomy_qa_queue_aside';

	/** Pages of Queue::PENDING_READ_LIMIT copies take() sets aside per hook. */
	private const MAX_PAGES = 50;

	/**
	 * Run $check with these hooks' pending copies set aside, then put them back.
	 *
	 * @since 2.0.8
	 *
	 * @param string[] $hooks Real job hooks the check cancels or counts.
	 * @param callable $check The check.
	 */
	public static function run( array $hooks, callable $check ): void {
		self::restore(); // An earlier run that died before its finally left copies aside.
		try {
			foreach ( $hooks as $hook ) {
				self::take( $hook );
			}
			$check();
		} finally {
			foreach ( $hooks as $hook ) {
				Queue::cancel( $hook );
			}
			self::restore();
		}
	}

	/**
	 * Save and cancel every pending copy of a hook, page by page; the saved list is stored after each page.
	 *
	 * @param string $hook Job hook.
	 */
	private static function take( string $hook ): void {
		$saved = (array) get_option( self::OPTION, array() );
		if ( Queue::has_as() ) {
			$store = \ActionScheduler::store();
			$query = array(
				'hook'     => $hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => Queue::PENDING_READ_LIMIT,
			);
			for ( $page = 0; $page < self::MAX_PAGES; $page++ ) {
				$actions = as_get_scheduled_actions( $query, 'objects' );
				if ( ! $actions ) {
					break;
				}
				foreach ( $actions as $action ) {
					$schedule = $action->get_schedule();
					$date     = $schedule->get_date();
					$every    = $schedule->is_recurring() && method_exists( $schedule, 'get_recurrence' ) ? $schedule->get_recurrence() : 0;
					$saved[]  = array(
						'hook'  => $hook,
						'args'  => $action->get_args(),
						'at'    => $date ? $date->getTimestamp() : 0,
						'every' => is_numeric( $every ) ? (int) $every : 0,
						'cron'  => false,
					);
				}
				update_option( self::OPTION, $saved, false ); // Saved before any is cancelled.
				foreach ( array_keys( $actions ) as $id ) {
					$store->cancel_action( (int) $id );
				}
			}
		}
		foreach ( (array) _get_cron_array() as $at => $events ) {
			foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
				$saved[] = array(
					'hook'  => $hook,
					'args'  => (array) $event['args'],
					'at'    => (int) $at,
					'every' => (string) ( $event['schedule'] ?? '' ),
					'cron'  => true,
				);
			}
		}
		update_option( self::OPTION, $saved, false );
		wp_unschedule_hook( $hook );
	}

	/**
	 * Queue every saved copy again and forget the list.
	 */
	private static function restore(): void {
		$saved = (array) get_option( self::OPTION, array() );
		if ( ! $saved ) {
			return;
		}
		foreach ( $saved as $row ) {
			self::put_back( (string) $row['hook'], $row );
		}
		delete_option( self::OPTION );
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
