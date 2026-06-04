# Jetonomy - WordPress Forum Plugin

> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory - 66 REST routes, 43 AJAX handlers, 141 hooks fired, 23 tables, 23 capabilities, 8 blocks, 9 shortcodes, 14 WP-CLI commands, 6 cron hooks, 13 admin pages. **Coverage-gated refresh completed 2026-06-04 (pre-1.4.5): hooks_fired reconciled (+29 missing since 1.4.3/1.4.4 incl. WB Gam filters + sidebar hooks, -1 stale), all other categories verified exact vs ground-truth grep (see `generated.refresh_2026_06_04`). SaaS feature audit: `audit/FEATURE_GAP_ANALYSIS.md`.** v2 schema with `static_analysis` populated (zero dead listeners after 1.4.2; 4 grid 1fr risks documented as design notes since the CSS already implements `minmax(0, 1fr)`). See also [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md) and [`audit/customer-experience-matrix.md`](audit/customer-experience-matrix.md). End-to-end customer flows live as runnable PHP scenarios under `includes/cli/scenarios/` and as the pre-release smoke runbook (`/jetonomy-smoke`). For an interactive graph view, run `cd audit && python3 -m http.server 8765` and open <http://localhost:8765/graph.html>. Refresh via `/wp-plugin-onboard --refresh` after non-trivial changes.

## Stability & Manifest-First Rules (enforced)

Jetonomy free + pro run on many live sites. Treat every change as production. Be
extra careful; never fix blindly.

1. **Manifest is the starting point.** Before adding ANY function, hook, REST
   route, or helper, check `audit/manifest.json` (and the Pro manifest for
   cross-plugin work) to confirm one does not already exist. Reuse the existing
   helper instead of writing a parallel one. The manifest exists so free and pro
   do not grow duplicate/similar functions.
2. **Keep the manifest in sync.** When you add a hook / endpoint / listener,
   update the manifest in the same change (a targeted manual delta is fine —
   `hooks_fired` in free, `free_filters_hooked` in pro). A full
   `/wp-plugin-onboard --refresh` reconciles at release.
3. **No duplicate code, no dead code.** If the same logic appears twice,
   consolidate to one implementation (PHP helper, or a shared JS lib such as
   `assets/js/lib/custom-fields.js` → `window.jetonomyCollectCustomFields`).
   Don't leave unreachable fallback branches behind once a guard makes them moot.
4. **Verify root cause, then fix.** Reproduce first (code or browser). Bug
   reports can be wrong — a 400/40x may be intended behaviour (e.g. accept-reply
   is Q&A-only). Don't "fix" a correct guard.
5. **Local CI before declaring done** (not just the pre-commit hook):
   - `php bin/audit-rest-routes.php includes/` and `... ../jetonomy-pro/includes/` → both OK
   - `wp jetonomy qa-actions` → 210/210
   - free+pro boot smoke (`../jetonomy-pro/tools/smoke-test.php`)
   - browser-verify every frontend/template change (incl. 390px mobile)

## Build Rule (enforced)

**Every release zip must be produced by `bin/build-release.sh`.** No exceptions.

