# Background Jobs

Jetonomy runs its background work on Action Scheduler, which it bundles, so jobs run on every host without
server cron. Free and Pro share one scheduling API, `\Jetonomy\Queue`, and one group, `jetonomy`, so every job
shows together under **Tools > Scheduled Actions** (filter the group by `jetonomy`).

## How the jobs are built

- **Nothing runs when there is nothing to do.** A site where nobody posts and nothing is due schedules about
  10 job runs a day (12 with Pro's badge, digest and webhook jobs on, plus Reply by Email's 5-minute inbox poll
  when that is on IMAP).
- **Jobs are armed when work appears**, not polled: a restriction that expires, a scheduled topic, a sign-up that
  is owed a verification reminder, a post that needs AI review.
- **Big lists are walked by a cursor job.** The job's arguments are an object id and the last id it handled; it
  queues its next run only when its slice was full. One post in a 5,000-member space queues about 20 fan-out
  runs (250 members each), never 5,000 jobs.
- **Arguments are ids and small numbers**, under 191 characters. URLs, secrets and member lists never go into the
  job tables. (A webhook delivery carries its event payload, the one exception, because a deleted topic cannot
  be read back later.)
- **Retries are capped.** A scheduled topic that will not publish is retried 3 times, 1, 2 and 4 minutes apart,
  with the attempt number in the job arguments, then logged once.
- **Recurring jobs are armed only on cron, wp-admin and WP-CLI requests**, never on a visitor's page view.
- **Jetonomy trims only its own history.** Action Scheduler keeps every plugin's finished jobs for 31 days and
  Jetonomy does not change that site-wide setting. It deletes its own completed jobs, with their log rows, after
  7 days. Failed and pending jobs are kept until you uninstall.

The full list of jobs (hook, trigger, arguments, rows per event) is in `docs/standards/background-jobs.md`,
section 9, in the plugin repository.

## Filters

### `jetonomy_finished_action_retention_days`

Days a finished (completed) Jetonomy job is kept before Jetonomy deletes it with its log rows. Only the
`jetonomy` group is touched. Integer days, at least 1. **Default: 7.** Since 2.0.8.

```php
// Keep finished Jetonomy jobs for 3 days instead of 7.
add_filter( 'jetonomy_finished_action_retention_days', static fn(): int => 3 );
```

The trim runs inside the daily `jetonomy_prune_activity` job, 200 rows per run.

### `jetonomy_recurring_jobs`

Every recurring job of the Jetonomy family, armed in one place. The value maps a hook to its interval in
seconds, or to `array( interval, seconds before the first run )`. **Default:** Jetonomy's own jobs
(`jetonomy_trust_evaluation` every 12 hours, `jetonomy_prune_activity` and `jetonomy_cleanup_media` daily,
`jetonomy_cleanup_notifications` weekly) plus whatever Pro's extensions register. Since 2.0.8.

```php
// Mark old notifications read every 3 days instead of weekly.
add_filter( 'jetonomy_recurring_jobs', static function ( array $jobs ): array {
	$jobs['jetonomy_cleanup_notifications'] = 3 * DAY_IN_SECONDS;
	return $jobs;
} );
```

A new interval takes effect when the job is next scheduled: cancel the old copy once (for example with
`wp action-scheduler action cancel` or from **Tools > Scheduled Actions**).

## Add your own recurring job

An add-on registers its job with `\Jetonomy\Queue::recurring()` from its boot code, before
`action_scheduler_init`. Jetonomy arms it with the others and cancels nothing it does not own.

```php
add_action( 'myaddon_nightly_cleanup', 'myaddon_run_nightly_cleanup' );
\Jetonomy\Queue::recurring( 'myaddon_nightly_cleanup', DAY_IN_SECONDS, HOUR_IN_SECONDS ); // First run in an hour.

function myaddon_run_nightly_cleanup(): void {
	// Your work here.
}
```

Cancel it when your add-on is switched off: `\Jetonomy\Queue::cancel( 'myaddon_nightly_cleanup' );`.

For one-off work use `\Jetonomy\Queue::async( $hook, $args )`, or `\Jetonomy\Queue::async_once()` to skip a
copy that is already waiting with the same arguments. Keep `$args` to ids and small integers.

## Inspect the queue

```bash
wp action-scheduler action list --group=jetonomy --status=pending --fields=hook,scheduled_date
wp action-scheduler action list --group=jetonomy --status=failed --fields=hook,args
```

Changed jobs, hooks and filters in 2.0.8: [Migrating to 2.0.8](./31-migrating-to-2-0-8.md).
