# wppqa baseline — jetonomy (free) — 2026-06-03 (1.5.0-dev)

Run as the Phase-0 bug-finder during the post-bug-sweep manifest refresh. This is
the authoritative current bug state (the manifest `static_analysis` is the map;
this is what's actually broken).

## Per-check result

| Check | Pass | Fail | Verdict |
|-------|------|------|---------|
| rest-js-contract | 38 | 23 | **all false positives** (see below) |
| plugin-dev-rules | 4 | 5 | **all in vendored `libs/`** (not our code) |
| wiring-completeness | 8 | 2 | **both false positives** |

## rest-js-contract — 23 "high", all false positives
The heuristic flags any JS property access within 50 lines of a route string and
compares against the controller's top-level envelope `[data, meta]`. The 23 hits
are two non-bug patterns:
1. **Outbound request payloads**, not response reads — e.g. `payload.custom_fields`,
   `payload.settings`, `payload.notification_preferences`, `body.custom_fields`,
   `payload.published_at`. These are properties being *set on the request body*, not
   read from a response.
2. **Nested response reads** — e.g. `res.data.slug` (new-space.js, view.js). `slug`
   lives inside `data`; the check doesn't recurse, so it reports it missing.
Browser-verified this session: create-space redirects via `res.data.slug`, create-post
persists, profile/space edits round-trip. No real envelope mismatch. Pro: 43/0 clean.

## plugin-dev-rules — 5 "high", all vendored third-party libs
All five are in `libs/action-scheduler/` (WooCommerce Action Scheduler) and
`libs/edd-sl-sdk/` (EDD licensing SDK) — bundled upstream code we don't modify.
Distribution-scope exclusion should drop `libs/` from the scan. Not actionable.
Warnings (7-breakpoint, sub-40px tap targets) are pre-existing CSS, not from this branch.

## wiring-completeness — 2 "high", both false positives
- `action_type` (class-activity-list-table.php:391) — a WP_List_Table **filter param**,
  read in admin PHP, not a persisted setting; check only inspects `templates/`.
- `viewport` (setup-wizard.php:20) — a `<meta name="viewport">` tag caught by the
  `name=` regex, not a setting.

## Bottom line
**No new real issues introduced by the 1.5.0-dev bug sweep.** Every error is a
heuristic false positive, vendored-lib code, or pre-existing CSS. See the Pro
baseline for the one genuine (pre-existing) finding: native alert()/confirm().
