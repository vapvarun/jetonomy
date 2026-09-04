<?php
/**
 * wp jetonomy content — inspect and remediate derived content columns.
 *
 * @package Jetonomy
 */

namespace Jetonomy\CLI\Commands;

use Jetonomy\Content_Backfill;

defined( 'ABSPATH' ) || exit;

/**
 * Re-derive the stored plain-text copy of post and reply bodies.
 *
 * Both subcommands drive {@see Content_Backfill}; this class only formats.
 * Deliberately owner-triggered rather than wired to the upgrade routine — see
 * the Content_Backfill docblock for why a full rewrite of the two biggest
 * tables on the site is not something a plugin update should do unannounced.
 */
final class Content_Command extends Base_Command {

	/**
	 * Report how many rows carry a stale content_plain. Changes nothing.
	 *
	 * Stale means the stored plain copy differs from what
	 * jetonomy_content_to_plain() derives from the same body today — which is
	 * every row written before 1.9.5 whose body contains an HTML entity or
	 * more than one block.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Wall-clock budget for this pass. Default 15.
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
	 *     wp jetonomy content scan-plain
	 *     wp jetonomy content scan-plain --format=json
	 *
	 * @subcommand scan-plain
	 */
	public function scan_plain( $args, $assoc ): void {
		$this->report( Content_Backfill::run_batch( false, (float) ( $assoc['seconds'] ?? 15 ) ), $assoc, false );
	}

	/**
	 * Rewrite stale content_plain values from the body they are derived from.
	 *
	 * Non-destructive: `content` is never touched, only the derived copy is
	 * recomputed, so a re-run is a no-op. Resumable: each pass stores its
	 * keyset cursor, so run it until `done` reports true.
	 *
	 * ## OPTIONS
	 *
	 * [--seconds=<seconds>]
	 * : Wall-clock budget for this pass. Default 15.
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
	 *     wp jetonomy content backfill-plain
	 *     wp jetonomy content backfill-plain --seconds=60
	 *
	 * @subcommand backfill-plain
	 */
	public function backfill_plain( $args, $assoc ): void {
		$this->report( Content_Backfill::run_batch( true, (float) ( $assoc['seconds'] ?? 15 ) ), $assoc, true );
	}

	/**
	 * @param array<string,mixed> $result Outcome from Content_Backfill::run_batch().
	 * @param array<string,mixed> $assoc  Associative args from the invocation.
	 * @param bool                $applied Whether the pass wrote.
	 */
	private function report( array $result, array $assoc, bool $applied ): void {
		if ( 'json' === (string) ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::log( (string) wp_json_encode( $result ) );
			return;
		}

		\WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'scanned' => $result['scanned'],
					'stale'   => $result['stale'],
					'fixed'   => $result['fixed'],
					'done'    => $result['done'] ? 'yes' : 'no',
				),
			),
			array( 'scanned', 'stale', 'fixed', 'done' )
		);

		if ( ! $result['done'] ) {
			\WP_CLI::warning( 'Ran out of clock — run the same command again to resume from the stored cursor.' );
			return;
		}

		\WP_CLI::success(
			$applied
				? sprintf( 'Backfill complete. %d row(s) rewritten.', (int) $result['fixed'] )
				: sprintf( 'Scan complete. %d row(s) would be rewritten.', (int) $result['stale'] )
		);
	}
}
