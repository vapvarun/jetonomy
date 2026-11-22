<?php
/**
 * Scheduled tasks (cron).
 *
 * @package Jetonomy
 */

namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

use Jetonomy\Models\UserProfile;
use Jetonomy\Notifications\Verification_Reminder;
use Jetonomy\Trust\Trust_Evaluator;
use Jetonomy\Trust\Trust_Levels;

class Cron {

	/**
	 * Scheduled-topic publisher. Not recurring: one single action armed at the
	 * earliest pending published_at and re-armed after every run (background
	 * jobs standard, pattern 3). Publishes on time and costs nothing on sites with
	 * nothing scheduled.
	 */
	public const PUBLISH_HOOK = 'jetonomy_publish_scheduled';

	/**
	 * Set while the publisher runs so each publish does not re-arm on its own.
	 *
	 * @var bool
	 */
	private static bool $publishing = false;

	/** Retries of a publisher run that failed to publish a due topic, 1, 2 then 4 minutes apart. */
	private const PUBLISH_MAX_RETRIES = 3;

	/** Rows a batch-limited job handles per pass; the default of the jetonomy_cron_batch_size filter. */
	public const BATCH_SIZE = 500;

	/**
	 * Expired restrictions, verification reminders and the roster backstop, in one single action armed at the
	 * earliest moment one of them is due ({@see arm_due_jobs()}). They used to tick hourly each, 72 actions a day on a
	 * site with nothing to do.
	 */
	public const DUE_HOOK = 'jetonomy_due_jobs';

	/** The hourly jobs DUE_HOOK replaced (2.0.8); cancelled on upgrade. */
	public const RETIRED_HOOKS = array( 'jetonomy_cleanup_expired', 'jetonomy_verification_reminder', 'jetonomy_reconcile_rosters' );

	/** Keyset cursor (last processed user_id) for the batched trust sweep. */
	private const TRUST_CURSOR_OPTION = 'jetonomy_trust_eval_cursor';

	/**
	 * Recurring actions Jetonomy schedules. hook => interval (seconds).
	 *
	 * Run via Action Scheduler so background work doesn't drop on quiet sites
	 * (WP-Cron only fires on page views — bad for trust evaluation, ban expiry,
	 * activity pruning, scheduled-post publishing at the scale targets).
	 */
	private const RECURRING = array(
		'jetonomy_trust_evaluation'      => 12 * HOUR_IN_SECONDS,
		'jetonomy_prune_activity'        => DAY_IN_SECONDS,
		'jetonomy_cleanup_notifications' => WEEK_IN_SECONDS,
		// Abandoned composer uploads. Lived only in Pro's attachments
		// extension, while the upload endpoint that creates these rows is in
		// free (Media_Controller -> Media_Library::tag_upload), so a free-only
		// site accumulated them forever with nothing to clean up.
		'jetonomy_cleanup_media'         => DAY_IN_SECONDS,
	);

	public function __construct() {
		// Handler listeners — fire whether scheduled via AS or (legacy) WP-Cron.
		add_action( 'jetonomy_trust_evaluation', array( $this, 'evaluate_trust_levels' ) );
		add_action( 'jetonomy_trust_evaluation_batch', array( $this, 'evaluate_trust_batch' ) );
		add_action( self::DUE_HOOK, array( $this, 'run_due_jobs' ) );
		add_action( 'jetonomy_prune_activity', array( $this, 'prune_activity_log' ) );
		add_action( 'jetonomy_cleanup_notifications', array( $this, 'cleanup_old_notifications' ) );
		add_action( 'jetonomy_publish_scheduled', array( $this, 'publish_scheduled_posts' ) ); // A retry passes its attempt number.
		add_action( 'jetonomy_cleanup_media', array( \Jetonomy\Media_Library::class, 'run_scheduled_cleanup' ) );

		// Self-heal: ensure every recurring action is registered. We hook the
		// 'action_scheduler_init' action AS fires once its data store is ready
		// (not 'init', which can run before AS's data store is wired up and
		// would emit "called incorrectly" notices on every page load).
		add_action( 'action_scheduler_init', array( self::class, 'ensure_scheduled' ) );
	}

	/**
	 * Schedule all cron events on plugin activation.
	 *
	 * During activation the plugins_loaded hook hasn't fired, so Action Scheduler
	 * may not yet have declared its global functions. ensure_scheduled() guards
	 * for that — anything not scheduled here will be picked up on the next init.
	 */
	public static function schedule(): void {
		self::ensure_scheduled();
	}

