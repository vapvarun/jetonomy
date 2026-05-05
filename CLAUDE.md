# Jetonomy — WordPress Forum Plugin

> **READ FIRST:** [`audit/manifest.json`](audit/manifest.json) is the canonical inventory — 64 REST endpoints, 43 AJAX handlers, 104 hooks fired (59 actions + 43 filters + 2 deprecated aliases), 23 tables, 23 capabilities, 8 blocks, 9 shortcodes, 14 WP-CLI commands, 6 cron hooks, 14 admin pages. v2 schema with `static_analysis` populated (4 grid 1fr risks; no dead listeners on free side; 13 contract bugs documented in jetonomy-pro/audit/manifest.json). See also [`audit/FEATURE_AUDIT.md`](audit/FEATURE_AUDIT.md), [`audit/customer-experience-matrix.md`](audit/customer-experience-matrix.md), [`audit/journeys.json`](audit/journeys.json). For an interactive graph view, run `cd audit && python3 -m http.server 8765` and open <http://localhost:8765/graph.html>. Refresh via `/wp-plugin-onboard --refresh` after non-trivial changes.

## Build Rule (enforced)

**Every release zip must be produced by `bin/build-release.sh`.** No exceptions.

- Why: on 1.3.5 a stale Desktop zip (built before the critical bootstrap fix was committed) reached the GitHub release and took a customer's live site down. The release agent trusted "zip already exists" instead of rebuilding.
- What the script guarantees, in order:
  1. **Step 0 — asset regen.** Auto-runs `grunt build` (after a one-time `npm install`) so every release zip ships with fresh `.min.css`, `.min.js`, RTL CSS, and `.pot`. Closes the "stale .min" gap that almost shipped in 1.3.8 (newly added source files had no minified counterpart).
  2. Clean-tree gate (`--allow-dirty` only for local dev). Ignores grunt-regenerated paths so Step 0's deterministic output doesn't trip the gate.
  3. Version triangulation — Version header, constant, and readme Stable tag must match.
  4. Production composer install in staging (`--no-dev --optimize-autoloader`).
  5. Required-files sanity check.
  5b. **Source/min pairing assertion** — every `assets/css/*.css` (non-RTL, non-min) must have a `*.min.css` in staging; same for JS. Fails with the missing list.
  5c. **Top-level cruft check** — rejects `verify-*.png`, `.playwright-mcp/`, `.distignore`, `.wp-env.json`, `phpstan-*.dist`, `*.log`, empty `build/` from leaking past EXCLUDES. Tonight's pro 1.3.8 zip had ~1.1 MB of these files; this gate would have failed the build before tagging.
  6. `php -l` on every staged PHP file.
  7. **WP-stub smoke test** — boots the plugin through `plugins_loaded` + `init` in a minimal WP stub (`tools/wp-stubs.php` + `tools/smoke-test.php`), catching load-time fatals like the 1.3.5 `Jetonomy\table()` bug.
  7b. **Browser smoke gate** — the smoke skill writes `.last-smoke-pass-free.json`; the script triages by `origin`, only `from`-origin failures or debug-log entries block. `for`-origin entries (test harness, theme, OS) stay informational.
  8. Zip → re-extract to scratch → re-run WP-stub smoke (catches zip corruption).
- Never attach a pre-existing zip to a release. Always rebuild from the tagged commit.
- Pro: `bin/build-release.sh` additionally enforces the lockstep rule — fails if Pro's version doesn't match free's. Pro does not have the browser smoke gate (uses the COMBO smoke skill for verification before tagging instead).

## Pre-Commit Rule (enforced)

**Every commit is locally gated by `.githooks/pre-commit`.** The hook runs PHPStan on the full tree (honours the baseline) and WPCS on staged PHP files before the commit lands. Failures block the commit so red X's never reach the public history.

- Install: `composer install` auto-configures it via `post-install-cmd` (sets `core.hooksPath .githooks`). For existing clones, `composer run hooks:install` does the same.
- Skip (emergencies only): `git commit --no-verify` — but fix it in the next commit.
- Target latency: under 30 seconds. If the hook gets slower, split the check or move it to pre-push.

## Release Rule (enforced)

**Free and Pro always ship with the same `x.y.z` version number.** No exceptions.

