# wppqa baseline — 2026-09-08

Run as Phase 0 of `/wp-plugin-onboard --refresh` on branch 1.9.7 @ 1845b04, before any manifest work.

| Check | Passed | Failed | Verdict |
|---|---|---|---|
| rest-js-contract | 52 | 22 | **all 22 false positive** |
| wiring-completeness | 13 | 3 | **all 3 false positive** |

Nothing actionable. Both checks are heuristic in ways the skill already documents, and every finding was spot-verified against source rather than taken at face value.

## rest-js-contract — 22 findings, 0 real

The check flags `X.property` accesses near a route URL when `property` is not a top-level key of the PHP response. Jetonomy's `Base_Controller` returns the standard `{data, meta}` envelope, so **every** access of an unwrapped inner object trips it.

Verified examples:

- `assets/js/composer.js:853` — flagged `body.closest` as a missing response key. Source reads `var ancestor = body.closest('[data-jt-space-id]')`. That is the DOM `Element.closest()` API, not a response field.
- `assets/js/header.js:381` — flagged `data.avatar_url`. Source is inside `renderHoverCard(card, data, anchor)`, where `data` is the already-unwrapped inner object. `resp.data.avatar_url` is the correct access.

The remaining 20 are the same envelope-unwrapping shape against `/users`, `/spaces`, `/tags` and `/feed`. No PHP or JS change is warranted; changing either side to satisfy the checker would break working code.

Worth doing eventually: the check suggests a JSON contract fixture per route, which would let the real drift surface loudly instead of being buried in noise. Not scoped here.

## wiring-completeness — 3 findings, 0 real

The check scans only `templates/` for reads, so any setting consumed by a service or integration class looks unwired. That blind spot is documented in the onboard skill itself.

- `jetonomy_bp_broadcast` — genuinely consumed, at `includes/integrations/class-buddypress.php:35` (`const OPT_BROADCAST`). Lives outside `templates/`, hence the flag.
- `action_type` — `includes/admin/class-activity-list-table.php:427` is `<select name="action_type">`, a list-table filter control. Not a setting; nothing to persist.
- `viewport` — `includes/admin/views/setup-wizard.php:20` is `<meta name="viewport" content="...">`. An HTML meta tag matched by the `name=` pattern.

## Note for the next run

Neither result should be treated as a release gate for this plugin until the two heuristics can distinguish (a) envelope-unwrapped access from a missing key, and (b) service-layer reads from genuinely dead settings. Recording the classification here so a future run does not re-triage the same 25 items.
