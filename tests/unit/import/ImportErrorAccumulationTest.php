<?php
namespace Jetonomy\Tests\Unit\Import;

use WP_UnitTestCase;
use Jetonomy\Import\Importer;

/**
 * The skipped-file surfacing contract the import AJAX handler relies on.
 *
 * The handler runs a FRESH importer per batch and does:
 *     $import_errors['count'] += count( $importer->get_errors() );
 * accumulating into the jetonomy_import_errors option. That is correct only if
 * get_errors() returns THIS batch's errors and nothing carried over — otherwise a
 * long-lived importer would re-report earlier batches' errors and the customer's
 * "skipped" count would balloon (double-count). A green "Import complete!" that
 * hid skipped files is the exact bug this surfacing exists to prevent.
 *
 * These are pure-unit tests of that per-instance error API (no DB, no admin-ajax
 * bootstrap) plus a faithful replay of the handler's accumulation to prove the
 * total is the true sum across fresh instances.
 */
class ImportErrorAccumulationTest extends WP_UnitTestCase {

	private function importer(): Importer {
		return new class() extends Importer {
			public function get_source_name(): string {
				return 'double'; }
			public function is_source_available(): bool {
				return true; }
			public function get_source_stats(): array {
				return array(); }
			public function get_total_count(): int {
				return 0; }
			public function run( array $options = array() ): array {
				return array(); }
			public function run_batch( string $phase, int $offset, int $batch_size ): array {
				return array( 'phase' => $phase, 'offset' => $offset, 'done' => true, 'processed' => 0 ); }
			/** Simulate a batch skipping $n unrecoverable files. */
			public function skip_files( int $n ): void {
				for ( $i = 0; $i < $n; $i++ ) {
					$this->log_error( 'attachment', "file-{$i}", 'Disallowed file type' );
				}
			}
		};
	}

	public function test_fresh_importer_reports_no_errors(): void {
		$this->assertSame( array(), $this->importer()->get_errors() );
	}

	public function test_get_errors_returns_only_this_instances_errors(): void {
		$a = $this->importer();
		$a->skip_files( 2 );
		$this->assertCount( 2, $a->get_errors() );

		// A second batch is a NEW importer — it must start empty, not inherit batch one.
		$b = $this->importer();
		$this->assertCount( 0, $b->get_errors(), 'A fresh importer must not carry a previous batch\'s errors.' );
		$b->skip_files( 3 );
		$this->assertCount( 3, $b->get_errors() );

		// Batch one is unchanged by batch two.
		$this->assertCount( 2, $a->get_errors() );
	}

	public function test_error_records_carry_type_id_and_message(): void {
		$imp = $this->importer();
		$imp->skip_files( 1 );
		$err = $imp->get_errors()[0];
		$this->assertSame( 'attachment', $err['type'] );
		$this->assertArrayHasKey( 'id', $err );
		$this->assertStringContainsString( 'Disallowed', $err['message'] );
	}

	/**
	 * The handler's accumulation, replayed against real per-instance importers:
	 * count is the TRUE total across batches, with no double-count.
	 */
	public function test_accumulation_across_fresh_batches_is_the_true_total(): void {
		$accumulated = array( 'count' => 0, 'sample' => array() );

		foreach ( array( 2, 0, 3 ) as $skips_this_batch ) {
			$importer     = $this->importer(); // fresh per batch, exactly as the handler does
			$importer->skip_files( $skips_this_batch );
			$batch_errors = $importer->get_errors();

			if ( $batch_errors ) {
				$accumulated['count'] += count( $batch_errors );
				$room = 50 - count( $accumulated['sample'] );
				if ( $room > 0 ) {
					$accumulated['sample'] = array_merge( $accumulated['sample'], array_slice( $batch_errors, 0, $room ) );
				}
			}
		}

		$this->assertSame( 5, $accumulated['count'], 'skipped = 2 + 0 + 3, counted once each.' );
		$this->assertCount( 5, $accumulated['sample'] );
	}

	public function test_reset_run_state_clears_the_accumulated_errors_option(): void {
		update_option( 'jetonomy_import_errors', array( 'count' => 9, 'sample' => array( 'x' ) ) );
		$this->importer()->reset_run_state();
		$this->assertFalse( get_option( 'jetonomy_import_errors' ), 'A fresh run must wipe the previous run\'s skipped-file tally.' );
	}
}