- Why: on 1.3.5 a stale Desktop zip (built before the critical bootstrap fix was committed) reached the GitHub release and took a customer's live site down. The release agent trusted "zip already exists" instead of rebuilding.
- What the script guarantees, in order:
  1. **Step 0 - asset regen.** Auto-runs `grunt build` (after a one-time `npm install`) so every release zip ships with fresh `.min.css`, `.min.js`, RTL CSS, and `.pot`. Closes the "stale .min" gap that almost shipped in 1.3.8 (newly added source files had no minified counterpart).
  2. Clean-tree gate (`--allow-dirty` only for local dev). Ignores grunt-regenerated paths so Step 0's deterministic output doesn't trip the gate.
  3. Version triangulation - Version header, constant, and readme Stable tag must match.
  4. Production composer install in staging (`--no-dev --optimize-autoloader`).
  5. Required-files sanity check.
  5b. **Source/min pairing assertion** - every `assets/css/*.css` (non-RTL, non-min) must have a `*.min.css` in staging; same for JS. Fails with the missing list.
  5c. **Top-level cruft check** - rejects `verify-*.png`, `.playwright-mcp/`, `.distignore`, `.wp-env.json`, `phpstan-*.dist`, `*.log`, empty `build/` from leaking past EXCLUDES. Tonight's pro 1.3.8 zip had ~1.1 MB of these files; this gate would have failed the build before tagging.
  6. `php -l` on every staged PHP file.
  7. **WP-stub smoke test** - boots the plugin through `plugins_loaded` + `init` in a minimal WP stub (`tools/wp-stubs.php` + `tools/smoke-test.php`), catching load-time fatals like the 1.3.5 `Jetonomy\table()` bug.
  7b. **Browser smoke gate** - the smoke skill writes `.last-smoke-pass-free.json`; the script triages by `origin`, only `from`-origin failures or debug-log entries block. `for`-origin entries (test harness, theme, OS) stay informational.
  8. Zip → re-extract to scratch → re-run WP-stub smoke (catches zip corruption).
- Never attach a pre-existing zip to a release. Always rebuild from the tagged commit.
- Pro: `bin/build-release.sh` additionally enforces the lockstep rule - fails if Pro's version doesn't match free's. Pro does not have the browser smoke gate (uses the COMBO smoke skill for verification before tagging instead).

## REST Mutation Auth Gate (manual until CI lands)

**Every mutation route MUST use `\Jetonomy\API\REST_Auth::auth_mutation()` or `auth_public_write()`.** No raw `is_user_logged_in`, `current_user_can`, closures, or class methods as `permission_callback` on mutation routes. The audit script verifies this.

**Pro extensions** must NOT reference `REST_Auth::auth_mutation()` directly at route registration (the eager static call fatals the whole REST API if free's class isn't loaded yet — Basecamp 9953887096). They use the lazy base-class wrapper `Jetonomy_Pro\Extension::rest_auth_mutation( $caps )`, which resolves `REST_Auth` at request time behind `class_exists()` and fails closed. `bin/audit-rest-routes.php` accepts `rest_auth_mutation` as an approved callback.

```
php bin/audit-rest-routes.php includes/                  # free
php bin/audit-rest-routes.php ../jetonomy-pro/includes/  # pro
```

Both must report `OK (no mutation routes missing REST_Auth)` before merging to `1.4.3`. Allowlist (signature-validated webhooks) lives in `bin/audit-rest-routes.php`. Run before tagging — the script ships with the repo so a local clone + `php` is enough; no GitHub Actions hook yet because `.github/workflows/ci.yml` edits are gated by an org-wide hook. Add the step to CI once the security-scan exemption process is sorted.

## Pre-Commit Rule (enforced)

**Every commit is locally gated by `.githooks/pre-commit`.** The hook runs PHPStan on the full tree (honours the baseline) and WPCS on staged PHP files before the commit lands. Failures block the commit so red X's never reach the public history.

- Install: `composer install` auto-configures it via `post-install-cmd` (sets `core.hooksPath .githooks`). For existing clones, `composer run hooks:install` does the same.
- Skip (emergencies only): `git commit --no-verify` - but fix it in the next commit.
- Target latency: under 30 seconds. If the hook gets slower, split the check or move it to pre-push.

## Release Rule (enforced)

**Free and Pro always ship with the same `x.y.z` version number.** No exceptions.

- Every release bumps `jetonomy` AND `jetonomy-pro` together, even if one side has no user-facing changes - the side with no changes gets a "Compatibility: Aligned with Jetonomy x.y.z" entry in its readme.
- `JETONOMY_VERSION`, `jetonomy.php` `Version:` header, and `readme.txt` `Stable tag:` must all match the corresponding Pro constants and headers.
- CI fails fast if the two versions drift.
- Rationale: pairing simplifies support ("what version are you on?"), EDD updater routing, and the release checklist - no cognitive load deciding which plugins need which bump.

## Feature Acceptance Rules (enforced for every release)

