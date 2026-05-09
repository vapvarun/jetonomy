# QA Coverage — manifest-driven QA gate

This plugin treats `audit/manifest.json` as the canonical inventory and runs
an automated gate that fails when new manifest entries land without
corresponding test coverage. The discipline is the same as `phpstan-baseline.neon`:
the uncovered count can drop, never grow.

---

## How it works

```
                    ┌─────────────────────────────┐
                    │  audit/manifest.json        │
                    │  (canonical inventory)      │
                    └──────────────┬──────────────┘
                                   │
                                   ▼
            ┌───────────────────────────────────────────┐
            │  bin/qa-coverage-check.php                │
            │  Reads manifest, scans test source,       │
            │  writes audit/qa-coverage.json            │
            └────────┬─────────────────────┬────────────┘
                     │                     │
        ┌────────────▼──────────┐   ┌──────▼─────────────┐
        │ Pre-commit hook       │   │ bin/build-release  │
        │ blocks commits that   │   │ blocks the dist    │
        │ grow uncovered_total  │   │ zip on regression  │
        └───────────────────────┘   └────────────────────┘

When uncovered:
        ┌────────────────────────────────────────────────┐
        │ bin/qa-stub-gen.php rest POST /foo             │
        │ → appends a TODO test stub to class-rest-tests │
        │ → human fills assertions → run again → covered │
        └────────────────────────────────────────────────┘
```

## Categories tracked (Phase A)

| Category | Coverage signal |
|---|---|
| `rest` | `$this->rest( 'METHOD', '/path' )` calls in QA test source AND `'B7: METHOD /path'` style test labels |
| `ajax` | Action name appears as a literal string in any test source file |
| `hooks_fired` | Only entries with non-empty `consumed_by[]`. Covered when a `do_action`/`apply_filters` for that hook is found in CLI journeys / scenarios |
| `cron` | Cron hook name appears as a literal string in any test source file |
| `wp_cli` | A `class-{slug}-journey.php` file exists for the command |

Categories deferred to Phase A.2: `blocks`, `shortcodes`, `admin_pages`.

## Scripts

### `bin/qa-coverage-check.php` — the gate

```bash
# Default usage — runs against current plugin directory
php bin/qa-coverage-check.php

# Run against a specific plugin
php bin/qa-coverage-check.php --plugin=/path/to/plugin

# JSON summary on stdout (for CI integration)
php bin/qa-coverage-check.php --json

# Strict mode — exit 1 on ANY uncovered (not just regression)
php bin/qa-coverage-check.php --strict

# Quiet — suppress human-readable summary (for hooks)
php bin/qa-coverage-check.php --quiet
```

Exit codes:
- `0` — coverage equal or improved vs prior run (or first run)
- `1` — coverage regressed (`uncovered_total` grew)
- `2` — manifest missing or malformed

Output: `audit/qa-coverage.json`. Schema:

```json
{
  "schema_version": "v1",
  "plugin":     { "slug": "...", "version": "..." },
  "generated":  { "at": "ISO", "manifest_at": "...", "manifest_branch": "..." },
  "categories": {
    "rest":        { "total": 64, "covered": 31, "uncovered": 33, "skipped": 0,
                     "covered_items": [...], "uncovered_items": [...] },
    "ajax":        { ... },
    "hooks_fired": { ... },
    "cron":        { ... },
    "wp_cli":      { ... }
  },
  "summary": {
    "categories_checked": 5,
    "items_total": 149,
    "items_covered": 36,
    "items_uncovered": 113,
    "items_skipped": 104,
    "coverage_percent": 24.2
  },
  "drift": {
    "previous_uncovered": 122,
    "current_uncovered": 113,
    "delta": -9
  }
}
```

### `bin/qa-stub-gen.php` — fast scaffolding

Generates a starter test stub for any uncovered manifest entry. The stub
appends to `includes/qa/class-rest-tests.php` with TODO markers — humans
fill in fixture-specific assertions, then the entry moves uncovered →
covered on the next coverage check.

```bash
# REST endpoint
php bin/qa-stub-gen.php rest POST "/posts/(?P<id>\d+)/idea-status"

# AJAX handler
php bin/qa-stub-gen.php ajax jetonomy_ban_user

# Hook (with consumer)
php bin/qa-stub-gen.php hook jetonomy_user_left_space

# Cron hook
php bin/qa-stub-gen.php cron jetonomy_prune_activity
```

The `stub_command` field in `qa-coverage.json#/categories/<cat>/uncovered_items[]`
is the exact command to run for that uncovered entry.

## Gates

### Pre-commit (`.githooks/pre-commit`)

Runs `bin/qa-coverage-check.php --quiet`. Blocks commits where
`uncovered_total` grew vs the previous run. Bypass (emergencies only):
`COVERAGE_SKIP=1 git commit ...`

### Release (`bin/build-release.sh`)

Runs `bin/qa-coverage-check.php` between the lint pass and the smoke test
(step 6b in the build pipeline). Blocks the dist zip if coverage regressed.
Bypass: `COVERAGE_SKIP=1 bin/build-release.sh`

## Adding the gate to a new plugin

The scripts are plugin-agnostic. To adopt:

1. Copy `bin/qa-coverage-check.php` and `bin/qa-stub-gen.php` to the new
   plugin's `bin/` directory.
2. Make both executable: `chmod +x bin/qa-coverage-check.php bin/qa-stub-gen.php`.
3. Add the pre-commit gate block to `.githooks/pre-commit` (see Jetonomy's
   hook for the exact snippet).
4. Add the build-release gate block to `bin/build-release.sh` (step 6b
   between `php -l` and the smoke test).
5. Run `php bin/qa-coverage-check.php` once to establish the baseline
   `audit/qa-coverage.json`. Commit it.
6. Adjust `$test_globs` in `qa-coverage-check.php` if the plugin's test
   files live in a non-standard location (default: `includes/qa/*.php`,
   `includes/cli/journeys/*.php`, `includes/cli/scenarios/*.php`,
   `tests/**/*.php`).

That's it. The gate is now active.

## When to skip

Skipping the gate is for genuine emergencies: a customer-reported P0 fix
that needs to ship before the next test scaffolding cycle. Use
`COVERAGE_SKIP=1` and FILE THE STUB IN THE NEXT COMMIT. If you find
yourself skipping more than once a quarter, the gate is mis-tuned (too
strict) or the codebase is shipping untested features — fix one or the
other.

## Why we count things this way

The Phase A detection is intentionally conservative — it only counts
"covered" when there's a clear static signal in the test source. This
means real coverage may be higher than what the gate reports (e.g., a
test calling a wrapper method instead of `$this->rest()` directly is
not detected). The trade-off: false negatives are easy to fix (write a
stub or annotate the manifest); false positives let bugs ship.

The next phases improve detection signals (Phase A.2: parse
`wp jetonomy qa-actions` output for live test results; Phase A.3: detect
journey-class coverage of REST endpoints) without changing the gate
contract: still uncovered_total can shrink, never grow.

## Out-of-scope (handled by other gates)

- **Live test PASS/FAIL** — that's `wp jetonomy qa-actions`, run as part
  of the pre-release smoke. Coverage check answers "is there a test?";
  qa-actions answers "does the test pass?".
- **Browser-side assertions** — handled by the smoke skill (`/jetonomy-smoke`
  with FREE / COMBO modes).
- **Cross-plugin wiring** — handled by `/action-audit`.
- **Code style / static analysis** — PHPCS + PHPStan in the same hook.

The coverage gate fills the gap between "the code exists" and "the code
is exercised by a test." It's a documentation-of-intent layer that the
other gates don't carry.
