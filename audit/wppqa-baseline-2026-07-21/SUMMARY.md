# wppqa baseline — 2026-07-21 (1.8.1-dev, commit 11e2a18)

Run: `wppqa_audit_plugin` full audit. Code quality **0 errors** (PHPCS, PHPCompat, PHP-lint 378/378, composer-audit, i18n, plugin-check all PASS). Maturity COMPLETE 75/100. Category detection: Community Platform, feature completeness 97% (14/15 found).

## Security posture (triaged, code-verified)

| Claim | Verdict | Evidence |
|---|---|---|
| "Some REST routes lack permission callbacks" | **FALSE POSITIVE** | `php bin/audit-rest-routes.php includes/` → OK (free) and `../jetonomy-pro/includes/` → OK (pro). All mutation routes use `REST_Auth::auth_mutation()`/`auth_public_write()`; public GET routes are intentionally public reads. |
| "Some AJAX handlers lack nonce verification" / 7× "Nonce check without capability check" | **FALSE POSITIVE** | Scripted sweep of all `includes/admin/ajax/class-*.php`: every `check_ajax_referer()` is followed by a `current_user_can()` within 5 lines (jetonomy_moderate / jetonomy_manage_settings / jetonomy_manage_spaces). Scanner's proximity heuristic missed the next-line cap check. |
| "Iterating over $_POST/$_GET directly" | **FALSE POSITIVE** | `grep foreach.*\$_POST\|\$_GET includes/` → 0 hits. |
| "No activation/deactivation hook" | **FALSE POSITIVE** | `includes/class-jetonomy.php:29-30` registers both. Scanner only reads the main plugin file. |
| "No uninstall cleanup" | **FALSE POSITIVE** | `uninstall.php` exists at plugin root. |
| "Cron jobs not cleared on deactivation" | **FALSE POSITIVE** | Background-jobs standard: `deactivate()` clears both Action Scheduler + WP-Cron (docs/standards/background-jobs.md). |

**Net: zero real security findings.** The plugin's own gates (REST mutation auth audit free+pro, per-handler cap sweep) all pass.

## Heuristic-driven checks (known FP-prone, unchanged from prior baselines)

- **REST-JS-CONTRACT (22)** — 50-line proximity window flags request-payload writes and view.js reads of serializer fields that DO exist (`avatar_url`, `trust_level`, `bookmarked`…). Same set as 2026-06-03/06-04 baselines; no new drift from 1.8.1-dev routes.
- **ENUM-CONSISTENCY (16)** — flags every string-union (`sort`, `status`, `role`…); canonical lists already centralized where it matters (`SpaceMember::VALID_ROLES`, `Space::visibility_levels()`, `Restriction` types).
- **UX-GUIDELINES (22 + 2574 warn)** — Lucide/dashicon heuristic; Jetonomy uses its own `jetonomy_echo_icon()` inline-SVG icon system by design.
- **A11Y (40)** — dominated by `outline:none` repeats counted per min/RTL variant of the same source rule, and admin settings labels. Real source-level distinct items ≈ 4; candidate for a UI-polish card, not a release blocker.
- **WIRING (3)** — `action_type`/`viewport` are not settings (heuristic misparse); `jetonomy_bp_broadcast` reads in the BuddyPress adapter, not templates.

## Deltas vs 2026-06-04 baseline

- qa-coverage: uncovered improved 119 → 116 (1.8.1-dev added covered journeys for the 2 new moderation routes; drift gate green, direction down).
- No new plugin-dev-rules / wiring / contract findings introduced by the 1.8.1-dev commits (member-moderation frontend, density, avatars, *_gmt serializers).
