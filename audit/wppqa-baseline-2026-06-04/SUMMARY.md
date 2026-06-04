# wppqa baseline — jetonomy (free) — 2026-06-04 (pre-1.4.5 release refresh)

Phase-0 bug-finder run for the 1.4.5 release manifest refresh.

## Per-check result

| Check | Pass | Fail | Verdict |
|-------|------|------|---------|
| plugin-dev-rules | 4 | 5 | **identical to 2026-06-03** — all 5 in vendored `libs/` (action-scheduler, edd-sl-sdk) |
| rest-js-contract | 38 | 23 | **identical to 2026-06-03** — all FPs (outbound payloads + nested `res.data.*` reads) |
| wiring-completeness | 8 | 2 | **identical to 2026-06-03** — both FPs (list-table filter param, viewport meta tag) |

## Bottom line

**Zero new findings vs the 2026-06-03 baseline.** Net source change since then was
the analytics-cap CSS fix (pro) and a reverted helper (free) — no PHP behavior
change on the free side. Full FP rationale: see `../wppqa-baseline-2026-06-03/SUMMARY.md`.

Release-gate verdict for 1.4.5: **no wppqa blockers**.
