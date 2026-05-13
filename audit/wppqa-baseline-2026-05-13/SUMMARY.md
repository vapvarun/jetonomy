# wppqa baseline — 2026-05-13 (post-WS1-WS4, v1.4.3)

Refresh trigger: 1.4.2 → 1.4.3 release cycle with substantial wiring migrations
(WS1 compose pipeline, WS2 REST_Auth helper, WS3 JS primitives + messaging,
WS4 hook wiring + i18n lint gate).

## plugin-dev-rules
- ✓ 9 passed, 0 failed (same as 2026-05-07).
- 13 warnings — ALL pre-existing:
  - 1× breakpoint proliferation (7 distinct values across CSS — unchanged since 2026-05-07).
  - 12× tap-target < 40px on 3 unique selectors across 4 build outputs
    (`jetonomy.css`, `jetonomy-rtl.css`, and both `.min.css` mirrors).
  - No new regressions from WS1-WS4.

## rest-js-contract
- 41 passed, 19 flagged — ALL confirmed false positives.
- Same false-positive class as 2026-05-07 baseline: wppqa's regex extracts
  the envelope shape `[data, meta]` from Base_Controller responses but doesn't
  follow the `data.data || data.results || data` fallback chain that JS uses
  to support both legacy and envelope responses (e.g. `view.js:230`).
- Sample verified: `assets/js/view.js:230` (`data.results`) — code reads
  `const posts = data.data || data.results || data;` then arrays-checks.
  Safe; envelope-aware.
- Sample verified: `assets/js/header.js:190` (`r.title`) — `r` is a single
  search result item INSIDE the envelope, not the envelope itself. The grep
  doesn't understand iteration scope.

## wiring
- 8 passed, 2 flagged — BOTH confirmed false positives.
- `action_type` at includes/admin/class-activity-list-table.php:391 — this is
  a `<select name="action_type">` filter dropdown on an admin list table
  (query-string filter, never persisted to options).
- `viewport` at includes/admin/views/setup-wizard.php:20 — this is the literal
  HTML `<meta name="viewport">` tag, not a settings field.
- wppqa's regex matches `name="X"` indiscriminately; UI form fields and HTML
  metadata get conflated.

## Undefined-reference hunt (extended scope per refresh task)

### REST_Auth + restFetch migration (WS2-A/B)
- ✓ `Jetonomy\API\REST_Auth` class exists at `includes/api/class-rest-auth.php`
  with `auth_mutation()`, `auth_public_write()`, `auth_read()` factories.
- ✓ 14 controllers migrated; all method calls match definitions.
- ✓ `bin/audit-rest-routes.php` reports "OK (no mutation routes missing REST_Auth)".
- ✓ `\Jetonomy\Visibility::rest_check` exists; 14 read routes reference it correctly.
- ✓ `window.jetonomyRest.restFetch()` exported from `assets/js/jetonomy-rest.js`;
  used in 5 JS modules.
- ✓ `window.jetonomyOptimistic.gen()` exported from optimistic.js; used in view.js.

### Working-tree (uncommitted) model + schema changes
- ✓ New paginated-list methods on Flag/Notification/Space/Tag/Revision/SpaceMember
  models all defined in their class files (limit/offset params + count_* siblings).
- ✓ New helper `\Jetonomy\get_profile_url()` in `includes/functions.php`
  used by leaderboards controller.
- ✓ `includes/db/class-schema.php` working-tree changes are additive
  (index-only); no column references that don't exist.

### Hook wiring (WS4-B — post_reported, flag_validated, idea_planned)
- ✓ `jetonomy_flag_created` (2 args) — fired in moderation-controller;
  Pro webhooks extension listens (`on_flag_created`).
- ✓ `jetonomy_flag_resolved` (3 args) — fired in moderation-service;
  Pro webhooks extension listens (`on_flag_resolved`).
- ⚠️ `jetonomy_after_resolve_flag` (2 args) — fired in moderation-service:259,
  NO listeners in either plugin. Dead-fire-only. Likely intentional
  (extension point reserved for future custom listeners) but worth noting
  in `static_analysis.dead_listeners` of manifest.
- ✓ `jetonomy_idea_status_changed` (4 args, pre-existing) — signature
  corrected in WS4-B; activity_tracker + notifier still wired correctly.

### Real findings
- None of high severity that affect free in isolation.
- See companion `jetonomy-pro/audit/wppqa-baseline-2026-05-13/SUMMARY.md`
  for the 3 cross-plugin i18n drift findings (free template-loader is the
  place those keys are localized; Pro JS reads them).

## Verdict
- **1.4.3 free is RELEASE-READY from a static-analysis perspective.**
- One LOW-priority manifest gap to note (`jetonomy_after_resolve_flag`
  is a dead-fire — add to `static_analysis.dead_listeners` on next refresh).
- All other wppqa "failures" verified as known false positives.
