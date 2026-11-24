# Migrating to 2.1.0

Jetonomy 2.1.0 rebuilt its background jobs (see [Background Jobs](./30-background-jobs.md)). Updating needs
no action from site owners. The upgrade cancels the old schedules once. A job that was still waiting is either
converted to its replacement (webhook deliveries, digest and badge runs) or, where it cannot be converted, run
by a listener 2.1.0 keeps for that purpose (pushes and field re-index chunks). This page is for developers whose code calls, listens to or filters a job hook.

## Removed

| Removed | Use instead |
|---|---|
| `jetonomy_cleanup_expired` (hourly) | `jetonomy_due_jobs`, armed for the next restriction expiry |
| `jetonomy_verification_reminder` (hourly) | `jetonomy_due_jobs`, armed for the next reminder that is owed |
| `jetonomy_reconcile_rosters` (hourly) | `jetonomy_due_jobs`, hourly only while membership-synced rows exist |
| Pro class `Jetonomy_Pro\Queue` (deprecated, removed in 2.1.1) | `\Jetonomy\Queue`. The old class still works and logs a deprecation notice. `batch()` has no replacement: queue one cursor job (object id, last id) that queues its next pass while rows remain. `recurring()` is now `recurring( $hook, $interval, $first_delay )`, called from boot; the 2.0.7 form with `$args`, `$group` and `$first_run` is accepted by the old class only. The old class's `async()` ignores its `$group` argument: every job goes to the `jetonomy` group. |
| Pro `jetonomy_pro_send_push`, `jetonomy_pro_send_expo_push` (one job per device) | `jetonomy_pro_send_push_batch` (one cursor job per push). 2.1.0 still runs copies queued before the update, so no push is lost; the listeners go in 2.1.1. |
| Pro `jetonomy_pro_dispatch_webhook` (URL and secret in args) | `jetonomy_pro_deliver_webhook`. The upgrade job (`jetonomy_pro_upgrade_jobs`, a background job) re-queues waiting deliveries, then deletes every row of the old hook (any status, with its logs) so no URL or secret stays in the job tables. Until 2.1.1 the old hook keeps a listener that converts a row the same way, so a queue runner that reaches an old delivery before the upgrade job does forwards it instead of failing it. Before deleting, the upgrade job re-queues old rows that failed in the last 7 days without running (no listener yet, or two runners colliding on the claim). The upgrade job runs on Action Scheduler; a WP-Cron copy is only the backstop for a first request that ends before Action Scheduler is ready. |
| Pro `jetonomy_pro_digest_batch` (offset chunks) | `jetonomy_pro_send_digests` (cursor job). A copy a runner reaches before the upgrade job restarts that frequency (listener until 2.1.1). |
| Pro `jetonomy_pro_badges_batch` (offset chunks) | `jetonomy_pro_evaluate_badges_after` (cursor job). A copy a runner reaches before the upgrade job restarts the run (listener until 2.1.1). |
| Pro `jetonomy_pro_backfill_searchable_fields` | `jetonomy_pro_reindex_field` (cursor job per field). The listener stays until 2.1.1 to run copies queued before the update and those the deprecated `queue_reindex( array )` form queues. |
| Pro AI batch review every 5 minutes | `jetonomy_pro_ai_batch_review` is now armed by a new topic or reply |

None of the removed hooks was a documented extension point. If your code called `do_action()` on one of
them to force a run, call the replacement instead.

## Changed public methods

Each removed or changed method shipped in 2.0.7. Where a call in the old form can still work, it does, and logs
a deprecation notice until 2.1.1.

| Method | 2.0.7 | 2.1.0 |
|---|---|---|
| `\Jetonomy\Queue::is_queued( $hook, $args, $include_running )` | Exact arguments, empty by default; third parameter for pending only | `is_queued( $hook, ?array $args = null )`: any arguments unless given, pending or running. A call with `false` as the third argument still asks the pending-only question and logs a deprecation notice; use `is_pending()`. A call with only the hook now matches copies with any arguments, where 2.0.7 matched argument-less copies only. |
| `\Jetonomy\Queue::next( $hook )` | `int` or `false`, argument-less copies only | `int`, `0` when nothing is pending (or the soonest copy is async or running). Matches copies with any arguments. |
| `\Jetonomy\Queue::at( $timestamp, $hook, $args )` | Always inserted | New fourth parameter `$unique`: insert only when no pending copy with these arguments exists (a running copy does not count, so a job can re-arm itself from its own run). |
| Pro `Extension::upgrade_jobs( $from )` | New in 2.1.0 development builds, returned `void` | Returns `bool`: true while old copies remain to convert. Pro's upgrade job calls it again until it returns false. |
| Pro `Custom_Badges\Extension::evaluate_badges_batch( array $batch )` | One offset chunk | `evaluate_badges_batch( $after )`: a cursor job from the last member id. |
| Pro `Email_Digest\Extension::process_digest_batch( array $batch )` | One offset chunk | `process_digest_batch( $frequency, $after )`: a cursor job per frequency from the last member id. |
| `\Jetonomy\Queue::cancel( $hook )` | Every copy | `cancel( $hook, array $args = array() )`: every copy, or only the copy with these arguments. |
| Pro `Webhooks\Extension::do_dispatch( $id, $url, $secret, $payload, $event )` | Sent one delivery | Deprecated shim, removed in 2.1.1. Queued deliveries call `deliver( $id, $event, $payload )`. |
| Pro `Custom_Fields\Extension::run_searchable_backfill( $post_ids )` | Re-indexed topics | Deprecated shim, removed in 2.1.1. Use `queue_reindex( $field_id )`. |
| Pro `Custom_Fields\Extension::queue_reindex( array $post_ids )` | Queued 500-topic chunks | Takes a field id and queues one cursor job. An array still works (deprecated): the topics are queued in 500-topic chunks, as before. |
| Pro `Attachments\Extension::gc()` | No-op listener for the retired daily GC | Removed. The GC schedule is cancelled once by the upgrade. |
| `\Jetonomy\Cron::is_scheduling_request()` | Said whether recurring jobs are checked on this request | Deprecated, keeps working through 2.1.x (Pro 2.0.7 and older call it on every request); removal 2.2.0 or later. Under `WP_DEBUG` it writes one log line per request, never a notice on the page. Use `Queue::is_scheduling_request()`. |
| `Space_Purge::clear_scheduled()`, `Recount_Backfill::clear_scheduled()` | Cancelled their job on deactivation | Removed. Deactivation cancels through `Queue::cancel_on_deactivate()`, which keeps an in-flight space purge pending. |

