# Wbcom Background-Jobs Standard (Cron + Action Scheduler)

**Status:** Normative. Applies to every Wbcom WordPress plugin/theme that runs
background work (cron, queues, digests, cleanups, scheduled publishing).
**Version:** 1.2 (2026-11-23; first issued 1.0, 2026-06-18). Distilled from the BuddyNext cron/AS audit;
reference implementations across BuddyNext and Jetonomy in Section 7.

This is the single source of truth. Each plugin keeps a synced copy at
`docs/standards/background-jobs.md` and a one-line pointer in its `CLAUDE.md`.
Do not re-derive the rules per plugin — link here.

## 1. The one principle

**A plugin must be fast by default on a vanilla install with zero environment
changes.** Background work is a selling point, not an afterthought. Never rely on
the site owner configuring server cron, and never make a site-wide change on
their behalf.

**Jetonomy and MediaVerse names for the same jobs API** (each class header lists the full differences):

| Jetonomy `\Jetonomy\Queue` | MediaVerse `\WPMediaVerse\Core\Jobs` |
|---|---|
| `cancel_on_deactivate( $hooks )` (skips `KEEP_ON_DEACTIVATE`) | `cancel_pending( $hooks )` (set-based, returns a count) |
| `flag_recovery()` + `recover_kept_jobs()`, `RECOVER_BATCH`, `RECOVER_WINDOW_DAYS` | `requeue_after_activation( $transient, $hooks )` + `requeue_unheard( $hooks )`, `REQUEUE_BATCH`, `REQUEUE_DAYS` |
| `ensure_recurring( $hook, $first, $interval )` (interval only) | `ensure_recurring( $hook, $first, $schedule, $cron )` (interval or cron expression) |
| `at( ..., $unique )`: a running copy does not block the insert, so a job re-arms from its run | `at( ..., $unique )` |
| `prune_history()`: completed and failed rows aged by last attempt, cancelled rows by scheduled date | `prune_history()` |
| `TIME_BUDGET` public | `TIME_BUDGET` private |

In both, `cancel( $hook, array() )` cancels every copy while `is_queued( $hook, array() )` asks for the
argument-less copy only (pass `null` for any arguments), and `next()` answers 0 both when nothing is queued and
when a copy is running.

## 2. Decision tree — pick the lightest mechanism that fits

Apply in order. Stop at the first that works.

| # | If the work is… | Use | Result |
|---|---|---|---|
| 1 | Derivable on demand | **Lazy compute + cache** (transient + object cache) | **No job at all** |
| 2 | A response to an event | **Reactive single-shot** (`as_enqueue_async_action`, or `wp_schedule_single_event`) | Fires only on the event |
| 3 | Due at a known future time | **Single event armed at that time**, re-armed after it runs | Zero polling |
| 4 | Event-driven but bursty (a queue that drains) | **Self-(un)scheduling recurring** — arm on write, disarm when queue empties | Dormant when idle |
| 5 | Genuinely always-on (digests, cleanups, reconciles) | **AS recurring**, lowest acceptable cadence (daily/weekly) | One observable, retrying queue |

**Anti-pattern (delete on sight):** a perpetual sub-hourly recurring poll that
runs whether or not there is work. That is the "heavy community plugin" smell.

## 3. Copy-paste patterns

These show what the calls do. A plugin with its own queue class calls the class instead (Jetonomy:
`\Jetonomy\Queue`, MediaVerse: `Core\Jobs`), so the timing, fallback and cancel rules live in one place.

### (1) Lazy-on-read — no job
```php
public function get_trending( int $limit = 10 ): array {
    $key = "trending_{$limit}";
    $hit = wp_cache_get( $key, self::GROUP );          // within-request
    if ( false !== $hit ) { return (array) $hit; }
    $t = get_transient( "pfx_trending_{$limit}" );      // cross-request, all hosts
    if ( false !== $t && is_array( $t ) ) {
        wp_cache_set( $key, $t, self::GROUP );
        return $t;
    }
    $rows = /* expensive query */;
    set_transient( "pfx_trending_{$limit}", $rows, 30 * MINUTE_IN_SECONDS );
    wp_cache_set( $key, $rows, self::GROUP );
    return $rows;
}
// Bust BOTH layers on the write path that changes the data.
```

