<?php
/**
 * Space orphan journey — inspect and remediate rows left by deleted spaces.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Journeys;

use Jetonomy\CLI\Journey_Result;
use Jetonomy\Space_Backfill;

defined( 'ABSPATH' ) || exit;

/**
 * Journey wrapper over {@see Space_Backfill} for the terminal.
 *
 * Deliberately the same shape as {@see Privacy_Journey}: scan is a dry run that
 * answers "what is still in my database from spaces I already deleted?", and
 * purge removes it and proves the removal by re-counting from the database
 * rather than from an accumulator we incremented ourselves.
 *
 * Pure PHP — no WP_CLI calls. Every method returns a {@see Journey_Result}.
 */
final class Space_Orphan_Journey {

	/**
	 * Report orphaned rows per table/column without changing anything.
	 */
	public function scan_orphans(): Journey_Result {
		$start = microtime( true );

		$report = Space_Backfill::count_orphans();
		$total  = array_sum( array_column( $report, 'orphan_rows' ) );

		return Journey_Result::ok(
			[
				'orphan_rows'   => $total,
				'orphan_spaces' => count( Space_Backfill::find_orphans() ),
				'columns'       => $report,
			],
			[ sprintf( '%d orphaned row(s) across %d column(s).', $total, count( $report ) ) ],
			(int) round( ( microtime( true ) - $start ) * 1000 )
		);
	}

	/**
	 * Purge orphaned space data now, and report what was removed.
	 *
	 * Runs to completion rather than one slice — someone at a terminal is
	 * waiting, and WP-CLI has no request timeout to respect. The per-slice
	 * wall-clock budget still applies inside each pass, so query size stays
	 * bounded exactly as it would in a background worker.
	 *
	 * Note the counts move differently from the privacy sweep: purging one
	 * orphaned space removes rows across many tables, and discovery only ever
	 * sees the space-referencing ones. So `rows_removed` counts what the
	 * discovery report could see, and the post-run recount is what proves the
	 * database is clean.
	 *
	 * @param int $max_passes Safety stop so a bug can never spin forever.
	 */
	public function purge_orphans( int $max_passes = 200 ): Journey_Result {
		$start = microtime( true );

		$before = Space_Backfill::count_orphans();
		$rows   = array_sum( array_column( $before, 'orphan_rows' ) );

		if ( 0 === $rows ) {
			return Journey_Result::ok(
				[
					'spaces_purged' => 0,
					'rows_removed'  => 0,
				],
				[ 'No orphaned space data found — nothing to purge.' ],
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		}

		$purged = 0;
		$logs   = [ sprintf( 'Found %d orphaned row(s). Purging…', $rows ) ];

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			$result  = Space_Backfill::run_batch();
			$purged += $result['purged'];

			if ( $result['remaining'] > 0 ) {
				continue;
			}
			// Queue drained — discovery is capped, so only stop once a fresh
			// look finds nothing at all.
			if ( ! Space_Backfill::find_orphans( 1 ) ) {
				break;
			}
		}

		$after     = Space_Backfill::count_orphans();
		$remaining = array_sum( array_column( $after, 'orphan_rows' ) );

		if ( $remaining > 0 ) {
			return Journey_Result::fail(
				sprintf( '%d orphaned row(s) still present after %d passes.', $remaining, $max_passes ),
				[
					'spaces_purged'     => $purged,
					'rows_removed'      => $rows - $remaining,
					'rows_remaining'    => $remaining,
					'columns_remaining' => $after,
				],
				$logs,
				(int) round( ( microtime( true ) - $start ) * 1000 )
			);
		}

		return Journey_Result::ok(
			[
				'spaces_purged' => $purged,
				'rows_removed'  => $rows,
			],
			array_merge( $logs, [ sprintf( 'Purged %d orphaned space(s); database reports 0 remaining.', $purged ) ] ),
			(int) round( ( microtime( true ) - $start ) * 1000 )
		);
	}
}
