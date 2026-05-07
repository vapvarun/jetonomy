# wppqa baseline — 2026-05-07

## plugin-dev-rules
- ✓ 9 passed, 0 failed (was 10 errors before this session).
- All Rule 10 alert/confirm violations migrated to shared modal toolkit (50cab7b).
- 1 nonce-no-cap fix landed (fa2334b — import-progress AJAX).
- Inline onclick on Re-Import button migrated to data-jt-confirm + dispatch-click.
- Remaining warnings: 7 distinct breakpoints (CSS hygiene), 12 tap-target-small
  (3 unique selectors across 4 build outputs).

## plugin-check (PCP)
- 0 issues.

## phpcs (project ruleset)
- 221 / 221 passed.

## phpstan
- 0 errors at level 5.

## a11y
- 32 form inputs without labels (settings.php + space-edit.php; deferred).
- outline:none → :focus-visible across 21 source-CSS sites (cd85457). Build
  artefacts (-rtl.css, -min.css) regenerate from source via grunt at release.

## REST/JS contract
- 20 errors flagged but ALL confirmed false positives (audit's regex extracts
  top-level shape `[data, meta]` from envelope-style controllers; nested
  `data.X` access by JS is correct).

## Real bug findings closed in 1.4.2
- import-progress AJAX added capability check (was nonce-only).
- Native alert/confirm migrated to shared modal toolkit (5 admin JS files).
- New admin-confirm.js shared [data-jt-confirm] delegate.