- Every release bumps `jetonomy` AND `jetonomy-pro` together, even if one side has no user-facing changes — the side with no changes gets a "Compatibility: Aligned with Jetonomy x.y.z" entry in its readme.
- `JETONOMY_VERSION`, `jetonomy.php` `Version:` header, and `readme.txt` `Stable tag:` must all match the corresponding Pro constants and headers.
- CI fails fast if the two versions drift.
- Rationale: pairing simplifies support ("what version are you on?"), EDD updater routing, and the release checklist — no cognitive load deciding which plugins need which bump.

## Feature Acceptance Rules (enforced for every release)

Three rules hard-gate every new feature. They live as memory entries (`feedback_rest_first_and_rtl_ready.md`, `feedback_frontend_rest_only_backend_ajax_ok.md`, `feedback_readme_txt_customer_facing.md`) so Claude carries them across sessions:

1. **REST-first, full CRUD, with documented contract.** Every read AND every mutation is reachable via a `jetonomy/v1/*` endpoint with documented route / method / payload / response / permission_callback. Reuse an existing controller when possible; only add a new one when no endpoint can carry the operation. AJAX-only or form-post-only paths are bugs.
2. **Frontend REST-only, backend AJAX is acceptable.** Customer-facing surfaces (frontend templates, blocks, app) call REST. wp-admin tooling can keep AJAX where it already exists. The two reasons: customer perf (`admin-ajax.php` triggers full admin bootstrap, defeats caching plugins, kills HTTP/2 multiplexing) and the upcoming app needs REST anyway. Don't refactor working admin AJAX for uniformity; do migrate any `wp_ajax_*` handler called from frontend JS.
3. **RTL ready out of the gate.** Every new template / partial / block ships with RTL parity from the first commit. Use logical CSS properties (`margin-inline-start`, `padding-inline-end`, `inset-inline-end`, `text-align: start`) so the browser flips for free. Hand-tuned `[dir="rtl"]` overrides only for genuinely asymmetric values. `grunt rtlcss` auto-generates `*-rtl.css` but visual verification under `<html dir="rtl">` is required before marking done.

Two further rules apply to every release:

- **readme.txt is customer-facing**, not developer notes. Lead with what's new and what's fixed, in plain English. Internal commits/refactors stay in git history and PR descriptions.
- **Release zip is verified by the build, not by hand.** `bin/build-release.sh` runs `grunt build` (Step 0), asserts every CSS/JS source has a `.min` pair (Step 5b), and rejects any top-level dev cruft (Step 5c). Manual extract-and-eyeball is the wrong model.