	/**
	 * Unschedule all cron events on plugin deactivation.
	 *
	 * Clears both AS-scheduled actions (current) and any legacy WP-Cron entries
	 * (idempotent — safe to call regardless of which scheduler the install was on).
	 */
	public static function unschedule(): void {
		// The recurring jobs plus the one-off ones: trust-sweep batches, per-member
		// Gravatar checks, the scheduled-topic publisher, the per-space roster sweep and the
		// subscriber fan-out batches.
		$hooks = array_merge( array_keys( self::RECURRING ), self::RETIRED_HOOKS, array( self::DUE_HOOK, 'jetonomy_trust_evaluation_batch', Avatar::CHECK_HOOK, self::PUBLISH_HOOK, Membership_Roster_Sync::SWEEP_HOOK, 'jetonomy_fanout_post_subscribers', 'jetonomy_fanout_reply_subscribers' ) );
		foreach ( $hooks as $hook ) {
			Queue::cancel( $hook );
		}
		delete_option( self::TRUST_CURSOR_OPTION );
	}

	/**
	 * Is this a request where recurring schedules should be checked and armed?
	 *
	 * Each check is a database lookup (as_has_scheduled_action, plus the one-time
	 * migration flags), and running them on every page, REST call and heartbeat
	 * made them part of every request's query floor. Check only in the cron runner
	 * (which re-arms a lost job within one tick), wp-admin page loads and WP-CLI.
	 * See docs/standards/background-jobs.md.
	 *
	 * @return bool
	 */
	public static function is_scheduling_request(): bool {
		return Queue::is_scheduling_request();
	}

