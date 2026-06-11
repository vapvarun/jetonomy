# wppqa baseline — jetonomy (free) — 2026-06-11 (post-audit-fixes refresh)

Phase-0 bug-finder run for the 1.5.0-dev manifest refresh, after the
full-audit fix series (audit/full-audit-2026-06-11.md: A3/A5/A7 + B/C/E).

## Per-check result

| Check | Pass | Fail | Verdict |
|-------|------|------|---------|
| plugin-dev-rules | 3 | 6 | 5 of 6 identical to 2026-06-04 — all in vendored `libs/` (action-scheduler ×4, edd-sl-sdk ×1; third-party, out of scope). **1 new:** `includes/qa/class-rest-tests.php:651` nonce-no-cap — FALSE POSITIVE: it is the E31 QA assertion `wp_verify_nonce($nonce,'wp_rest')` validating the new GET /auth/nonce response, not an authorization gate. |
| rest-js-contract | 39 | 21 | Down from 23 on 2026-06-04 (2 FPs disappeared with the dead-code sweep). All 21 remain the known heuristic FP classes: outbound REQUEST payload keys (`captcha_token`, `custom_fields`, `published_at` attributed to nearby routes by the 50-line window) and nested `data.data.*` envelope reads the scanner can't follow. No new real findings. |
| wiring-completeness | 8 | 2 | Identical to 2026-06-04 — both known FPs (`action_type` is an activity list-table filter param, not a setting; `viewport` is the setup-wizard meta tag). |

## Net change vs 2026-06-04

- **No new real findings.** The audit-fix series (transition hooks,
  /auth/nonce, dead-table drop, duplicate consolidation, 32-symbol dead-code
  sweep) introduced zero wppqa regressions.
- Tap-target warnings (14–20px buttons at jetonomy.css:513/517/1508) and the
  7-breakpoint warning are long-standing design notes — `.jt-btn-sm` text
  buttons; tracked in the ux-audit backlog, not release blockers.

## Release-gate verdict for 1.5.0-dev

**No wppqa blockers.** All errors are vendored-lib or heuristic FPs with the
rationale above.
