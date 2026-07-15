<?php
/**
 * Admin AJAX handler — import.
 *
 * @package Jetonomy
 */

namespace Jetonomy\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Import\Import_Manager;

class Import_Handler {

	public function __construct() {
		// Batched import is the only client flow; the legacy single-shot
		// run_import and progress-poll actions had no JS caller anywhere
		// (the "kept for CLI" claim was wrong — CLI calls
		// Import_Manager::run() directly) and were removed in 1.5.0
		// (audit C).
		add_action( 'wp_ajax_jetonomy_import_batch', [ $this, 'ajax_import_batch' ] );
	}

	/**
	 * AJAX: Run a single import batch (500 records) and return progress.
	 */
	public function ajax_import_batch(): void {
		check_ajax_referer( 'jetonomy_admin', 'nonce' );
		if ( ! current_user_can( 'jetonomy_manage_settings' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'jetonomy' ) );
		}

		$source     = sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) );
		$phase      = sanitize_text_field( wp_unslash( $_POST['phase'] ?? 'forums' ) );
		$offset     = absint( $_POST['offset'] ?? 0 );
		$batch_size = absint( $_POST['batch_size'] ?? 500 );
		// Set only on the first batch of a fresh/restart run (never on a resume or
		// a mid-run board hand-off, which also arrive as phase=forums/offset=0).
		$new_run = (bool) absint( wp_unslash( $_POST['new_run'] ?? 0 ) );

		Import_Manager::init();
		$importers = Import_Manager::get_importers();

		if ( ! isset( $importers[ $source ] ) ) {
			wp_send_json_error( __( 'Unknown import source.', 'jetonomy' ) );
		}

		$importer = $importers[ $source ];

		// A fresh run clears whatever a previous (possibly aborted) import left
		// behind — a stale id_map, a non-zero processed counter, cached board
		// state. Without this the next import inherits them and either mis-resolves
		// parents (the id_map's 'forum'/'topic' keys are shared across sources) or
		// reports a wrong progress %.
		if ( $new_run ) {
			$importer->reset_run_state();
		}

		// Restore ID map from previous batch (empty after a reset above).
		$importer->id_map = get_option( 'jetonomy_import_id_map', [] );

		// Save resume point so the import can be resumed if interrupted.
		$existing_resume = get_option( 'jetonomy_import_resume', [] );
		update_option(
			'jetonomy_import_resume',
			[
				'source'     => $source,
				'phase'      => $phase,
				'offset'     => $offset,
				'batch_size' => $batch_size,
				'started_at' => $new_run ? current_time( 'mysql' ) : ( $existing_resume['started_at'] ?? current_time( 'mysql' ) ),
			],
			false
		);

		// Run one batch.
		$result = $importer->run_batch( $phase, $offset, $batch_size );

		// Calculate overall progress.
		$total           = $importer->get_total_count();
		$total_processed = absint( get_option( 'jetonomy_import_total_processed', 0 ) ) + $result['processed'];
		update_option( 'jetonomy_import_total_processed', $total_processed, false );

		$percent = $total > 0 ? min( 100, round( ( $total_processed / $total ) * 100, 1 ) ) : 0;

		$phase_labels = [
			'forums'   => __( 'Importing forums...', 'jetonomy' ),
			'topics'   => __( 'Importing topics...', 'jetonomy' ),
			'replies'  => __( 'Importing replies...', 'jetonomy' ),
			'profiles' => __( 'Creating user profiles...', 'jetonomy' ),
			'recount'  => __( 'Recounting statistics...', 'jetonomy' ),
			'complete' => __( 'Import complete!', 'jetonomy' ),
		];

		// Save progress for polling endpoint.
		$importer->save_progress(
			[
				'status'    => $result['done'] ? 'complete' : 'running',
				'phase'     => $result['phase'],
				'processed' => $total_processed,
				'total'     => $total,
				'percent'   => $percent,
				'message'   => $phase_labels[ $result['phase'] ] ?? '',
			]
		);

		if ( $result['done'] ) {
			// Save completion record to import history.
			$history            = get_option( 'jetonomy_import_history', [] );
			$history[ $source ] = [
				'completed_at' => current_time( 'mysql' ),
				'imported'     => $total_processed,
				'source'       => $source,
				'source_name'  => $importers[ $source ]->get_source_name(),
			];
			update_option( 'jetonomy_import_history', $history, false );

			// Clear transient state.
			delete_option( 'jetonomy_import_resume' );
			delete_option( 'jetonomy_import_total_processed' );
			delete_option( 'jetonomy_import_id_map' );
			\Jetonomy\Import\Importer::clear_progress();
		}

		wp_send_json_success(
			[
				'phase'     => $result['phase'],
				'offset'    => $result['offset'],
				'done'      => $result['done'],
				'processed' => $total_processed,
				'total'     => $total,
				'percent'   => $percent,
				'message'   => $phase_labels[ $result['phase'] ] ?? '',
			]
		);
	}
}