	/**
	 * Idempotent scheduler — ensures every recurring action exists in AS exactly
	 * once. On first run after upgrade from <1.4.4, also wipes the legacy WP-Cron
	 * registrations so the same handler doesn't fire from two schedulers.
	 */
	public static function ensure_scheduled(): void {
		if ( ! self::is_scheduling_request() ) {
			return;
		}
		if ( ! function_exists( 'as_schedule_recurring_action' ) ) {
			return; // AS not booted yet (e.g. activation request before plugins_loaded). Retry next request.
		}

		// One-time migration: drop legacy WP-Cron registrations so AS owns these hooks.
		if ( ! get_option( 'jetonomy_cron_as_migrated' ) ) {
			foreach ( array_keys( self::RECURRING ) as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			update_option( 'jetonomy_cron_as_migrated', 1, false );
		}

		/**
		 * Recurring jobs of the Jetonomy family, armed here and only here: hook => interval in seconds, or
		 * array( interval, seconds before the first run ). Pro's extensions add theirs from boot.
		 *
		 * @since 2.0.8
		 *
		 * @param array<string,int|array{0:int,1:int}> $jobs Jobs.
		 */
		foreach ( (array) apply_filters( 'jetonomy_recurring_jobs', self::RECURRING ) as $hook => $job ) {
			$job = (array) $job;
			Queue::ensure_recurring( (string) $hook, time() + (int) ( $job[1] ?? 0 ), (int) $job[0] );
		}
		// Self-heal for the two due-time jobs (one lookup each); the writes that make something due arm them
		// themselves, and deactivation cancels them, so activation arms them again here.
		if ( ! Queue::is_queued( self::DUE_HOOK ) ) {
			self::arm_due_jobs();
		}
		if ( ! Queue::is_queued( self::PUBLISH_HOOK ) ) {
			self::arm_scheduled_publish();
		}
	}

	/**
	 * Arm the publisher for the earliest scheduled topic. Called by Post on every write that sets a schedule, after
	 * each run, and by ensure_scheduled() as a self-heal. Nothing scheduled, nothing queued; a run left queued for a
	 * schedule that was cleared finds nothing due and stops, so clearing a schedule needs no cancel. One lookup: a copy
	 * armed for that moment is left alone, so it never stacks; so is a retry waiting for an overdue topic (at most 4
	 * minutes away), because re-arming would restart its attempt count. Otherwise the run moves to the earliest
	 * schedule, so moving a topic later moves the run with it.
	 */
	public static function arm_scheduled_publish(): void {
		if ( self::$publishing ) {
			return; // publish_scheduled_posts() re-arms once when it finishes.
		}
		Queue::when_ready(
			static function (): void {
				$next = \Jetonomy\Models\Post::next_scheduled_at();
				if ( null === $next ) {
					return;
				}
				$when   = max( (int) strtotime( $next . ' UTC' ), time() );
				$queued = Queue::next( self::PUBLISH_HOOK );
				$retry  = $when <= time() && $queued <= time() + MINUTE_IN_SECONDS * ( 2 ** ( self::PUBLISH_MAX_RETRIES - 1 ) );
				if ( $queued && ( $queued === $when || $retry ) ) {
					return;
				}
				Queue::cancel( self::PUBLISH_HOOK );
				Queue::at( $when, self::PUBLISH_HOOK );
			}
		);
	}

	/**
	 * Arm DUE_HOOK for the earliest moment there is work: a restriction expires, a sign-up is owed its verification
	 * reminder, or (hourly) 'tier' roster rows exist to re-check. Nothing due, nothing queued. Called by the writes
	 * that create such work, by the job itself after each run, and by ensure_scheduled() as a self-heal.
	 *
	 * @param int $not_before Earliest time to arm for (0 = no floor).
	 */
	public static function arm_due_jobs( int $not_before = 0 ): void {
		Queue::when_ready(
			static function () use ( $not_before ): void {
				$when   = self::next_due_at();
				$when   = $when ? max( $when, $not_before ) : 0;
				$queued = Queue::next( self::DUE_HOOK );
				if ( ! $when || ( $queued && $queued <= $when ) ) {
					return; // Nothing due, or already armed for that moment or sooner.
				}
				Queue::cancel( self::DUE_HOOK );
				Queue::at( $when, self::DUE_HOOK );
			}
		);
	}

	/**
	 * Earliest moment DUE_HOOK has work, or 0 for none.
	 *
	 * @return int Unix time.
	 */
	public static function next_due_at(): int {
		$due     = array();
		$expires = \Jetonomy\Models\Restriction::next_expiry();
		if ( null !== $expires ) {
			$due[] = (int) strtotime( $expires . ' UTC' );
		}
		$remind = Verification_Reminder::next_due_at();
		if ( $remind ) {
			$due[] = $remind;
		}
		if ( \Jetonomy\Models\SpaceMember::list_tier_rows_after( 0, 0, 1 ) ) {
			$due[] = time() + HOUR_IN_SECONDS; // Some adapters let a plan lapse without telling us: re-check hourly.
		}
		return $due ? max( time(), min( $due ) ) : 0;
	}

	/**
	 * DUE_HOOK: run each job (each one does nothing when it has nothing to do), then arm the next due moment.
	 */
	public function run_due_jobs(): void {
		try {
			$this->cleanup_expired_restrictions();
			Verification_Reminder::run();
			Membership_Roster_Sync::reconcile();
		} finally {
			// A minute at the least, so a backlog drains in steps and a job that cannot progress never spins.
			self::arm_due_jobs( time() + MINUTE_IN_SECONDS );
		}
	}

	/**
	 * Dispatcher for the trust sweep (runs every 12h).
	 *
	 * Resets the keyset cursor and kicks off the first async batch;
	 * evaluate_trust_batch() walks the whole base in bounded slices via Action
	 * Scheduler, so no user past the first N rows is skipped.
	 */
	public function evaluate_trust_levels(): void {
		update_option( self::TRUST_CURSOR_OPTION, 0, false );
		// First slice in this run: a site with few members who could move up finishes here, no batch action.
		$this->evaluate_trust_batch();
	}

	/**
	 * Process one keyset slice, then self-continue until the base is covered.
	 * Cursor = last processed user_id (advances every run, so no row is missed
	 * and none is re-processed within a sweep).
	 */
	public function evaluate_trust_batch(): void {
		$after  = (int) get_option( self::TRUST_CURSOR_OPTION, 0 );
		$batch  = (int) apply_filters( 'jetonomy_cron_batch_size', self::BATCH_SIZE, 'evaluate_trust_levels' );
		$result = self::run_trust_batch( $after, $batch );

		if ( 0 === $result['count'] ) {
			delete_option( self::TRUST_CURSOR_OPTION ); // Sweep complete.
			return;
		}

		if ( $result['count'] >= $batch ) {
			// Slice was full — more remain; advance the cursor and continue.
			update_option( self::TRUST_CURSOR_OPTION, $result['last_id'], false );
			self::enqueue_trust_batch();
		} else {
			delete_option( self::TRUST_CURSOR_OPTION ); // Last (partial) slice.
		}
	}

	/**
	 * Enqueue the next trust batch on Action Scheduler (WP-Cron single-event
	 * fallback). Reuses the plugin's AS group — no new scheduling surface.
	 */
	private static function enqueue_trust_batch(): void {
		Queue::async( 'jetonomy_trust_evaluation_batch', array(), true, MINUTE_IN_SECONDS );
	}

	/**
	 * Thresholds of trust levels 1-3, keyed by level: what a member one level below must reach to move up.
	 *
	 * @return array<int,array>
	 */
	private static function next_level_thresholds(): array {
		$levels = range( 1, 3 );
		return array_combine( $levels, array_map( array( Trust_Levels::class, 'get_requirements' ), $levels ) );
	}

	/**
	 * Evaluate one keyset-paginated slice of profiles with trust_level < 4 and
	 * user_id > $after. Shared by the cron worker AND the WP-CLI trust-evaluate
	 * command so there is one implementation (no parallel logic, no OOM on the
	 * CLI). Served by the user_profiles trust_user (trust_level, user_id) index.
	 *
	 * @param int  $after   Keyset cursor — return rows with user_id greater than this.
	 * @param int  $limit   Slice size.
	 * @param bool $dry_run Count the promotions that WOULD happen and write nothing (no profile update, no action).
	 * @return array{last_id:int,count:int,promoted:int} Max user_id seen, rows
	 *                                                    processed, promotions made (or that would be made).
	 */
	public static function run_trust_batch( int $after, int $limit, bool $dry_run = false ): array {
		// Only members who could reach the next level, unless something filters the level (it may raise anyone).
		$next     = has_filter( 'jetonomy_trust_level_pre_change' ) ? array() : self::next_level_thresholds();
		$profiles = UserProfile::list_for_trust_batch( $after, $limit, $next );

		if ( empty( $profiles ) ) {
			return array(
				'last_id'  => $after,
				'count'    => 0,
				'promoted' => 0,
			);
		}

		// Batch-fetch replies-received for the whole slice (one query, no N+1).
		$replies_received_rows = \Jetonomy\Models\Reply::count_received_by_authors( array_map( 'intval', wp_list_pluck( $profiles, 'user_id' ) ) );

		$replies_received_map = array();
		foreach ( $replies_received_rows as $row ) {
			$replies_received_map[ (int) $row->author_id ] = (int) $row->cnt;
		}

		$last_id  = $after;
		$promoted = 0;
		foreach ( $profiles as $profile ) {
			$last_id = max( $last_id, (int) $profile->user_id );

			$days_active = $profile->created_at
				? (int) ( ( time() - strtotime( $profile->created_at ) ) / DAY_IN_SECONDS )
				: 0;

			$stats = array(
				// Topics plus replies written: a member who only answers questions counts too.
				'post_count'       => (int) $profile->post_count + (int) $profile->reply_count,
				'days_active'      => $days_active,
				'reputation'       => (int) $profile->reputation,
				'replies_received' => $replies_received_map[ (int) $profile->user_id ] ?? 0,
			);

			$new_level = Trust_Evaluator::evaluate_level( $stats );

			/**
			 * Filter the auto-evaluated trust level before it is written.
			 *
			 * Listeners can lower the level (e.g. veto promotion for sandboxed
			 * users) or raise it (e.g. onboarding fast-track). Returning the
			 * user's current level short-circuits the write. Only fires on the
			 * automatic promotion paths (cron + CLI trust-evaluate); manual
			 * admin/CLI overrides intentionally bypass this filter.
			 *
			 * @param int   $new_level Level the evaluator chose.
			 * @param int   $user_id   Target user.
			 * @param array $stats     Stats fed to the evaluator.
			 */
			$new_level = (int) apply_filters( 'jetonomy_trust_level_pre_change', $new_level, (int) $profile->user_id, $stats );

			if ( $new_level > (int) $profile->trust_level ) {
				if ( $dry_run ) {
					++$promoted;
					continue;
				}
				// Through the model, not raw $wpdb: update_profile() busts
				// profile:{id} BEFORE the action below fires, so its four
				// listeners (activity tracker, notifier, Pro badges, Pro
				// webhooks) re-read the promoted level instead of a cached
				// stale row.
				UserProfile::update_profile( (int) $profile->user_id, array( 'trust_level' => $new_level ) );
				/**
				 * Fires after the trust-level sweep changes a user's trust level.
				 *
				 * @since 1.0.0
				 *
				 * @param int $user_id   User ID.
				 * @param int $old_level Previous trust level.
				 * @param int $new_level New trust level.
				 */
				do_action( 'jetonomy_trust_level_changed', (int) $profile->user_id, (int) $profile->trust_level, $new_level );
				++$promoted;
			}
		}

		return array(
			'last_id'  => $last_id,
			'count'    => count( $profiles ),
			'promoted' => $promoted,
		);
	}

	/**
	 * Remove expired bans/restrictions (runs hourly).
	 *
	 * Processes at most jetonomy_cron_batch_size rows per run (default 500)
	 * to avoid long-running DELETE locks on large communities.
	 */
	public function cleanup_expired_restrictions(): void {
		\Jetonomy\Models\Restriction::delete_expired( (int) apply_filters( 'jetonomy_cron_batch_size', self::BATCH_SIZE, 'cleanup_expired_restrictions' ) );
	}

	/**
	 * Prune old activity log entries (runs daily).
	 *
	 * Retention period is configurable via jetonomy_settings['activity_log_retention_days'].
	 * Defaults to 90 days. Set to 0 to keep forever.
	 * Deletes in batches of 5 000 to avoid lock contention, looping until all expired rows are removed.
	 */
	public function prune_activity_log(): void {
		\Jetonomy\Queue::prune_history();

		$settings = get_option( 'jetonomy_settings', array() );
		$days     = (int) ( $settings['activity_log_retention_days'] ?? 90 );
		if ( $days <= 0 ) {
			return; // 0 = keep forever.
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		// Delete in batches to avoid lock contention, loop until done.
		// Within a 20 s budget like cleanup_old_notifications(); the rest waits for tomorrow.
		$deadline = microtime( true ) + 20;
		do {
			$deleted = \Jetonomy\Models\ActivityLog::delete_before( $cutoff, 5000 );
		} while ( $deleted >= 5000 && microtime( true ) < $deadline );
	}

	/**
	 * Mark old unread notifications as read (runs weekly, 30 days).
	 *
	 * Updates jetonomy_cron_batch_size rows at a time (default 500) so no
	 * single UPDATE holds long locks, repeating until the backlog is clear or
	 * the run's time budget is spent.
	 */
	public function cleanup_old_notifications(): void {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$batch  = (int) apply_filters( 'jetonomy_cron_batch_size', self::BATCH_SIZE, 'cleanup_old_notifications' );

		// Batches until the backlog is clear, within a 20 s budget: one 500-row
		// pass a week fell behind on any community producing more than that.
		// Index read_created makes each pass a range read; whatever the budget
		// leaves is picked up next week.
		$deadline = microtime( true ) + 20;
		do {
			$done = \Jetonomy\Models\Notification::mark_read_before( $cutoff, $batch );
		} while ( $done >= $batch && microtime( true ) < $deadline );
	}

	/**
	 * Publish the topics whose schedule has passed, at most jetonomy_cron_batch_size (default 500) per run.
	 *
	 * A topic that fails to publish does not stop the others. The run is then retried with the attempt number in its
	 * args, 1, 2 and 4 minutes later; after the third retry it logs one line and waits for the next schedule (the
	 * failed topic is tried again then), instead of re-running the moment the queue runs as it did before.
	 *
	 * @param int $attempt Retry number (0 = the scheduled run).
	 */
	public function publish_scheduled_posts( $attempt = 0 ): void {
		$attempt   = (int) $attempt;
		$batch     = (int) apply_filters( 'jetonomy_cron_batch_size', self::BATCH_SIZE, 'publish_scheduled_posts' );
		$due_posts = \Jetonomy\Models\Post::get_due_scheduled( $batch );
		$failed    = array();
		$error     = '';

		self::$publishing = true;
		try {
			foreach ( $due_posts as $post ) {
				try {
					$ok = \Jetonomy\Models\Post::publish_scheduled( (int) $post->id );
				} catch ( \Throwable $e ) {
					$ok    = false;
					$error = $e->getMessage();
				}
				$row = $ok ? null : \Jetonomy\Models\Post::find( (int) $post->id );
				if ( $row && 'draft' === $row->status && ! empty( $row->published_at ) ) {
					$failed[] = (int) $post->id;
				}
			}
		} finally {
			self::$publishing = false;
		}

		if ( ! $failed ) {
			// Next schedule, or the rest of a backlog larger than one batch (a past-due MIN arms at time()).
			self::arm_scheduled_publish();
			return;
		}
		Queue::cancel( self::PUBLISH_HOOK );
		if ( $attempt < self::PUBLISH_MAX_RETRIES ) {
			Queue::at( time() + MINUTE_IN_SECONDS * ( 2 ** $attempt ), self::PUBLISH_HOOK, array( $attempt + 1 ) );
			return;
		}
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- one line when a scheduled topic gives up.
		error_log( sprintf( 'Jetonomy: scheduled topics %s did not publish after %d retries%s; trying again at the next schedule.', implode( ',', $failed ), self::PUBLISH_MAX_RETRIES, '' !== $error ? ' (' . $error . ')' : '' ) );
		$later = \Jetonomy\Models\Post::next_scheduled_at( gmdate( 'Y-m-d H:i:s' ) );
		if ( null !== $later ) {
			Queue::at( (int) strtotime( $later . ' UTC' ), self::PUBLISH_HOOK );
		}
	}
}
