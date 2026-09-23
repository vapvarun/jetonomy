<?php
/**
 * One-off 2.0.0 recount of counters that drifted before the write paths were fixed.
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use function Jetonomy\table;

/**
 * Rebuilds drifted counters on an upgrading site, in bounded background slices.
 *
 * Why this exists
 * ---------------
 * 2.0.0 fixes the WRITE paths behind reply counters - the moderation
 * double-count, and a `count_by_post()` that counted every status rather than
 * published replies. Fixing the writes does nothing for numbers already stored:
 * a topic that recorded 3 replies keeps saying 3 while rendering 2, and the
 * author profile counts behind the leaderboard are wrong by the same drift.
 * Nothing surfaces the discrepancy to a site owner, and `wp jetonomy recount`
 * is a CLI command most community owners will never run, so without this an
 * upgraded site carries wrong numbers indefinitely.
 *
 * Why it is not just Recount::run()
 * ---------------------------------
 * That method rewrites entire tables in unbounded set-based UPDATEs. It is the
 * right shape for an operator who typed a command and is watching it, and the
 * wrong shape for a migration: on a 10k-topic forum it would land inside
 * whichever anonymous page load happened to arrive first after the update.
 * This walks the same statements in id-bounded slices instead - the SQL still
 * lives in exactly one place ({@see Recount::run()}), which matters because the
 * status filter is the part that is easy to get wrong and a second copy would
 * be free to drift from the first.
 *
 * Resumability
 * ------------
 * Progress is a stage plus a cursor in one option, written after each slice
 * lands. A batch that dies takes at most its own slice with it: the next run
 * re-enters at the last committed cursor, and re-running a slice is harmless
 * because every statement recomputes from the canonical tables rather than
 * incrementing. Completion is recorded, so a second upgrade pass is a no-op.
 */
final class Recount_Backfill {

	/** Progress + completion record. Survives across requests; this is not a cache. */
	private const OPTION = 'jetonomy_recount_backfill';

	/** One group per plugin, so every Jetonomy job is observable together. */
	private const AS_GROUP = 'jetonomy';

	/** Continuation hook. Re-enqueued until every stage drains. */
	public const BATCH_HOOK = 'jetonomy_recount_backfill_batch';

	/**
	 * Id span covered per slice.
	 *
	 * These are set-based UPDATEs, so a slice costs one statement per counter
	 * regardless of how many rows fall inside the span - the span bounds the
	 * work, not a row loop. Wide enough that a large forum finishes in a
	 * sensible number of batches, narrow enough to stay inside a modest
	 * max_execution_time on shared hosting.
	 */
	private const SPAN = 2000;

	/**
	 * Stages in run order, each mapped to the table whose primary key drives it.
	 *
	 * Ordered so rows are correct before the profile totals that read them.
	 *
	 * @var array<string,array{table:string,key:string}>
	 */
	private const STAGES = [
		'posts'  => [
			'table' => 'posts',
			'key'   => 'id',
		],
		'spaces' => [
			'table' => 'spaces',
			'key'   => 'id',
		],
		'users'  => [
			'table' => 'user_profiles',
			'key'   => 'user_id',
		],
	];

	/**
	 * Wire the runner.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( self::BATCH_HOOK, [ self::class, 'run_batch' ] );

		// Action Scheduler's data store is not ready on plugins_loaded, which is
		// when migrations run - so the migration only records that the work is
		// due (see mark_pending()) and the hand-off happens here, once AS says
		// it can accept a job. Scheduling any earlier is the timing bug the
		// background-jobs standard calls out.
		add_action( 'action_scheduler_init', [ self::class, 'ensure_scheduled' ] );
	}

	/**
	 * Record that an upgrading site owes a recount.
	 *
	 * Called from the 2.0.0 migration. Deliberately does NOT schedule anything:
	 * see register(). A fresh install never runs migrations, so it never marks
	 * this pending - correct, because a new site has no drift to repair.
	 *
	 * @return void
	 */
	public static function mark_pending(): void {
		// An upgrade that crosses 2.0.0 twice (a re-run, a restored backup)
		// must not rewind a job that already finished or is midway through.
		if ( get_option( self::OPTION, null ) !== null ) {
			return;
		}

		update_option(
			self::OPTION,
			[
				'stage'     => array_key_first( self::STAGES ),
				'cursor'    => 0,
				'done'      => false,
				'queued_at' => now(),
			],
			false
		);
	}

