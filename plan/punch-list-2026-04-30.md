# Jetonomy free + pro — punch list from 2026-04-30 audit session

Single consolidated plan. Every item has file:line evidence and effort. Replaces the prior multi-file plan sprawl from this session (`v1.5-search-adapter-completion.md` is folded in here as Block A).

**Status:** PLANNING — no code changes from this list until items are signed off.
**Branch:** 1.4.1
**Source of evidence:** `audit/derived/*.json` (detector outputs from this session) + earlier audit-refresh manifest findings.

## How to read this

Each task is a checkbox. Tier order = priority. Effort is best-guess focused dev hours. "Free" / "Pro" indicates which plugin the change lives in. **Where bugs were detected,** the line number references the bug location, not the fix location.

---

## Block A — Search adapter wiring (was: v1.5-search-adapter-completion.md)

Three real bugs in trunk. Foundation exists; nothing's connected. Fix order matters.

- [ ] **A1.** Fix Abilities fatal — route `execute_search` through `Search_Adapter` interface methods that exist (no `search_posts()` / `search_spaces()`). Delete the two corresponding entries from `phpstan-baseline.neon`. (Free, 30 min) — `includes/class-abilities.php:1600–1614`
- [ ] **A2.** Decide search interface widening — Option A (keep narrow, controller stays direct-SQL) or Option B (widen to controller shape, eliminate duplicate `MATCH AGAINST` SQL). Write the decision into the file as a comment so future readers know intent. (Free, ~30 min decision)
- [ ] **A3.** If A2 = Option B: refactor `Search_Controller::search()` to route through `Adapter_Registry::get_search()->search($query, $args)`. Move filter clauses (tag, date, author, sort, viewer-aware visibility) into the widened adapter signature. (Free, 1 day) — `includes/api/class-search-controller.php:79–159` and `includes/search/class-fulltext-search.php:51–116`
- [ ] **A4.** Add explicit adapter-selection mechanism to `Adapter_Registry` — option-based `get_search()` so the iteration-order-wins pattern can't bite when a 2nd adapter registers. Same for `email` and `realtime` slots. (Free, 2 hours) — `includes/adapters/class-adapter-registry.php:67`
- [ ] **A5.** Resolve 3 COUNT-query pagination TODOs. (Free, 0.5 day) — `includes/api/class-search-controller.php:151`, `includes/class-abilities.php:1265`, `includes/class-abilities.php:1324`

**Block A acceptance:** PHPStan baseline drops 2 entries. `Search_Controller` calls `Adapter_Registry::get_search()`. No duplicate `MATCH AGAINST` SQL across the two classes. `meta.total` on `/jetonomy/v1/search` is the true result count, not `count($items)`. Pro Elasticsearch / Algolia adapters can be slotted in cleanly when the time comes (pre-condition for the 1.5.1+ Pro work — explicitly NOT in scope here).

---

## Block B — Email-adapter bypasses (NEW bugs from D2 detector)

Two production paths fire `wp_mail()` directly, bypassing any registered `Email_Adapter`. When the first Pro Mailgun / SES / Postmark adapter ships, these paths will silently miss the configured provider.

- [ ] **B1.** Route `verification-reminder.php` through the email adapter. (Free, 1 hour) — `includes/notifications/class-verification-reminder.php:76`
- [ ] **B2.** Route admin "send test email" through the email adapter. (Free, 30 min) — `includes/admin/ajax/class-settings-handler.php:90`

**Block B acceptance:** D2 detector run shows zero "REAL BUG" entries in production paths. CLI commands and `tools/wp-stubs.php` may keep direct `wp_mail()` (acceptable per detector triage).

---

## Block C — Realtime adapter decision

Interface exists (`includes/adapters/interface-realtime-adapter.php`, 15 lines). One implementation: `Polling_Adapter` (transient-based; the JS does the polling at `assets/js/view.js:2235` against `/jetonomy/v1/updates`). **Zero callers ever call `Adapter_Registry::get_realtime()->publish(...)`.** The publish path is a phantom abstraction — JS pulls, nothing pushes.

Two options:

- [ ] **C1.** EITHER wire `Polling_Adapter::publish()` to actually be called from notification fire sites, so the contract is real and a future WebSocket/SSE adapter is a drop-in replacement. (Free, 1 day)
- [ ] **C2.** OR delete `interface-realtime-adapter.php`, `class-polling-adapter.php`, the `register_realtime` / `get_realtime` registry entries. The polling endpoint at `/jetonomy/v1/updates` keeps working; it just doesn't pretend to be backed by a swappable adapter. (Free, 30 min)

**Decision criterion:** if real-time is on the 1.6/1.7 roadmap (per the strategic-gap analysis earlier today), do C1 — make the contract real before the WebSocket adapter ships. Otherwise C2 — kill the phantom.

---

## Block D — Concrete TODOs left in source

- [ ] **D1.** Asgaros batched-import TODO. (Free, 1 day) — `includes/import/class-asgaros-importer.php:52`
- [ ] **D2.** Pro Queue::recurring migration — 3 sites currently using direct `wp_schedule_event`. (Pro, 3 hours total) — `jetonomy-pro/includes/extensions/email-digest/class-extension.php:86, 96`, `jetonomy-pro/includes/extensions/custom-badges/class-extension.php:69`

---

## Block E — Documentation / phantom-abstraction cleanup

- [ ] **E1.** Document `AI_Adapter` as a Pro-only extension hook in `class-jetonomy.php:402` so future readers don't read it as dead code. Free has 0 in-tree consumers (Pro AI extension consumes it via the registry — legitimate split). (Free, 5 min comment)
- [ ] **E2.** Update `audit/FEATURE_AUDIT.md` "Known issues" with the 3 new bugs from this session: Bug A1 (Abilities fatal), Bug B1 (verification-reminder bypass), Bug B2 (settings-handler bypass). (Free, 15 min)

