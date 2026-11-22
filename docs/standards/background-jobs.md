# Wbcom Background-Jobs Standard (Cron + Action Scheduler)

**Status:** Normative. Applies to every Wbcom WordPress plugin/theme that runs
background work (cron, queues, digests, cleanups, scheduled publishing).
**Version:** 1.1 (2026-11-23; first issued 1.0, 2026-06-18). Distilled from the BuddyNext cron/AS audit;
reference implementations across BuddyNext and Jetonomy in Section 7.

This is the single source of truth. Each plugin keeps a synced copy at
`docs/standards/background-jobs.md` and a one-line pointer in its `CLAUDE.md`.
Do not re-derive the rules per plugin — link here.

## 1. The one principle

**A plugin must be fast by default on a vanilla install with zero environment
changes.** Background work is a selling point, not an afterthought. Never rely on
the site owner configuring server cron, and never make a site-wide change on
their behalf.

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

### Deactivation — clear both systems
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
   ( defined( 'WP_CLI' ) && WP_CLI )`, plus activation (in Jetonomy: `Cron::is_scheduling_request()`).
   The cron runner re-arms a lost job within one tick. A transient guard is not the fix: without a
   persistent object cache the transient read is itself a query.
3. **Batch sweeps** with a cursor/watermark + continuation. Fan-outs (notifications, indexing) are cursor
   jobs too (rule 6); a failed slice retries from its own cursor, so one bad row never repeats the rest.
4. **Prune our own rows only** — never change Action Scheduler's site-wide retention (its cleaner keeps
   every plugin's finished jobs 31 days). Delete your own group's completed jobs, with their logs, after
   `{prefix}_finished_action_retention_days` (integer days, default 7), cancelled ones likewise, and your own failed jobs after 90
   days (Action Scheduler 4.0 does this itself; 3.x never does), from a job that already runs daily, in runs
   of 200 rows, at most 50 runs a day. Never delete pending rows; uninstall removes all of your rows.
5. **Small arguments** — job args are ids and small integers, under 191 characters (longer args go to a
   separate column and cost a second write). Never put URLs, secrets, tokens or member lists in args.
6. **Cursor jobs, never up-front fan-out** — a job walks its rows from `last id` and queues the next run
   only when its slice was full. Never queue `ceil( N / batch )` jobs at once.
7. **No `unique` with arguments** — Action Scheduler's unique flag ignores args; dedupe with a pending
   lookup on the exact hook + args instead (in Jetonomy: `Queue::async_once()`).
8. **Never skip** — a job is never dropped because the runner looks stalled; retries are capped (3, with
   backoff) and the attempt number travels in the args, not in an option or meta.

## 6. Per-job checklist (gate before shipping any job)

1. Could this be lazy-on-read instead of a job?
2. Does it poll when idle? (It must not. The one documented exception in Jetonomy is Reply by Email on IMAP,
   which has no push channel and polls every 5 minutes only while the owner has it switched on.)
3. Group tag set?
4. Idempotent guard before scheduling?
5. Scheduled on `init` / `wp_loaded` / `action_scheduler_init`, **not** `plugins_loaded`?
6. Cleared on deactivate (AS **and** WP-Cron)?
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
| One scheduling API for Free and Pro, the same shape as MediaVerse `Core\Jobs` (`when_ready`, `async`, `async_once`, `at`, `is_queued`, `is_pending`, `next`, `cancel`, `pending_args`, `recurring`, `ensure_recurring`, `prune_history`, `delete_rows`) | `jetonomy/includes/class-queue.php` |
| Recurring jobs armed in one place, on scheduling requests only; Pro registers through `jetonomy_recurring_jobs` | `jetonomy/includes/class-cron.php::ensure_scheduled()` |
| Single event at the next due moment, re-armed after each run (expiries, reminders, roster backstop) | `jetonomy/includes/class-cron.php::arm_due_jobs()` |
| Single event at due time with capped retries (attempt number in the args) | `jetonomy/includes/class-cron.php::publish_scheduled_posts()` |
| Cursor job (object id + last id in the args) | `jetonomy/includes/notifications/class-notifier.php::fanout_batches()`, `jetonomy-pro/includes/extensions/web-push/class-extension.php::do_send_batch()` |
| Lazy, armed on write (no idle polling) | `jetonomy-pro/includes/extensions/ai/class-batch-reviewer.php::arm()` |
| One-time conversion of an older version's queued jobs on upgrade, under core's upgrader lock, version stored after | `jetonomy-pro/includes/class-jetonomy-pro.php::run_upgrade()`, each extension's `upgrade_jobs()` / `retired_jobs()` |
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
requests, never on a visitor's page view. Finished (completed) jobs older than
`jetonomy_finished_action_retention_days` (default 7) are deleted with their logs by the daily
`jetonomy_prune_activity` run, in runs of 200, at most 50 runs (10,000 rows) a day; a bigger backlog is
cleared over the following days. Our own cancelled rows go after the same 7 days. Our own failed rows are kept 90 days for diagnosis, then
deleted the same way (Action Scheduler 4.0 does this itself; 3.x never purges failed rows). Pending rows are kept until
uninstall. Every row of the retired 2.0.7 webhook hook (its args held the URL and secret) is deleted, in any
status with its logs, right after its deliveries are re-queued. Old jobs are converted once, by the Pro upgrade under core's upgrader lock; the version is stored
after the conversion, so a request that dies part way is retried by the next one.
A job whose hook was retired keeps its listener for one release when its queued args cannot be converted
(2.0.8: the per-device push and field re-index chunks); otherwise the upgrade converts and cancels it.

| Hook | Plugin | Trigger | Args | Rows per event |
|---|---|---|---|---|
| `jetonomy_trust_evaluation` | Free | recurring, 12 h | none | 1 per run; the first 500 candidates run inline |
| `jetonomy_trust_evaluation_batch` | Free | continuation of the trust sweep | none (cursor in `jetonomy_trust_eval_cursor`) | 1 per further 500 members who could move up; 0 when nobody can |
| `jetonomy_prune_activity` | Free | recurring, daily | none | 1 a day |
| `jetonomy_cleanup_notifications` | Free | recurring, weekly | none | 1 a week |
| `jetonomy_cleanup_media` | Free | recurring, daily | none | 1 a day |
| `jetonomy_due_jobs` | Free | single, armed at the next expiry, owed verification reminder, or hourly while `tier` roster rows exist | none | 0 on a site with nothing due |
| `jetonomy_publish_scheduled` | Free | single, at the earliest scheduled topic | none, or `attempt` (1-3) on a retry | 1 per schedule; at most 3 retries, 1, 2 and 4 minutes apart |
| `jetonomy_fanout_post_subscribers`, `jetonomy_fanout_reply_subscribers` | Free | a post or reply with more than 25 subscribers | object ids + last user id | 1 per 250 subscribers (reads one row ahead, so no empty last run): 20 for a 5,000-member space |
| `jetonomy_space_purge_batch` | Free | a space is deleted | `space_id`, `attempt` | 1 per batch; at most 3 retries |
| `jetonomy_sweep_space_roster` | Free | an access rule of a space is removed | space id | 1 per space change |
| `jetonomy_gravatar_check` | Free | a member without a cached avatar check | user id | 1 per member, once |
| `jetonomy_recount_backfill_batch` | Free | the counter backfill after an update | cursor | 1 per batch, once |
| `jetonomy_pro_send_push_batch` | Pro | a request that created notifications for members with push | first notification id, last id handled, last notification id | 1 per push per request, chaining per 300 members who have a device (reads at most 3,000 rows a run); a run sends for about 15 s (`jetonomy_pro_push_send_seconds`), then continues from the last member sent |
| `jetonomy_pro_deliver_webhook` | Pro | an event a webhook listens to | webhook id, event, payload | 1 per webhook and event |
| `jetonomy_pro_prune_webhook_deliveries` | Pro | recurring, daily | none | 1 a day |
| `jetonomy_pro_send_digests` | Pro | the daily or weekly digest run (first batch inline) | frequency, last user id | 1 per further 50 recipients (`jetonomy_pro_digest_batch_size`) |
| `jetonomy_pro_send_daily_digest`, `jetonomy_pro_send_weekly_digest` | Pro | recurring, daily and weekly | none | 1 per run |
| `jetonomy_pro_evaluate_badges` | Pro | recurring, 6 h; does nothing without criteria badges | none | 1 per run; the first 500 members inline |
| `jetonomy_pro_evaluate_badges_after` | Pro | continuation of the badge run | last user id | 1 per further 500 members |
| `jetonomy_pro_reindex_field` | Pro | a field's Searchable or Active switch, or its deletion | field id, last topic id | 1 per 500 topics with a value |
| `jetonomy_pro_attachments_lock` | Pro | a space's privacy changes, or the extension is switched on | space id + offset | 1 per 200 files |
| `jetonomy_pro_ai_batch_review` | Pro | single, 5 min after a topic or reply is written (or when content is waiting) | none | 1 per 5-minute window with new content; 0 when idle |
| `jetonomy_pro_ai_generate_summary`, `jetonomy_pro_ai_generate_suggestions` | Pro | a topic needs a summary or suggestions | post id | 1 per topic |
| `jetonomy_pro_announce_post` | Pro | a topic is pinned as an announcement | post id, last user id | 1 per batch of members |
| `jetonomy_pro_rbe_imap_poll` | Pro | recurring, 5 min, only with Reply by Email on IMAP (the one documented exception to "no idle polling": IMAP has no push) | none | 288 a day while on |
| `jetonomy_pro_license_check` | Pro | recurring, weekly | none | 1 a week |

Idle site, measured from the live schedule on 2026-11-23 (nothing written, nothing due): Free alone 2 trust
runs + 2 daily jobs + one weekly job (1/7 a day), about 4.1 rows a day; with Pro's badge, digest, webhook and
licence jobs on, about 10.4 rows a day. Reply by Email on IMAP adds 288. One topic in a 5,000-member space,
measured on a 10,000-member seed: 20 fan-out rows + 1 to 20 push rows (one per runner request that recorded
notifications; 6 measured) + 1 badge row. Fan-out and badge runs took under 1 second each; a push run is
bounded by its 15-second send budget plus one member's sends, because each send waits on the push service.

Uninstall deletes every `jetonomy_%` row of Free (and `jetonomy_pro_%` of Pro) with its logs, failed rows
included, in batches; the shared Action Scheduler tables are never dropped, and the runner event is
cleared only when no other active plugin bundles Action Scheduler.

Changes in 2.0.8 for developers: `docs/website/developer-guide/` (Background jobs, Migrating to 2.0.8).

