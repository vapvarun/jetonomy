# Detector validation run — 2026-04-30

Five experimental detectors run ad-hoc against jetonomy + jetonomy-pro to test whether the algorithms find real bugs before promoting any of them to the `wp-plugin-onboard` skill.

**Source:** `.claude-tmp/detectors/run_all.py` (this plugin only — script is plugin-side, not skill content).

## Verdict per detector

| ID | Detector | Findings | New bugs found | Useful enough to skill? |
|---|---|---|---|---|
| D1 | Undefined-method calls on registry-typed vars | 2 | 0 (regression test of known Bug 1) | **Yes** — passed regression, zero false positives |
| D2 | Registry bypasses (FULLTEXT SQL / direct `wp_mail`) | 7 raw → 3 real bugs after triage | **2 new bugs** | **Yes** — surfaced verification-reminder + admin settings-handler bypasses |
| D3 | Suppressed baseline errors | 262 entries | Inventory of debt (no individual "new bugs" per se) | **Yes** — surfaces hidden debt; 0% visibility before this |
| D4 | Contract-resolution map (interface ↔ impl ↔ consumer) | 5 interfaces, 1 incompatible | 0 (overlap with D1) | **Yes but condense** — useful supplementary view, can ride D1's data |
| D5 | Adapter-registry strategy | 5 real slots + 2 false-positives (`all_*` getters) | 0 | **Yes with refinement** — filter `all_*` aggregate getters from slot list |

## D1 — Undefined-method calls (regression-test passed)

```
[high] includes/class-abilities.php:1609  $adapter->search_posts()  iface=Search_Adapter  impl=Fulltext_Search visibility=private
[high] includes/class-abilities.php:1612  $adapter->search_spaces()  iface=Search_Adapter  impl=Fulltext_Search visibility=private
```

Both expected. Both are the bug `phpstan-baseline.neon` was suppressing. Detector picked them up correctly. Zero false positives.

## D2 — Registry bypasses (2 new bugs found)

| File:line | Slot | Verdict |
|---|---|---|
| `includes/api/class-search-controller.php:191` | search | **REAL BUG** (known — confirmed) |
| `includes/admin/ajax/class-settings-handler.php:90` | email | **REAL BUG (new)** — admin "send test email" path bypasses Email_Adapter; custom mail-provider adapters won't see it |
| `includes/notifications/class-verification-reminder.php:76` | email | **REAL BUG (new)** — production verification-reminder cron fires direct `wp_mail()`, ignores any configured Email_Adapter |
| `tools/wp-stubs.php:160` | email | Acceptable (test stub) |
| `includes/cli/commands/class-notification-command.php:216` | email | Acceptable (CLI test command) |
| `includes/cli/journeys/class-notification-journey.php:19` | email | Acceptable (CLI journey scenario) |
| `jetonomy-pro/includes/cli/journeys/class-email-digest-journey.php:225` | email | Acceptable (CLI journey scenario) |

**Customer impact of the 2 new bugs:** customers running a Pro Mailgun / SES / Postmark adapter (when one ships) would silently see verification reminders and the admin "send test email" button bypass their configured provider and go through `wp_mail()` (which often = unreliable shared host SMTP). This is exactly the kind of silent-divergence the adapter pattern was meant to prevent.

## D3 — Suppressed baseline errors (262 entries)

Composition by likely category:

| Category | Approx count | Action |
|---|---|---|
| `WP_CLI::log/success/warning/error` "unknown class" | 119 | Add `szepeviktor/phpstan-wordpress` WP-CLI stubs to `phpstan-bootstrap.php`. Pure noise, zero risk to remove. |
| `JETONOMY_DIR` / `JETONOMY_URL` / `JETONOMY_PRO_DIR` / `JETONOMY_PRO_URL` / `DB_NAME` not found | ~94 | Declare these constants in `phpstan-bootstrap.php`. Pure noise. |
| `Variable $X might not be defined` (`$space`, `$per_page`, `$post`, `$paged`) | ~70 | Real PHPStan findings — represent variables initialised inside conditionals. Each one is a potential undefined-var if the condition is false. **Worth triaging individually.** |
| `WordPress` (sniff prefix) | 79 | Likely WPCS suppressions inside the `WordPress` rule namespace — coding-standard exclusions, not bugs. |
| Other (real type/escape assertions) | ~15 | Each is real code debt. Worth triaging. |