### (2) Reactive single-shot
```php
if ( function_exists( 'as_enqueue_async_action' ) ) {
    as_enqueue_async_action( $hook, $args, self::GROUP );
} else {
    wp_schedule_single_event( time(), $hook, $args );   // fallback
}
```

### (3) Single event at a known due time (re-armed)
```php
public static function arm(): void {
    $next = /* MIN(due_at) of pending rows, or null */;
    wp_clear_scheduled_hook( self::HOOK );               // never stack duplicates
    if ( null === $next ) { return; }                    // nothing pending -> disarm
    wp_schedule_single_event( max( strtotime( $next . ' UTC' ), time() ), self::HOOK );
}
public static function run(): void {
    /* process all rows now due */
    self::arm();                                         // re-arm for the next one
}
// Call arm() from EVERY write path (create / reschedule / cancel).
```

### (4) Self-(un)scheduling recurring (queue that drains)
```php
public static function arm_if_needed(): void {
    if ( wp_next_scheduled( self::HOOK ) ) { return; }   // already armed
    if ( ! self::has_pending_work() ) { return; }        // empty -> stay disarmed
    wp_schedule_event( time(), self::INTERVAL, self::HOOK );
}
public static function tick(): void {
    /* process a batch */
    if ( ! self::has_pending_work() ) {
        wp_clear_scheduled_hook( self::HOOK );           // self-disarm when drained
    }
}
// Call arm_if_needed() from the write path that adds work.
```

### (5) Always-on recurring → AS-first with WP-Cron fallback
```php
const GROUP = 'myplugin';
private function maybe_schedule( string $hook, string $recur ): void {
    if ( function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' ) ) {
        if ( false === as_next_scheduled_action( $hook, array(), self::GROUP ) ) {
            if ( wp_next_scheduled( $hook ) ) { wp_clear_scheduled_hook( $hook ); } // kill legacy dupe
            as_schedule_recurring_action( time(), $this->seconds( $recur ), $hook, array(), self::GROUP );
        }
        return;
    }
    if ( ! wp_next_scheduled( $hook ) ) { wp_schedule_event( time(), $recur, $hook ); } // fallback
}
// Handlers stay add_action( $hook, ... ) — AS fires the same hook.
```

### The timing gotcha (this costs real bugs)

Schedule AS actions on **`init` / `wp_loaded` / `action_scheduler_init`**,
**never on `plugins_loaded`**. Action Scheduler's data store is not ready until
`init`; an `as_schedule_*` call before that **silently no-ops** — and if you
cleared the WP-Cron event first, the job ends up unscheduled entirely. If a class
boots at `plugins_loaded`, defer the scheduling: run it now if
`did_action('action_scheduler_init')`, else `add_action('action_scheduler_init', …)`.
Always verify scheduling happened after the fact.

### Deactivation - clear both systems, except work already promised