## Quick Reference
- **Type**: WordPress Plugin (forum, Q&A, ideas, social feed)
- **PHP**: 8.1+ required
- **WP**: 6.7+ required
- **Namespace**: `Jetonomy\`
- **Table prefix**: `jt_` (22 custom tables)
- **REST API**: `jetonomy/v1` (42 endpoints, 15 controllers)

## Architecture
- **Database**: Custom MySQL tables via `dbDelta()` — NOT WordPress CPTs
- **Models**: `includes/models/` — all extend abstract `Model` class
- **Permissions**: 3-layer system (WP Caps → Space Roles → Trust Levels)
- **API**: `includes/api/` — all extend `Base_Controller` → `WP_REST_Controller`
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
- `includes/cli/class-journey-result.php` — shared DTO (ok/fail/from_wp_error)
- `includes/cli/class-cli-dispatcher.php` — registers 13 free command slugs
- `includes/cli/journeys/` — 8 journey classes (pure PHP, no WP_CLI coupling)
- `includes/cli/commands/` — 13 command classes (thin WP_CLI wrappers extending Base_Command)
- `includes/cli/scenarios/` — Scenario_Runner + 5 bundled scenarios
- `includes/qa/class-journey-tests.php` — Phase 4 of qa-actions, smoke-tests every journey
- `tests/unit/cli/journeys/` — 8 journey unit test files
- `tests/unit/cli/scenarios/ScenarioRunnerTest.php` — 11 scenario runner tests
- `tests/pro/cli/journeys/` — 14 Pro journey unit test files (in free plugin's tests/pro/ dir)

**Pro CLI (in jetonomy-pro plugin):**
- `jetonomy-pro/includes/cli/class-pro-cli-dispatcher.php` — 15 Pro command slugs
- `jetonomy-pro/includes/cli/journeys/` — 14 journey classes (one per extension)
- `jetonomy-pro/includes/cli/commands/` — 15 command classes
- Pro CLI loads via `Jetonomy_Pro::maybe_load_cli()` — guarded by `is_dir()` for dist safety

**Test commands:**
```bash
composer test              # PHPUnit (free + pro combo)
composer test:free         # PHPUnit free-only (JETONOMY_TEST_SKIP_PRO=1)
composer test:combo        # PHPUnit with Pro loaded
composer test:usability    # Playwright browser tests (250 flows)
```

## Testing strategy (2026-04-14)

**Removed: browser-level Playwright usability suite** (`tests/usability/`).
After evaluation it surfaced zero product UX bugs and primarily exposed
test-infrastructure drift (hardcoded IDs, CLI arg mismatches, selector
drift). The grind-to-signal ratio was too poor to justify continued
investment. Real UX validation moved back to manual browser testing +
Basecamp triage.

**Kept — the layers that actually find bugs:**
1. **PHPUnit** — 226 tests across unit/integration/security/concurrency/error-paths/pro. Runs via `composer test`. Caught the `jt_notifications.object_type` schema bug that was silently breaking every Pro DM notification in prod.
2. **`wp jetonomy qa-actions`** — 210 live-stack smoke checks (74 REST + 23 Model + 62 Pro + 51 Journey). Runs in ~30s, surfaces config gaps (e.g. missing `trust_thresholds` defaults).
3. **`wp jetonomy scenario run <name>`** — 5 bundled end-to-end scenarios.

**Next session priorities:**
- Triage Basecamp "In Testing" column (13+ cards never triaged)
- Customer ticket triage (`/autovap support`)
- Add `composer test` + `wp jetonomy qa-actions` to CI if not already wired

## Recent Changes
| Date | Commit | Summary |
|---|---|---|
| 2026-04-30 | audit refresh | `/wp-plugin-onboard --refresh` rerun for free + pro; v2 schema's `static_analysis` section added to both manifests. Free side: no dead listeners (the 6 candidates are wp_schedule_event cron names, not bugs); 4 low-severity grid 1fr collapse candidates documented for next CSS sweep. Cross-plugin scan against pro flagged 13 contract bugs (white-label filters never applied, custom-fields filters never applied, webhooks 8 lifecycle events have name drift or no firer). Documented in `audit/FEATURE_AUDIT.md`. Each entry has a fix target with file:line. |
| 2026-04-30 | 1.4.1 in-flight | UX uniformity sweep landed: 5 contract sweeps (icons, action density, byline, spacing, empty state) executed across templates + partials + blocks. New `templates/partials/empty-state.php` partial used by 22 surfaces. Lucide icons only (no inline SVG, no WP emojis). Voting fix: Pro analytics aggregator gated by `table_exists()` check (was breaking AJAX response on sites without the analytics table). |
| 2026-04-26 | 1.4.0 in-flight | Branch open. Phase 0 prelude landed: `Space::create` always seeds the creator as space admin (was AJAX-vs-Abilities split). Phases A (frontend AJAX → REST migration: media upload + Login block) and B (front-end space governance, G1–G7 in `docs/plans/v1.4.0.md`) follow. REST-first + RTL-ready hard-gated for every new feature. Frontend-REST-only / backend-AJAX-OK split codified. |
| 2026-04-26 | release process | `bin/build-release.sh` hardened with three new gates: Step 0 auto `grunt build` (regen .min/.pot/RTL), Step 5b source/min pairing assertion, Step 5c top-level cruft check. Manual zip-extract per release replaced with the build gate. Mirror landed on Pro. |
| 2026-04-26 | v1.3.8 | Space-scoped moderation queue at `/community/s/:slug/mod/` plus cross-space dashboard at `/community/mod/`; front-end member role management on the space members page; FluentCommunity integration (paired spaces, profile cross-links, unified avatars, member sync, activity broadcast, comment-to-reply bridge); BuddyPress group activity broadcast with comment round-trip; dark-mode contrast fixes for plugin headings and accent tints; sort modes (oldest/newest/unanswered) + space-settings merge fixes; rewrite-flush on activation; long bug-fix tail. |
| 2026-04-21 | v1.3.7 | Reaction picker bundled-SVG fix (CDN / emoji-loader resilience); Polls topic body-required inline error parity with regular posts; plain-language polish across admin labels and emails. |
| 2026-04-20 | v1.3.6 | Private Messaging recipient typeahead with shared-space scope; `POST /conversations` enforces shared-space scope on every recipient (security); Top Members + profile-hover Message actions wired up. |
| 2026-04-18 | v1.3.5 | Jetonomy Navigation block + Jetonomy Login block (`includes/blocks/class-navigation-block.php`, `class-login-block.php`); inline editor paragraph preservation fix; `bin/build-release.sh` as the only path to a release zip (clean-tree gate, version triangulation, prod composer install, `php -l`, smoke test, zip/re-extract/re-smoke-test) |
| 2026-04-18 | incident | 1.3.5 stale-desktop-zip broke a customer's live site on auto-update — led to the build-release.sh hardening above. Never attach a pre-existing zip to a release; always rebuild from the tagged commit. |
| 2026-04-17 | v1.3.4 | Akismet bypass for staff roles (`includes/moderation/class-akismet-adapter.php`); one-click Approve/Not Spam in admin lists; spaces auto-join authors on post/reply + historical back-fill upgrade routine; counter accuracy through approve/spam/trash actions; moderation queue REST supports `status=pending\|spam\|all`; bulk trust-level promotion via admin API |
| 2026-04-16 | v1.3.3 | Access Control collapsed 3-option → 2-option (Public / Private) with auto-migration; admin counter-rebuild button (no WP-CLI needed); preserve original `created_at` on imported/seeded topics; `default_space_type` setting actually applied (was previously dead) |
| 2026-04-13 | v1.3.2 | Setup wizard PHP 8.1+/WP 6.4+ deprecations fixed (`strip_tags(null)`, `print_emoji_styles`, `wp_admin_bar_header`); new `jetonomy_new_post_submit_action` filter so Pro extensions can intercept the new-post submit URL server-side (closed three Preact/virtual-DOM bugs in the Poll extension) |
| 2026-04-10 | v1.3.1 | Scoped CSS reset for `.jt-app` — theme button hover styles no longer bleed into Jetonomy button states (BuddyX/Reign regression from 1.3.0). Release process now requires browser verification on BuddyX, Reign, and Twenty Twenty-Five before tagging. |
| 2026-04-18 | docs | `docs/website/` audit + refresh: `docs_config.json` version bumped to 1.3.5; shortcodes-widgets-blocks.md updated to five blocks; theme-compatibility.md adds BuddyX Pro + Reign + Kirki bridge; AI extension doc adds multi-provider fallback / spend caps / moderation presets; README.md changelog extended through 1.3.5 |
| 2026-04-18 | community | community.wbcomdesigns.com (Jetonomy-powered Wbcom support community) prepping for public registration flip — becomes the default first-stop for support. Paid tickets unchanged. |
| 2026-04-15 | feature | Theme bridge for BuddyX, BuddyX Pro, Reign — reads Kirki theme mods, injects `--jt-accent` + toggles `.jt-dark` via `body_class` (`includes/integrations/class-theme-integration.php`) |
| 2026-04-14 | pending | fix(#9721640432): posts_per_page space setting truly applies — drop `default=>20` from REST `limit` schema (made null-check dead), use raw query params in posts controller, save `null` (not `20`) on empty admin field, render empty `value=""` (not `0`) in space-edit form, strip key in spaces handler sanitizer when null/0 |
| 2026-04-14 | `9b242b3` | fix(cli): reply create docblock — `[--status]` and `[--format]` missing `: description` before `---` enum, so WP-CLI rejected `--format=json` |
| 2026-04-14 | `8745756` | test(usability): id-lookup helpers + member/admin/moderator fixes — free-flow pass rate 29 → 67 |
| 2026-04-14 | `1a89088` | test(usability): fix CLI arg signatures in 7 flows (`demo-seed`, `demo-cleanup`, `space list --category=<id>`) |
| 2026-04-14 | `f1106a2` | test(usability): align demo seeder (friendly user logins, category slug `community`) + matcher implicit `flow_completes_without_error` |
| 2026-04-14 | `1cfeae6` | fix: jt_notifications `object_type` ENUM adds `'message'` (migration 1.2.3) + declare `$ai_spam_detector` to remove PHP 8.2 deprecation; DB_VERSION → 1.2.3 |
| 2026-04-12 | `bf1a1a8` | Usability test suite complete — 250 flows, 250 YAMLs, all layers connected, zero TODO |
| 2026-04-12 | `7f205b2` | 66 free flows implemented (trusted + moderator + admin + cross-cutting) |
| 2026-04-12 | `7e45d40` | 52 free flows implemented (anonymous + registered + member) |
| 2026-04-12 | `159f8fe` | Phase 4 qa-actions extended to all 14 Pro journeys (51/51 checks) |
| 2026-04-12 | multiple | Pro CLI — 14 extension journeys + commands + tests across both plugins |
| 2026-04-11 | `06db47c` | Scenario runner + 5 bundled scenarios |
| 2026-04-11 | `32968a9`→`d61b49b` | CLI module foundation through qa-actions Phase 4 (10 commits) |
| 2026-04-11 | 5 commits | 5 Basecamp bug fixes (notification click, Join Space, email URL, tab visibility, settings defaults) |
| 2026-04-05 | v1.3.0 | AI adapter layer (interface, Ollama provider, registry, spam detector), 9 Basecamp bug fixes, security/code quality audit, WPCS ruleset |
| 2026-03-29 | v1.0.1 | Theme compat: .container→.jt-container rename, dynamic --jt-container-width from theme settings, flex parent fix, sub-nav inside container, page title suppression, tested 12 themes |
| 2026-03-27 | `e42b7ec` | Fix: space settings merge (not replace), join request state persists on refresh, join request email notification to admins |
| 2026-03-27 | `e3a21fc` | Fix: WPCS translators comments, Yoda conditions, PHPStan baseline update for space-edit |
| 2026-03-27 | `189fe6d` | Fix: 10 Basecamp bugs — notification defaults reset, vote state indicator, rewrite flush deferred, admin View link, join request admin UI, Post::create() last_reply_at default |
| 2026-03-26 | `cc27780` | Fix: settings write pattern (always write all fields), nonce mismatch (wp_rest), credentials: same-origin on all fetch calls |
| 2026-03-26 | CI | PHPUnit 219/219 pass, PHPStan 0 errors, WPCS 0 new errors, GitHub Actions CI green |
| 2026-03-24 | feature | BLOCK DT: Unified Design Token Bridge — `--jt-*` root tokens now reference BuddyNext tokens first (`--brand`, `--bg`, `--text-1`, `--green`, `--amber`, `--red`, `--r-md`, `--font-body`, `--font-display`), then WP theme.json, then hardcoded fallback; dark mode flows automatically via CSS cascade | `assets/css/jetonomy.css` |
| 2026-03-24 | feature | Categories page split layout — form left (360px), table right; removed Order column; truncate description in table | includes/admin/views/categories.php, assets/css/admin.css |
| 2026-03-20 | pending | Human-readable activity feed, activity backfill, uninstall.php, model methods, Pro abilities rewrite |
| 2026-03-20 | `2c554ea` | Admin Content page (post/reply management), realistic demo data, demo cleanup |
| 2026-03-20 | `49381a2` | WordPress Abilities API (18 abilities) + centralized Activity Tracker |
| 2026-03-19 | `ea1dfe2` | Fix Spaces admin page — unregistered capability |
| 2026-03-19 | `2043cfb` | Enterprise import UX: completion tracking, resume on failure, step indicators |
| 2026-03-19 | `c2440f8` | G4-G10: RTL, quote text, hover cards, IP ban, shortcuts, emoji picker, invite links |
| 2026-03-19 | `439553d` | Cache layer, eager loading, cursor-based pagination |

## Key Files (recent additions)
| Path | Purpose |
|---|---|
| `includes/class-abilities.php` | WP Abilities API — 18 abilities in 5 categories |
| `includes/class-activity-tracker.php` | Centralized event logging via hooks |
| `includes/admin/views/content.php` | Admin post/reply management page |
| `uninstall.php` | Clean removal of all tables, options, caps, cron |

## Coding Patterns
- All data access via model classes — no raw SQL outside `includes/db/` (exception: Abilities execute callbacks for cross-table queries)
- Denormalized counters updated on write (reply_count, post_count, vote_score)
- Permission checks via `Permission_Engine::can($user_id, $action, $space_id)`
- Content stored as sanitized HTML (`wp_kses_post`), plain text copy for FULLTEXT search
- All external integrations via adapter interfaces
- Templates overridable via `theme/jetonomy/` directory
- Zero inline styles in templates (except truly dynamic values like kanban column colors)
- Trust level badges use `data-jt-tl` attribute selectors for background color
- Rewrite rules auto-flush on first load via deferred `init:99` callback (not during activation — rules aren't registered yet)
- Space settings save MERGES with existing JSON via `array_merge()` — never replaces entire settings column
- Activity logging via `Activity_Tracker` hooks — no direct `ActivityLog::log()` in controllers
- Demo data tracked in `jetonomy_demo_data` option for one-click cleanup
- Activity backfill runs automatically once via `jetonomy_activity_backfilled` flag

## CSS Token Rules (enforced — mirrors BuddyNext pattern)

**Golden rule: never write a hardcoded px, hex, or font-family value in any CSS file.**

All values must reference `--jt-*` custom properties. If a token doesn't exist for the value you need, add it to the `:root, .jt-app` block in `jetonomy.css` first.

### Where tokens are defined

All `--jt-*` tokens live in `:root, .jt-app` at the top of `assets/css/jetonomy.css`. Root tokens inherit from WP preset tokens so they auto-adapt to the active theme:

```css
/* Root tokens inherit from BuddyNext first, then WP theme.json, then hardcoded fallback.
   When BuddyNext is active, its TokenService injects --brand, --bg, --text-1 etc.
   Dark mode flows automatically — BuddyNext's [data-theme="dark"] overrides the
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
/* Correct — hex fallback first, color-mix second */
--jt-text-secondary: #4B5563;
--jt-text-secondary: color-mix(in srgb, var(--jt-text) 70%, transparent);

