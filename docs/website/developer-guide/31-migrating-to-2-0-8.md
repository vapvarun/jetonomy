# Migrating to 2.0.8

Jetonomy 2.0.8 rebuilt its background jobs (see [Background Jobs](./30-background-jobs.md)). Updating needs
no action from site owners. The upgrade cancels the old schedules once. A job that was still waiting is either
converted to its replacement (webhook deliveries, digest and badge runs) or, where it cannot be converted, run
by a listener 2.0.8 keeps for that purpose (pushes and field re-index chunks). This page is for developers whose code calls, listens to or filters a job hook.

## Removed

| Removed | Use instead |
|---|---|
| `jetonomy_cleanup_expired` (hourly) | `jetonomy_due_jobs`, armed for the next restriction expiry |
| `jetonomy_verification_reminder` (hourly) | `jetonomy_due_jobs`, armed for the next reminder that is owed |
| `jetonomy_reconcile_rosters` (hourly) | `jetonomy_due_jobs`, hourly only while membership-synced rows exist |
| Pro class `Jetonomy_Pro\Queue` (deprecated, removed in 2.1.0) | `\Jetonomy\Queue`. The old class still works and logs a deprecation notice. `batch()` has no replacement: queue one cursor job (object id, last id) that queues its next pass while rows remain. `recurring()` is now `recurring( $hook, $interval, $first_delay )`, called from boot; the 2.0.7 form with `$args`, `$group` and `$first_run` is accepted by the old class only. |
| Pro `jetonomy_pro_send_push`, `jetonomy_pro_send_expo_push` (one job per device) | `jetonomy_pro_send_push_batch` (one cursor job per push). 2.0.8 still runs copies queued before the update, so no push is lost; the listeners go in 2.0.9. |
| Pro `jetonomy_pro_dispatch_webhook` (URL and secret in args) | `jetonomy_pro_deliver_webhook`. The upgrade re-queues waiting deliveries, then deletes every row of the old hook (any status, with its logs) so no URL or secret stays in the job tables. |
| Pro `jetonomy_pro_digest_batch` (offset chunks) | `jetonomy_pro_send_digests` (cursor job) |
| Pro `jetonomy_pro_badges_batch` (offset chunks) | `jetonomy_pro_evaluate_badges_after` (cursor job) |
| Pro `jetonomy_pro_backfill_searchable_fields` | `jetonomy_pro_reindex_field` (cursor job per field). 2.0.8 still runs copies queued before the update; the listener goes in 2.0.9. |
| Pro AI batch review every 5 minutes | `jetonomy_pro_ai_batch_review` is now armed by a new topic or reply |

None of the removed hooks was a documented extension point. If your code called `do_action()` on one of
them to force a run, call the replacement instead.

## Changed public methods

Each removed or changed method shipped in 2.0.7. Where a call in the old form can still work, it does, and logs
a deprecation notice until 2.1.0.

| Method | 2.0.7 | 2.0.8 |
|---|---|---|
| `\Jetonomy\Queue::is_queued( $hook, $args, $include_running )` | Exact arguments, empty by default; third parameter for pending only | `is_queued( $hook, ?array $args = null )`: any arguments unless given, pending or running. Pending only: `is_pending()`. |
| `\Jetonomy\Queue::next( $hook )` | `int` or `false` | `int`, `0` when nothing is pending. |
| `\Jetonomy\Queue::cancel( $hook )` | Every copy | `cancel( $hook, array $args = array() )`: every copy, or only the copy with these arguments. |
| Pro `Webhooks\Extension::do_dispatch( $id, $url, $secret, $payload, $event )` | Sent one delivery | Deprecated shim, removed in 2.1.0. Queued deliveries call `deliver( $id, $event, $payload )`. |
| Pro `Custom_Fields\Extension::run_searchable_backfill( $post_ids )` | Re-indexed topics | Deprecated shim, removed in 2.1.0. Use `queue_reindex( $field_id )`. |
| Pro `Custom_Fields\Extension::queue_reindex( array $post_ids )` | Queued 500-topic chunks | Takes a field id and queues one cursor job. An array still works (deprecated): the topics are re-indexed in that request. |
| Pro `Attachments\Extension::gc()` | No-op listener for the retired daily GC | Removed. The GC schedule is cancelled once by the upgrade. |

New on `\Jetonomy\Queue`: `when_ready()`, `is_pending()`, `pending_args()` (oldest 100), `ensure_recurring()`, `delete_rows()`,
`is_scheduling_request()` and `prune_history()`.

## Renamed

None. The filter for finished-job retention shipped for the first time in 2.0.8 as
`jetonomy_finished_action_retention_days`, the name every Wbcom plugin uses
(`{prefix}_finished_action_retention_days`).

## New

| Hook | Type | What it does |
|---|---|---|
| `jetonomy_recurring_jobs` | filter | Every recurring job of the family, hook => interval or `array( interval, first delay )`. |
| `jetonomy_finished_action_retention_days` | filter | Days a finished Jetonomy job is kept. Default 7. |
| `jetonomy_due_jobs` | job | Expired-restriction cleanup, verification reminders and the membership roster backstop. |
| `\Jetonomy\Queue::recurring()` | method | Register a recurring job with Jetonomy's scheduler. |
| `jetonomy_pro_push_send_seconds` | filter | Seconds one web push run sends before queueing the rest. Default 15. |

Code examples for each are in [Background Jobs](./30-background-jobs.md).

## Behaviour changes worth knowing

- A scheduled topic that fails to publish is retried 3 times (1, 2 and 4 minutes apart), then one line is
  written to the PHP error log and the topic waits for the next scheduled publish.
- Recurring jobs are armed on cron, wp-admin and WP-CLI requests only, never on a visitor's page view.
- Jetonomy deletes its own completed and cancelled jobs after 7 days and its own failed jobs after 90 days, up to 10,000 rows
  a day (50 runs of 200); a larger backlog is cleared over the following days. Other plugins' jobs and Action
  Scheduler's site-wide retention are not touched.
- A web push run sends for about 15 seconds, then queues itself from the last member it sent
  (filter `jetonomy_pro_push_send_seconds`).

## Failed jobs from retired hooks

**Tools > Scheduled Actions** may list failed rows for a retired hook, for example
`jetonomy_pro_badges_batch` or `jetonomy_pro_send_push` from a site that ran a 2.0.8 development build before
the upgrade routine. They are history only and can be ignored. Jetonomy deletes its own failed rows after
90 days in its daily cleanup, on any Action Scheduler version (Action Scheduler 4.0 also does this itself;
3.x never deletes failed rows). Uninstalling Jetonomy deletes all of its rows.

Rolling back from 2.0.8 to 2.0.7 is not a supported path: old jobs are cancelled once, at the first 2.0.8 upgrade, and are not cancelled again after a rollback.
