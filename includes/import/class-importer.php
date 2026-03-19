<?php
namespace Jetonomy\Import;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\Category;
use Jetonomy\Models\Space;
use Jetonomy\Models\Post;
use Jetonomy\Models\Reply;
use Jetonomy\Models\UserProfile;
use function Jetonomy\now;
use function Jetonomy\table;

abstract class Importer {

	public array $id_map  = [];
	protected array $errors  = [];
	protected int $imported  = 0;
	protected int $skipped   = 0;
	protected bool $dry_run  = false;

	/**
	 * Enable or disable dry-run mode.
	 *
	 * In dry-run mode, no records are written to the database.
	 * Import counts are still incremented to simulate the result.
	 */
	public function set_dry_run( bool $dry_run ): void {
		$this->dry_run = $dry_run;
	}

	abstract public function get_source_name(): string;
	abstract public function is_source_available(): bool;
	abstract public function get_source_stats(): array;
	abstract public function run( array $options = [] ): array;

	/**
	 * Get the total count of records to import.
	 * Used to calculate progress percentage.
	 */
	abstract public function get_total_count(): int;

	/**
	 * Run a single batch of the import.
	 *
	 * @param string $phase   Current phase: 'forums', 'topics', 'replies', 'profiles', 'recount'.
	 * @param int    $offset     Where to start this batch.
	 * @param int    $batch_size How many records per batch.
	 * @return array{phase: string, offset: int, done: bool, processed: int}
	 */
	abstract public function run_batch( string $phase, int $offset, int $batch_size ): array;

	/**
	 * Get the import phases in order.
	 *
	 * @return string[]
	 */
	public function get_phases(): array {
		return [ 'forums', 'topics', 'replies', 'profiles', 'recount' ];
	}

	/**
	 * Save progress to wp_options for polling.
	 */
	public function save_progress( array $progress ): void {
		update_option( 'jetonomy_import_progress', $progress, false );
	}

	/**
	 * Get current import progress.
	 */
	public static function get_progress(): array {
		return get_option( 'jetonomy_import_progress', [
			'status'    => 'idle',
			'phase'     => '',
			'processed' => 0,
			'total'     => 0,
			'percent'   => 0,
			'message'   => '',
		] );
	}

	/**
	 * Clear stored import progress.
	 */
	public static function clear_progress(): void {
		delete_option( 'jetonomy_import_progress' );
	}

	/**
	 * Map an old ID to a new ID.
	 */
	protected function map_id( string $type, $old_id, int $new_id ): void {
		$this->id_map[ $type ][ $old_id ] = $new_id;
	}

	protected function get_mapped_id( string $type, $old_id ): ?int {
		return $this->id_map[ $type ][ $old_id ] ?? null;
	}

	/**
	 * Ensure a user profile exists.
	 */
	protected function ensure_profile( int $user_id ): void {
		UserProfile::find_or_create( $user_id );
	}

	/**
	 * Log an error.
	 */
	protected function log_error( string $type, $id, string $message ): void {
		$this->errors[] = [
			'type'    => $type,
			'id'      => $id,
			'message' => $message,
		];
	}

	/**
	 * Get import results.
	 */
	protected function results(): array {
		return [
			'source'   => $this->get_source_name(),
			'imported' => $this->imported,
			'skipped'  => $this->skipped,
			'errors'   => $this->errors,
			'dry_run'  => $this->dry_run,
		];
	}
}
