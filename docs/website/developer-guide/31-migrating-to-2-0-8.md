# Migrating to 2.0.8

Jetonomy 2.0.8 rebuilt its background jobs (see [Background Jobs](./30-background-jobs.md)). Updating needs
no action from site owners: the upgrade cancels the old schedules once and converts any job that was still
waiting. This page is for developers whose code calls, listens to or filters a job hook.

## Removed

| Removed | Use instead |
|---|---|
| `jetonomy_cleanup_expired` (hourly) | `jetonomy_due_jobs`, armed for the next restriction expiry |
| `jetonomy_verification_reminder` (hourly) | `jetonomy_due_jobs`, armed for the next reminder that is owed |
| `jetonomy_reconcile_rosters` (hourly) | `jetonomy_due_jobs`, hourly only while membership-synced rows exist |
| Pro class `Jetonomy_Pro\Queue` | `\Jetonomy\Queue` (same methods, plus `recurring()`) |
| Pro `jetonomy_pro_send_push`, `jetonomy_pro_send_expo_push` (one job per device) | `jetonomy_pro_send_push_batch` (one cursor job per push) |
| Pro `jetonomy_pro_dispatch_webhook` (URL and secret in args) | `jetonomy_pro_deliver_webhook` |
| Pro `jetonomy_pro_digest_batch` (offset chunks) | `jetonomy_pro_send_digests` (cursor job) |
| Pro `jetonomy_pro_badges_batch` (offset chunks) | `jetonomy_pro_evaluate_badges_after` (cursor job) |
| Pro `jetonomy_pro_backfill_searchable_fields` | `jetonomy_pro_reindex_field` (cursor job per field) |
| Pro AI batch review every 5 minutes | `jetonomy_pro_ai_batch_review` is now armed by a new topic or reply |

None of the removed hooks was a documented extension point. If your code called `do_action()` on one of
them to force a run, call the replacement instead.

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

Code examples for each are in [Background Jobs](./30-background-jobs.md).

## Behaviour changes worth knowing

- A scheduled topic that fails to publish is retried 3 times (1, 2 and 4 minutes apart), then one line is
  written to the PHP error log and the topic waits for the next scheduled publish.
- Recurring jobs are armed on cron, wp-admin and WP-CLI requests only, never on a visitor's page view.
- Jetonomy deletes its own completed jobs after 7 days. Other plugins' jobs and Action Scheduler's
  site-wide retention are not touched.