	/**
	 * Hand the next slice to Action Scheduler, if one is owed.
	 *
	 * Idempotent: a job already in the queue is left alone rather than stacked.
	 *
	 * @return void
	 */
	public static function ensure_scheduled(): void {
		$state = self::state();

		if ( null === $state || ! empty( $state['done'] ) ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' )
			&& as_has_scheduled_action( self::BATCH_HOOK, [], self::AS_GROUP ) ) {
			return;
		}

		self::enqueue();
	}

	/**
	 * Queue one continuation.
	 *
	 * AS-first; WP-Cron carries it if the store refuses the job. The return
	 * value is load-bearing - as_enqueue_async_action() answers 0 when it
	 * declines, and treating that as a successful hand-off is what strands a
	 * half-finished backfill with nothing scheduled to finish it.
	 *
	 * @return void
	 */
	private static function enqueue(): void {
		if ( function_exists( 'as_enqueue_async_action' )
			&& as_enqueue_async_action( self::BATCH_HOOK, [], self::AS_GROUP ) ) {
			return;
		}

		if ( ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 60, self::BATCH_HOOK );
		}
	}

	/**
	 * One slice: recount a bounded id span, commit the cursor, queue the next.
	 *
	 * @return void
	 */
	public static function run_batch(): void {
		$state = self::state();

		if ( null === $state || ! empty( $state['done'] ) ) {
			return;
		}

		$stage = (string) ( $state['stage'] ?? '' );

		if ( ! isset( self::STAGES[ $stage ] ) ) {
			// Unknown stage - a hand-edited option, or a downgrade. Start over
			// rather than spin: the work is idempotent, so a repeat is only cost.
			$stage           = array_key_first( self::STAGES );
			$state['stage']  = $stage;
			$state['cursor'] = 0;
		}

		$cursor = max( 0, (int) ( $state['cursor'] ?? 0 ) );
		$until  = $cursor + self::SPAN;

		// Same statements as the CLI recount, bounded to this span. Half-open,
		// so slices tile the key space and the first one covers key 0.
		Recount::run( $stage, $cursor, $until );

		$max = self::max_key( $stage );

		// $until is exclusive, so the stage is only finished once it passes $max.
		if ( $until > $max ) {
			$stage = self::next_stage( $stage );

			if ( null === $stage ) {
				self::complete( $state );
				return;
			}

			$state['stage']  = $stage;
			$state['cursor'] = 0;
		} else {
			$state['cursor'] = $until;
		}

		update_option( self::OPTION, $state, false );

		self::enqueue();
	}

	/**
	 * Record completion and drop the caches the recount invalidated.
	 *
	 * @param array<string,mixed> $state Current progress record.
	 * @return void
	 */
	private static function complete( array $state ): void {
		$state['done']         = true;
		$state['cursor']       = 0;
		$state['completed_at'] = now();

		update_option( self::OPTION, $state, false );

		// Held until now rather than run per slice: the counters written here
		// back the space:{id} and profile:{id} caches, and flushing the group
		// on every batch would keep a large site cold for the whole job.
		Cache::flush();

		/**
		 * Fires once the 2.0.0 counter backfill has finished.
		 *
		 * @since 2.0.0
		 */
		do_action( 'jetonomy_recount_backfill_complete' );
	}

	/**
	 * Highest key value a stage has to reach.
	 *
	 * @param string $stage Stage id.
	 * @return int
	 */
	private static function max_key( string $stage ): int {
		global $wpdb;

		$spec = self::STAGES[ $stage ] ?? null;

		if ( null === $spec ) {
			return 0;
		}

		$t   = table( $spec['table'] );
		$key = $spec['key'];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table() is a trusted prefixed name; $key is from the STAGES constant.
		return (int) $wpdb->get_var( "SELECT MAX({$key}) FROM {$t}" );
	}

	/**
	 * Stage that follows this one, or null when the last is done.
	 *
	 * @param string $stage Current stage id.
	 * @return string|null
	 */
	private static function next_stage( string $stage ): ?string {
		$stages = array_keys( self::STAGES );
		$at     = array_search( $stage, $stages, true );

		if ( false === $at || ! isset( $stages[ $at + 1 ] ) ) {
			return null;
		}

		return $stages[ $at + 1 ];
	}

	/**
	 * Current progress record, or null when nothing is owed.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::OPTION, null );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Has the backfill finished on this site?
	 *
	 * @return bool
	 */
	public static function is_complete(): bool {
		$state = self::state();

		return null !== $state && ! empty( $state['done'] );
	}

	/**
	 * Drop any queued continuation. Called on deactivation.
	 *
	 * @return void
	 */
	public static function clear_scheduled(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::BATCH_HOOK, [], self::AS_GROUP );
		}

		wp_clear_scheduled_hook( self::BATCH_HOOK );
	}
}