Three rules hard-gate every new feature. They live as memory entries (`feedback_rest_first_and_rtl_ready.md`, `feedback_frontend_rest_only_backend_ajax_ok.md`, `feedback_readme_txt_customer_facing.md`) so Claude carries them across sessions:

1. **REST-first, full CRUD, with documented contract.** Every read AND every mutation is reachable via a `jetonomy/v1/*` endpoint with documented route / method / payload / response / permission_callback. Reuse an existing controller when possible; only add a new one when no endpoint can carry the operation. AJAX-only or form-post-only paths are bugs.
2. **Frontend REST-only, backend AJAX is acceptable.** Customer-facing surfaces (frontend templates, blocks, app) call REST. wp-admin tooling can keep AJAX where it already exists. The two reasons: customer perf (`admin-ajax.php` triggers full admin bootstrap, defeats caching plugins, kills HTTP/2 multiplexing) and the upcoming app needs REST anyway. Don't refactor working admin AJAX for uniformity; do migrate any `wp_ajax_*` handler called from frontend JS.
3. **RTL ready out of the gate.** Every new template / partial / block ships with RTL parity from the first commit. Use logical CSS properties (`margin-inline-start`, `padding-inline-end`, `inset-inline-end`, `text-align: start`) so the browser flips for free. Hand-tuned `[dir="rtl"]` overrides only for genuinely asymmetric values. `grunt rtlcss` auto-generates `*-rtl.css` but visual verification under `<html dir="rtl">` is required before marking done.

Two further rules apply to every release:

- **readme.txt is customer-facing**, not developer notes. Lead with what's new and what's fixed, in plain English. Internal commits/refactors stay in git history and PR descriptions.
- **Release zip is verified by the build, not by hand.** `bin/build-release.sh` runs `grunt build` (Step 0), asserts every CSS/JS source has a `.min` pair (Step 5b), and rejects any top-level dev cruft (Step 5c). Manual extract-and-eyeball is the wrong model.

## Release Notes Style

See **`~/.claude/CLAUDE.md` -> "Release Notes Style (ALL plugins & themes)"** for the canonical format spec. Same WooCommerce-style action-prefix rules apply to this plugin's `readme.txt` and GitHub release body. This plugin is the reference implementation — open `readme.txt` for the exact pattern.

