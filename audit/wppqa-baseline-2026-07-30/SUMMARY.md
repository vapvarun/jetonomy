# wppqa baseline — 2026-07-30 (1.8.1-dev, commit 6ca9d25)

Run: `wppqa_audit_plugin` full audit, taken as part of the `/wp-plugin-onboard --refresh`
closing the zero-Bugs-column round (11e2a18..6ca9d25, three QA deep-audit waves, Bugs 26 → 0).

Code quality **0 errors**: PHP-lint 385/385, composer-audit PASS, i18n PASS, bundle-size PASS
(6 size warnings, unchanged). PHPCS/PHPStan/PCP ran empty in this environment — both are enforced
per-commit by `.githooks/pre-commit` (full-tree PHPStan + staged WPCS), so every commit in the
range already passed them.

## Security posture (triaged, code-verified)

Same known-FP shape as the 2026-07-21 baseline. The two newly-named files were spot-verified:

| Claim | Verdict | Evidence |
|---|---|---|
| 2× "nonce without capability" in `includes/admin/class-admin.php` | **FALSE POSITIVE** | `class-admin.php:67-70` checks `current_user_can('install_plugins')` BEFORE `check_admin_referer()` — scanner's proximity heuristic only looks after the nonce. `:1029` is the pulse-notice dismissal: per-user notice meta behind `check_admin_referer('jetonomy_dismiss_pulse')`, no privileged mutation. |
| "nonce without capability" in `includes/qa/class-rest-tests.php` | **FALSE POSITIVE** | `:723` is the QA harness ASSERTING `wp_verify_nonce()` behaviour (test E31), not a request handler. |
| Remaining nonce/`$_POST` flags | **FALSE POSITIVE** | All in vendored `libs/action-scheduler/` + `libs/edd-sl-sdk/` (upstream code, excluded from our gates). |

**Net: zero real security findings.** Plugin gates re-run green this refresh: REST mutation auth
audit OK (free + pro), admin-table guard OK (6 free legacy files baselined, shrink-only),
hooks-index drift gate clean at 201 (101 actions + 100 filters), qa-actions 257/257.

## Heuristic-driven checks (known FP-prone, unchanged in kind)

- **REST-JS-CONTRACT (22)** — same proximity-window set as 07-21; no new drift from the round's
  changes (flags contract, role-caps matrix, admin tables are server-rendered).
- **ENUM-CONSISTENCY (16)** — same string-union flags; canonical lists remain centralized.
- **UX-GUIDELINES (21)** — Lucide/dashicon heuristic vs our `jetonomy_echo_icon()` system.
- **WIRING (3)** — same 3 (`action_type`/`viewport` misparses; `jetonomy_bp_broadcast` reads in
  the BuddyPress adapter, not templates).
- **Responsive rule flags** — breakpoint-count + tap-target warnings predate the round; the round
  actually SHRANK the real surface (all four wp-admin Moderation tabs + Spaces + Settings matrices
  migrated to the `jetonomy_admin_table()` contract with a static guard).

## Deltas vs 2026-07-21 baseline

- qa-coverage uncovered: 118 → 115 (drift gate green, direction down — new flag-contract tests
  E25/E25b/c/d + RoleCapsMapping/NormalizeEditorHtml/AvatarPrime/AdapterRegistryLevelLabel suites).
- One manifest drift found and fixed by this refresh: `jetonomy_notification_should_send`
  (global notification veto filter, baeef5d) was fired in 3 producers but missing from
  `hooks_fired` — added; hooks index regenerated to 201.
- No new plugin-dev-rules / wiring / contract findings introduced by the round.
