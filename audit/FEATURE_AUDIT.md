# Feature Audit — Jetonomy (free)

> **Source of truth:** [`manifest.json`](manifest.json). This document is a human-readable view of the same data, refreshed on 2026-04-30 by `/wp-plugin-onboard --refresh`.

## Summary

| Category | Count | Notes |
|---|---|---|
| REST endpoints | 64 | Namespace `jetonomy/v1`. 15 controllers extending `Base_Controller`. |
| AJAX handlers | 43 | All in `includes/admin/ajax/` — admin-only. Frontend uses REST. |
| Hooks fired | 104 | 59 actions + 43 filters + 2 deprecated aliases. |
| Tables | 23 | All `jt_*` prefix, created via `dbDelta()` in `includes/db/class-schema.php`. |
| Capabilities | 23 | Custom + 3-layer permission engine. |
| Blocks | 8 | PHP-registered (no `block.json`). Includes Login, Navigation, Activity, Spaces, Top Members. |
| Shortcodes | 9 | `jetonomy_*` legacy + 6 block shortcodes. |
| WP-CLI | 14 | Subcommands under `wp jetonomy *`. |
| Cron hooks | 6 | All scheduled via `wp_schedule_event`. |
| Admin pages | 14 | Dashboard, Spaces, Categories, Content, Moderation, Users, Activity, Revisions, Settings, Tags, Taxonomies, Import, Setup, Imports. |

## Static analysis findings (v2 schema additions)

### Dead listeners — none on free side
The 6 hooks initially flagged (`jetonomy_cleanup_expired`, `jetonomy_cleanup_notifications`, `jetonomy_prune_activity`, `jetonomy_publish_scheduled`, `jetonomy_trust_evaluation`, `jetonomy_verification_reminder`) are WordPress cron events scheduled via `wp_schedule_event()`. They fire from the WP-Cron scheduler, not from a `do_action()` call in source. **Not bugs.**

### Grid 1fr collapse risks (low severity)
4 fixed-track grids use bare `1fr` instead of `minmax(0, 1fr)`. Current cell content fits, so no visible bug — but if a future contributor places long unbreakable text in any of these cells, the track will overflow. Address on next CSS sweep.

| File | Selector | Fix |
|---|---|---|
| `assets/css/jetonomy.css:617` | `.jt-stats-bar` | `repeat(4, minmax(0, 1fr))` |
| `assets/css/jetonomy.css:623` | `.jt-badges` | `repeat(3, minmax(0, 1fr))` |
| `assets/css/jetonomy.css:631` | `.jt-kanban` | `repeat(4, minmax(0, 1fr))` |
| `assets/css/jetonomy.css:694` | `.jt-kanban` (tablet breakpoint) | `repeat(2, minmax(0, 1fr))` |

### Visual required without enforcement — none
Both `<span class="jt-required">` markers (`templates/views/new-space.php:65`, `templates/views/space-edit.php:232`) sit on labels for inputs that already carry the HTML5 `required` attribute. No degraded UX surface.

### REST hang risks — n/a
Free does not use `wp.apiFetch`. All frontend network calls go through the Interactivity API store actions which use raw `fetch()` with internal error handling. A future improvement would be adding `AbortController` signals to user-cancellable actions (typeahead, search-as-you-type), but no current call leaves the UI in a permanent loading state on a stalled network.

### Layout-owning blocks — 0
No `block.json` blocks; all blocks are PHP-registered render-only. No layout-owning candidates.

## Pro contract dependencies — discovered bugs in jetonomy-pro

The refresh cross-checked Pro's `add_action`/`add_filter` registrations against this plugin's fired hooks. **13 Pro listeners are silently inert** because the corresponding free hook is either named differently or never fired at all. Three customer-impact patterns:

1. **White-label customizations don't apply** — Pro's `white-label` extension filters `jetonomy_header_logo`, `jetonomy_footer_text`, `jetonomy_admin_footer_text`. Free never calls `apply_filters()` on any of those names. Branding overrides set in admin silently do nothing on the live site.
2. **Custom-fields values never reach REST responses** — Pro's `custom-fields` extension filters `jetonomy_post_response` and `jetonomy_profile_response`. Free never applies those filters. Fields configured in admin appear unused on the API.
3. **Webhooks extension never fires** — Pro's `webhooks` extension subscribes to 8 lifecycle events (post/reply update + delete, flag create + resolve, member join + leave). Free fires the same events under different names (e.g. `jetonomy_post_updated` vs `jetonomy_after_update_post`). The naming drift means webhooks subscribers never see any of those events.

Full details with file:line + suggested fix in `jetonomy-pro/audit/manifest.json` → `static_analysis.dead_listeners[]` (13 entries).

The fix is mechanical for each one: either rename the Pro listener to the actual free hook name, OR add an aliasing `do_action()` call in free at the documented call site. **Lockstep approach recommended:** add the missing `apply_filters()` / `do_action()` calls in free (with the names Pro already listens for) so future extensions can rely on the documented contract.

## Known issues surfaced by audit (action items)

- [ ] **Pro contract gap (HIGH).** Wire 13 missing free hooks (or rename Pro listeners). See list above. Slot for next release.
- [ ] **Grid track-overflow risk (LOW).** Add `minmax(0, 1fr)` to 4 fixed-column grids. Drop into next CSS sweep.

## How to re-run

```bash
cd wp-content/plugins/jetonomy
/wp-plugin-onboard --refresh
```

Refresh re-scans only the changed source files since `manifest.generated.at` and updates the `static_analysis` sections in-place.
