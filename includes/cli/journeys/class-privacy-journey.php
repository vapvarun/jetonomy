<?php
/**
 * Privacy journey — inspect and remediate orphaned user data.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Privacy_Backfill;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper over {@see Privacy_Backfill} for the terminal.
 *
 * The 1.8.0 upgrade queues the orphan sweep in the background, which is right
 * for the automatic path but wrong as the ONLY path: an owner answering a
 * regulator, or one who simply wants to know whether the remediation actually
 * ran, cannot see a background job. GDPR remediation you have to take on faith
 * is the same failure that got us here — we told owners the data was gone once
 * already. So the sweep is also drivable on demand, synchronously, and reports
 * what it removed with real numbers.
 *
 * Pure PHP — no WP_CLI calls. Every method returns a {@see Journey_Result}.
 */
final class Privacy_Journey {

	/**
	 * Report orphaned rows per table/column without changing anything.
	 *
	 * The dry run. Answers "what is still in my database from accounts I
	 * already deleted?" — and, run again afterwards, proves it is gone.
	 */
	public function scan_orphans(): Journey_Result {
		$start = microtime( true );

		$report = Privacy_Backfill::count_orphans();
		$total  = array_sum( array_column( $report, 'orphan_rows' ) );

		return Journey_Result::ok(
			[
				'orphan_rows'  => $total,
				'orphan_users' => count( Privacy_Backfill::find_orphans() ),
				'columns'      => $report,
			],
			[ sprintf( '%d orphaned row(s) across %d column(s).', $total, count( $report ) ) ],
			(int) round( ( microtime( true ) - $start ) * 1000 )
		);
	}

	/**
	 * Purge orphaned user data now, and report what was removed.
	 *
	 * Runs to completion rather than one slice: someone at a terminal is
	 * waiting for an answer, and WP-CLI has no request timeout to respect. The
	 * per-slice wall-clock budget still applies inside each pass, so memory and
	 * query size stay bounded exactly as they do in the background worker.
	 *
	 * @param int $max_passes Safety stop so a bug can never spin forever.
	 */
	public function purge_orphans( int $max_passes = 200 ): Journey_Result {
		$start = microtime( true );

		$before = Privacy_Backfill::count_orphans();
		$rows   = array_sum( array_column( $before, 'orphan_rows' ) );

		if ( 0 === $rows ) {
			return Journey_Result::ok(
				[
					'users_purged' => 0,
					'rows_removed' => 0,
				],
				[ 'No orphaned user data found — nothing to purge.' ],
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		}

		$purged = 0;
		$logs   = [ sprintf( 'Found %d orphaned row(s). Purging…', $rows ) ];

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$result  = Privacy_Backfill::run_batch();
			$purged += $result['purged'];

			if ( $result['remaining'] > 0 ) {
				continue;
			}
			// Queue drained — discovery is capped, so only stop once a fresh
			// look finds nothing at all.
			if ( ! Privacy_Backfill::find_orphans( 1 ) ) {
				break;
			}
		}

		// Re-count rather than trusting the loop: this is the number the owner
		// will quote to a regulator, so it comes from the database, not from an
		// accumulator we incremented ourselves.
		$after     = Privacy_Backfill::count_orphans();
		$remaining = array_sum( array_column( $after, 'orphan_rows' ) );

		if ( $remaining > 0 ) {
			return Journey_Result::fail(
				sprintf( '%d orphaned row(s) still present after %d passes.', $remaining, $max_passes ),
				[
					'users_purged'     => $purged,
					'rows_removed'     => $rows - $remaining,
					'rows_remaining'   => $remaining,
					'columns_remaining' => $after,
				],
				$logs,
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		}

		return Journey_Result::ok(
			[
				'users_purged' => $purged,
				'rows_removed' => $rows,
			],
			array_merge( $logs, [ sprintf( 'Removed %d row(s) belonging to %d deleted account(s).', $rows, $purged ) ] ),
			(int) round( ( microtime( true ) - $start ) * 1000 )
		);
	}
}
