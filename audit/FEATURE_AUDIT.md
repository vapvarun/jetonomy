# Feature Audit — Jetonomy (free)

> **Source of truth:** [`manifest.json`](manifest.json). This document is a human-readable view of the same data, refreshed on 2026-05-14 by `/wp-plugin-onboard --refresh` (delta on top of the 2026-04-30 base).

> **1.5.0-dev delta (2026-06-11):** the full-audit fix series changed the inventory — read [`full-audit-2026-06-11.md`](full-audit-2026-06-11.md) for the complete findings. Net feature-level changes: **removed** jt_space_tags / jt_space_tag_map / jt_user_interests tables (never wired, Migration_1_5_0 drops them), GET /space-tags, 3 dead AJAX actions (run_import, import_progress, get_replies), 9 superseded REST permission callbacks, ~17 dead model methods, the jetonomy_recent_activity shortcode entry (was never registered). **Added** GET /auth/nonce (session nonce refresh backing the restFetch 403 retry), jetonomy_post_publish_transition / jetonomy_reply_publish_transition hooks (consumed by Pro analytics), captcha_token on /auth/login, Captcha_Adapter::render_widget() (Turnstile containers on every captcha-verified form), Avatar class (local avatar resolution via pre_get_avatar_data, jt_user_profiles.avatar_url now consumed), InviteLink::accept() (single owner of the invite flow), Template_Loader::enqueue_rest_client() (REST client on embed surfaces). Counts in the Summary table below predate this delta — the manifest is current.

## Summary

| Category | Count | Notes |
|---|---|---|
| REST endpoints | 67 | Namespace `jetonomy/v1`. 15 controllers extending `Base_Controller`. +2 in 1.4.3 (DELETE /notifications/{id}, POST /notifications/bulk); existing GET /notifications gained `?filter=`. |
| AJAX handlers | 43 | All in `includes/admin/ajax/` — admin-only. Frontend uses REST. |
| Hooks fired | 108 | 62 actions + 44 filters + 2 deprecated aliases. +3 in 1.4.3 (jetonomy_post_list_results_for_space, jetonomy_post_card_after_badges, jetonomy_reputation_points_for). |
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

- [x] **Pro contract gap (HIGH) — FIXED in 1.4.1 (commit `c54b856`).** 13 missing free hooks wired so Pro extensions (white-label, custom-fields, webhooks) actually fire on customer sites.
- [x] **Abilities `execute_search` runtime fatal — FIXED in 1.4.1 (commit `d609424`).** `class-abilities.php:1609, 1612` was calling `$adapter->search_posts()` and `$adapter->search_spaces()` on a `Search_Adapter`-typed variable; methods don't exist on the interface and are private on `Fulltext_Search`. Suppressed in `phpstan-baseline.neon` until detector D1 surfaced it. Now routes through `Search_Adapter::search()`. Both baseline suppressions removed.
- [x] **Verification reminder bypassed Email_Adapter — FIXED in 1.4.1 (commit `5a67043`).** `class-notifier.php:187` reached for an undefined `jetonomy_get_email_adapter()` helper and fell back to direct `wp_mail()`. Customers configuring a custom Email_Adapter (Pro Mailgun / SES / Postmark when one ships) silently lost the verification-reminder path. Now uses `Adapter_Registry::get_email()`.
- [x] **Admin "send test email" bypassed Email_Adapter — FIXED in 1.4.1 (commit `5a67043`).** `class-settings-handler.php:90` fired `wp_mail()` directly. The whole point of "test email" is to verify the production path; bypassing the adapter defeated that. Now routes through `Adapter_Registry::get_email()`.
- [ ] **Search adapter contract too narrow (MEDIUM, deferred to 1.4.2).** `Search_Adapter::search()` signature can't carry the filters `Search_Controller` already applies (tag, date, author, sort, viewer-aware visibility), so the controller bypasses the registry. Direction recorded in `interface-search-adapter.php` head comment. Block A3/A4 in `plan/punch-list-2026-04-30.md`.
- [x] **Grid track-overflow risk (LOW) — FIXED in 1.4.1.** `minmax(0, 1fr)` added to 4 fixed-column grids on each side.
- [x] **Suppressed-baseline debt — partially closed in 1.4.1.** Baseline shrunk 262 → 86 (67%) via stub-hardening (commit `666db38`) and view-template `@var` docblocks (commit `eca6bcd`). Remaining 86 are mostly defensive `is_wp_error` checks against filter-returnable errors and `$wpdb->get_row()` generic-object access. Carry to 1.4.2 as Block G3.

### A11y findings (Sprint 3 first-pass audit, 2026-04-30)

Sonnet sub-agent walked the five customer-critical flows (new post, single post + voting, reply submission, moderation queue, leaderboard) at 390px and 1280px in light + dark mode. 9 verified WCAG 2.1 AA gaps. Quick wins land in 1.4.1; toggle-state and live-region work carry to 1.4.2 because each requires WP Interactivity binding changes and template restructuring.