## Quick Reference
- **Type**: WordPress Plugin (forum, Q&A, ideas, social feed)
- **PHP**: 8.1+ required
- **WP**: 6.7+ required
- **Namespace**: `Jetonomy\`
- **Table prefix**: `jt_` (22 custom tables)
- **REST API**: `jetonomy/v1` (42 endpoints, 15 controllers)

## Architecture
- **Database**: Custom MySQL tables via `dbDelta()` - NOT WordPress CPTs
- **Models**: `includes/models/` - all extend abstract `Model` class
- **Permissions**: 3-layer system (WP Caps → Space Roles → Trust Levels)
- **API**: `includes/api/` - all extend `Base_Controller` → `WP_REST_Controller`
- **Frontend**: PHP templates + WP Interactivity API + CSS Custom Properties
- **Adapters**: Universal adapter pattern for membership, search, real-time, email

## Key Files
| Path | Purpose |
|---|---|
| `jetonomy.php` | Main plugin file, constants, bootstrap |
| `includes/class-jetonomy.php` | Singleton, activation, dependency loading |
| `includes/class-router.php` | URL rewrite rules for /community/* |
| `includes/class-template-loader.php` | Template resolution with theme overrides |
| `includes/db/class-schema.php` | All 21 table definitions |
| `includes/db/class-migrator.php` | Version-based schema migrations |
| `includes/models/` | 15 model classes (Category, Space, Post, Reply, Vote, etc.) |
| `includes/permissions/class-permission-engine.php` | 3-layer permission resolver |
| `includes/trust/` | Trust levels (0-5), reputation calculator, auto-evaluator |
| `includes/api/` | 12 REST API controllers |
| `includes/adapters/` | 4 interfaces + WP Roles, Polling, wp_mail, MemberPress, PMPro adapters |
| `includes/notifications/class-notifier.php` | Event-driven notification dispatcher |
| `includes/import/` | bbPress + wpForo import tools |
| `templates/` | 12 views + 6 partials (theme-overridable) |
| `assets/css/jetonomy.css` | Theme-adaptive CSS (inherits from theme.json) |
| `assets/js/view.js` | Interactivity API store (voting, sorting, polling) |

## Documentation
- **Implementation Plans**: `docs/plans/` (only future / unshipped plans kept; shipped ones are pruned each release)
- **Design Prototypes**: `docs/prototype/` (open HTML files in browser)

## URL Structure
```
/community/                     → Home
/community/category/:slug/      → Category
/community/s/:slug/             → Space (topic listing)
/community/s/:slug/t/:slug/     → Single post + replies
/community/s/:slug/new/         → New post in space
/community/s/:slug/members/     → Space members
/community/s/:slug/roadmap/     → Space roadmap (ideas)
/community/u/:login/            → User profile
/community/u/:login/edit/       → Edit profile
/community/tag/:slug/           → Tag view
/community/search/              → Search
/community/leaderboard/         → Leaderboard
/community/notifications/       → Notifications
/community/mod/                 → Moderation (admin)
/community/invite/:code/        → Invite link landing page
/community/messages/            → Private messages (Pro)
/community/messages/:id/        → Conversation thread (Pro)
```

## Database Tables
Categories, Spaces, Posts, Replies, Votes, UserProfiles, Notifications, Subscriptions, ReadStatus, SpaceMembers, Tags, PostTags, SpaceTags, SpaceTagMap, UserInterests, ActivityLog, Restrictions, AccessRules, Flags, Revisions, JoinRequests, InviteLinks

## CLI Module (shipped 2026-04-11)

Journey-based CLI architecture for headless testing + automation. Every user/admin action is driveable from the terminal AND unit-testable via PHPUnit.

```
wp jetonomy <subject> <subcommand>       # 13 free command roots
wp jetonomy-pro <subject> <subcommand>   # 15 Pro command roots
wp jetonomy qa-actions                   # 210/210 smoke tests (4 phases)
wp jetonomy scenario run <name>          # 5 bundled end-to-end scenarios
```

**Key files:**
- `includes/cli/class-journey-result.php` - shared DTO (ok/fail/from_wp_error)
- `includes/cli/class-cli-dispatcher.php` - registers 13 free command slugs
- `includes/cli/journeys/` - 8 journey classes (pure PHP, no WP_CLI coupling)
- `includes/cli/commands/` - 13 command classes (thin WP_CLI wrappers extending Base_Command)
- `includes/cli/scenarios/` - Scenario_Runner + 5 bundled scenarios
- `includes/qa/class-journey-tests.php` - Phase 4 of qa-actions, smoke-tests every journey
- `tests/unit/cli/journeys/` - 8 journey unit test files
- `tests/unit/cli/scenarios/ScenarioRunnerTest.php` - 11 scenario runner tests
- `tests/pro/cli/journeys/` - 14 Pro journey unit test files (in free plugin's tests/pro/ dir)

**Pro CLI (in jetonomy-pro plugin):**
- `jetonomy-pro/includes/cli/class-pro-cli-dispatcher.php` - 15 Pro command slugs
- `jetonomy-pro/includes/cli/journeys/` - 14 journey classes (one per extension)
- `jetonomy-pro/includes/cli/commands/` - 15 command classes
- Pro CLI loads via `Jetonomy_Pro::maybe_load_cli()` - guarded by `is_dir()` for dist safety

**Test commands:**
```bash
composer test              # PHPUnit (free + pro combo)
composer test:free         # PHPUnit free-only (JETONOMY_TEST_SKIP_PRO=1)
composer test:combo        # PHPUnit with Pro loaded
composer test:usability    # Playwright browser tests (250 flows)
```

## Testing strategy

The browser-level Playwright usability suite (`tests/usability/`) was removed because it surfaced zero product UX bugs and primarily exposed test-infrastructure drift. Real UX validation runs through manual browser testing + Basecamp triage. The layers that actually find bugs:

1. **PHPUnit** - `composer test` (free + pro combo). Caught the `jt_notifications.object_type` schema bug that was silently breaking every Pro DM notification in prod.
2. **`wp jetonomy qa-actions`** - live-stack smoke checks across REST + Model + Pro + Journey layers. Runs in ~30s, surfaces config gaps.
3. **`wp jetonomy scenario run <name>`** - bundled end-to-end scenarios.

For release history, run `git log --oneline` or read `readme.txt`. For architectural context on a specific subsystem, check the manifest at `audit/manifest.json` first, then grep.

## Coding Patterns
- All data access via model classes - no raw SQL outside `includes/db/` (exception: Abilities execute callbacks for cross-table queries)
- Denormalized counters updated on write (reply_count, post_count, vote_score)
- Permission checks via `Permission_Engine::can($user_id, $action, $space_id)`
- Content stored as sanitized HTML (`wp_kses_post`), plain text copy for FULLTEXT search
- All external integrations via adapter interfaces
- Templates overridable via `theme/jetonomy/` directory
- Zero inline styles in templates (except truly dynamic values like kanban column colors)
- Trust level badges use `data-jt-tl` attribute selectors for background color
- Rewrite rules auto-flush on first load via deferred `init:99` callback (not during activation - rules aren't registered yet)
- Space settings save MERGES with existing JSON via `array_merge()` - never replaces entire settings column
- Activity logging via `Activity_Tracker` hooks - no direct `ActivityLog::log()` in controllers
- Demo data tracked in `jetonomy_demo_data` option for one-click cleanup
- Activity backfill runs automatically once via `jetonomy_activity_backfilled` flag

## CSS Token Rules (enforced - mirrors BuddyNext pattern)

**Golden rule: never write a hardcoded px, hex, or font-family value in any CSS file.**

All values must reference `--jt-*` custom properties. If a token doesn't exist for the value you need, add it to the `:root, .jt-app` block in `jetonomy.css` first.

### Where tokens are defined

All `--jt-*` tokens live in `:root, .jt-app` at the top of `assets/css/jetonomy.css`. Root tokens inherit from WP preset tokens so they auto-adapt to the active theme:

```css
/* Root tokens inherit from BuddyNext first, then WP theme.json, then hardcoded fallback.
   When BuddyNext is active, its TokenService injects --brand, --bg, --text-1 etc.
   Dark mode flows automatically - BuddyNext's [data-theme="dark"] overrides the
   underlying tokens, and --jt-* picks them up via the var() cascade. */