New on `\Jetonomy\Queue`: `when_ready()`, `is_pending()`, `pending_args()` (oldest 100), `ensure_recurring()`, `delete_rows()`,
`is_scheduling_request()`, `prune_history()`, `now()`, `cancel_on_deactivate()`, `flag_recovery()`, `recover_kept_jobs()`, the `TIME_BUDGET` constant (10 seconds per prune or delete call)
and the `KEEP_ON_DEACTIVATE` list.

## Renamed

None. The filter for finished-job retention shipped for the first time in 2.1.0 as
`jetonomy_finished_action_retention_days`, the name every Wbcom plugin uses
(`{prefix}_finished_action_retention_days`).

## New

| Hook | Type | What it does |
|---|---|---|
| `jetonomy_recurring_jobs` | filter | Every recurring job of the family, hook => interval or `array( interval, first delay )`. |
| `jetonomy_finished_action_retention_days` | filter | Days a finished Jetonomy job is kept. Default 7. |
| `jetonomy_pro_webhook_timeout` | filter (Pro) | Seconds a webhook delivery waits for the receiver. Default 15. |
| `jetonomy_due_jobs` | job | Expired-restriction cleanup, verification reminders and the membership roster backstop. |
| `\Jetonomy\Queue::recurring()` | method | Register a recurring job with Jetonomy's scheduler. |
| `jetonomy_pro_push_send_seconds` | filter | Seconds one web push run sends before queueing the rest. Default 15. |

Code examples for each are in [Background Jobs](./30-background-jobs.md).

## Behaviour changes worth knowing

- A scheduled topic that fails to publish is retried 3 times (1, 2 and 4 minutes apart), then one line is
  written to the PHP error log and one more run is queued at the next scheduled publish or a day later,
  whichever comes first. A topic that never publishes costs one try and one log line a day.
- Recurring jobs are armed on cron, wp-admin and WP-CLI requests only, never on a visitor's page view.
- Deactivating Free or Pro leaves work already promised queued: a space purge in progress, scheduled topics,
  subscriber notifications, a site announcement, the Pro upgrade conversion, a custom-field re-index, a private
  space's file locking and webhook deliveries stay queued through an update
  (`Queue::KEEP_ON_DEACTIVATE`); recurring sweeps, digests and sends are still cancelled and re-armed on activation.
  If one of those jobs failed while the plugin was off (another plugin's Action Scheduler ran it with no listener),
  the next activation, version change or one-click update queues it again with the same arguments, when it failed
  within the last 7 days.
- Jetonomy deletes its own completed jobs 7 days after they finished, cancelled jobs 7 days after the time they were
  scheduled for, and its own failed jobs after 90
  days, for at most 10 seconds and 10,000 rows per status a day (50 runs of 200); a larger backlog is cleared over
  the following days. Other plugins' jobs and Action Scheduler's site-wide retention are not touched.
- The Free database update runs in one request at a time: the first request after the update takes a 5-minute
  lock (the `jetonomy_migration_lock` transient) while every other request keeps serving on the old schema. If that
  request is killed midway, the lock stays until it expires, so the site can sit half-migrated for up to 5 minutes
  before the next request resumes the update. This is by design: a second request never runs the same table
  changes at the same time, and the migrations are safe to run again.
- The Pro upgrade runs in one request at a time under core's upgrader lock: it updates the extension tables, queues
  the job conversion (a background job, never on a visitor's page view), then stores the new version. If that
  request is killed midway, the lock stays held for 10 minutes, so the new version is stored only after it expires;
  the conversion job does not wait for the lock.
- A web push run sends for about 15 seconds, then queues itself from the next device it has not sent (the cursor job
  `jetonomy_pro_send_push_batch` gains a fourth int arg, the next member's devices already sent)
  (filter `jetonomy_pro_push_send_seconds`). Each push request waits at most 5 seconds
  (`Jetonomy_Pro\Extension::REMOTE_TIMEOUT`, was 15). Webhook deliveries still wait 15 seconds (new filter
  `jetonomy_pro_webhook_timeout`).

## Failed jobs from retired hooks

**Tools > Scheduled Actions** may list failed rows for a retired hook, for example
`jetonomy_pro_badges_batch` or `jetonomy_pro_send_push` from a site that ran a 2.1.0 development build before
the upgrade routine. They are history only and can be ignored. Jetonomy deletes its own failed rows after
90 days in its daily cleanup, on any Action Scheduler version (Action Scheduler 4.0 also does this itself;
3.x never deletes failed rows). Uninstalling Jetonomy deletes all of its rows.

Rolling back from 2.1.0 to 2.0.7 is not a supported path: old jobs are cancelled once, at the first 2.1.0 upgrade, and are not cancelled again after a rollback.
