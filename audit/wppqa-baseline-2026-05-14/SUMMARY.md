# wppqa baseline — 2026-05-14 (post-1.4.3 feature drop, v1.4.3)

Refresh trigger: 4 commits landed between 2026-05-13 and 2026-05-14 that
expand the public hook surface and add a new top-level admin bar integration.
This baseline is a manifest-refresh companion — full `mcp__wp-plugin-qa__*`
scans were **not re-run** for this delta (last full run: 2026-05-13). See the
delta below for the new surface area that future runs should cover.

Skipped tooling: full `wppqa_check_plugin_dev_rules`, `wppqa_check_rest_js_contract`,
`wppqa_check_wiring_completeness` re-runs. The 2026-05-13 baseline's findings
(13 warnings, 19 false positives in rest-js-contract, 2 false positives in
wiring) still stand — no code in this delta changes the surfaces those
checkers exercise.

## New surface area since 2026-05-13

### Commits in scope
- `16215a2` feat: admin bar + Single Idea spacing + configurable reputation + Pro hooks
- `6bfebca` feat: unified admin empty-state primitive + notifications page redesign
- `e2a3cd0` fix: BuddyPress dark-mode propagation, composer Private toggle spacing, Banned Users confirm tone
- `b0fa218` css: fix focus-visible drift on editor body + feed actions (a11y)

### New code that future wppqa scans should cover

**New class**
- `Jetonomy\Admin_Bar` — `includes/class-admin-bar.php`. Registers on
  `admin_bar_menu` priority 60 with a top-level community quick-jump menu
  (frontend + admin).

**New global helper**
- `jetonomy_admin_empty_state( array $args ): void` — `includes/helpers.php`.
  Function-exists-guarded. Renders the unified `.jetonomy-empty-state`
  primitive used across all Free admin views (and now Pro too via the Pro
  empty-state migration in commit `45cd375`).

**New `Notification` model methods**
- `list_for_user_with_targets()` — paginated read + joined targets.
- `counts_by_filter()` — `SELECT` aggregates per filter bucket.
- `delete_for_user()` — bulk delete with user_id guard.
- `mark_read_for_user()` — bulk mark-read with user_id guard.
- `count_for_user()` — gained a `$filter` arg.

**New REST routes** (`Notifications_Controller`)
- `DELETE /notifications/(?P<id>\d+)` → `delete_notification()`
- `POST   /notifications/bulk`        → `bulk_action()` (mark_read | delete)
- `GET    /notifications` — gained `?filter=` query arg.

**New public hooks fired** (extension surface for Pro)
- filter `jetonomy_post_list_results_for_space` — fires inside
  `Post::list_by_space_visible()` immediately before returning the result
  set. Consumed by jetonomy-pro/site-announcements extension.
- action `jetonomy_post_card_after_badges` — fires inside
  `templates/partials/post-card.php` right after the badges row.
  Consumed by jetonomy-pro/site-announcements.
- filter `jetonomy_reputation_points_for` — fires inside
  `Reputation::points_for()` before returning the final point value.
  Pro NOT currently consuming.

**New `Reputation` API**
- `Reputation::action_points_map(): array` — returns the merged
  (defaults + settings + filter) action→points map.
- `Reputation::action_points_defaults(): array` — returns the canonical
  defaults shipped by the plugin.

**Settings**
- `sanitize_settings()` now handles a `reputation_points` array
  (keys = action slugs, values = ints). New "Reputation points" card on
  the Settings page.

**Templates rewritten**
- `templates/admin/notifications.php` — filter tabs, bulk toolbar,
  per-row menu, pagination. Uses `jetonomy_admin_empty_state()` for the
  zero-result state.

## Manifest impact
- `hooks_fired`: +3 (jetonomy_post_list_results_for_space,
  jetonomy_post_card_after_badges, jetonomy_reputation_points_for).
- `rest.endpoints`: +2 (DELETE /notifications/{id}, POST /notifications/bulk).
- `services`: empty list pre-existing; no change.
- manifest schema doesn't enumerate classes/global_helpers as top-level
  arrays — Admin_Bar and `jetonomy_admin_empty_state()` surface via the
  refresh_note on `.generated` instead.