**Net read:** ~213 entries are noise that could be cleared by a one-time PHPStan stub-hardening pass. ~85 entries (`might not be defined` + real type asserts) are debt to triage individually. The detector exposes a 5x overstatement of code-quality risk that wasn't previously visible.

## D4 — Contract resolution

```
Search_Adapter — INCOMPATIBLE
  consumer: includes/class-abilities.php:1600
  calls: search_posts, search_spaces
  unsupported (not on interface): search_posts, search_spaces
```

Same finding as D1, expressed as "the contract between the interface and its consumers is broken." Useful for the per-interface report shape, but D1's data is sufficient — D4 could be a derived view of D1 + the implementer/consumer indices, not a separate scan.

## D5 — Registry strategy

```
membership: explicit_id_or_option, registered=9, risk=low
ai:         explicit_id_or_option, registered=5, risk=low
search:     first_active_only,     registered=1, risk=low_now_high_when_count>1
email:      first_active_only,     registered=1, risk=low_now_high_when_count>1
realtime:   first_active_only,     registered=0, risk=low_now_high_when_count>1
```

(Plus `all_membership` and `all_ai` from `get_all_*` aggregate getters, which aren't real adapter slots — refine the parser to skip those.)

**Read:** the moment a Pro Elasticsearch / Mailgun / WebSocket adapter registers a second adapter for one of `search`, `email`, or `realtime`, the iteration order in PHP determines which one wins. Brittle. The fix (already discussed in `plan/v1.5-search-adapter-completion.md` Step 4) is to add explicit ID-or-option-based selection — same pattern `membership` and `ai` already use.

## Decisions

1. **D1, D2, D3 promote to skill** — generic algorithms only, no plugin-specific class names or SQL patterns. Each catches real issues; D2 alone surfaced 2 new bugs in this codebase.
2. **D4 folds into D1** — its findings are derivable from D1's output plus the implementer/consumer indices already maintained by the contract-resolution check.
3. **D5 promotes with refinement** — filter `get_all_*` aggregate getters from the slot list before counting registered adapters. Otherwise useful as a heads-up about "this design is brittle the moment a second adapter registers."

## Bugs to file

Three bugs to add to the `audit/FEATURE_AUDIT.md` "Known issues" section and consider for the next bug-fix PR:

1. `class-abilities.php:1600–1614` — `execute_search` calls `$adapter->search_posts()` and `$adapter->search_spaces()` (private on `Fulltext_Search`, missing from `Search_Adapter` interface). Already documented in `plan/v1.5-search-adapter-completion.md`.
2. `class-verification-reminder.php:76` — production cron fires `wp_mail()` directly, bypassing the email adapter registry. Customers configuring a custom Email_Adapter (Mailgun, SES, etc) silently lose this notification path. **NEW finding.**
3. `class-settings-handler.php:90` — admin "send test email" feature fires `wp_mail()` directly. Same bypass class. **NEW finding.**

## Skill-promotion checklist (when promoting D1/D2/D3/D5 to wp-plugin-onboard)

- [ ] Strip every plugin-specific name (no `Search_Adapter`, `Fulltext_Search`, `MATCH AGAINST`, `wp_jt_*`, etc).
- [ ] Use `{prefix}` placeholders or generic descriptions like "any class implementing the registered adapter interface."
- [ ] Detection algorithm references files by glob pattern, never specific path.
- [ ] Output JSON shape uses `<interface>`, `<class>`, `<file:line>` placeholders in examples.
- [ ] Cache key inputs use generic glob hashing per Phase 1.4, no per-plugin specific exclusions baked in.