/* Wrong — skipping the fallback */
--jt-text-secondary: color-mix(in srgb, var(--jt-text) 70%, transparent);
```

### Dark mode rule

Never write per-component dark selectors. Dark mode overrides only live in `.jt-dark .jt-app` in `jetonomy.css` by reassigning the `--jt-*` root tokens. Individual components automatically get dark mode by using the tokens:

```css
/* Correct — uses tokens, dark mode is automatic */
.jt-card { background: var(--jt-bg); border: 1px solid var(--jt-border); }

/* Wrong — adds a separate dark selector per component */
.jt-dark .jt-card { background: #1e1e1e; }
```

### Spacing and font-size

There is no spacing scale yet (gap to fill in a future task). Until a `--jt-space-*` scale is added:
- Use `rem` units for font sizes, not `px`
- Prefer named spacing that references `--jt-radius` for radius values
- Do NOT add a `--jt-space-*` scale without updating this section

### What to do when adding new CSS

1. Pick the closest existing `--jt-*` token
2. If no token fits, add one to `:root, .jt-app` — inherit from `--wp--preset--*` if applicable
3. Never copy-paste hex or px values from designs — always map them to token names first
4. Test at 390px viewport width before committing

---

## Admin Architecture Rules (enforced)

**AJAX handlers live in `Jetonomy\Admin\Ajax\` — never in `Admin` class directly.**

| Rule | Detail |
|------|--------|
| `Admin` class max 750 lines | Render methods, menu, settings, assets only |
| New AJAX group → new handler | Create `includes/admin/ajax/class-{domain}-handler.php` |
| Handler max 400 lines | If exceeded, split the domain further |
| Autoloader entry required | Add `'Jetonomy\\Admin\\Ajax\\'` → `'includes/admin/ajax/'` (already in map) |
| No render logic in handlers | Render methods stay in `Admin`; handlers are AJAX-only |
| No AJAX in `Admin::__construct()` | `__construct()` only: `add_menu`, `register_settings`, `enqueue_assets`, and `new Ajax\*_Handler()` calls |

**Current handler map** (see `includes/admin/ajax/`):
- `Categories_Handler` — create/update/delete/reorder category AJAX
- `Spaces_Handler` — space + member + access-rule AJAX
- `Moderation_Handler` — approve/spam/trash content + resolve flag AJAX
- `Users_Handler` — ban/unban/trust-level/search-users AJAX
- `Import_Handler` — run/batch/progress import AJAX
- `Settings_Handler` — test-email/flush-rules AJAX
- `Content_Handler` — post/reply CRUD + bulk-action AJAX
- `Setup_Handler` — setup wizard AJAX
- `Demo_Seeder` — helper class (static seed/cleanup methods), NOT an AJAX handler; used by Setup_Handler

**Naming conventions:**
- Options: `jetonomy_*` prefix always
- User meta: `jetonomy_*` prefix always
- DB tables: `jt_*` prefix always (becomes `wp_jt_*` with WP prefix)
- Hook names: `jetonomy_*` prefix always — never rename existing hooks
- AJAX actions: `wp_ajax_jetonomy_*` — never rename existing actions
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