- [x] **A11Y-1 — breadcrumb contrast 3.11:1 (light) / 3.15:1 (dark) — FIXED in 1.4.1.** `--jt-text-tertiary` token bumped from 60% → 65% (light) and 40% → 60% (dark) so all 84 surfaces using it pass 4.5:1.
- [x] **A11Y-7 — heading hierarchy on single post + new post — FIXED in 1.4.1.** Single post: `Replies` h3 → h2, `Your Reply` h4 → h3. New post (Pro custom-fields): `Additional Fields` h4 → h2. WCAG 1.3.1.
- [x] **A11Y-6 — reaction picker buttons used machine slugs — FIXED in 1.4.1.** Pro reactions extension now uses the human label from the REACTIONS map (`Like`, `Love`, `Haha`, etc.) as the `aria-label` instead of the slug (`thumbsup`, `heart`, …). WCAG 4.1.2.
- [x] **A11Y-9 — sort pills missing `aria-current` — FIXED in 1.4.1.** Active sort pill across single-post replies, space, tag, and search views now sets `aria-current="true"`. WCAG 4.1.2.
- [ ] **A11Y-2 — vote / bookmark / follow toggle buttons no `aria-pressed` (HIGH, deferred to 1.4.2).** Each toggle is a WP Interactivity-bound button; needs `data-wp-bind--aria-pressed` wired to the corresponding state field. WCAG 4.1.2. Affects every post and reply page.
- [ ] **A11Y-3 — vote count updates have no `aria-live` region (HIGH, deferred to 1.4.2).** Casting / removing a vote updates `.jt-vote-cluster .n` silently. Wrap the score span in a `polite` live region. WCAG 4.1.3.
- [ ] **A11Y-4 — reply / new-post composer `contenteditable` missing `role="textbox"` and `aria-multiline="true"` (HIGH, deferred to 1.4.2).** Some screen readers (NVDA + Firefox in particular) don't recognise the editor as an editable field. WCAG 4.1.2.
- [ ] **A11Y-5 — "More options" menu button has no `aria-expanded` / `aria-haspopup` (MEDIUM, deferred to 1.4.2).** Custom dropdown needs Interactivity-bound `aria-expanded` and the popup list needs `role="menu"` + `role="menuitem"` children. WCAG 4.1.2.
- [ ] **A11Y-8 — leaderboard top-3 rank position not announced to AT (MEDIUM, deferred to 1.4.2).** Medal SVGs are `aria-hidden="true"` with no text alternative; rank 1/2/3 invisible to screen readers. Add visually-hidden `<span class="screen-reader-text">1st place</span>` for top-3. WCAG 1.3.1.

## 1.4.3 feature drop (2026-05-14)

Four commits between 2026-05-13 and 2026-05-14 expanded the public surface:

**Top-level Admin Bar** — new `Jetonomy\Admin_Bar` class in `includes/class-admin-bar.php`, registered on `admin_bar_menu` priority 60. Adds a quick-jump menu to the WP admin bar (frontend + backend) so logged-in users can hop straight into spaces, moderation queue, and notifications without leaving wp-admin or the public theme. First top-level admin-bar integration the plugin ships — previously every nav surface was inside the `/community/*` shell.

**Notifications inbox redesign** — `templates/admin/notifications.php` rewritten end-to-end. New affordances: filter tabs (all / unread / mentions / replies / system), bulk-action toolbar (select-all + mark-read + delete), per-row overflow menu, pagination. Surfaces backed by 2 new REST endpoints (`DELETE /notifications/{id}`, `POST /notifications/bulk`) and a `?filter=` query arg on the existing `GET /notifications`. New `Notification` model methods (`list_for_user_with_targets`, `counts_by_filter`, `delete_for_user`, `mark_read_for_user`, `count_for_user($filter)`) keep all per-user scoping in the model layer — no raw `$wpdb` outside `includes/db/`. The empty state uses the new unified `jetonomy_admin_empty_state()` global helper (`includes/helpers.php`, function-exists-guarded).

**Configurable reputation** — `Reputation::points_for()` now passes its return value through a new `jetonomy_reputation_points_for` filter, so any layer (Pro, theme, mu-plugin) can override the points awarded for a given action. The canonical defaults are exposed via `Reputation::action_points_defaults()`, and the merged (defaults + settings + filter) map is available via `Reputation::action_points_map()`. The Settings page gained a "Reputation points" card backed by a new `reputation_points` array on the existing `jetonomy_settings` option; `sanitize_settings()` filters to known action slugs from `action_points_defaults()` so unknown keys are dropped silently.

**Pro consumption hooks** — two new public hooks fired specifically so the new `site-announcements` Pro extension can hang off them: `jetonomy_post_list_results_for_space` (filter, inside `Post::list_by_space_visible()` so Pro can re-order pinned posts to the top of every feed) and `jetonomy_post_card_after_badges` (action, inside `templates/partials/post-card.php` so Pro can render an "Announcement" badge on pinned cards). Both follow the existing `consumed_by: jetonomy-pro` convention in the manifest.

**a11y polish** — focus-visible drift fixed on the editor body and feed actions (commit `b0fa218`). No surface-area change; visible focus rings now match the rest of the plugin's a11y contract.

**Misc fixes** — BuddyPress dark-mode propagation, composer Private-toggle spacing, Banned Users confirm copy tone (commit `e2a3cd0`). UI-only; no manifest topology change.

## How to re-run

```bash
cd wp-content/plugins/jetonomy
/wp-plugin-onboard --refresh
```

Refresh re-scans only the changed source files since `manifest.generated.at` and updates the `static_analysis` sections in-place.
