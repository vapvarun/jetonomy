# Wbcom Background-Jobs Standard (Cron + Action Scheduler)

**Status:** Normative. Applies to every Wbcom WordPress plugin/theme that runs
background work (cron, queues, digests, cleanups, scheduled publishing).
**Version:** 1.0 (2026-06-18). Distilled from the BuddyNext cron/AS audit;
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
        as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
    }
    wp_clear_scheduled_hook( self::HOOK );
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
3. **Batch sweeps** with a cursor/watermark + continuation; keep fan-outs
   (notifications, indexing) per-item async so each retries independently.
4. **Prune** — let AS retention clear `actionscheduler_*` tables (on by default);
   verify it is enabled so the logs don't bloat.

## 6. Per-job checklist (gate before shipping any job)

1. Could this be lazy-on-read instead of a job?
2. Does it poll when idle? (It must not.)
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

**Jetonomy**
| Pattern | File |
|---|---|
| AS-first recurring, scheduled on `action_scheduler_init` (correct timing) | `jetonomy/includes/class-cron.php` |
| Deferred scheduling helper (`plugins_loaded`-safe `when_ready()`) | `jetonomy-pro/includes/class-queue.php` |
| Reactive single-shot + batch fan-out primitives | `jetonomy-pro/includes/class-queue.php::async()/batch()` |
| Reactive single-shot on earn + safety-net reconcile (6h, above hourly) | `jetonomy-pro/includes/extensions/custom-badges/class-extension.php` |
| Bundled Action Scheduler (always present, fallback unneeded) | `jetonomy/jetonomy.php` (loads `libs/action-scheduler`) |

**Known gaps (tracked, fix in 1.5.x):**
- **Cron-health Tools note (§4)** — not implemented in free or pro. Add a Tools
  status line that warns only when `DISABLE_WP_CRON` is set AND actions are overdue.
- **AI batch reviewer idle-polls (§2 anti-pattern)** —
  `jetonomy-pro/includes/extensions/ai/class-batch-reviewer.php` schedules a
  5-minute recurring action whenever AI review is enabled, firing whether or not
  content is pending. Move it to reactive (enqueue/arm on content-needing-review,
  the way custom-badges does async-on-earn) so it is dormant when idle.

## 8. Adoption per plugin

1. Copy this file to `docs/standards/background-jobs.md` in the plugin.
2. Add a one-line pointer in the plugin's `CLAUDE.md`.
3. Run the Section 6 checklist (wp-plugin-qa includes it in its audit notes).
4. Fix violations; verify scheduling happened after the fact before release.