Deactivation is step one of every routine update (deactivate, replace files, activate), so it must not break a
promise already made to a member. It cancels only jobs that are recomputed or lost by nature (recurring jobs, the
due-time job, digests, trust and badge sweeps, AI review, push sends, recount, the IMAP poll). Jobs that finish an action a member or the owner already committed, or carry state nothing
else can rebuild, stay pending. Jetonomy keeps ONE list, `\Jetonomy\Queue::KEEP_ON_DEACTIVATE`, and every
deactivation path (Free's `Cron::unschedule()`, Pro's `deactivate()`, each extension's base `deactivate()`) cancels
through `Queue::cancel_on_deactivate()`, which skips it:

| Kept hook | Why it must survive | Recovery if it failed while the plugin was off |
|---|---|---|
| `jetonomy_space_purge_batch` | Deletes the rows of a space the owner deleted; the space row is already gone. | Re-queued after the next activation or version change, if it failed within 7 days of Free |
| `jetonomy_publish_scheduled` | Publishes topics their authors scheduled. | Re-queued after the next activation or version change, if it failed within 7 days of Free |
| `jetonomy_fanout_post_subscribers`, `jetonomy_fanout_reply_subscribers` | Write the in-app notifications for content already published; push and email are delivery and may drop, the in-app record may not. | Re-queued after the next activation or version change, if it failed within 7 days of Free |
| `jetonomy_pro_announce_post` | Notifies members of an announcement the owner published. | Re-queued after the next activation or version change, if it failed within 7 days of Pro |
| `jetonomy_pro_upgrade_jobs` | Converts the old version's queued jobs; once the version is stored nothing queues it again. | Re-queued after the next activation or version change, if it failed within 7 days of Pro |
| `jetonomy_pro_reindex_field`, `jetonomy_pro_backfill_searchable_fields` | Re-index custom fields for search; the cursor (or id list) is in the args and nothing else queues the rest. | Re-queued after the next activation or version change, if it failed within 7 days of Pro, or the Custom Fields extension switched back on |
| `jetonomy_pro_deliver_webhook` | Delivers an event that already happened; its body lives only in the job args. | Re-queued after the next activation or version change, if it failed within 7 days of Pro, or the Webhooks extension switched back on |
| `jetonomy_pro_attachments_lock` | Locks the files of a space made private, page by page; stopped midway, files of a private space stay reachable by URL. | Re-queued after the next activation or version change, if it failed within 7 days of Pro, or the Attachments extension switched back on |

Member erasure and moderation cascades run inside the request (no job), so none is listed. While the plugin stays
off, Action Scheduler loaded by another plugin runs a kept row with no listener and marks it FAILED ("no callbacks
are registered") on its next run, about a minute. A flag (`Queue::flag_recovery()`, a one-week transient) is set by
Free's and Pro's activation, by every version change (Free's migration path and Pro's `run_upgrade()`), by
`upgrader_process_complete` for either plugin, and when an extension is switched back on: core's one-click update
deactivates silently (no deactivation or activation hook fires), so activation alone would miss rows failed during
the file swap. On the next scheduling requests `Queue::recover_kept_jobs()` re-queues those rows: only failed rows of
kept hooks whose log carries that message in English, the site's language or the request's language (a job that
really ran and errored stays failed), only rows that failed within the last 7 days (`RECOVER_WINDOW_DAYS`, so an old
fan-out is never replayed to members), 200 per request, same args, one pending copy (`async_once`), and the failed
row is deleted with its logs. A row whose args no longer decode stays failed. Only hooks with a listener at that
moment are recovered, so Pro's wait for Pro. The guard reads the cheap checks first (scheduling request, store
ready, then the flag), so a page view costs nothing. What remains lost: a row that failed more than 7 days before
recovery runs, or logged in a fourth language. Add a new hook to the list only for the same reason, with its row in
this table.

```php
public static function deactivate_cron(): void {
    if ( function_exists( 'as_unschedule_all_actions' ) ) {
        // Hook only: passing array() with a group matches argument-less copies only, so jobs with ids survive.
        as_unschedule_all_actions( self::HOOK );
    }
    wp_unschedule_hook( self::HOOK );                   // every copy, whatever its arguments
}
```

## 4. The cron policy — never force, detect + guide

- **Never** put `define( 'DISABLE_WP_CRON', true )` in plugin code or require it.
  It is a site-wide constant that disables WP-Cron for *every* plugin and
  silently breaks them all if no real system cron is wired.
- Action Scheduler works fine triggered by WP-Cron by default. Running it off a
  real system cron is an *optional* server-level optimisation the owner may apply
  themselves — never a requirement for the plugin to be fast.
- **Detect the failure case and guide the admin** with a Tools health check:

```php
public static function health(): array {
    $off     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
    $overdue = function_exists( 'as_get_scheduled_actions' )
        ? count( as_get_scheduled_actions( array(
            'status' => 'pending', 'date' => as_get_datetime_object( '-1 hour' ),
            'date_compare' => '<=', 'per_page' => 50,
        ), 'ids' ) )
        : 0;
    return array(
        'wp_cron_disabled' => $off,
        'overdue'          => $overdue,
        'stalled'          => ( $off && $overdue > 0 ),
    );
}
```

Render a normal status line; **only** when `stalled` is true, show a warning with
a ready-to-paste system-cron command built from the site's own URL:

```cron
*/5 * * * * wget -q -O - 'https://SITE/wp-cron.php?doing_wp_cron' >/dev/null 2>&1
```

## 5. Action Scheduler effectiveness rules

1. **One group per plugin** (`'myplugin'`) so everything is observable together
   in Tools → Scheduled Actions and bulk-cancelable.
2. **Idempotent guards** — `as_next_scheduled_action()` / `as_has_scheduled_action()`
   before scheduling; keep handlers idempotent (AS retries on failure).
   **The guard itself is a DB query per job, so never run it on every request:** check and arm
   recurring jobs only when `wp_doing_cron() || ( is_admin() && ! wp_doing_ajax() ) ||
   ( defined( 'WP_CLI' ) && WP_CLI )`, plus activation (in Jetonomy: `Queue::is_scheduling_request()`).
   The cron runner re-arms a lost job within one tick. A transient guard is not the fix: without a
   persistent object cache the transient read is itself a query.
3. **Batch sweeps** with a cursor/watermark + continuation. Fan-outs (notifications, indexing) are cursor
   jobs too (rule 6); a failed slice retries from its own cursor, so one bad row never repeats the rest.
4. **Prune our own rows only** — never change Action Scheduler's site-wide retention (its cleaner keeps
   every plugin's finished jobs 31 days). Delete your own group's completed jobs, with their logs, after
   `{prefix}_finished_action_retention_days` (integer days, default 7, counted from when the row finished, the
   `modified` (last attempt) date, not its scheduled date), cancelled ones after the same days counted from their
   scheduled date (Action Scheduler records no cancel time), and your own failed jobs after 90 days
   (Action Scheduler 4.0 does this itself; 3.x never does), from a job that already runs daily, in runs of 200
   rows, at most 50 runs a day and at most 10 seconds a call. Catch the exception `delete_action()` throws for a
   row another process deleted first. Never delete pending rows; uninstall removes all of your rows.
5. **Small arguments** — job args are ids and small integers, under 191 characters (longer args go to a
   separate column and cost a second write). Never put URLs, secrets, tokens or member lists in args.
   Exception: a payload that cannot be read back later, such as an outgoing webhook body describing a topic
   that is deleted the moment the event fires. Carry it in the args (Jetonomy: `jetonomy_pro_deliver_webhook`),
   still never with a URL or secret, which the job reads from its own row by id.
6. **Cursor jobs, never up-front fan-out** — a job walks its rows from `last id` and queues the next run
   only when its slice was full. Never queue `ceil( N / batch )` jobs at once.
7. **`unique` only on Action Scheduler 4.0 or later** - from 4.0 the unique flag matches hook, group and
   args among pending and running rows, in one insert, so two requests arriving together add one row. 3.x
   ignores args. A RUNNING row blocks the insert too, so a job that re-arms itself from inside its run gets
   nothing: when the unique insert answers 0 and no pending copy exists, insert a plain copy (`Queue::at()`). A plugin that bundles 4.0 (Jetonomy, MediaVerse) can rely on it, because the newest bundled
   copy always loads; otherwise dedupe with a pending lookup on the exact hook + args.
8. **Never skip** — a job is never dropped because the runner looks stalled; retries are capped (3, with
   backoff) and the attempt number travels in the args, not in an option or meta.

## 6. Per-job checklist (gate before shipping any job)

1. Could this be lazy-on-read instead of a job?
2. Does it poll when idle? (It must not. The one documented exception in Jetonomy is Reply by Email on IMAP,
   which has no push channel and polls every 5 minutes only while the owner has it switched on.)
3. Group tag set?
4. Idempotent guard before scheduling?
5. Scheduled on `init` / `wp_loaded` / `action_scheduler_init`, **not** `plugins_loaded`?
6. Cleared on deactivate (AS **and** WP-Cron) through `Queue::cancel_on_deactivate()`, or in `KEEP_ON_DEACTIVATE` with its reason?
7. Works with AS absent (WP-Cron fallback) — OR the plugin bundles AS so AS is always present?
8. Zero dependency on `DISABLE_WP_CRON` or any site-specific config?
9. Is there a Tools cron-health line that warns only when genuinely stalled?

## 7. Reference implementations (copy from these)

**BuddyNext**
| Pattern | File |
|---|---|
| Lazy-on-read + transient | `includes/Hashtags/HashtagService.php::get_trending()` |
| Single event at due time (re-armed) | `includes/Feed/ScheduledPostsPublisher.php` |
| Reactive single-shot with backoff | `includes/Outbound/OutboundWebhookService.php` |
| Self-(un)scheduling recurring | `buddynext-pro/includes/Email/BroadcastService.php` |
| AS-first recurring helper | `includes/Core/CronScheduler.php::maybe_schedule()` |
| Cron health + Tools note | `includes/Core/CronScheduler.php::health()` + `includes/Admin/ToolsTab.php` |

**Jetonomy** (2.0.8)
| Pattern | File |
|---|---|
| One scheduling API for Free and Pro (`when_ready`, `async`, `async_once`, `at`, `is_queued`, `is_pending`, `next`, `cancel`, `cancel_on_deactivate`, `flag_recovery`, `recover_kept_jobs`, `is_scheduling_request`, `pending_args`, `recurring`, `ensure_recurring`, `prune_history`, `delete_rows`, `now`). The shared method names behave as in MediaVerse `Core\Jobs`; the differences are listed in the class header and in section 1: `ensure_recurring()` takes an interval only, `recurring()` is a registry armed by `Cron::ensure_scheduled()` (also on `action_scheduler_ensure_recurring_actions`) where each MediaVerse job arms itself, `delete_rows()` exists only here, `adopt_pending()` and `LEGACY_GROUPS` only in MediaVerse, and `at()` / `async_once()` insert with the unique flag | `jetonomy/includes/class-queue.php` |
| Recurring jobs armed in one place, on scheduling requests only; Pro registers through `jetonomy_recurring_jobs` | `jetonomy/includes/class-cron.php::ensure_scheduled()` |
| Single event at the next due moment, re-armed after each run (expiries, reminders, roster backstop) | `jetonomy/includes/class-cron.php::arm_due_jobs()` |
| Single event at due time with capped retries (attempt number in the args) | `jetonomy/includes/class-cron.php::publish_scheduled_posts()` |
| Cursor job (object id + last id in the args) | `jetonomy/includes/notifications/class-notifier.php::fanout_batches()`, `jetonomy-pro/includes/extensions/web-push/class-extension.php::do_send_batch()` |
| Lazy, armed on write (no idle polling) | `jetonomy-pro/includes/extensions/ai/class-batch-reviewer.php::arm()` |
| Upgrade in one request under core's upgrader lock (tables, the conversion job queued, then the version stored), the job conversion as a self-chaining background job | `jetonomy-pro/includes/class-jetonomy-pro.php::run_upgrade()` and `upgrade_jobs()`, each extension's `upgrade_jobs()` / `retired_jobs()` |
| Time-boxed send loop that continues from the last row sent | `jetonomy-pro/includes/extensions/web-push/class-extension.php::do_send_batch()` |
| Bundled Action Scheduler (always present, fallback unneeded) | `jetonomy/jetonomy.php` (loads `libs/action-scheduler`) |

The full Jetonomy job register (every hook, trigger, rows per event and retention) is section 9 of the
Free copy: `jetonomy/docs/standards/background-jobs.md`.

**Known gap:** the cron-health Tools note (section 4) is not implemented in Free or Pro yet.

## 8. Adoption per plugin

1. Copy this file to `docs/standards/background-jobs.md` in the plugin.
2. Add a one-line pointer in the plugin's `CLAUDE.md`.
3. Run the Section 6 checklist (wp-plugin-qa includes it in its audit notes).
4. Fix violations; verify scheduling happened after the fact before release.

## 9. Jetonomy job register (Free + Pro, 2.0.8)

One group for the family: `jetonomy`. One scheduling API: `\Jetonomy\Queue` in Free; Pro calls it and owns
no copy. Recurring jobs are armed only by `Cron::ensure_scheduled()` on cron, wp-admin and WP-CLI
requests and on Action Scheduler's daily `action_scheduler_ensure_recurring_actions` run, never on a
visitor's page view. Completed jobs that finished, and cancelled jobs scheduled, more than
`jetonomy_finished_action_retention_days` (default 7) ago are deleted with their logs by the daily
`jetonomy_prune_activity` run (after the activity-log prune, in `finally`, so neither stops the other), in
runs of 200, at most 50 runs (10,000 rows) per status and 10 seconds (`Queue::TIME_BUDGET`) a day; a bigger
backlog is cleared over the following days. Our own failed rows are kept 90 days for diagnosis, then deleted
the same way (Action Scheduler 4.0 does this itself; 3.x never purges failed rows). Pending rows are kept until
uninstall. The Pro upgrade runs in one request under core's upgrader lock: it updates the extension tables, queues
`jetonomy_pro_upgrade_jobs` with `async_once` (a durable WP-Cron event when Action Scheduler's store is not ready)
and sets the recovery flag, then stores the version, so a request that dies in between leaves the job. That job is
a background job that converts the waiting
old jobs and then cancels and deletes every row of the retired hooks (the 2.0.7 webhook rows held the URL and
secret), re-queueing itself while either remains. A job whose hook was retired keeps its listener when its
queued args cannot be converted (2.0.8: the per-device push until 2.1.0, the field re-index chunks until
2.1.0); otherwise the upgrade converts and cancels it.

| Hook | Plugin | Trigger | Args | Rows per event |
|---|---|---|---|---|
| `jetonomy_trust_evaluation` | Free | recurring, 12 h | none | 1 per run; the first 500 candidates run inline |
| `jetonomy_trust_evaluation_batch` | Free | continuation of the trust sweep | none (cursor in `jetonomy_trust_eval_cursor`) | 1 per further 500 members who could move up; 0 when nobody can |
| `jetonomy_prune_activity` | Free | recurring, daily | none | 1 a day |
| `jetonomy_cleanup_notifications` | Free | recurring, weekly | none | 1 a week |
| `jetonomy_cleanup_media` | Free | recurring, daily | none | 1 a day |
| `jetonomy_due_jobs` | Free | single, armed at the next expiry, owed verification reminder, or hourly while `tier` roster rows exist | none | 0 on a site with nothing due |
| `jetonomy_publish_scheduled` | Free | single, at the earliest scheduled topic | none, or `attempt` (1-3) on a retry | 1 per schedule; at most 3 retries, 1, 2 and 4 minutes apart, then one run (attempt 3) at the next schedule or a day later, whichever is first |
| `jetonomy_fanout_post_subscribers`, `jetonomy_fanout_reply_subscribers` | Free | a post or reply with more than 25 subscribers | object ids + last user id | 1 per 250 subscribers (reads one row ahead, so no empty last run): 20 for a 5,000-member space |
| `jetonomy_space_purge_batch` | Free | a space is deleted | `space_id`, `attempt` | 1 per batch; at most 3 retries |
| `jetonomy_sweep_space_roster` | Free | an access rule of a space is removed | space id | 1 per space change |
| `jetonomy_gravatar_check` | Free | a member without a cached avatar check | user id | 1 per member, once |
| `jetonomy_recount_backfill_batch` | Free | the counter backfill after an update | none (cursor in an option) | 1 per batch, once |
| `jetonomy_pro_send_push_batch` | Pro | a request that created notifications for members with push | first notification id, last id handled, last notification id | 1 per push per request, chaining per 300 members who have a device (reads at most 3,000 rows a run); a run sends for about 15 s (`jetonomy_pro_push_send_seconds`, clock read before every send, 5 s per request), then continues from the last member fully sent; the continuation for the rows after its read is queued before sending |
| `jetonomy_pro_deliver_webhook` | Pro | an event a webhook listens to | webhook id, event, payload (the rule 5 exception: the payload describes content that may be deleted before the job runs; the URL and secret are read from the webhook row) | 1 per webhook and event |
| `jetonomy_pro_prune_webhook_deliveries` | Pro | recurring, daily | none | 1 a day |
| `jetonomy_pro_send_digests` | Pro | the daily or weekly digest run (first batch inline) | frequency, last user id | 1 per further 50 recipients (`jetonomy_pro_digest_batch_size`) |
| `jetonomy_pro_send_daily_digest`, `jetonomy_pro_send_weekly_digest` | Pro | recurring, daily and weekly | none | 1 per run |
| `jetonomy_pro_evaluate_badges` | Pro | recurring, 6 h; does nothing without criteria badges | none | 1 per run; the first 500 members inline |
| `jetonomy_pro_evaluate_badges_after` | Pro | continuation of the badge run | last user id | 1 per further 500 members |
| `jetonomy_pro_reindex_field` | Pro | a field's Searchable or Active switch, or its deletion | field id, last topic id | 1 per 500 topics with a value |
| `jetonomy_pro_attachments_lock` | Pro | a space's privacy changes, or the extension is switched on (unique insert: one copy per space) | space id + offset | 1 per 200 files |
| `jetonomy_pro_ai_batch_review` | Pro | single, 5 min after a topic or reply is written (or when published content waits past the watermark) | none | 1 per 5-minute window with new published content; 0 when idle |
| `jetonomy_pro_ai_generate_summary`, `jetonomy_pro_ai_generate_suggestions` | Pro | a topic view needs a summary or suggestions | post id | 1 per topic per 5 minutes while queued; after a run that produced nothing, none for an hour |
| `jetonomy_pro_upgrade_jobs` | Pro | once per Pro update, queued by `run_upgrade()` | version upgraded from | 1, plus 1 per further 1,000 old webhook deliveries or 10 s of deletes |
| `jetonomy_pro_announce_post` | Pro | a topic is pinned as an announcement | post id, last user id | 1 per batch of members |
| `jetonomy_pro_rbe_imap_poll` | Pro | recurring, 5 min, only with Reply by Email on IMAP (the one documented exception to "no idle polling": IMAP has no push) | none | 288 a day while on |
| `jetonomy_pro_license_check` | Pro | recurring, weekly | none | 1 a week |

Idle site, measured from the live schedule on 2026-11-23 (nothing written, nothing due): Free alone 2 trust
runs + 2 daily jobs + one weekly job (1/7 a day), about 4.1 rows a day; with Pro's badge, digest, webhook and
licence jobs on, about 10.4 rows a day. Reply by Email on IMAP adds 288. One topic in a 5,000-member space,
measured on a 10,000-member seed: 20 fan-out rows + 1 to 20 push rows (one per runner request that recorded
notifications; 6 measured) + 1 badge row. Fan-out and badge runs took under 1 second each; a push run is
bounded by its 15-second send budget plus one send (5 seconds at most), except that the first member of a run
is always sent in full so every run makes progress.

Uninstall deletes every `jetonomy_%` row of Free (and `jetonomy_pro_%` of Pro) with its logs, failed rows
included, in batches; the shared Action Scheduler tables are never dropped, and the runner event is
cleared only when no other active plugin bundles Action Scheduler.

Changes in 2.0.8 for developers: `docs/website/developer-guide/` (Background jobs, Migrating to 2.0.8).