--jt-font:     var(--font-body, var(--wp--preset--font-family--body, inherit))
--jt-accent:   var(--brand, var(--wp--preset--color--primary, #3B82F6))
--jt-text:     var(--text-1, var(--wp--preset--color--contrast, #1a1a1a))
--jt-bg:       var(--bg, var(--wp--preset--color--base, #ffffff))
--jt-radius:   var(--r-md, var(--wp--custom--border-radius, 8px))
```

### Available token categories

| Category | Tokens |
|----------|--------|
| Typography | `--jt-font`, `--jt-font-heading`, `--jt-font-mono` |
| Accent | `--jt-accent`, `--jt-accent-hover`, `--jt-accent-light`, `--jt-accent-muted` |
| Text | `--jt-text`, `--jt-text-secondary`, `--jt-text-tertiary` |
| Background | `--jt-bg`, `--jt-bg-subtle`, `--jt-bg-muted`, `--jt-bg-hover` |
| Border | `--jt-border`, `--jt-border-strong` |
| Semantic | `--jt-success`, `--jt-success-light`, `--jt-warn`, `--jt-warn-light`, `--jt-danger`, `--jt-danger-light` |
| Trust levels | `--jt-tl0` … `--jt-tl5` |
| Badge tiers | `--jt-badge-bronze`, `--jt-badge-silver`, `--jt-badge-gold` |
| Radius | `--jt-radius`, `--jt-radius-sm`, `--jt-radius-lg`, `--jt-radius-full` |
| Motion | `--jt-ease`, `--jt-dur` |

### The color-mix fallback pattern

Derived color tokens use `color-mix()` for modern browsers with a hex fallback for older ones. Always write the hex fallback first, then override with `color-mix()` on the next line:

```css
/* Correct - hex fallback first, color-mix second */
--jt-text-secondary: #4B5563;
--jt-text-secondary: color-mix(in srgb, var(--jt-text) 70%, transparent);

/* Wrong - skipping the fallback */
--jt-text-secondary: color-mix(in srgb, var(--jt-text) 70%, transparent);
```

### Dark mode rule

Never write per-component dark selectors. Dark mode overrides only live in `.jt-dark .jt-app` in `jetonomy.css` by reassigning the `--jt-*` root tokens. Individual components automatically get dark mode by using the tokens:

```css
/* Correct - uses tokens, dark mode is automatic */
.jt-card { background: var(--jt-bg); border: 1px solid var(--jt-border); }

/* Wrong - adds a separate dark selector per component */
.jt-dark .jt-card { background: #1e1e1e; }
```

### Spacing and font-size

There is no spacing scale yet (gap to fill in a future task). Until a `--jt-space-*` scale is added:
- Use `rem` units for font sizes, not `px`
- Prefer named spacing that references `--jt-radius` for radius values
- Do NOT add a `--jt-space-*` scale without updating this section

### What to do when adding new CSS

1. Pick the closest existing `--jt-*` token
2. If no token fits, add one to `:root, .jt-app` - inherit from `--wp--preset--*` if applicable
3. Never copy-paste hex or px values from designs - always map them to token names first
4. Test at 390px viewport width before committing

---

## Admin Architecture Rules (enforced)

**AJAX handlers live in `Jetonomy\Admin\Ajax\` - never in `Admin` class directly.**

| Rule | Detail |
|------|--------|
| `Admin` class max 750 lines | Render methods, menu, settings, assets only |
| New AJAX group → new handler | Create `includes/admin/ajax/class-{domain}-handler.php` |
| Handler max 400 lines | If exceeded, split the domain further |
| Autoloader entry required | Add `'Jetonomy\\Admin\\Ajax\\'` → `'includes/admin/ajax/'` (already in map) |
| No render logic in handlers | Render methods stay in `Admin`; handlers are AJAX-only |
| No AJAX in `Admin::__construct()` | `__construct()` only: `add_menu`, `register_settings`, `enqueue_assets`, and `new Ajax\*_Handler()` calls |

**Current handler map** (see `includes/admin/ajax/`):
- `Categories_Handler` - create/update/delete/reorder category AJAX
- `Spaces_Handler` - space + member + access-rule AJAX
- `Moderation_Handler` - approve/spam/trash content + resolve flag AJAX
- `Users_Handler` - ban/unban/trust-level/search-users AJAX
- `Import_Handler` - run/batch/progress import AJAX
- `Settings_Handler` - test-email/flush-rules AJAX
- `Content_Handler` - post/reply CRUD + bulk-action AJAX
- `Setup_Handler` - setup wizard AJAX
- `Demo_Seeder` - helper class (static seed/cleanup methods), NOT an AJAX handler; used by Setup_Handler

**Naming conventions:**
- Options: `jetonomy_*` prefix always
- User meta: `jetonomy_*` prefix always
- DB tables: `jt_*` prefix always (becomes `wp_jt_*` with WP prefix)
- Hook names: `jetonomy_*` prefix always - never rename existing hooks
- AJAX actions: `wp_ajax_jetonomy_*` - never rename existing actions
- Asset handles: `jetonomy` or `jetonomy-{variant}`

## Basecamp Board

- **Project ID**: `46596502`
- **Board ID**: `9706083020`
- **URL**: https://3.basecamp.com/5798509/buckets/46596502/card_tables/9706083020

| Column | ID |
|--------|----|
| Triage | `9706083021` |
| Not now | `9706083022` |
| Scope | `9706083326` |
| Figuring it out | `9706083023` |
| Bugs | `9706083723` |
| UI | `9706189351` |
| In progress | `9706083024` |
| In Testing | `9706083581` |
| Done | `9706083025` |