---

## Block F — Code-quality debt (lower priority)

- [ ] **F1.** Add `minmax(0, 1fr)` hardening to 4 free fixed-track grids. (Free, 30 min) — `assets/css/jetonomy.css:617, 623, 631, 694`
- [ ] **F2.** Add `minmax(0, 1fr)` hardening to 4 Pro admin grids. (Pro, 30 min) — `jetonomy-pro/assets/css/pro-admin.css:141, 147, 426, 470`
- [ ] **F3.** Replace 6 inline SVGs in setup wizard with Lucide via `jetonomy_echo_icon()`. (Free, 1 hour) — `includes/admin/views/setup-wizard.php`
- [ ] **F4.** Triage 7 pre-existing `SpaceMembersUpdateGuardTest` integration failures. Fixtures broken since `31eff22 feat(spaces): G4 commit 1`. Either restore fixtures or rewrite tests against current REST shape. (Free, 0.5–1 day depending on root cause) — `tests/integration/api/SpaceMembersUpdateGuardTest.php`

---

## Block G — Suppressed-baseline debt cleanup

D3 detector surfaced 262 PHPStan/PHPCS suppressions. Composition:

- [ ] **G1.** PHPStan stub hardening — declare `WP_CLI`, `JETONOMY_DIR`, `JETONOMY_URL`, `JETONOMY_PRO_DIR`, `JETONOMY_PRO_URL`, `DB_NAME`, plus 14 other constants in `phpstan-bootstrap.php`. Clears ~213 noise suppressions in one pass. (Free + Pro, 1 hour) — `phpstan-bootstrap.php`
- [ ] **G2.** Triage ~70 "Variable $X might not be defined" assertions. Each is a real undefined-variable risk if the conditional preceding it is false. (Free + Pro, 0.5 day)
- [ ] **G3.** Triage ~15 real type/escape assertions left in baseline. (Free + Pro, 0.5 day)

**Block G acceptance:** `phpstan-baseline.neon` shrinks from 262 entries to ≤30 entries, all of which represent reviewed-and-accepted edge cases (with comments explaining why).

---

## Block H — Browser + a11y verification (carry-over from earlier audits)

- [ ] **H1.** Verify the 5 1.4.1 UI sweeps at 390px + desktop in browser. Per-item, not batched. Existing task #34. (Free, 1 day)
- [ ] **H2.** First-pass a11y audit. Keyboard-nav walk through new-post, single-post, voting, moderation queue, leaderboard. Screen-reader pass on the same flows. Score against WCAG 2.1 AA. File specific failures as bugs. (Free + Pro, 1 day) — currently zero structural visibility into a11y maturity.

---

## Block I — Skill propagation (after this codebase's findings stabilise)

- [ ] **I1.** Promote D1 (undefined-method calls), D2 (registry bypasses), D3 (suppressed baseline errors), D5 (registry strategy) detectors into `wp-plugin-onboard` skill as Phase 2.5.11–2.5.14 sub-checks. **Generic algorithms only** — no class names, no SQL patterns, no namespace prefixes specific to one codebase. Plugin-specific findings stay in the plugin's `audit/derived/`. (~/.claude/skills/wp-plugin-onboard/, 2–3 hours of careful skill-writing) — Detection rules already validated and documented in `audit/derived/detector-validation-2026-04-30.md`

---

## Block J — Release prep (when ready)

NOT YET. Listed for visibility, gated on Blocks A + B clearing.

- [ ] **J1.** Bump free `JETONOMY_VERSION` and `Stable tag` to 1.4.1.
- [ ] **J2.** Bump pro `JETONOMY_PRO_VERSION` and `Stable tag` to 1.4.1.
- [ ] **J3.** Customer-facing 1.4.1 changelog in both `readme.txt` files.
- [ ] **J4.** Run `bin/local-ci.sh --combo --browser` and resolve any blocker.
- [ ] **J5.** Run `/jetonomy-smoke` runbook in both modes (FREE-only + COMBO).
- [ ] **J6.** Build release zips via `bin/build-release.sh`. Verify via Step 8's zip / re-extract / re-smoke.

---

## Effort summary (ballpark)

| Block | LOE |
|---|---|
| A. Search adapter wiring | ~2 days |
| B. Email-adapter bypasses | ~1.5 hours |
| C. Realtime decision | 30 min (C2) or 1 day (C1) |
| D. TODOs | ~1.5 days |
| E. Docs/cleanup | ~20 min |
| F. Code-quality debt | ~3 hours |
| G. Baseline cleanup | ~1.5 days |
| H. Verification + a11y | ~2 days |
| I. Skill promotion | ~3 hours |
| J. Release | ~half day |

Total: ~9 dev days for everything if done sequentially. Blocks B + E + F can run in parallel with A.

## Order of operations recommendation

**Sprint 1 (must-ship-before-1.4.1):** A1 + A2 + A5 + B1 + B2 + E2 + J1–J3 + J4 + J5 + J6. Net: ~3 days incl release.

**Sprint 2 (1.4.2 candidate):** A3 + A4 + C decision + F1 + F2 + F3 + H1.

**Sprint 3 (debt-pay-down):** D1 + D2 + F4 + G1 + G2 + G3 + H2.

**Sprint 4 (skills):** I1.

This file replaces ad-hoc planning notes from this session. Subsequent updates should be edits to this file or its successor, not new sibling plan files.
