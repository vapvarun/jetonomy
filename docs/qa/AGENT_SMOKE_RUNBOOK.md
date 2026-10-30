# Agent Smoke Runbook - Jetonomy Pre-Release

**Audience:** a browser-capable agent (Claude Sonnet or equivalent) with Playwright MCP + WP-CLI Bash access, OR a human QA person with the same access. Both should be able to execute every step of this runbook.

## If you are a human tester, read this and skip the rest of the machinery

You do **not** need to produce the JSON in "Agent output contract" - that exists so an agent's run can gate the build. Work through the sections, and for anything that fails, file a card using the template in **Failure protocol** at the bottom. Everything else here is for the agent.

**Three things make a card actionable, and cards without them cost a round trip:**

1. **The URL and who you were signed in as.** Half of what we ship is permission-shaped, so "logged out", "a normal member", "a space moderator" or "admin" changes the answer completely.
2. **What you expected, in the product's own words.** Most steps quote the promise the screen makes. Paste that line as "Expected". It is the strongest possible bug report, because it makes the disagreement between what we say and what we do impossible to argue with.
3. **`from` or `for`.** `from` = our plugin is at fault. `for` = it broke while our plugin was running but the cause looks like the theme, another plugin, the browser or old imported data. Guessing is fine - say which you think and why in one line. It stops theme quirks landing in the Bugs column, and it is the single most useful thing you can add.

**Worth filing even when nothing looks broken:**

- The screen says one thing and the site does another. That is a bug even if the behaviour seems reasonable - one of the two is wrong and we need to know which.
- Two places disagree about the same thing. A space that opens in the browser but 403s in the app, a count that differs between two screens, a member visible in one list and missing from another.
- It works but nobody could have guessed it would. Silent misconfiguration costs us more support time than crashes do.

**Not worth filing:** styling on a theme we do not ship, anything you can only reproduce with the browser console open, and pre-existing demo data that is simply odd. If unsure, file it as `for` with a note - a wrong guess costs nothing, a missing report costs a customer.

## How to read this runbook

Each C and E step describes a **customer contract**: what the feature promises, why it matters, the surfaces it touches, and what "working" looks like in customer terms. It does NOT prescribe the exact Playwright calls, selectors, REST paths, or DB queries. Read the relevant plugin code, pick the right mechanism, and verify the contract. This freedom is the point: the verifier is expected to notice bugs we did not pre-imagine.

D (regression guards) stays specific - those are repros of past incidents; the exact fixture is the contract.

Infrastructure sections (preconditions, output contract, debug-log protocol, fixture cleanup, failure protocol) stay specific because they are the stable machinery the walk rides on.

**Covers and Verified - what makes this runbook a release gate.** Every journey names the files it walks (`**Covers:**`, git globs; Pro files as `pro:path`) and the commits it last passed a browser walk at (`**Verified:** <free-sha>`, plus `pro:<sha>` when it covers Pro). Two gates read them, and `bin/build-release.sh` (Free and Pro) will not build until both are clear:

- `bin/check-journey-coverage.php`: every shipped PHP / JS / CSS file in Free and Pro is in some journey's Covers, or in `docs/qa/journey-exempt.txt` with a reason. A file no journey claims is a change no walk would catch.
- `bin/check-runbook-fresh.php`: no covered file has changed since the journey's Verified commits. A changed file means the journey must be walked again.

- `bin/check-journey-depth.php`: every journey has a **Checks:** list deep enough to catch what QA catches, and every stamp is backed by a walk record in which every check passed.

**Checks - what a walk must prove.** The "What to verify" paragraph says what the feature promises. The **Checks:** list under it says exactly what a walk must do, one line each:

```
- `c-member-vote.4` @member #ui +limit: At the daily vote limit, take a vote back -> it is removed and the toast says "Vote removed".
```

`@role` is who does it (guest or a persona in `qa-config.json`: member, member_b, member_c, moderator, space_admin, silenced, space_banned, admin). `#surface` is where (ui, admin, rest, cli, email, block). `+state` is the condition (+closed, +private, +hidden, +long, +rtl, +empty, +many, +limit, +blocked, +silenced, +old-data, +offline, +concurrent, +390, +dark...). `!refuse` means the action must be refused with its reason shown; a read of something the caller cannot see must answer exactly like "not found". `card:<id>` is the Basecamp bug the check exists to catch again. Every C and E journey refuses something and walks at least two roles, or says why not in a `**Depth note:**` line.

**A journey passes only when every check passed.** Not "mostly worked", not PASS with a list of things not tried. Each check is walked in the browser (or through the surface it names), on the realistic seeded community, once on the primary setup: Reign, light, 1440. A check tagged +390 or +dark runs in that setup too, and every check tagged +long, +390 or +dark (where content or the viewport changes the layout) is repeated on BuddyX and Twenty Twenty-Five, so the plugin, not one theme, carries the look. The F journeys sweep the rest of the matrix in `qa-config.json`. Third-party integrations (WooCommerce, PMPro, Tutor LMS, LifterLMS, Sensei, MasterStudy, Uncanny Automator, bbPress, Yoast, Akismet) are installed but inactive; activate one only for the journey that needs it and deactivate it afterwards. A paid-only path with no free equivalent is `untested` with that reason, never passed. The result goes in `docs/qa/walks/<journey id>.json`:

```json
{
  "journey": "C.member.vote",
  "free": "<git rev-parse --short HEAD in jetonomy>",
  "pro": "<same in jetonomy-pro, when Covers has pro: files>",
  "walked_at": "<ISO 8601 UTC>",
  "env": { "themes": ["reign", "buddyx", "twentytwentyfive"], "viewports": [1440, 1024, 390], "schemes": ["light", "dark"] },
  "checks": {
    "c-member-vote.1": { "result": "pass", "evidence": "score 12 -> 13, kept after reload" },
    "c-member-vote.4": { "result": "fail", "evidence": "toast said Vote added", "screenshot": "qa-artifacts/..." }
  },
  "proposed_checks": [ "- `c-member-vote.9` @member #ui +rtl: ..." ]
}
```

`result` is pass, fail or untested (with what it needs). **The runbook heals itself through `proposed_checks`:** when a walk meets a state, role or surface no check covers, or finds a bug no check would have caught, the walker writes the missing check there. The journey cannot be stamped until that check is in the runbook and has passed. Every bug QA finds after a walk adds its check too, tagged `card:<id>`, with a row in `audit/escapes.json`, so the same miss cannot pass a walk twice.

Developer and QA use one command, `bin/self-qa.sh`: it prints the files with no journey, the journeys without checks, and the journeys to walk now. Walk every check, write the walk record, then `bin/self-qa.sh --stamp <id>...` records the commits; it refuses a journey whose walk record is not at the current commits with every check passed. When you add a template, partial, script or class, add it to the Covers of every journey whose behaviour it renders, and add checks for what it changes.

## Global preconditions

- Working directory: `~/Local Sites/forums/app/public` (the Local site on the machine running the walk)
- Site URL: `http://forums.local`
- WP-CLI template: `wp --path="$WP_PATH" <cmd>`
- Admin auto-login: `?autologin=1` on any front-end URL
- Per-user auto-login: `?autologin=<user_login>`
- Playwright: reuse one Chromium session. Restart with `browser_close` + `browser_navigate` if it dies.
- Debug log: `wp-content/debug.log`
- Release target: value of the `JETONOMY_VERSION` constant

## Agent output contract

Each journey walked writes its walk record, `docs/qa/walks/<journey id>.json` (format under "How to read this runbook"); that is what `--stamp` and the release build read. At the end of the whole walk, also write exactly one JSON file to
`wp-content/plugins/jetonomy/docs/qa/.last-smoke-pass.json`:

```json
{
  "release_version": "<JETONOMY_VERSION>",
  "ran_at": "<ISO 8601 UTC>",
  "sections": {
    "A_fresh_install":     { "pass": N, "fail": N, "skipped": N },
    "B_upgrade":           { "pass": N, "fail": N, "skipped": N },
    "C_core_flows":        { "pass": N, "fail": N, "skipped": N },
    "D_regression_guards": { "pass": N, "fail": N, "skipped": N },
    "E_pro_smoke":         { "pass": N, "fail": N, "skipped": N },
    "F_cross_browser":     { "pass": N, "fail": N, "skipped": N }
  },
  "failures": [
    {
      "id": "C.member.post-create",
      "origin": "from | for",
      "triage_note": "one line on why you classified it that way",
      "expected": "...",
      "actual": "...",
      "url": "...",
      "screenshot": "..."
    }
  ],
  "debug_log_issues": [
    { "section": "...", "level": "fatal|warning|notice|deprecated", "line": "...", "file": "..." }
  ],
  "manual_required": [
    "Firefox Desktop: ...",
    "Safari iOS 390px: ..."
  ]
}
```

Also emit a Basecamp draft for every failure using the template in the Failure protocol.

## Fixture SEEDING (before every walk) — do this first

**Walk on a realistic community, never on throwaway fixtures.** Realistic content is what shows a layout or wording problem on the first walk: the 2.0.3 Reign header squeeze only appeared with a long space description, and the seed had none. Seed the demo community the owner gets from Setup, plus the shapes it lacks:

```bash
wp --path="$WP_PATH" eval-file wp-content/plugins/jetonomy/bin/seed-qa-users.php        # persona accounts
wp --path="$WP_PATH" eval-file wp-content/plugins/jetonomy/bin/seed-demo-community.php  # realistic community
wp --path="$WP_PATH" eval-file wp-content/plugins/jetonomy/bin/seed-qa-pages.php        # one page per block/shortcode
```

What it gives the checks (details in the seeder's header): ~20 members with bios and avatars, a long name with no avatar (Grace), every space type (forum, Q&A with accepted answers, ideas, feed), every visibility and join policy (public; Partner Program private and open; Customer Advisory Board invite-only; Moderators' Room hidden; Insiders / Beta with a pending request; an archived space), 14 chapter spaces with the last five empty, a 288-character space description (Help Desk), a 159-character title with a list, quote, code block and unbroken URL, an Arabic topic, a 45-reply thread (two pages), a closed topic, a silenced member (finn), a space-banned member (omar) and a -4 scored post.

**Reseeding rebuilds the content with new ids.** Test steps and card comments name spaces and topics, never ids.

Content a check creates is written as a real member would write it: a believable title and body, never "test 1". If a check needs a shape the seed lacks, add it to `bin/seed-demo-community.php` in the same change; "no fixture" is a bug in the seeder, not a reason to skip.

`bin/seed-qa-content.php` still seeds the `jt-qa-*` spaces that `wp jetonomy qa-actions` uses internally. Do not walk browser checks on them.

## Fixture cleanup (before every walk)

Delete any leftover test data from prior runs. Exact WP-CLI eval script is permitted here because this is infrastructure, not a feature check.

```bash
wp --path="$WP_PATH" eval '
global $wpdb;
$wpdb->query("DELETE FROM wp_jt_posts WHERE title LIKE \"E2E %\" OR title LIKE \"Smoke %\"");
$wpdb->query("DELETE FROM wp_jt_replies WHERE content_plain LIKE \"smoke-%\" OR content_plain LIKE \"e2e-%\"");
$wpdb->query("DELETE FROM wp_jt_flags WHERE reason LIKE \"smoke-%\"");
$ids = implode(",", array_map(fn($l) => (int) (get_user_by("login", $l)->ID ?? 0), array("bob", "alice")));
$wpdb->query("DELETE FROM wp_jt_subscriptions WHERE user_id IN ($ids) AND object_type IN (\"post\", \"space\")");
wp_cache_flush();
echo "fixtures cleaned\n";
'
```

## Debug log protocol

Enable WP_DEBUG + WP_DEBUG_LOG + WP_DEBUG_DISPLAY=false for the entire walk. Baseline `wp-content/debug.log` byte count. After every section, diff new lines and record `Fatal error:` / `Warning:` / `Notice:` / `Deprecated:` entries into `debug_log_issues[]`. Silent warnings are the bugs that ship, so treat any new non-info line as a failure unless explicitly whitelisted.

```bash
BASELINE_SIZE=$(wc -c < "$WP_PATH/wp-content/debug.log" 2>/dev/null || echo 0)
# after each section:
tail -c +$((BASELINE_SIZE + 1)) "$WP_PATH/wp-content/debug.log" 2>/dev/null | grep -vE "^\s*$|^\[cli\]"
```

At walk end, archive the diff window to `docs/qa/.debug-log-<release_version>-<ran_at>.txt`.

---

## A - Fresh install (skip on live dev sites)

### A1 - Activation and first-request routing
**Covers:** includes/class-router.php, includes/class-jetonomy.php, includes/api/class-api.php, includes/class-autoloader.php, includes/class-cache.php, includes/functions-multisite.php, includes/functions.php, includes/helpers.php, templates/views/not-found.php, jetonomy.php
**Verified:** 64212bb5
**What to verify:** after a clean activation, the plugin's front-end routes respond 200 on the very first request, without the user having to hit Settings > Permalinks. A route whose feature is switched off (for example Messages) shows a "page not available" page inside the community, not a blank page. With Plain permalinks active, every admin screen shows an error notice linking to Settings > Permalinks, because community URLs cannot work there.
**Why it matters:** rewrite-flush-on-activation regressions have broken customer sites before (1.3.5 incident).
**Acceptance:** any `/community/*` route returns HTTP 200 on the first request after activating, and `rewrite_rules` option contains the plugin's routes.

**Checks:**
- `a1.1` @admin #cli: on a clean install run `wp plugin activate jetonomy`, then `curl -s -o /dev/null -w '%{http_code}'` on /community/, /community/leaderboard/ and one /community/s/<space>/ as the very first requests -> every one answers 200 with no visit to Settings > Permalinks.
- `a1.2` @admin #cli: after activation run `wp option get rewrite_rules` -> the output contains the Jetonomy community patterns (community, s/, t/, u/ routes).
- `a1.3` @guest #ui: open /community/ immediately after activation -> the community home renders inside the theme with a header and no blank page or theme 404.
- `a1.4` @admin #ui +settings-off: switch Messages off, then open /community/messages/ -> a "page not available" page renders inside the community layout (site header and community nav present, full width, no sidebar), not a blank page.
- `a1.5` @guest #rest +settings-off: with Messages off, GET /wp-json/jetonomy/v1/messages -> a 404 error, never a 200 with data and never a PHP notice in the body.
- `a1.6` @admin #admin +settings-off: set Settings > Permalinks to Plain, then open Jetonomy > Dashboard, Spaces, Settings and Moderation -> every screen shows an error notice linking to Settings > Permalinks.
- `a1.7` @guest #ui !refuse: with Plain permalinks open /?pagename=community and a made-up community route such as /community/s/zzz-nope/ -> it shows the community "not found" page (HTTP 404) with a link back to the community, and no fatal (a made-up path outside the community routes gets the theme's own 404, which is correct).
- `a1.8` @admin #cli: after switching back to Post name permalinks run `wp jetonomy qa-actions` smoke and read wp-content/debug.log, with no other walker writing to the site (the fixture-leak detector compares global row counts) -> zero new Fatal, Warning, Notice or Deprecated lines from activation to the first routed request.
- `a1.9` @guest #ui +390: open /community/ at 390px right after activation -> no horizontal scroll and the header menu button opens.
- `a1.10` @admin #cli +old-data: deactivate then reactivate on the seeded community -> no duplicate tables or duplicate default categories, and /community/ still lists the seeded spaces with 200.
**Depth note:** one role (site administrator) activates; a visitor only proves the routes answer.

### A2 - Database schema is in place
**Covers:** includes/db/class-schema.php, includes/db/class-migrator.php
**Verified:** d1392e03
**What to verify:** all expected custom tables exist and the stored db-version option matches the constant.
**Acceptance:** `wp_jt_*` tables count matches the schema class; `jetonomy_db_version` option equals `JETONOMY_DB_VERSION`.

**Checks:**
- `a2.1` @admin #cli: run `wp db query "SHOW TABLES LIKE '%jt\_%'"` -> the count of wp_jt_* tables equals the count of tables defined in includes/db/class-schema.php, none missing.
- `a2.2` @admin #cli: run `wp option get jetonomy_db_version` and `wp eval 'echo JETONOMY_DB_VERSION;'` -> both print the same version.
- `a2.3` @admin #cli: for wp_jt_posts, wp_jt_replies, wp_jt_votes, wp_jt_subscriptions, wp_jt_flags run `SHOW INDEX` -> each has the keys the schema defines (space/status/published_at on posts, post_id on replies, user+target on votes).
- `a2.4` @admin #cli +old-data: set jetonomy_db_version one release lower and load any admin page -> the migrator brings it back to JETONOMY_DB_VERSION with no duplicate-table error in debug.log.
- `a2.5` @admin #cli !refuse: run the schema installer a second time -> it is idempotent; the table count and the seeded row counts (spaces, posts, replies) do not change.
- `a2.6` @admin #admin: open Jetonomy > Tools or the Dashboard health panel -> it reports the database as current and does not show an "update required" notice.
**Depth note:** infrastructure check with one role; refusal is covered by the idempotency check.

### A3 - Pro pairs cleanly (if Pro is also being installed)
**Covers:** includes/class-jetonomy.php, includes/integrations/class-companion-registry.php, includes/integrations/class-companion-installer.php, includes/integrations/views/companion-cards.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** activating Pro on top of free does not fatal, adds Pro-only tables, and both version constants agree.

---

**Checks:**
- `a3.1` @admin #cli: run `wp plugin activate jetonomy-pro` on top of Free -> exit 0, no fatal, no debug.log entry.
- `a3.2` @admin #cli: list wp_jt_* tables before and after Pro activation -> Pro-only tables appear and no Free table is altered or dropped.
- `a3.3` @admin #cli: read JETONOMY_VERSION and the Pro version constant -> both match the released pair (lockstep), and the Plugins screen shows no "requires Jetonomy x.y" notice.
- `a3.4` @admin #admin !refuse: deactivate Free while Pro stays active -> the admin shows a notice that Pro needs Jetonomy, and the site front end does not fatal.
- `a3.5` @admin #admin: open Jetonomy > Extensions after pairing -> each Pro extension card (Reactions, Polls, Custom Badges, Webhooks) shows Active or an Enable control, and the companion cards list installed or missing companions correctly.
- `a3.6` @guest #ui: open a seeded topic with Pro active -> the page renders with Pro features (reactions bar) and no layout break; the same page without Pro still renders.
- `a3.7` @admin #cli +old-data: activate Pro on the seeded community that already has 45-reply Feature Requests topic -> the topic still shows 45 replies and Pro tables backfill without error.
**Depth note:** installation journey with one actor; refusal is the Free-missing guard.

## B - Upgrade from previous version (skip if no prior version)

### B1 - Migration runs quietly, existing data still works
**Covers:** includes/db/class-migrator.php, includes/db/migrations/class-migration_2_0_2.php, includes/db/migrations/*.php, includes/class-content-backfill.php, includes/class-privacy-backfill.php, includes/class-space-backfill.php
**Verified:** 64212bb5
**What to verify:** bumping from the prior stable version to this build completes with no debug.log entries during the activation HTTP request; pre-existing posts, spaces, users, and settings still render and function; denormalized counters remain in sync. The 2.0.2 migration also leaves these true: private spaces stored as Open now take join requests (Private + Approval), community bios are copied into the member's WordPress Biographical Info, roles that can post keep uploading (they gain the Upload media capability), and space bans that were stored without a space are gone.

---

**Checks:**
- `b1.1` @admin #cli +old-data: restore the prior-stable database snapshot, update the plugin and request /wp-admin/ once -> the migration finishes in that request and debug.log gains no line.
- `b1.2` @admin #cli +old-data: compare row counts of spaces, posts, replies, votes, subscriptions before and after the migration -> identical.
- `b1.3` @admin #cli +old-data: compare each space's post_count/reply_count column to `SELECT COUNT(*)` of its published rows -> zero drift, and home/space/profile counts match.
- `b1.4` @guest #ui +old-data: after migration open /community/, a space and a topic -> all render with 200 and show their old titles, bodies and authors.
- `b1.5` @admin #cli +old-data: read a space that was stored as Open and Private -> it is now Private + Approval and its join form says "Request to join".
- `b1.6` @member #ui +old-data: as bob open a migrated private space he does not belong to -> he sees the request-to-join gate, not the topics.
- `b1.7` @admin #cli +old-data: for a member with a community bio and empty WP bio run `wp user meta get <id> description` -> the bio text was copied in; a member whose WP Biographical Info already held text keeps it unchanged.
- `b1.8` @admin #admin +old-data: open Settings > Permissions for the Member role -> "Upload media" is ticked for every role that could post before the migration.
- `b1.9` @admin #cli +old-data: count wp_jt_bans rows with a NULL/0 space_id -> zero; space bans (omar in Off-Topic Lounge) are untouched.
- `b1.10` @admin #cli: run the migration a second time (set db_version back one step) -> no data changes, no duplicate rows, no log lines.
- `b1.11` @member_b #ui +old-data: as alice open a pre-release topic with a vote she cast and a bookmark -> the vote is still highlighted and the bookmark still listed.
- `b1.12` @admin #rest +old-data: GET /jetonomy/v1/spaces and /posts?space_id=<Feature Requests> after migration -> 200 with the same total as before the migration (X-WP-Total header).

## C - Core customer flows

Persona ladder in this section: **Anonymous > Member > Moderator > Admin**. Pick a real test user from each persona (admin is user 1; create a subscriber-role user with a generated login if none exists). Cover both desktop 1280px and mobile 390px where relevant.

Each step below is a contract, not a script. When you verify it, exercise the UI as a user would AND confirm the server-side effect (DB row, REST response, email queued) to rule out a "looks right, didn't actually save" bug.

### C.anon.home
**Covers:** templates/views/home.php, templates/partials/post-card.php, templates/partials/sidebar.php, assets/css/header.css, assets/js/header.js, assets/js/header-font-scale.js, assets/js/dark-mode-mirror.js, includes/api/class-feed-controller.php, includes/functions.php, includes/helpers.php, includes/template-canvas.php, templates/partials/category-chips.php, templates/partials/managed-by-card.php
**Verified:** 64212bb5
**What to verify:** the community home page renders for a logged-out visitor, shows real content (posts or spaces), and offers a clear way in (login/register/join). Each category shows its first six spaces (sub-category spaces follow their parent's) with a "View all" link to the category page. With registration off, the welcome offers Log in only, never a sign-up button. Private spaces appear to logged-out visitors only as locked previews, and only when Settings > General > Access Control > "Show private spaces to logged-out visitors" is on (off by default); hidden spaces never appear.
**Why it matters:** first impression surface; a broken home means no acquisition.

**Checks:**
- `c-anon-home.1` @guest #ui: open /community/ -> the page shows real seeded posts or spaces and a clear way in (Log in, and Register when registration is open).
- `c-anon-home.2` @guest #ui: open the Local Chapters chip on the home page -> it expands to six spaces and a "View all 14 spaces" link to the category page.
- `c-anon-home.3` @guest #ui: click "View all" on a block that has 6 or fewer spaces -> either no link appears, or it opens the category page listing the same spaces; a block with more than six always has the link.
- `c-anon-home.4` @guest #ui: open the Local Chapters category page -> all 14 city spaces list, and the five empty ones show "0 topics" without an error.
- `c-anon-home.5` @admin #admin +settings-off: turn off Settings > General > Registration, then open /community/ as guest -> the welcome shows "Log in" only and no sign-up/Register button.
- `c-anon-home.6` @guest #ui +settings-off: with "Show private spaces to logged-out visitors" off (default), look for Partner Program and Customer Advisory Board -> neither appears anywhere on the home page.
- `c-anon-home.7` @guest #ui: switch "Show private spaces to logged-out visitors" on -> Partner Program and Customer Advisory Board appear as locked previews (lock/Private badge, no topics listed); clicking one shows the login prompt.
- `c-anon-home.8` @guest #ui !refuse: with that setting on, look for Moderators' Room (hidden) -> it never appears on home, in the sidebar or in popular lists, and /community/s/moderators-room/ returns the community not-found page.
- `c-anon-home.9` @guest #rest !refuse: GET /jetonomy/v1/feed and /spaces as guest -> neither response contains Moderators' Room or any topic from Customer Advisory Board.
- `c-anon-home.10` @guest #ui +long: view the home page's space cards and trending list -> long titles and the 288-character Help Desk description stay inside their cards with no horizontal overflow, at 1440 and 390.
- `c-anon-home.11` @guest #ui +rtl: with the site language set to Arabic (lane A), view the home page -> the layout mirrors (cards, chips, stats) and nothing overlaps.
- `c-anon-home.12` @guest #ui +390: open home at 390px -> category chips scroll sideways inside their strip, cards stack in one column, no horizontal page scroll.
- `c-anon-home.13` @guest #ui +dark: toggle dark mode on Reign -> cards, sidebar and chips use dark tokens with readable text and no white panels.
- `c-anon-home.14` @guest #ui +empty: on a fresh community with no posts (jt-qa seed cleaned) -> the home shows an empty state that invites a visitor to log in, not a blank column.
- `c-anon-home.15` @guest #ui: click a feed card title and the author name -> the topic page and the author's profile open with 200.
- `c-anon-home.16` @guest #ui +1440: look at any space card stats line -> 'Active N minutes ago' shows in full, not cut off with an ellipsis.
- `c-anon-home.19` @guest #ui +empty: after all topics are deleted, the home welcome pulse updates immediately (here it still said 2,316 topics / 5,000 members after jt_posts=0).

### C.anon.category
**Covers:** templates/views/category.php, includes/models/class-category.php, includes/api/class-categories-controller.php, templates/partials/breadcrumb.php, templates/partials/category-chips.php
**Verified:** 64212bb5
**What to verify:** a category page renders its child spaces, with working links into each space. Each space card shows a Private badge and how to join. A category the visitor cannot see (it holds only hidden or unreadable spaces) is not revealed through breadcrumbs or counts.

**Checks:**
- `c-anon-category.1` @guest #ui: open the Local Chapters category page -> all 14 city spaces list as cards with a working link into each.
- `c-anon-category.2` @guest #ui: look at a private space card (Partner Program) when private teasers are on -> it carries a "Private" badge and says how to join (request or invite).
- `c-anon-category.3` @guest #ui: look at an invite-only card (Customer Advisory Board) -> the card says it is invite only and offers no Join button.
- `c-anon-category.4` @guest #ui !refuse: open a category holding only hidden spaces (Moderators' Room's category) -> the same community not-found page as a missing slug, with no breadcrumb, name or space count revealing it.
- `c-anon-category.5` @guest #rest !refuse: GET /jetonomy/v1/categories/<hidden category id> and GET /categories?per_page=100 -> by id a 404 jetonomy_not_found; the list omits that category and its space_count.
- `c-anon-category.6` @guest #ui: look at the category's breadcrumb and space counts on a mixed category -> counts include only spaces the guest can see, not the hidden ones.
- `c-anon-category.7` @guest #ui +empty: open a category whose spaces are all empty (the last five city spaces) -> cards show 0 topics and no error.
- `c-anon-category.8` @guest #ui !refuse: open /community/category/does-not-exist/ -> the not-found page (HTTP 404) with a link back to the community.
- `c-anon-category.9` @guest #ui +archived: look at the 2025 Hackathon card -> it shows an Archived marker and opens its space read-only.
- `c-anon-category.10` @guest #ui +390: open the category at 390px -> cards stack one per row with no horizontal scroll.
- `c-anon-category.11` @guest #ui +long: view Help Desk's card with its 288-character description on Reign -> the description clamps or wraps inside the card.
- `c-anon-category.12` @guest #ui +dark: toggle dark mode -> badges (Private, Archived) stay legible.
- `c-anon-category.13` @member #ui +private: as bob, a member of Partner Program, open its category -> the card opens the space for him; as hana, not a member, the card offers the request to join.
- `c-anon-category.14` @member_c #ui +hidden: as maya, a moderator of Moderators' Room, open its category -> the hidden space is listed for her, while bob still gets the not-found page from c-anon-category.4.
- `c-anon-category.15` @member #ui +private: as a member of Partner Program, open its category -> the card carries a visible member marker (for example 'Member'), not the 'Request to join' wording.

### C.anon.space-listing
**Covers:** templates/views/space.php, templates/partials/space-header.php, templates/partials/pagination.php, includes/models/class-post.php, includes/class-feed.php, includes/models/class-read-status.php, includes/models/class-model.php, templates/partials/feed-card.php, templates/partials/managed-by-card.php, templates/partials/space-tabs.php, templates/views/space-roadmap.php, pro:includes/extensions/seo-pro/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a space page shows its topics in the configured order (Popular breaks vote ties by reply count), with pagination that actually advances, and a visible "new post" invitation for authed users. The same space header (cover, description, counts, Join / Follow / Leave) appears on the Discussions, Members and Roadmap tabs. Open spaces show Join; private spaces show "Request to join" (invite-only ones say so); archived and locked spaces show Follow instead of Join, and their empty state reads "No topics were posted here." with no New Topic link. An empty space invites a post ("No ideas yet. Suggest the first one…" plus the button) only to a viewer who can post; a guest, non-member, pending requester or read-only viewer sees the plain "No ideas yet." / "No questions yet." / "Nothing posted yet." / "No topics yet." with no button. The header "Request to join" on a public approval space asks for an optional message first. A private space shows a logged-out visitor the login prompt (and Register when registration is open), not its topics. A signed-in non-member of a private or invite-only space gets a gate that names the space and shows its description (a hidden space is never named). After requesting, the page reloads into "Your request to join <Space> is awaiting approval" with a Withdraw request button (REST DELETE /jetonomy/v1/spaces/{id}/join-requests/mine); withdrawing returns the Request to join form and removes the staff's notice of it. Closed topics carry a "Closed" badge next to Pinned and Private.

**Checks:**
- `c-anon-space-listing.1` @guest #ui: open a space with more topics than one page (QA Paging space) -> topics list in the configured order and Load More appends different topics until none remain.
- `c-anon-space-listing.2` @guest #ui: sort Popular on a space where two topics tie on votes -> the topic with more replies ranks first.
- `c-anon-space-listing.3` @guest #ui: look at Discussions, Members and Roadmap tabs of one space -> the same header (cover, description, counts, Join/Follow) appears on all three.
- `c-anon-space-listing.4` @guest #ui: on a public space look for the "new post" invitation -> a guest sees none; a signed-in member (bob) sees the New Topic button.
- `c-anon-space-listing.5` @member #ui: open Feature Requests as bob -> a visible New Topic button is shown.
- `c-anon-space-listing.6` @member #ui: open the public Open-join space as bob before joining -> the header shows Join; click it -> it becomes Leave and the member count rises by one.
- `c-anon-space-listing.7` @member #ui: open Insiders / Beta as carlos's peer (bob, not a member) -> the header shows "Request to join"; the invite-only Customer Advisory Board says it is invite only with no request form.
- `c-anon-space-listing.8` @member #ui: on a public approval space click header "Request to join" -> a form asks for an optional message first and submitting shows "Your request to join <Space> is awaiting approval" with a Withdraw request button.
- `c-anon-space-listing.9` @member #rest: click Withdraw request, then DELETE /jetonomy/v1/spaces/<id>/join-requests/mine -> UI returns the Request to join form, REST answers 200 once and 404 on a second call.
- `c-anon-space-listing.10` @moderator #ui: after the withdrawal open the staff notifications -> the staff notice of that join request is gone.
- `c-anon-space-listing.11` @member #ui +archived: open 2025 Hackathon as bob -> header shows Follow not Join, empty state reads "No topics were posted here." with no New Topic link.
- `c-anon-space-listing.12` @guest #ui +empty: open an empty city space as guest -> plain "No topics yet." with no button.
- `c-anon-space-listing.13` @member #ui +empty: open an empty city space as bob -> the empty state invites a first post with a button to compose.
- `c-anon-space-listing.14` @member #ui +empty +settings-off: open an empty ideas space as a viewer who cannot post (pending requester carlos) -> plain "No ideas yet." with no button; the Q&A and feed variants read "No questions yet." and "Nothing posted yet.".
- `c-anon-space-listing.15` @guest #ui !refuse: open Partner Program as guest -> the login prompt (and Register when open), no topic titles.
- `c-anon-space-listing.16` @member #ui !refuse: open Customer Advisory Board as bob -> a gate naming the space and showing its description, no topics.
- `c-anon-space-listing.17` @member #ui !refuse: open Moderators' Room by slug as bob -> the community not-found page, the space is never named.
- `c-anon-space-listing.18` @guest #ui: open Announcements -> "Scheduled maintenance on Saturday" carries a "Closed" badge next to any Pinned badge.
- `c-anon-space-listing.19` @guest #ui +long card:10415367216: open Help Desk (288-character description) on Reign at 1440 -> the description takes its own full-width row above the stats and the Join button, not a squeezed column beside them.
- `c-anon-space-listing.20` @guest #ui +long: open Help Desk at 1024 and 390 on Reign, BuddyX and Twenty Twenty-Five -> description wraps in its own row, stats and Join stay on one line or stack with no overlap.
- `c-anon-space-listing.21` @guest #ui +long: open General Discussion and read Grace's 159-character title and the unbroken 100-character URL in the listing -> neither widens the page past the viewport.
- `c-anon-space-listing.22` @guest #ui +rtl: open the space holding Omar's Arabic topic -> list rows mirror and the title is right-aligned.
- `c-anon-space-listing.23` @guest #ui +dark: toggle dark mode on Help Desk -> header, badges and tabs are readable.
- `c-anon-space-listing.24` @guest #rest: GET /jetonomy/v1/spaces/<Feature Requests id>/posts?sort=popular -> 200 with X-WP-Total matching the UI and the same order as the Popular pill.
- `c-anon-space-listing.25` @silenced #ui +silenced: open any space as finn -> the New Topic button is replaced with a clear notice that he is silenced, not an enabled button that fails.
- `c-anon-space-listing.26` @space_banned #ui +banned: open Off-Topic Lounge as omar -> he sees a banned notice and no Join or New Topic control.
- `c-anon-space-listing.27` @guest #rest !refuse: GET /spaces/<id>/posts?orderby=popular -> either sorted popular or 400, never a silent latest order.
- `c-anon-space-listing.28` @member #ui: open a public approval space as a non-member -> header 'Request to join' (needs a seeded public approval space).

### C.anon.single-post
**Covers:** templates/views/single-post.php, templates/partials/reply-card.php, includes/models/class-reply.php, includes/class-attachments.php, includes/class-author.php, includes/class-embeds.php, includes/functions.php, includes/helpers.php, includes/models/class-read-status.php, includes/models/class-model.php, templates/partials/avatar.php, templates/partials/breadcrumb.php, pro:includes/extensions/polls/*.php, pro:includes/extensions/reactions/*.php, pro:assets/js/polls-frontend.js, pro:assets/js/pro-view.js, pro:includes/extensions/seo-pro/*.php
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** a topic renders with its title, body, author, replies, and vote counts; auth-gated actions (reply, vote, subscribe) cleanly redirect a logged-out visitor to login rather than failing silently with 403 (the reply area offers "Log in to reply" as a button). A topic or reply edited more than a minute after posting shows "edited 2 hours ago" to everyone, with the exact time on hover. A nested reply shows "in reply to <name>" linking to its parent. A topic with no replies says "Be the first to reply!" only to a viewer who could reply; a guest, read-only viewer, or anyone on a closed topic (moderators excepted) sees "No replies yet.". Private replies show as a tombstone to people who may not read them. A topic in a private or hidden space returns the same 404 page as a missing topic, so a guessed URL confirms nothing.

**Checks:**
- `c-anon-single-post.1` @guest #ui: open "Dark mode for the community on phones" -> title, body, author, vote count and replies render.
- `c-anon-single-post.2` @guest #ui: click Reply, vote or Subscribe -> the browser goes to login (reply area offers a "Log in to reply" button) and returns to the topic after login; no silent 403.
- `c-anon-single-post.3` @guest #rest !refuse: POST /jetonomy/v1/posts/<id>/replies and /posts/<id>/vote without auth -> 401 jetonomy_login_required (or rest_forbidden), never 200.
- `c-anon-single-post.4` @guest #ui: view a reply edited 2 hours after posting -> it shows "edited 2 hours ago" and hovering shows the exact time.
- `c-anon-single-post.5` @guest #ui: view a reply edited 30 seconds after posting and an unedited reply -> neither shows an "edited" mark.
- `c-anon-single-post.6` @guest #ui: view a nested reply -> it shows "in reply to <name>" linking to the parent reply.
- `c-anon-single-post.7` @guest #ui +empty: open a topic with zero replies -> guest sees "No replies yet." and not "Be the first to reply!"; bob on the same topic sees "Be the first to reply!".
- `c-anon-single-post.8` @guest #ui +closed: open the closed Announcements topic "Scheduled maintenance on Saturday" -> "Closed" badge, no reply composer, and a reply count of zero reads "No replies yet.".
- `c-anon-single-post.9` @moderator #ui +closed +empty: open a closed topic with no replies as test_moderator -> the composer is still present and the empty line is "Be the first to reply!".
- `c-anon-single-post.10` @guest #ui: open a topic in Partner Program (private) -> the same 404 page and text as a missing topic ("Topic not found."), no body.
- `c-anon-single-post.11` @guest #ui !refuse: open a topic URL in Moderators' Room (hidden) -> the same 404 page as a missing topic id; page title and text do not name the space or the topic.
- `c-anon-single-post.12` @guest #rest !refuse card:10415367168: GET /jetonomy/v1/replies/<id of a reply in Moderators' Room> -> 404 jetonomy_not_found, body identical to GET /replies/9999999.
- `c-anon-single-post.13` @guest #rest !refuse card:10415367168: GET /jetonomy/v1/replies/<id of a reply in Customer Advisory Board> -> 404 jetonomy_not_found, not 403, same body as a missing id.
- `c-anon-single-post.14` @guest #rest !refuse card:10415367168: GET /jetonomy/v1/updates?scope=post&id=<hidden topic id>&since=<now> -> 404 jetonomy_not_found, the same as a missing id.
- `c-anon-single-post.15` @guest #rest card:10415367168: GET /jetonomy/v1/updates?scope=post&id=<public topic id>&since=<5 minutes ago> -> 200 with a reply id list (not 401), so the new-replies banner works for visitors.
- `c-anon-single-post.16` @guest #rest !refuse: GET /updates?scope=global and ?scope=space&id=<id> -> 401, they stay login-only.
- `c-anon-single-post.17` @guest #ui: view a private reply in a public topic -> a tombstone placeholder is shown, not its text; a member who may read it (author, moderator) sees the text.
- `c-anon-single-post.18` @guest #rest !refuse: GET /replies/<private reply id> as guest -> 404 jetonomy_not_found (a private reply is not readable by id), and the list endpoint returns an empty body with is_private true; the original body never appears.
- `c-anon-single-post.19` @guest #ui +long: open Grace's General Discussion topic (159-character title, list, quote, code block, unbroken 100-character URL) on Reign at 1440 and 390 -> the code block scrolls inside itself, the URL wraps or scrolls, the page has no horizontal scroll.
- `c-anon-single-post.20` @guest #ui +rtl: open Omar's Arabic topic -> body, replies and the vote column mirror correctly.
- `c-anon-single-post.21` @guest #ui +many: open Feature Requests' 45-reply topic -> 30 replies show, a page 2 control or Load More brings the rest, and the reply count reads 45.
- `c-anon-single-post.22` @guest #ui +deleted: view a reply whose author account was deleted -> the author shows as a neutral deleted-user name with a default avatar, not a PHP notice or blank.
- `c-anon-single-post.23` @guest #ui +long: view Grace (long name, no avatar) as author of a reply on 390px -> the name wraps, the initials avatar shows, and meta does not overlap the vote buttons.
- `c-anon-single-post.24` @guest #ui +dark: toggle dark mode on a topic with blockquote and code -> contrast stays readable.
- `c-anon-single-post.25` @guest #ui: use the breadcrumb on a topic -> category > space > topic links work.
- `c-anon-single-post.26` @guest #ui +dark: view a topic with a code block in dark mode -> the code block uses dark tokens, not a bright white panel.
- `c-anon-single-post.27` @guest #ui: open a topic whose body links to an unreachable site -> no console error and the link shows as a plain link.
- `c-anon-single-post.28` @guest #rest !refuse card:10430601579: a member replies to the topic and the reply is held for approval, then GET /updates?scope=post&id=<topic id>&since=<before the reply> -> the held reply id is not listed, the approved one is.

### C.anon.user-profile
**Covers:** templates/views/user-profile.php, includes/models/class-user-profile.php, includes/class-author.php, includes/class-avatar.php, templates/partials/avatar.php, pro:includes/extensions/custom-badges/*.php, pro:includes/extensions/custom-fields/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** any user's public profile is viewable by anonymous visitors and shows their posts/replies/badges without leaking private data (email, settings, drafts). The trust level shows by its name (owner-renamable under Settings > Permissions; level 5 is "Veteran" by default). Topics from private spaces and private replies are not listed to viewers who cannot read them (a private reply reads "Private reply"). Profile links use the member's public slug (user_nicename), so a member whose login is an email address opens normally, an old link with the login still opens, and the email never appears in the URL or the meta description. A logged-in viewer sees a Message button when Jetonomy messaging is active or another messenger supplies a link through the `jetonomy_member_message_url` filter; otherwise there is no button.

**Checks:**
- `c-anon-user-profile.1` @guest #ui: open bob's profile /community/u/<nicename>/ -> avatar, bio, posts, replies and badges show.
- `c-anon-user-profile.2` @guest #ui !refuse: view the profile source and page -> no email, settings link or drafts tab is visible to a guest.
- `c-anon-user-profile.3` @guest #rest !refuse: GET /jetonomy/v1/users/<id> -> the response contains no email or notification settings fields.
- `c-anon-user-profile.4` @guest #ui: view a member at trust level 5 -> the badge reads "Veteran" by default, and after the admin renames it in Settings > Permissions it shows the new name.
- `c-anon-user-profile.5` @guest #ui !refuse: view bob's Posts tab -> topics from Partner Program, Customer Advisory Board and Moderators' Room are not listed to a guest.
- `c-anon-user-profile.6` @guest #ui !refuse: view a profile with a private reply -> the reply row reads "Private reply" and not its text.
- `c-anon-user-profile.7` @guest #ui: open the profile of a member whose login is an email address by nicename -> it opens, and the URL and meta description contain no email address.
- `c-anon-user-profile.8` @guest #ui +old-data: open /community/u/<old login>/ for a member whose nicename differs -> it still opens (or redirects) with 200.
- `c-anon-user-profile.9` @guest #ui: open /community/u/no-such-person/ -> the not-found page with 404.
- `c-anon-user-profile.10` @member #ui: open alice's profile as bob with Jetonomy messaging active -> a Message button shows; with messaging off and no `jetonomy_member_message_url` filter the button is absent; as guest it is always absent.
- `c-anon-user-profile.11` @member #ui +settings-off: turn messaging off, open alice's profile as bob -> no Message button and no dead link.
- `c-anon-user-profile.12` @guest #ui +long: open Grace's profile (long name, no avatar) on Reign at 390 -> the full name wraps in the header and an initials avatar shows, no overflow.
- `c-anon-user-profile.13` @guest #ui +empty: open a member with no posts or bio -> each tab shows an empty state, not a blank area.
- `c-anon-user-profile.14` @guest #ui +blocked: open finn's profile (silenced, post scored -4) -> it opens normally; the -4 post shows its score with no notice naming the restriction.
- `c-anon-user-profile.15` @guest #ui +rtl: open Omar's profile -> bio and tab bar mirror.
- `c-anon-user-profile.16` @guest #ui +dark: toggle dark -> badges and stats readable.
- `c-anon-user-profile.17` @guest #rest !refuse: GET /users/<silenced id> -> no restriction field naming silence or ban for a guest (currently restriction:'silence' for finn).
- `c-anon-user-profile.18` @member #ui: on a site with WPMediaVerse messaging active (chat bubble shown) open a member's profile -> a Message button opens a conversation with them.
- `c-anon-user-profile.19` @member #ui: with WPMediaVerse active and Pro messaging dormant, open alice's profile as bob -> a Message button that opens a WPMediaVerse conversation shows, or the site owner is told messaging is unavailable (today neither: only Report and Block).

### C.anon.tag
**Covers:** templates/views/tag.php, includes/models/class-tag.php, includes/api/class-tags-controller.php
**Verified:** 64212bb5
**What to verify:** the tag page lists topics carrying that tag, or shows a clean empty state; no fatal on unknown tag slug. The topic count and the list include only topics the visitor can read, so a tag used only in private topics shows nothing to a guest. Popular tags in the sidebar count the same way.

**Checks:**
- `c-anon-tag.1` @guest #ui: open a tag used by public topics -> the topic list shows those topics and the count matches the list length.
- `c-anon-tag.2` @guest #ui !refuse: open a tag used only in Customer Advisory Board topics -> the count is 0 and the list is empty; no topic title or count from private topics leaks.
- `c-anon-tag.3` @guest #rest: GET /jetonomy/v1/tags -> every tag's count excludes topics in private and hidden spaces the guest cannot read.
- `c-anon-tag.4` @guest #ui: open /community/tag/no-such-tag/ -> the tag not-found page (404) or a clean empty state, never a fatal.
- `c-anon-tag.5` @guest #ui: compare Popular tags in the sidebar against tag pages -> each shown count equals what the tag page lists for the same viewer.
- `c-anon-tag.6` @member #ui: open the same tag as bob (member of Partner Program) -> bob sees his private-space topics counted, a guest does not.
- `c-anon-tag.7` @guest #ui: open /community/tag/no-such-tag/ -> the not-found page shows with a 404 status and no PHP error.
- `c-anon-tag.8` @guest #ui +many: open a tag with more than 30 topics -> pagination advances and shows no duplicates.
- `c-anon-tag.9` @guest #ui +390: open a tag at 390px -> cards stack and the page does not scroll sideways.
- `c-anon-tag.10` @guest #rest: GET /tags -> every tag count equals the topics its tag page lists for a guest.

### C.anon.leaderboard
**Covers:** templates/views/leaderboard.php, includes/api/class-leaderboards-controller.php, includes/trust/class-reputation.php, pro:includes/extensions/custom-badges/*.php
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** the leaderboard page renders a ranking, not an error; period selector (if present) switches the dataset.

**Checks:**
- `c-anon-leaderboard.1` @guest #ui: open /community/leaderboard/ -> a ranked list with rank number, avatar, name and score renders, not an error.
- `c-anon-leaderboard.2` @guest #ui: switch the period selector (week, month, all time) -> the list changes and the selected period is highlighted.
- `c-anon-leaderboard.3` @guest #rest: GET /jetonomy/v1/leaderboards?period=week then period=all -> 200 each with different ordering or scores, and invalid period returns 400.
- `c-anon-leaderboard.4` @guest #ui +empty: choose a period with no activity -> a plain "No activity" empty state and no empty table.
- `c-anon-leaderboard.5` @guest #ui +long: view Grace (long name, no avatar) in the list -> her name wraps or truncates inside the row with an initials avatar.
- `c-anon-leaderboard.6` @guest #ui +blocked: find finn (silenced, post -4) -> his rank uses his real reputation, and no private-space content is mentioned.
- `c-anon-leaderboard.7` @guest #ui !refuse: check the list against banned members -> a member banned site-wide does not appear.
- `c-anon-leaderboard.8` @guest #ui: view the leaderboard with Pro active -> rank, name and reputation show for each member; badges are not a leaderboard column (they show on the member's profile).
- `c-anon-leaderboard.9` @member #ui: open the leaderboard as bob -> his own row is highlighted or marked.
- `c-anon-leaderboard.10` @guest #ui +390: open the leaderboard at 390px in dark mode -> rows do not overflow and text is readable.
- `c-anon-leaderboard.11` @guest #rest !refuse: GET /leaderboards?period=bogus -> 400 invalid period (currently 200 all-time).
- `c-anon-leaderboard.12` @guest #ui !refuse: a site-banned member with reputation is absent from week, month and all time and from REST /leaderboards; a quiet week says "No activity this week." with a "See all time" link.

### C.anon.search
**Covers:** templates/views/search.php, includes/search/class-fulltext-search.php, includes/api/class-search-controller.php, includes/adapters/interface-search-adapter.php, includes/adapters/interface-search-query-adapter.php, templates/partials/empty-state.php
**Verified:** 64212bb5
**What to verify:** searching for a known-present term returns matching topics with relevance that actually looks relevant (share ≥ 1 meaningful token with the query). Searching for gibberish returns a clean empty state. Results never include private replies or content in spaces the searcher cannot read.
**Why it matters:** this is how customers find existing answers; a broken search drives support tickets.

**Checks:**
- `c-anon-search.1` @guest #ui: search "dark mode" -> topics with the exact phrase in the title rank above titles with only one of the words or a hyphenated form, and every result shares a meaningful token with the query.
- `c-anon-search.2` @guest #ui +empty: search "qzxvbnm" -> a clean empty state ("No results") with no raw error.
- `c-anon-search.3` @guest #ui !refuse: search a phrase only present in Customer Advisory Board and Moderators' Room topics -> no results from either.
- `c-anon-search.4` @guest #rest !refuse: GET /jetonomy/v1/search?q=<same phrase> -> 200 with an empty list, the total does not count private/hidden rows.
- `c-anon-search.5` @guest #ui !refuse: search for text that exists only in a private reply of a public topic -> the topic does not match through that reply.
- `c-anon-search.6` @member #ui: search the same Partner Program phrase as bob (a member) -> the Partner Program topic appears; as member_b who is not a member it does not.
- `c-anon-search.7` @guest #ui: press / or Cmd+K on a community page to open the Jetonomy search overlay (the theme's own header search is the WordPress site search and has no typeahead) and type -> suggestions drop down after the minimum characters, Enter opens the full results page.
- `c-anon-search.8` @guest #ui +long: search a word from Grace's 159-character title -> the result row wraps and highlights the term without breaking the layout.
- `c-anon-search.9` @guest #ui +rtl: search an Arabic word from Omar's topic -> the result is found and rendered right-to-left.
- `c-anon-search.10` @member #ui +old-data: create a new topic as bob, then search a distinctive word from it -> found; after OPTIMIZE TABLE on the full-text index if a new row is not found (stale InnoDB FTS) report the index, not the code.
- `c-anon-search.11` @guest #rest !refuse: GET /search?q=a (single character) and an empty q -> 400 with a clear validation message, not a 500.
- `c-anon-search.12` @guest #ui +deleted: search the title of a deleted topic -> it is not in the results.
- `c-anon-search.13` @guest #ui +390: search at 390px -> the form and result cards fit the viewport.
- `c-anon-search.14` @guest #ui: type 2+ characters in the header search overlay and press Enter -> the full results page for that query opens (today nothing happens).
- `c-anon-search.15` @guest #ui: the header overlay suggestions and the full results page rank 'Dark mode for the community on phones' the same way, exact title match first.
- `c-anon-search.16` @member_b #ui: replace 'member_b who is not a member' with a user who really is not a member of Partner Program (maya, after the reseed alice is a member).

### C.anon.invite-landing
**Covers:** templates/views/invite.php, includes/models/class-invite-link.php, includes/api/class-spaces-controller.php
**Verified:** 64212bb5
**What to verify:** an invite link lands on a page that names the space being offered and does NOT join anyone by itself. Logged out: it offers "Create free account" (when registration is open) and "Log in to accept invite", and returns to the invite after login. Logged in: it shows a "Join" button; the member is added only when they click it (a POST with a nonce), then land in the space. Opening the link, a link preview or a prefetch never adds anyone. Invalid codes, expired links and links at their usage limit get a clean error, not a fatal. An archived or locked space, or a member banned from the space, cannot be joined by invite.

**Checks:**
- `c-anon-invite-landing.1` @guest #ui: open a valid invite link -> the page names the space offered, shows "Create free account" (registration open) and "Log in to accept invite"; nobody is added.
- `c-anon-invite-landing.2` @guest #ui: click "Log in to accept invite", log in as bob -> returns to the invite page, still not joined.
- `c-anon-invite-landing.3` @member #ui: as bob click Join on the invite -> a POST with nonce adds him and he lands in the space; the member count rises by one.
- `c-anon-invite-landing.4` @member #ui !refuse: open the same invite as already-member bob -> it shows he already belongs and a link to the space, with no duplicate membership row.
- `c-anon-invite-landing.5` @member #rest !refuse: GET the invite URL twice and send a HEAD/prefetch request -> no membership row is created (check wp_jt_space_members count unchanged).
- `c-anon-invite-landing.6` @member #rest !refuse: POST to the join endpoint without the nonce -> rejected (403 or a nonce error), the member is not added.
- `c-anon-invite-landing.7` @admin #admin +settings-off: turn registration off and open the invite as guest -> only "Log in to accept invite" shows, no Create free account.
- `c-anon-invite-landing.8` @guest #ui !refuse: open /invite/does-not-exist/ -> a clean "invalid invite" error page, no fatal.
- `c-anon-invite-landing.9` @guest #ui !refuse +old-data: open an expired invite -> a clean "expired" error, no Join button.
- `c-anon-invite-landing.10` @member #ui !refuse: open an invite that reached its usage limit -> a clean error, no Join button.
- `c-anon-invite-landing.11` @member #ui !refuse +archived: open an invite to 2025 Hackathon (archived) -> the page explains it cannot be joined, no Join button.
- `c-anon-invite-landing.12` @space_banned #ui !refuse +banned: open an invite to Off-Topic Lounge as omar -> the page refuses with a banned message; a forced POST also fails.
- `c-anon-invite-landing.13` @member_b #ui +concurrent: two members open a one-use invite and both click Join -> exactly one is added; the other sees the limit error.
- `c-anon-invite-landing.14` @guest #ui +390: open the invite on a phone in dark mode -> buttons are full width and readable.
- `c-anon-invite-landing.15` @guest #ui !refuse: open an invite for Moderators' Room (hidden) while logged out -> it names the space only to the invite holder, never exposes members or topics.
- `c-anon-invite-landing.16` @guest #ui !refuse: open /invite/<well-formed but unknown token>/ -> an 'Invalid invite link.' page with HTTP 404 (today it is HTTP 200, a soft 404).
- `c-anon-invite-landing.17` @space_banned #ui !refuse +banned: omar, who still has a member row in Off-Topic Lounge, opens its invite -> the page says he is banned instead of silently redirecting into the space.
- `c-anon-invite-landing.18` @member #ui: as an existing member open an invite to a space he is in -> decide the contract: either a visible 'You are already a member' page with a link (what check 4 says) or the silent redirect (what the code does); fix whichever is wrong.

### C.member.post-create
**Covers:** templates/views/new-post.php, templates/partials/composer.php, templates/partials/compose-fields.php, assets/js/composer.js, includes/api/class-posts-controller.php, includes/api/class-media-controller.php, includes/api/class-rest-auth.php, assets/js/jetonomy-rest.js, includes/class-attachments.php, includes/class-media-library.php, includes/models/class-attachment.php, includes/models/class-model.php, includes/permissions/class-rate-limiter.php, templates/partials/compose-topic-embed.php, pro:includes/extensions/polls/*.php, pro:assets/js/polls-frontend.js, pro:includes/extensions/advanced-moderation/*.php, pro:includes/extensions/ai/*.php, pro:includes/extensions/webhooks/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a logged-in member can compose a new post with title + content, pick a visibility / schedule / tags, submit, and land on the new topic. The post is persisted, appears in the space listing, and fires any expected notifications (subscribers notified, activity log updated). The image button, paste and drop of images appear and work only for a member who holds the Upload media permission; otherwise they are hidden and a paste or drop shows "You do not have permission to upload images". An archived or locked space refuses new topics with a message, and its /s/<slug>/new/ page shows that notice with "Back to <Space>" instead of a form.

**Checks:**
- `c-member-post-create.1` @member #ui: as bob open Help Desk > New Topic, write the title "How do I reset my membership password?" with a two-paragraph body, and submit -> he lands on the new topic and it is listed first in Help Desk.
- `c-member-post-create.2` @member #rest: POST /jetonomy/v1/spaces/{id}/posts with title and content -> 201 and the same topic appears in GET /spaces/{id}/posts.
- `c-member-post-create.3` @member #ui +empty !refuse: submit with an empty title, then with an empty body -> an inline error names the missing field and nothing is created.
- `c-member-post-create.4` @member #ui +long: enter a 300-character title -> either the counter blocks it with a visible limit or it saves and wraps without breaking the card.
- `c-member-post-create.5` @member #ui: pick tags, a visibility option and a schedule -> they show on the saved topic.
- `c-member-post-create.6` @member_b #email: alice follows Help Desk; after bob posts there -> alice gets one notification and one Mailpit email with the owner's saved New topic subject, not two.
- `c-member-post-create.7` @member #ui: check the space listing and the space and profile post counts after posting -> each rose by exactly one (the home has no recent-topics feed; its community pulse is a one-hour cache for guests and is not checked here).
- `c-member-post-create.8` @member #ui: click the image button, paste and drop an image as a member who holds Upload media -> the image uploads and shows inline.
- `c-member-post-create.9` @member #ui !refuse +settings-off: remove Upload media from the Member role, reopen the composer -> the image button is hidden; pasting an image shows "You do not have permission to upload images".
- `c-member-post-create.10` @member #rest !refuse +settings-off: POST /media without Upload media -> 403 and nothing stored.
- `c-member-post-create.11` @member #ui !refuse +archived: open /s/2025-hackathon/new/ -> a notice with "Back to 2025 Hackathon" and no form; a locked space behaves the same.
- `c-member-post-create.12` @member #rest !refuse +archived: POST /spaces/{2025 Hackathon id}/posts -> 403 jetonomy_space_restricted with the archived/locked message, nothing stored; the same through `wp jetonomy post create`.
- `c-member-post-create.13` @silenced #ui !refuse +silenced: as finn open the composer and submit -> a message "Your account is currently silenced and cannot post." and nothing created.
- `c-member-post-create.14` @silenced #rest !refuse +silenced: POST /posts as finn -> 403 jetonomy_user_silenced.
- `c-member-post-create.15` @space_banned #ui !refuse +banned: as omar post in Off-Topic Lounge -> refused with a ban message; in another space he can still post.
- `c-member-post-create.16` @member #ui !refuse: as bob post in Customer Advisory Board -> the compose page shows the gate, never a form; POST /spaces/{id}/posts returns 403 jetonomy_forbidden (private spaces are listed publicly, so the refusal reveals nothing) and nothing is stored.
- `c-member-post-create.17` @member #ui +limit !refuse: post until the new-member daily limit is reached -> the message reads "New members can post N topics a day. You can post again in about X. Please try again later." and REST returns 429.
- `c-member-post-create.18` @member #ui +offline: take the network offline and submit -> the composer keeps the text, shows an error and a retry works; no duplicate topic after reconnect.
- `c-member-post-create.19` @member #ui +concurrent: double-click Submit -> exactly one topic is created.
- `c-member-post-create.20` @member #ui +rtl: write an Arabic title and body -> the topic renders right-to-left.
- `c-member-post-create.21` @member #ui +settings-off: set the space to require approval and post -> the member sees "awaiting approval", the topic is not public, a moderator sees it queued, and subscribers are notified only after approval, once.
- `c-member-post-create.22` @guest #rest !refuse: POST /posts logged out -> 401.
- `c-member-post-create.23` @member #ui +390: compose at 390px -> toolbar wraps, Submit reachable without zoom.
- `c-member-post-create.24` @member #ui +dark: compose in dark mode -> editor surface and toolbar icons readable.
- `c-member-post-create.25` @member #ui +long: type a 300-character title -> a visible character counter or limit message appears before the field stops accepting input (today it silently cuts at 255).
- `c-member-post-create.26` @member #ui +offline: submit offline -> exactly one 'Failed to save.' message (today it shows twice).
- `c-member-post-create.27` @member #ui +rtl: post an Arabic topic on an LTR site -> the title and body are right-aligned via dir=auto on user content.
- `c-member-post-create.28` @member_b #ui: a space has no recent-topics feed on the community home page -> decide whether the check 'home feed' count means the directory counts.
- `c-member-post-create.29` @member #ui +390: on New Topic, and on Post Reply in a long thread with replies below the composer, run `button.scrollIntoView({block:'end'})` on Post Topic / Post Reply and also Tab to it -> the button is fully above the bottom tab bar and `document.elementFromPoint` at its centre and bottom edge is the button, not a tab; at 1440 the page has no extra bottom gap.

### C.member.post-edit
**Covers:** templates/views/single-post.php, templates/partials/edited-mark.php, includes/api/class-posts-controller.php, includes/models/class-post.php, includes/models/class-revision.php
**Verified:** 64212bb5
**What to verify:** a post author can edit their own post (this needs the Edit own posts permission, which Member and Participate grants include); changes persist and are reflected everywhere the post is referenced (home feed, space list, search). The "edited ... ago" marker appears when the edit came more than a minute after posting. The topic menu offers only the actions the viewer's permissions allow.

**Checks:**
- `c-member-post-edit.1` @member #ui: bob edits the body of his own topic -> saved, and the new text shows on the topic, space listing, home feed and search.
- `c-member-post-edit.2` @member #rest: PATCH /posts/<id> with new title -> 200 and GET shows the title.
- `c-member-post-edit.3` @member #ui: edit more than a minute after posting -> "edited ... ago" appears with exact time on hover.
- `c-member-post-edit.4` @member #ui: edit within a minute of posting, and view an unedited topic -> no "edited" mark.
- `c-member-post-edit.5` @member_b #ui !refuse: as alice open bob's topic menu -> no Edit item; opening /edit URL shows a refusal.
- `c-member-post-edit.6` @member_b #rest !refuse: PATCH /posts/<bob's id> as alice -> 403 jetonomy_forbidden.
- `c-member-post-edit.7` @moderator #ui: as test_moderator open a Bug Reports topic menu -> Edit, Close, Pin, Move and Merge are offered; in a space he does not moderate only the actions his role grants show.
- `c-member-post-edit.8` @member #ui !refuse +settings-off: remove "Edit own posts" from Member -> bob's Edit item disappears and PATCH returns 403.
- `c-member-post-edit.9` @member #ui +closed: bob edits his own topic after it is closed -> refused with the closed message or allowed per contract, and the menu matches what REST does.
- `c-member-post-edit.10` @silenced #ui !refuse +silenced: finn tries to edit his -4 post -> refused with the silenced notice.
- `c-member-post-edit.11` @member #ui +long: edit Grace-style long content (list, quote, code block) -> formatting survives a save and reload.
- `c-member-post-edit.12` @member #ui +concurrent: edit the same topic in two tabs and save both -> the second save does not silently discard the first without notice, and a revision is kept.
- `c-member-post-edit.13` @member #ui +offline: save while offline -> the editor stays open with an error and the text is kept.
- `c-member-post-edit.14` @admin #admin: open the revision history for the edited topic -> the old version is listed.
- `c-member-post-edit.15` @member #ui +concurrent: after tab two saves over tab one, the member is told the topic changed meanwhile (conflict notice) instead of a silent overwrite.
- `c-member-post-edit.16` @moderator #ui: a moderator with a Member WP role who moderates only Bug Reports opens a Show & Tell topic menu -> only the actions his role grants show (the test_moderator account is a WP editor so it sees everything).
- `c-member-post-edit.17` @member_b #ui !refuse: open the topic URL with /edit/ appended -> decide the contract: a refusal page or the plain 404 it returns today.

### C.member.post-delete
**Covers:** templates/views/single-post.php, includes/api/class-posts-controller.php, includes/models/class-post.php, assets/js/jetonomy-modals.js
**Verified:** 64212bb5
**What to verify:** a post author can delete their own post (this needs the Delete own posts permission); it disappears from all listings and its single-post URL no longer serves the body (either 404 or "trashed" state per plugin contract); replies are handled consistently (cascaded or orphaned per contract).

**Checks:**
- `c-member-post-delete.1` @member #ui: bob deletes his own topic through the menu and confirms the dialog -> he returns to the space and the topic is gone from the space, home, search and tag listings.
- `c-member-post-delete.2` @guest #ui: open the deleted topic's URL -> the not-found page (404) or a trashed state without the body.
- `c-member-post-delete.3` @member #rest: DELETE /posts/<id> -> 200 and GET /posts/<id> returns 404 jetonomy_not_found for guests and other members (the author still reads their own trashed topic, status trash, so it can be restored).
- `c-member-post-delete.4` @member #ui: cancel the confirm dialog -> nothing is deleted.
- `c-member-post-delete.5` @member_b #ui !refuse: alice opens bob's topic menu -> no Delete item.
- `c-member-post-delete.6` @member_b #rest !refuse: DELETE /posts/<bob's id> as alice -> 403 jetonomy_forbidden and the topic remains.
- `c-member-post-delete.7` @member #ui !refuse +settings-off: remove "Delete own posts" from Member -> the Delete item disappears and DELETE returns 403.
- `c-member-post-delete.8` @member #ui: delete a topic with 3 replies -> the replies follow the contract (cascaded or orphaned) and the space reply count and the replies of those authors' profiles drop accordingly.
- `c-member-post-delete.9` @member #ui: check space post count, home count and profile post count after deleting -> each fell by one.
- `c-member-post-delete.10` @member #ui: delete a topic that carries an accepted answer -> reputation already earned is kept (documented: deleting a topic does not change reputation; only Spam applies a penalty), and the notifications about that topic no longer show in the bell or GET /notifications.
- `c-member-post-delete.11` @member_b #ui +deleted: alice had bookmarked or subscribed to the deleted topic -> her Bookmarks list no longer shows a broken row and her bell shows no dead link.
- `c-member-post-delete.12` @moderator #ui: test_moderator removes bob's topic -> it moves to Trash with a Restore option for staff and bob is not told the wrong reason.
- `c-member-post-delete.13` @member #ui +concurrent: delete the same topic from two tabs -> the second shows "already deleted" or not found, not a PHP error.
- `c-member-post-delete.14` @member #ui +offline: delete while offline -> the topic stays in the list with an error toast.
- `c-member-post-delete.15` @silenced #ui !refuse +silenced: finn deletes his -4 post -> refused with the silenced notice.
- `c-member-post-delete.16` @member_b #ui +deleted: alice followed a topic that is then deleted -> My Subscriptions marks it 'No longer available' with an Unfollow button (a trashed topic can be restored, so the follow is kept and stays manageable), and alice gets no further notifications from it.
- `c-member-post-delete.17` @member #ui: delete a topic that has replies -> its replies are not left as published rows under a trashed parent, or the contract says so and the counters follow.
- `c-member-post-delete.18` @moderator #ui: restore the deleted topic from Moderation > Trash -> the topic, its replies, the accepted answer and the counts come back.

### C.member.reply-create
**Covers:** templates/views/single-post.php, includes/api/class-replies-controller.php, includes/models/class-reply.php, includes/notifications/class-notifier.php, includes/permissions/class-rate-limiter.php, pro:includes/extensions/reply-by-email/*.php, pro:includes/extensions/webhooks/*.php, pro:assets/js/pro-view.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a member can post a reply on any topic they can see; the reply appears inline, increments the topic's reply count, and triggers a notification to the OP (unless OP unsubscribed). A reply cannot be created under a parent that is missing, trashed, spam or in another topic. A private reply is not sent to thread subscribers.

**Checks:**
- `c-member-reply-create.1` @member #ui: bob replies "Agreed - on a phone the sidebar covers half the screen, so a dark theme would also help battery life." on the Feature Requests topic -> the reply appears inline at the end, the topic's reply count rises from 45 to 46.
- `c-member-reply-create.2` @member #rest: POST /jetonomy/v1/posts/<id>/replies with content -> 201 and GET shows the reply under the topic.
- `c-member-reply-create.3` @member_b #email: alice is the OP of the topic; bob replies -> alice gets one bell notification and one Mailpit email, unless she has unsubscribed from the topic, in which case neither.
- `c-member-reply-create.4` @member #ui +empty !refuse: submit an empty reply -> an inline error and nothing is posted.
- `c-member-reply-create.5` @member #rest !refuse: POST a reply with parent_id of a missing reply, a trashed reply, a spam reply and a reply of another topic -> each returns a validation error and nothing is created.
- `c-member-reply-create.6` @member #ui !refuse card:10415335351 +closed: as bob open "Scheduled maintenance on Saturday" in Announcements -> no Reply or Quote buttons and no composer; the empty line reads "No replies yet." if there are none.
- `c-member-reply-create.7` @member #rest !refuse card:10415335351 +closed: POST a reply to the closed topic as bob -> 403 with "This topic is closed and cannot receive new replies."
- `c-member-reply-create.8` @moderator #ui card:10415335351 +closed: as test_moderator open the closed topic -> the composer is present and a reply posts.
- `c-member-reply-create.9` @member_b #ui: subscribers other than the OP, who follow the thread -> get one notification each for a public reply.
- `c-member-reply-create.10` @member_b #email: a private reply by bob in a public topic -> thread subscribers receive no notification or email, only the OP and a mentioned reader who may read it.
- `c-member-reply-create.11` @member #ui !refuse: bob opens a Customer Advisory Board topic -> no composer; POST /posts/<id> (the reply route) returns 404 jetonomy_not_found (the topic is hidden from a non-member, the space itself is listed) and nothing is stored.
- `c-member-reply-create.12` @silenced #ui !refuse +silenced: finn types a reply -> "Your account is currently silenced and cannot post." and nothing posts; REST returns 403 jetonomy_user_silenced.
- `c-member-reply-create.13` @space_banned #ui !refuse +banned: omar replies in an Off-Topic Lounge topic -> refused with the ban message; elsewhere he can reply.
- `c-member-reply-create.14` @member #ui +limit !refuse: reply repeatedly past the new-member daily reply limit -> "New members can post N replies a day. You can reply again in about X." and REST returns 429.
- `c-member-reply-create.15` @member #ui +concurrent: submit twice quickly -> one reply created.
- `c-member-reply-create.16` @member #ui +offline: submit offline -> the draft text is kept with an error and no duplicate after retry.
- `c-member-reply-create.17` @member #ui +rtl: reply in Arabic on Omar's topic -> reply renders right-to-left.
- `c-member-reply-create.18` @member #ui +long: reply with a 2000-character body and an unbroken URL -> it wraps without overflow.
- `c-member-reply-create.19` @member #ui +settings-off: in a space requiring approval, reply -> "awaiting moderation" is shown and the reply is invisible to others until approved, with announce-once on approval.
- `c-member-reply-create.20` @member #ui: check topic reply count, space reply count, profile reply count after replying -> each rose by one.
- `c-member-reply-create.21` @member #ui +390: reply at 390px with the keyboard open -> the Submit button stays reachable.
- `c-member-reply-create.22` @member #ui +long: reply on a topic with more replies than one page -> the new reply is visible straight away (scrolled to or loaded) instead of only the count changing.
- `c-member-reply-create.23` @space_banned #ui !refuse +banned: omar opens a topic in Off-Topic Lounge -> a banned message, not 'Topic not found'; POST /replies returns jetonomy_user_space_banned.
- `c-member-reply-create.24` @member #ui +empty: submit an empty reply -> an inline 'Write your reply before posting.' under the editor, announced to screen readers, nothing posted; POST /replies with empty content returns 400 'Reply content is required.'.

### C.member.reply-quote
**Covers:** templates/partials/reply-card.php, assets/js/view.js, assets/js/composer.js
**Verified:** 64212bb5
**What to verify:** a member can quote an existing reply and the quoted content is visually preserved as a blockquote in the new reply. Choosing Reply on a reply shows "Replying to <name>" with the first line of that reply quoted and linking back to it.

**Checks:**
- `c-member-reply-quote.1` @member #ui: click Quote on alice's reply -> the composer is prefilled with a blockquote naming alice, and posting it shows the quote visually preserved as a blockquote.
- `c-member-reply-quote.2` @member #ui: click Reply on a reply -> the composer shows "Replying to <name>" with the first line of that reply, and the posted reply shows "in reply to <name>" linking back.
- `c-member-reply-quote.3` @member #ui: cancel the "Replying to" chip -> the composer returns to a plain reply and the parent is not set.
- `c-member-reply-quote.4` @member #ui +long: quote a reply with a list, code block and 100-character URL -> the quote keeps structure and shows without overflow.
- `c-member-reply-quote.5` @member #ui !refuse +closed: on the closed Announcements topic -> no Quote or Reply buttons for bob.
- `c-member-reply-quote.6` @guest #ui !refuse: as guest -> Quote and Reply buttons send to login or are hidden.
- `c-member-reply-quote.7` @member #ui +deleted: quote a reply by a deleted account -> attribution reads a neutral deleted-user name, not blank.
- `c-member-reply-quote.8` @member #ui: quote a private reply of bob's as bob; then view as member_b -> member_b sees the tombstone, never the quoted text.
- `c-member-reply-quote.9` @member #ui +rtl: quote Omar's Arabic reply -> blockquote and attribution render right-to-left.
- `c-member-reply-quote.10` @member #ui +dark: quote in dark mode -> the blockquote bar and text contrast stay readable.
- `c-member-reply-quote.11` @member #ui: click Quote on a reply -> the saved reply is posted as a plain top-level reply with a quote, or as a threaded reply; decide which (today parent_id is set, same as Reply).
- `c-member-reply-quote.12` @member #ui +dark: the quote bar has enough contrast in dark mode (today 12% white).
- `c-member-reply-quote.13` @guest #ui +deleted: a reply from a deleted account does not carry the OP badge.
- `c-member-reply-quote.14` @member #ui !refuse: a private reply the viewer may read shows no Quote button.

### C.member.mention
**Covers:** includes/class-mentions.php, includes/notifications/class-notifier.php, includes/api/class-posts-controller.php, includes/api/class-replies-controller.php
**Verified:** 64212bb5
**What to verify:** using `@username` in a post or reply produces a notification for that user AND renders as a link to their profile in the saved content. Mentioning someone in a private reply notifies them only if they may read it. The mention email uses the owner's saved Mention subject and body (Settings > Emails), like every other email.

**Checks:**
- `c-member-mention.1` @member #ui: bob writes "@alice thanks, that fixed it for me" in a reply -> the saved reply shows @alice as a link to her profile.
- `c-member-mention.2` @member_b #ui: alice opens the bell -> exactly one mention notification linking to the reply.
- `c-member-mention.3` @member_b #email: read alice's Mailpit email -> subject is "[forums] You were mentioned by Bob Martinez" (actor name present) and the body comes from the owner's saved Mention template or the Settings > Emails default.
- `c-member-mention.4` @member_b #email card:10412885103: bob replies mentioning @alice who is the topic author (OP) -> alice gets one notification and one email, not two.
- `c-member-mention.5` @member_b #email card:10412885103: bob replies mentioning @maya, who follows the thread -> maya gets one notification and one email, not a reply notice plus a mention notice.
- `c-member-mention.6` @member #ui: mention the same person twice in one reply -> one link each and still one notification.
- `c-member-mention.7` @member #ui: write a mention in a new post body -> the post saves with the link and the mentioned user is notified once.
- `c-member-mention.8` @member #rest: POST a reply with "@alice" -> 201, content keeps the raw @alice (no content_html field), the page render links it, and GET /notifications as alice shows it.
- `c-member-mention.9` @member #ui !refuse: bob writes @nobodyhere (no such user) -> the text stays plain with no link and no error.
- `c-member-mention.10` @member_b #email !refuse: bob mentions @alice in a private reply she may not read -> no notification and no email; if she may read it, she gets exactly one.
- `c-member-mention.11` @member #ui !refuse: bob mentions @alice inside Customer Advisory Board where alice is not a member (bob cannot post there) -> and in Moderators' Room, a mention of a non-member produces no notification.
- `c-member-mention.12` @silenced #ui !refuse +silenced: finn writes @bob -> the reply is refused; no notification is created.
- `c-member-mention.13` @member #ui +settings-off: alice turns off mention notifications in her preferences -> bob's mention creates no bell and no email for her.
- `c-member-mention.14` @member #ui +long: mention "Grace Oluwaseun Adeyemi-Whitfield" from the autocomplete -> the list shows her full name and the link resolves to her profile.
- `c-member-mention.15` @member #ui: mention yourself -> no notification to bob.
- `c-member-mention.16` @member #ui +rtl: mention @omar inside Arabic text -> the link renders correctly inside the right-to-left sentence.
- `c-member-mention.17` @member #ui +deleted: edit the reply to remove the mention -> the old notification stays/goes per contract and no second notification is sent when other text is edited.
- `c-member-mention.18` @member #ui: choose a member from the @ autocomplete with Enter, then keep typing -> a space follows the handle ("@grace could ...") and the saved reply links to her profile.
- `c-member-mention.19` @member #ui +long: the autocomplete row for a member without an avatar shows initials, not a broken image, and the long name is not cut with an ellipsis.

### C.member.vote
**Covers:** includes/api/class-votes-controller.php, includes/models/class-vote.php, assets/js/view.js, templates/views/single-post.php, templates/partials/reply-card.php, assets/js/lib/optimistic.js, assets/js/jetonomy-rest.js, includes/api/class-rest-auth.php, includes/permissions/class-rate-limiter.php, templates/partials/feed-card.php, pro:includes/extensions/reactions/*.php, pro:assets/js/pro-view.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a member can vote up/down on posts and replies, the score updates on screen, the vote persists across page reloads, and re-clicking the same vote toggles it off. The active vote button reads "Remove your upvote" / "Remove your downvote", and every vote on a topic or reply shows one toast, "Vote added" or "Vote removed" (never two). A member at the daily vote limit can still take a vote back. A logged-in member who may not vote sees the score as a disabled control that explains why on hover or click, not a button that does nothing. Taking back a vote removes the author's grouped "X people voted" notification once no votes remain; it never shows "0 people voted".

**Checks:**
- `c-member-vote.1` @member #ui: bob upvotes alice's reply -> the score rises by 1 on screen, one toast "Vote added" shows, and the button reads "Remove your upvote".
- `c-member-vote.2` @member #ui: reload the page -> the vote is still active and the score unchanged.
- `c-member-vote.3` @member #ui: click the active upvote again -> score returns, one toast "Vote removed", button label back to "Vote up".
- `c-member-vote.4` @member #ui: downvote a reply -> the button reads "Remove your downvote"; switching from up to down moves the score by 2.
- `c-member-vote.5` @member #rest: POST /posts/<id>/vote with value 1, then -1, then -1 again (which takes the vote back) -> each response carries the new score, and value 0 returns 400 rest_invalid_param.
- `c-member-vote.6` @member #ui: vote on a topic (not a reply) and on a feed card -> the same toast appears once and the score matches on the topic page, space list and feed.
- `c-member-vote.7` @member #ui !refuse: bob looks at his own reply -> the vote control is disabled with the reason "You cannot vote on your own content."; no vote is recorded and the score is unchanged.
- `c-member-vote.8` @member #rest !refuse: POST /posts/{id}/vote with value 1 and with value -1 on his own content -> each 400 jetonomy_self_vote "You cannot vote on your own content."; taking back an older vote on own content is still allowed.
- `c-member-vote.9` @member #ui +limit !refuse: reach the daily vote limit -> the next new vote shows "You can vote again in about X." and REST returns 429.
- `c-member-vote.10` @member #ui +limit: at the daily limit click an active vote to take it back -> the vote is removed with "Vote removed" even though he is at the cap.
- `c-member-vote.11` @member #ui !refuse: sign in as a member who lacks the vote permission -> the score shows as a disabled control that explains why on hover or click, not a dead button.
- `c-member-vote.12` @guest #ui !refuse: click vote as guest -> sent to login; REST returns 401.
- `c-member-vote.13` @member_b #ui: alice receives votes from bob and maya -> her bell shows one grouped "2 people voted" notification.
- `c-member-vote.14` @member #ui: bob takes his vote back until none remain -> the grouped notification is removed and never shows "0 people voted".
- `c-member-vote.15` @silenced #ui !refuse +silenced: finn votes -> refused with the silenced message.
- `c-member-vote.16` @member #ui +closed: vote on a reply in the closed Announcements topic -> follows the contract and the UI matches REST.
- `c-member-vote.17` @member #ui +offline: vote while offline -> the optimistic score rolls back and an error toast says it failed.
- `c-member-vote.18` @member #ui +concurrent: click Upvote ten times fast -> final state is a single consistent vote, one toast per real change, score equals server.
- `c-member-vote.19` @member_b #ui +concurrent: two tabs of bob show different states -> the second tab's click leaves the server score correct.
- `c-member-vote.20` @member #ui: vote in Partner Program topic bob can read, and open Customer Advisory Board reply id via REST -> the latter returns 404 jetonomy_not_found.
- `c-member-vote.21` @member #ui: check finn's -4 post -> the negative score is shown with a minus sign and the up/down state is correct.
- `c-member-vote.22` @member #ui: with Pro Reactions active, add a reaction beside the vote -> vote and reaction act independently.
- `c-member-vote.23` @member #ui +dark +390: voting controls at 390px in dark mode -> tap targets stay at least 40px and the active state is visible.
- `c-member-vote.24` @silenced #ui !refuse +silenced: finn clicks the disabled score on a reply -> the toast says his account is silenced (the REST text "Your account is currently silenced and cannot post."), not the generic "Voting is not available to you here.".
- `c-member-vote.25` @member_b #ui: the grouped vote notice reads "2 people voted on your reply" without quotation marks around "your reply".

### C.member.flag
**Covers:** includes/api/class-moderation-controller.php, includes/models/class-flag.php, includes/moderation/class-moderation-service.php, assets/js/jetonomy-modals.js, assets/js/jetonomy-rest.js, includes/moderation/class-flag-scope.php, includes/moderation/class-moderation-permissions.php
**Verified:** 64212bb5
**What to verify:** a member can report a topic, reply or member; a dialog asks for a required reason (Spam, Offensive, Off-topic, Harassment, Other) plus optional details, and submitting with no reason shows "Choose a reason.". The report reaches moderation (a moderator sees it in the queue with that reason). The reporting member gets the toast "Reported. Thank you.", not a silent submit. Content the member cannot read (a private space, a private reply) cannot be reported, and the error does not reveal whether it exists.

**Checks:**
- `c-member-flag.1` @member #ui: bob opens a topic menu and picks Report -> a dialog asks for a required reason (Spam, Offensive, Off-topic, Harassment, Other) and optional details.
- `c-member-flag.2` @member #ui !refuse: submit with no reason -> the hint "Choose a reason." shows and nothing is sent.
- `c-member-flag.3` @member #ui: submit with Spam -> toast "Reported. Thank you." appears.
- `c-member-flag.4` @moderator #ui: test_moderator opens the moderation queue -> the report is listed with reason Spam and the reporter name.
- `c-member-flag.5` @member #ui !refuse: bob reports the same reply again -> toast "You have already reported this content." (REST 409 jetonomy_already_flagged) and no second queue row.
- `c-member-flag.6` @member #rest: POST /moderation/flags with target and reason -> 201; invalid reason returns 400.
- `c-member-flag.7` @member #ui: report a reply and report a member profile -> both reach the queue with the right target type.
- `c-member-flag.8` @member #rest !refuse: report your own content -> 400 jetonomy_flag_self.
- `c-member-flag.9` @member #rest !refuse: report a reply id in Customer Advisory Board as bob (not a member) -> 404 jetonomy_not_found, the same as a missing id.
- `c-member-flag.10` @member #rest !refuse: report a private reply bob may not read -> the same 404, with no sign that it exists.
- `c-member-flag.11` @guest #rest !refuse: POST a flag as guest -> 401.
- `c-member-flag.12` @silenced #ui !refuse +silenced: finn reports a post -> "Your account is currently silenced and cannot post." (403 jetonomy_user_silenced), the same wording as every other refused write.
- `c-member-flag.13` @member #rest !refuse: report a target id that no longer exists -> 404 jetonomy_flag_target_missing or not found.
- `c-member-flag.14` @member #ui +offline: submit the report offline -> an error toast and the dialog stays open.
- `c-member-flag.15` @member #ui +concurrent: bob and alice report the same reply -> two flags merge into one queue entry with count 2.
- `c-member-flag.16` @member #ui +long: type 2000 characters into details -> the field shows its limit and saves or blocks, never a 500.
- `c-member-flag.17` @member #ui +390 +dark: open the dialog at 390px in dark mode -> it fits the screen, the reason list scrolls, buttons are reachable.
- `c-member-flag.18` @member #ui +rtl: report Omar's Arabic topic -> the dialog inherits the page direction (right-to-left under an RTL site language, left-to-right otherwise).
- `c-member-flag.19` @member #rest !refuse: GET /replies/<id> of a private reply bob may not read -> 404 jetonomy_not_found like the flag and vote refusals (today 200 with empty content but author_id and created_at).
- `c-member-flag.20` @member #rest +long: POST /flags with a 100000-character description -> 400 with a message (not a 500), and the empty details box states the limit in its placeholder ("up to 2,000 characters").
- `c-member-flag.21` @member #ui +many: three members report the same reply -> the moderator sees one card with "3 reports" and their reasons; resolving it resolves all three and each reporter is told once.

### C.member.subscribe-thread-and-space
**Covers:** includes/api/class-subscriptions-controller.php, includes/models/class-subscription.php, includes/notifications/class-notifier.php, templates/views/subscriptions.php, assets/js/lib/optimistic.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a member can subscribe to a thread and to a space; a subsequent reply (thread) or new post (space) produces a notification; unsubscribing stops new notifications. A member who creates a space from the front end follows it automatically. Thread subscribers are not notified about private replies.

**Checks:**
- `c-member-subscribe-thread-and-space.1` @member #ui: bob clicks Follow on the Feature Requests topic -> the button changes to Following and /community/subscriptions/ lists it.
- `c-member-subscribe-thread-and-space.2` @member_b #ui: alice replies in the thread bob follows -> bob gets one bell notification and one email whose subject says he follows the thread ("[forums] Alice Chen replied in <topic>"), not "replied to your topic" when bob is not the author.
- `c-member-subscribe-thread-and-space.3` @member #ui: bob unfollows -> the button reverts; the next reply produces no notification for him.
- `c-member-subscribe-thread-and-space.4` @member #ui: follow Help Desk (space) -> a new topic by alice there produces one notification for bob.
- `c-member-subscribe-thread-and-space.5` @member #rest: POST /subscriptions then DELETE it -> 201 then 200; unfollowing a thread removes that subscription and not the newest one.
- `c-member-subscribe-thread-and-space.6` @member #ui: follow both a space and one of its threads -> a new reply in the thread produces one notification, not two.
- `c-member-subscribe-thread-and-space.7` @member #ui: create a space from the front end (as allowed) -> the creator already follows it.
- `c-member-subscribe-thread-and-space.8` @member_b #email !refuse: bob subscribes to a thread; alice posts a private reply -> bob gets no notification or email unless he may read it.
- `c-member-subscribe-thread-and-space.9` @member #ui !refuse: following a Moderators' Room topic id via REST as bob -> 404 jetonomy_not_found.
- `c-member-subscribe-thread-and-space.10` @member #ui +private: bob follows Partner Program and is later removed -> his notifications about it disappear and he stops receiving mail.
- `c-member-subscribe-thread-and-space.11` @guest #ui !refuse: a guest sees no Follow control; /community/subscriptions/ redirects to wp-login.php?redirect_to=...; POST /subscriptions returns 401.
- `c-member-subscribe-thread-and-space.12` @member #ui +offline: follow offline -> the button rolls back and an error toast shows.
- `c-member-subscribe-thread-and-space.13` @member #ui +concurrent: click Follow twice quickly -> one subscription row.
- `c-member-subscribe-thread-and-space.14` @member #ui +empty: open /community/subscriptions/ with none -> a clean empty state.
- `c-member-subscribe-thread-and-space.15` @member #ui +many: with more than 30 subscriptions -> the list paginates and counts match.
- `c-member-subscribe-thread-and-space.16` @member #ui +archived: follow 2025 Hackathon -> Follow works (not Join) and yields no notices as nothing posts.
- `c-member-subscribe-thread-and-space.17` @member #ui +deleted: a followed topic is deleted -> its row stays as "No longer available" with Unfollow (owner decision).
- `c-member-subscribe-thread-and-space.18` @member #ui +dark +390: subscriptions page at 390px dark -> rows fit.
- `c-member-subscribe-thread-and-space.19` @member #rest +private: after bob loses access to a followed space, GET /subscriptions and /bookmarks and the member pages hide that target, but a member admitted only by a rule (no roster row) still sees their follows and bookmarks there; trash a followed topic -> its row reads "No longer available" (2.0.4).

### C.member.accept-answer
**Covers:** templates/views/single-post.php, templates/partials/reply-card.php, includes/api/class-replies-controller.php, includes/models/class-reply.php
**Verified:** 64212bb5
**What to verify:** on Q&A-typed spaces, the OP can mark a reply as the accepted answer, the reply is visibly promoted, and a second acceptance replaces the first per contract. A private reply is never shown in the accepted-answer callout to people who may not read it, and merging or splitting topics keeps exactly one accepted answer.

**Checks:**
- `c-member-accept-answer.1` @member #ui: bob, OP of a question in the Q&A space, clicks Accept on alice's reply -> the reply gets an Accepted badge and the question shows Resolved; the pinned "Accepted answer" callout appears above the replies only when the accepted reply is on another page, otherwise it is highlighted in place.
- `c-member-accept-answer.2` @member #rest: POST /replies/<id>/accept -> 200 and GET /posts/<id> carries accepted_reply_id.
- `c-member-accept-answer.3` @member #ui: accept a second reply -> the first loses its badge, the new one gains it; exactly one is accepted.
- `c-member-accept-answer.4` @member #ui: click the accepted badge or Unaccept -> the accepted state is cleared.
- `c-member-accept-answer.5` @member_b #ui !refuse: alice (not OP) opens a question -> no Accept control; REST POST accept returns 403 jetonomy_forbidden.
- `c-member-accept-answer.6` @moderator #ui: test_moderator accepts in a Bug Reports question -> allowed for moderators, and the answer author receives a notification.
- `c-member-accept-answer.7` @member_b #email: the accepted answer's author -> gets one notification and one email, plus reputation.
- `c-member-accept-answer.8` @member #ui !refuse: a forum-type space topic -> no Accept control (accept applies to Q&A spaces only).
- `c-member-accept-answer.9` @guest #ui !refuse: view the Q&A topic -> accepted callout shows but no controls.
- `c-member-accept-answer.10` @member_b #ui !refuse: accept a private reply and view as guest -> the callout never shows its text to people who may not read it.
- `c-member-accept-answer.11` @moderator #ui: merge a Q&A topic into another and split one out -> exactly one accepted answer remains per topic.
- `c-member-accept-answer.12` @member #ui +closed: accept in a closed question topic -> follows the contract and the UI matches REST.
- `c-member-accept-answer.13` @member #ui +deleted: delete the accepted reply -> the topic reverts to unanswered.
- `c-member-accept-answer.14` @member #ui +long: accepted answer with long code block -> callout does not overflow at 390.
- `c-member-accept-answer.15` @member #ui +offline: accept offline -> badge rolls back and an error shows.
- `c-member-accept-answer.16` @member #ui: the Accepted callout is shown above the replies when the accepted reply is on a later page than the one being viewed (on the same page it is highlighted inline).

### C.member.profile-edit
**Covers:** templates/views/edit-profile.php, includes/api/class-users-controller.php, includes/models/class-user-profile.php, includes/class-avatar.php, pro:includes/extensions/custom-fields/*.php, pro:assets/js/admin-custom-fields.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a member can edit their display name, bio, avatar, and notification preferences; changes save, reload cleanly, and reflect across the site. The bio is the WordPress Biographical Info field, so wp-admin and the community profile always match. A display name that is a reserved word (such as admin) saves. The avatar upload controls appear only for a member with the Upload media permission; others see "Your Gravatar is used as your photo." The notification preferences list "Answer to my request to join a space" (email on by default).

**Checks:**
- `c-member-profile-edit.1` @member #ui: bob changes his display name and bio and saves -> both show on his community profile and in his posts' author line.
- `c-member-profile-edit.2` @admin #admin: open wp-admin > Users > bob -> Biographical Info equals the community bio just saved.
- `c-member-profile-edit.3` @admin #admin: edit the bio in wp-admin -> the community profile shows the new bio on reload.
- `c-member-profile-edit.4` @member #rest: PATCH /users/me with a display_name built from his own first, last or nickname fields -> 200; any other display_name -> 400 jetonomy_invalid_display_name.
- `c-member-profile-edit.5` @member #ui: a reserved word such as admin cannot be chosen as the display name (400), even when the nickname is admin.
- `c-member-profile-edit.6` @member #ui: upload an avatar as a member holding Upload media -> it shows in the header, topics and replies.
- `c-member-profile-edit.7` @member #ui !refuse +settings-off: member without Upload media -> upload controls are hidden and the text "Your Gravatar is used as your photo." shows.
- `c-member-profile-edit.8` @member #rest !refuse +settings-off: POST an avatar upload without the permission -> 403.
- `c-member-profile-edit.9` @member #ui: open notification preferences -> the list includes "Answer to my request to join a space" with email on by default.
- `c-member-profile-edit.10` @member #ui: turn that preference off, save, reload -> still off, and a later join answer sends no email.
- `c-member-profile-edit.11` @member_b #ui !refuse: alice opens /community/u/bob/edit/ -> she gets her own edit form (the slug is ignored) or a refusal; she cannot see or change bob's data.
- `c-member-profile-edit.12` @member #rest !refuse +empty: PATCH display_name "" -> 200 and the old name stays.
- `c-member-profile-edit.13` @member #ui +long: a 200-character display name and a 2000-character bio -> either limited with a message or wrapped without breaking the header.
- `c-member-profile-edit.14` @member #ui: remove the avatar -> Gravatar/initials return.
- `c-member-profile-edit.15` @member #ui +offline: save offline -> an error and the form keeps typed values.
- `c-member-profile-edit.16` @member #ui: with Pro Custom Fields, fill in a field -> it shows on the public profile; required empty field blocks the save.
- `c-member-profile-edit.17` @member #ui +390 +dark: the form at 390px dark -> labels and inputs readable.
- `c-member-profile-edit.18` @silenced #ui +silenced: finn edits his profile -> allowed (silencing stops posting only).
- `c-member-profile-edit.19` @member #ui: for a member who has a first or last name (the old name must come from a profile field, as in wp-admin), after switching the display name to the username a member can switch back (the old option stays selectable).

### C.member.bookmarks
**Covers:** templates/views/bookmarks.php, includes/api/class-bookmarks-controller.php, includes/models/class-bookmark.php, assets/js/lib/optimistic.js, templates/partials/empty-state.php
**Verified:** 64212bb5
**What to verify:** a member can bookmark a post, find it in their bookmarks view, and unbookmark it; count reflects the change. Removing the last bookmark shows the empty state immediately, without a reload, and the next tab you open shows its full content (after a hand-made DOM change the next navigation is a full page load).

**Checks:**
- `c-member-bookmarks.1` @member #ui: bob bookmarks "Dark mode for the community on phones" -> the icon fills and a toast confirms.
- `c-member-bookmarks.2` @member #ui: open /community/bookmarks/ -> the topic is listed with its title and space.
- `c-member-bookmarks.3` @member #ui: unbookmark from the list -> the row disappears and the count drops by one.
- `c-member-bookmarks.4` @member #ui +empty: remove the last bookmark -> the empty state shows immediately without a reload, and clicking another tab shows its full content.
- `c-member-bookmarks.5` @member #rest: POST /bookmarks -> 200 {bookmarked:true}; a second POST toggles it off ({bookmarked:false}); DELETE /bookmarks/<id> -> 200 {deleted:true} and a repeat DELETE is also 200; never a duplicate row.
- `c-member-bookmarks.6` @member #rest !refuse: bookmark a topic id from Moderators' Room -> 404 jetonomy_not_found.
- `c-member-bookmarks.7` @guest #ui !refuse: a guest sees no bookmark button; /community/bookmarks/ redirects to wp-login.php?redirect_to=/community/bookmarks/; POST /bookmarks returns 401.
- `c-member-bookmarks.8` @member_b #ui !refuse: alice opens bob's bookmark list -> sees only her own.
- `c-member-bookmarks.9` @member #ui +deleted: bookmark then have the topic deleted -> the list omits it and shows no broken row.
- `c-member-bookmarks.10` @member #ui +private: bookmark a Partner Program topic and leave the space -> it disappears from the list.
- `c-member-bookmarks.11` @member #ui +many: with 40 bookmarks -> the list paginates.
- `c-member-bookmarks.12` @member #ui +offline: bookmark offline -> the icon rolls back with an error.
- `c-member-bookmarks.13` @member #ui +long: Grace's long-title topic in the list -> wraps inside the row.
- `c-member-bookmarks.14` @member #ui +390 +dark: bookmarks page at 390px dark -> readable.
- `c-member-bookmarks.15` @member #ui +390: a bookmark row at 390 keeps the reply count readable and not under the bookmark button (the relative time cell is hidden on phones by design).

### C.member.share
**Covers:** templates/views/single-post.php, assets/js/view.js, assets/js/lib/smart-dropdown.js, includes/api/class-oembed-controller.php
**Verified:** 64212bb5
**What to verify:** the Share control on a topic opens a dropdown anchored to the button, offers Copy Link + X + Facebook + LinkedIn, Copy Link actually writes the URL to the clipboard, external links open on the right platform, and the dropdown closes when the user scrolls or clicks outside.

**Checks:**
- `c-member-share.1` @member #ui: click Share on a topic -> a dropdown anchors to the button with Copy Link, X, Facebook and LinkedIn.
- `c-member-share.2` @member #ui: click Copy Link, then paste into the address bar -> the topic URL is pasted and a "Copied" confirmation shows.
- `c-member-share.3` @member #ui: click each social item -> a new tab opens the right platform with the topic URL and title encoded.
- `c-member-share.4` @member #ui: scroll the page and click outside -> the dropdown closes.
- `c-member-share.5` @member #ui: press Escape with the dropdown open -> it closes and focus returns to the button.
- `c-member-share.6` @guest #ui: Share on a public topic as guest -> works the same.
- `c-member-share.7` @member #ui !refuse: a Partner Program topic -> either Share shows a note that only members can open the link, or the link is the same private URL; it never exposes the body.
- `c-member-share.8` @guest #rest: GET /oembed?url=<public topic> -> 200 with title and author; for a hidden or private topic 404 jetonomy_oembed_not_found.
- `c-member-share.9` @member #ui +long: share Grace's 159-character title -> encoded link works and the dropdown stays in view.
- `c-member-share.10` @member #ui +390 +rtl: open the dropdown at 390px in RTL near the screen edge -> it stays inside the viewport.
- `c-member-share.11` @member #ui +offline: clipboard denied -> a fallback message shows instead of a silent failure.

### C.member.scheduled-post
**Covers:** templates/partials/compose-fields.php, assets/js/composer.js, includes/api/class-posts-controller.php, includes/class-cron.php, templates/partials/schedule-fields.php, assets/js/view.js, templates/views/single-post.php
**Verified:** 64212bb5
**What to verify:** a member can schedule a post for a future date/time using the composer's schedule mode, picking both hour and minute; the scheduled post is stored with the correct `published_at`, does not appear on public listings before that time, and appears automatically when the time arrives. The HH:MM picker works in every supported browser (Chromium proven here; Firefox + Safari iOS in `manual_required`).

**Checks:**
- `c-member-scheduled-post.1` @member #ui: bob schedules a topic "Meetup recap - planning the March community call" for tomorrow 14:35 using hour and minute pickers -> the form accepts it and confirms "scheduled".
- `c-member-scheduled-post.2` @member #rest: POST /spaces/<id>/posts with status draft and a future published_at -> 201 and published_at is stored for that time in the site timezone.
- `c-member-scheduled-post.3` @guest #ui !refuse: before the time arrives view the space and search -> the scheduled topic is not in listings, search or its URL (404).
- `c-member-scheduled-post.4` @member #ui: bob opens Drafts or his scheduled list -> the topic is listed there with its release time.
- `c-member-scheduled-post.5` @member #ui: advance time past 14:35 and run `wp cron event run` -> the topic appears on the listing with publish time as its date and subscribers get one notification.
- `c-member-scheduled-post.6` @member #rest !refuse: POST status=draft with a date-only published_at (no time of day) -> 400 jetonomy_schedule_time_required.
- `c-member-scheduled-post.7` @member #rest !refuse: schedule a time in the past -> 400 jetonomy_schedule_in_past.
- `c-member-scheduled-post.8` @member #rest !refuse: submit the same scheduled post twice -> 409 jetonomy_duplicate_scheduled.
- `c-member-scheduled-post.9` @member #ui: edit the schedule time before it publishes -> the new time is saved.
- `c-member-scheduled-post.10` @member #ui: delete the scheduled topic before it publishes -> it never appears.
- `c-member-scheduled-post.11` @member_b #ui !refuse: alice opens bob's scheduled topic URL -> 404.
- `c-member-scheduled-post.12` @member #ui +settings-off: scheduling in a require-approval space -> still held until approved.
- `c-member-scheduled-post.13` @silenced #ui !refuse +silenced: finn schedules -> refused.
- `c-member-scheduled-post.14` @member #ui: the picker works in Chromium; Firefox and Safari iOS -> noted in manual_required if not available.
- `c-member-scheduled-post.15` @member #ui +dark +390: schedule picker on a phone in dark mode -> usable.
- `c-member-scheduled-post.16` @member #ui +390: the "More publishing options" menu (Publish now, Save as draft, Schedule...) is fully above the fixed mobile tab bar and every item is tappable.
- `c-member-scheduled-post.17` @member #ui: a member can change the release time of a scheduled topic from its page.
- `c-member-scheduled-post.18` @member #ui: the author changes a scheduled topic's time from the topic page (Save time), then picks Cancel schedule -> the publish job moves, then the topic stays a draft; PATCH published_at as another member -> refused.

### C.member.space-pagination
**Covers:** templates/views/space.php, templates/partials/pagination.php, assets/js/pagination-frontend.js, assets/js/pagination-hydrator.js, templates/partials/feed-card.php
**Verified:** 64212bb5
**What to verify:** on a space page, `posts_per_page` controls how many topics load initially; each click on Load More adds exactly one more batch with no duplicates; nothing loads on scroll alone (loading is click-only by design, see the header of `assets/js/pagination-frontend.js`); the button disappears after the last page.

**Checks:**
- `c-member-space-pagination.1` @member #ui: open the jt-qa-paging space with posts_per_page = 1 -> exactly one topic shows with a Load More button.
- `c-member-space-pagination.2` @member #ui: click Load More once -> exactly one more topic is added, no duplicates.
- `c-member-space-pagination.3` @member #ui: scroll to the bottom without clicking -> nothing loads.
- `c-member-space-pagination.4` @member #ui: click Load More until the end -> the button disappears after the last page.
- `c-member-space-pagination.5` @member #ui +many: Feature Requests with 30+ topics at 30 per page -> the first batch is 30, Load More adds the next batch, the total equals the REST X-WP-Total.
- `c-member-space-pagination.6` @member #rest: GET /spaces/<id>/posts?offset=20 returns the next batch, offset beyond the total returns an empty list, and ?page=N is either honoured or rejected, not silently ignored.
- `c-member-space-pagination.7` @member #ui +empty: a space with zero or fewer posts than one page -> no Load More button.
- `c-member-space-pagination.8` @member #ui +concurrent: a new topic is posted while page 1 is open, then Load More is clicked -> no duplicate row and no skipped row.
- `c-member-space-pagination.9` @member #ui +offline: click Load More offline -> the button stays and an error shows; retry works.
- `c-member-space-pagination.10` @member #ui: change the Settings > posts_per_page value -> the first batch size follows it.
- `c-member-space-pagination.11` @guest #ui !refuse: Load More in Partner Program as guest -> no topics, only the login prompt.
- `c-member-space-pagination.12` @member #ui +390 +dark: Load More at 390px dark -> the button is full width and focus moves sensibly for keyboard users.
- `c-member-space-pagination.13` @member #ui +concurrent: Load More after a new topic was posted shows no duplicate row.
- `c-member-space-pagination.14` @member #ui +offline: Load More offline shows an error message and keeps the button.
- `c-member-space-pagination.15` @member #rest: GET list routes (spaces, topics, replies, subscriptions, bookmarks) with ?page=2 and per_page=1 -> return the second slice, not the first page again.

### C.member.profile-mobile-tabs
**Covers:** templates/views/user-profile.php, templates/views/drafts.php
**Verified:** 64212bb5
**What to verify:** on mobile (390px), the profile tabs bar is fully usable - no tab falls unreachable off-screen, the active tab is visible on load.

**Checks:**
- `c-member-profile-mobile-tabs.1` @member #ui +390: open bob's own profile at 390px -> the tab bar shows and the active tab is scrolled into view on load.
- `c-member-profile-mobile-tabs.2` @member #ui +390: swipe the tabs sideways -> every tab in the strip (Topics, Replies, Votes, Bookmarks, Drafts) can be reached and tapped.
- `c-member-profile-mobile-tabs.3` @member #ui +390: open directly /community/u/bob/drafts -> the Drafts tab is the visible active one.
- `c-member-profile-mobile-tabs.4` @guest #ui +390: guest views a profile at 390px -> public tabs only, none off-screen without a way to reach them.
- `c-member-profile-mobile-tabs.5` @member_b #ui +390 !refuse: alice sees bob's profile -> no Drafts, Bookmarks or Settings tabs.
- `c-member-profile-mobile-tabs.6` @member #ui +390 +rtl: Arabic locale -> the tab strip starts at the right and the active tab is visible.
- `c-member-profile-mobile-tabs.7` @member #ui +390 +long: Grace's long name with tabs -> the name wraps and the tab bar stays one row.
- `c-member-profile-mobile-tabs.8` @member #ui +390 +dark: dark mode -> active underline and text contrast pass.
- `c-member-profile-mobile-tabs.9` @member #ui +390: rotate to 1024 and back -> the layout recalculates, active tab remains visible.

### C.member.online-indicator
**Covers:** templates/partials/reply-card.php, templates/views/user-profile.php, templates/partials/sidebar.php, includes/models/class-user-profile.php, templates/partials/avatar.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a user who is active within the threshold shows an online dot on every avatar context (topic page, profile header, home feed). The dot is visually attached to the avatar's top-right corner across all avatar sizes.

**Checks:**
- `c-member-online-indicator.1` @member_b #ui: alice was active within the threshold -> an online dot shows on her avatar on a topic page, reply card, profile header and home feed.
- `c-member-online-indicator.2` @member #ui: view a member who was last active beyond the threshold (for example carlos) -> no dot on any avatar.
- `c-member-online-indicator.3` @member #ui: check the dot placement on small, medium and large avatars -> it sits on the top-right corner of the avatar in every size.
- `c-member-online-indicator.4` @member #ui +rtl: view under a real RTL locale (the -rtl.css only loads for one; html dir=rtl emulation does not load it) -> the dot stays attached at the avatar's corner (mirrored) without drifting.
- `c-member-online-indicator.5` @member #ui +long: Grace (no avatar, initials avatar) while active -> the dot attaches to the initials avatar.
- `c-member-online-indicator.6` @member_b #rest: GET /users/<id> -> the last-active or online flag matches the UI.
- `c-member-online-indicator.7` @member #ui +settings-off: switch off online status display (or the member hides it in privacy) -> no dot anywhere for that member.
- `c-member-online-indicator.8` @guest #ui !refuse: guest views an active member -> follow the contract: dot shown or hidden consistently in all contexts, no precise last-seen time revealed.
- `c-member-online-indicator.9` @member #ui +banned: omar (banned from one space) while active -> the dot shows normally; finn silenced shows normally.
- `c-member-online-indicator.10` @member #ui +390 +dark: dot at 390px dark mode -> a visible ring separates it from the avatar and it is not clipped.
- `c-member-online-indicator.11` @member #ui +deleted: reply by deleted account -> no dot and no error.
- `c-member-online-indicator.12` @guest #rest !refuse: GET /users/<id> logged out does not return the exact last_seen_at of a member.

### C.mod.queue-and-actions
**Covers:** templates/views/moderation.php, templates/partials/moderation/*.php, includes/api/class-moderation-controller.php, includes/moderation/class-moderation-service.php, assets/js/jetonomy-modals.js, includes/models/class-restriction.php, includes/moderation/class-flag-scope.php, includes/moderation/class-moderation-permissions.php, templates/partials/pagination-nav.php, templates/partials/empty-state.php, pro:includes/extensions/advanced-moderation/*.php, pro:assets/js/moderation-admin.js, pro:includes/extensions/ai/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a user with moderator capability sees the moderation queue, can filter by type (post/reply) and status (pending/spam/trash/resolved-flag), and can approve / trash / mark-spam from the queue. Every action updates the underlying record, disappears from its source tab, and appears in the destination tab. Silenced users cannot post until unsilenced. A site moderator (not a site admin) sees queues, report lists and counts only for spaces they can read, and cannot act on content in private spaces they cannot read. Space pages link to their own moderation queue with a count for space admins and site moderators; the header "Moderation queue" button opens the Approvals tab when only held content is pending, so it never lands on "0 pending flags". Per-space flag cards show the topic title (or an excerpt for an untitled feed post) and the flagged text. Site-wide bans listed there include IP address bans. Moving content to Trash counts as removal for reputation, the same as Spam, and approving it restores only what was taken.

**Checks:**
- `c-mod-queue-and-actions.1` @moderator #ui: open /community/mod/ -> the page titled "Moderation Queue" (heading "Moderation Overview") shows the tabs Flags, Awaiting approval, Trash and Banned members, with counts on Flags and Awaiting approval (Trash shows its count in the subtitle once opened), and the sidebar stays visible.
- `c-mod-queue-and-actions.2` @moderator #ui: in Awaiting approval, filter Content type to topics, then to replies, then pick a status -> only that type and status is listed, and the pager keeps the filter on Next.
- `c-mod-queue-and-actions.3` @moderator #ui #rest: Approve a held topic by carlos in Insiders / Beta from its card, then GET /moderation/queue?status=pending -> the card leaves Awaiting approval with no reload, the topic is public in its space, the REST queue no longer lists it, and carlos is notified once.
- `c-mod-queue-and-actions.4` @moderator #ui #rest: click Reject on a held reply (confirm "Reject this submission? It moves to trash.") -> the card leaves Awaiting approval, appears in Trash with a Restore button, and POST /moderation/trash/reply/{id} returns 200.
- `c-mod-queue-and-actions.5` @moderator #ui #rest: mark a held topic as spam from the queue -> it lists under the spam status only (not pending), the author's reputation drops the same amount as for Trash, and POST /moderation/spam/post/{id} returns 200.
- `c-mod-queue-and-actions.6` @moderator #ui: Restore a trashed topic, then Approve -> reputation returns only to what was taken (no extra points), and the topic is public again.
- `c-mod-queue-and-actions.7` @moderator #ui +dark: on Trash use "Delete permanently" on a topic that has replies -> the confirm reads "Delete this topic and all its replies permanently? This cannot be undone.", and after confirming the row and its replies are gone.
- `c-mod-queue-and-actions.8` @moderator #rest: POST /moderation/bulk with action=approve and three held ids, then with action=bogus and then with items=[] -> the first returns 200 for all three, the second returns 400 rest_invalid_param "action is not one of approve, spam, and trash.", the third "items must be a non-empty array.".
- `c-mod-queue-and-actions.9` @moderator #ui +empty: empty the queue and open every section -> Flags reads "No pending flags anywhere. Your community is clean.", Awaiting approval reads "No topics are waiting for approval.", Trash reads "The trash is empty.".
- `c-mod-queue-and-actions.10` @moderator #ui +many: seed 60 held items and open Awaiting approval -> the list pages with the "Awaiting approval pagination" nav, Next lands on page 2 with `?pg=2`, and the count badge shows the true total.
- `c-mod-queue-and-actions.11` @moderator #ui: open the queue as a space-only moderator (alice given the moderator role in Bug Reports and Moderators' Room; test_moderator is a site Editor with jetonomy_moderate and counts as a site-wide moderator) with a flag pending in Customer Advisory Board -> that flag is not listed, not counted in the badge, and the subtitle reads "N pending flags across the spaces you moderate".
- `c-mod-queue-and-actions.12` @moderator #rest !refuse: POST /moderation/approve/post/{id} for a held topic in Customer Advisory Board (private but listed, test_moderator cannot reach it) -> 403 jetonomy_forbidden, identical to the answer for a missing topic id (no existence hint), and the topic stays held.
- `c-mod-queue-and-actions.13` @member #ui #rest !refuse: open /community/mod/ and GET /moderation/queue as bob -> the page says "You do not have permission to view this page." and REST returns 403 jetonomy_forbidden.
- `c-mod-queue-and-actions.14` @guest #ui #rest !refuse: open /community/mod/ and GET /moderation/queue logged out -> the page sends the guest to login and REST returns 401.
- `c-mod-queue-and-actions.15` @silenced #ui !refuse: finn (silenced site-wide) tries to post a reply -> the composer submit is refused with "Your account is currently silenced and cannot post.", and after an admin lifts the silence the same reply posts.
- `c-mod-queue-and-actions.16` @space_admin #ui: open the Help Desk space page -> a "Moderation queue" link shows the space's own pending count, and when only held content is pending the header "Moderation queue" button opens the Approvals tab, never "0 pending flags".
- `c-mod-queue-and-actions.17` @moderator #ui: open a per-space flag card on a feed post with no title -> the card shows an excerpt of the post, and on a titled topic it shows the linked topic title plus the start of the flagged text.
- `c-mod-queue-and-actions.18` @moderator #ui: approve an anonymous-posted topic from an approval card -> the card shows the author as anonymous, never the real member name or login.
- `c-mod-queue-and-actions.19` @admin #ui: open the banned/silenced list after banning an IP address -> the entry is labelled "IP address ban" with the address, and "Lift restriction" removes it.
- `c-mod-queue-and-actions.20` @moderator #ui +390: open the queue at 390px -> cards stack, Approve/Reject/Remove buttons are at least 40px tall, and nothing scrolls sideways.
- `c-mod-queue-and-actions.21` @moderator #ui +concurrent: two moderators open the same held topic and one approves it, then the other clicks Reject -> the second sees a clear "already handled" message and the topic stays published, with no second notification to the author.
- `c-mod-queue-and-actions.22` @moderator #ui +offline: go offline and click Approve -> the card stays in the list, an error toast shows, and reconnecting then clicking Approve works once.
- `c-mod-queue-and-actions.23` @moderator #ui #rest +settings-off: with the Pro Advanced Moderation extension off, hold a topic by an auto-rule match -> no rule-held items appear, and with it on a keyword rule "Hold for Approval" puts a new topic from a trust level 0 member in Awaiting approval.
- `c-mod-queue-and-actions.24` @moderator #ui: open a held topic card in Awaiting approval -> a Mark as spam control sits beside Approve and Reject, or the check is dropped.
- `c-mod-queue-and-actions.25` @moderator #rest !refuse +concurrent: a stale Reject, Approve, Trash or Spam (sent with `from=pending`, as the queue card does) on a topic another moderator already approved -> 409 jetonomy_already_handled, the topic stays published and no notice or email goes out; the same action without `from` is a direct moderation of a published topic and is allowed.
- `c-mod-queue-and-actions.26` @guest #rest !refuse: GET /moderation/queue logged out -> 401 jetonomy_unauthorized (a GET; the write routes return rest_not_logged_in).
- `c-mod-queue-and-actions.27` @moderator #ui: the Awaiting approval view has a Pending/Spam select -> choosing Spam lists only spam items and choosing Pending lists only pending ones, and the choice survives approving one item.

### C.mod.flag-resolution
**Covers:** templates/partials/moderation/flag-card.php, includes/models/class-flag.php, includes/moderation/class-moderation-service.php, includes/moderation/class-flag-scope.php, includes/moderation/class-moderation-permissions.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a moderator sees flagged content with the flagging user's reason, can resolve the flag from its card (Remove or Dismiss), and the resolution is recorded and reflected on the reporter's side (the reporter's notification reads "Your report was reviewed. Thanks for helping keep the community healthy.", and the email subject is "[site] Your report was reviewed"). Dismissing a report on a reply restores the author's reputation, as it does for topics. Anonymous authors stay anonymous on approval cards. The restriction-lifting button reads "Lift restriction". The per-space flag card shows the linked topic title and the start of the flagged text, so a moderator need not open each item.

**Checks:**
- `c-mod-flag-resolution.1` @member_b #ui #rest: report Grace's long General Discussion topic with reason "Spam" from the topic menu -> a toast confirms the report, and POST /flags returns 201.
- `c-mod-flag-resolution.2` @moderator #ui: open Flags -> the card shows "Reported by Alice ..." with the reason and note the reporter typed, and the flagged text.
- `c-mod-flag-resolution.3` @moderator #ui #email: click Dismiss on the flag -> the card leaves Flags, the reporter's bell reads "Your report was reviewed. Thanks for helping keep the community healthy.", and Mailpit holds one email "[site] Your report was reviewed".
- `c-mod-flag-resolution.4` @moderator #ui: click Remove on a flag for a reply -> the reply leaves the thread, the card leaves Flags, the reply shows in Trash, and the reply author's reputation drops.
- `c-mod-flag-resolution.5` @moderator #ui: Dismiss a flag on a reply, then on a topic -> in both cases the author's reputation returns to what it was before the flag (reply parity with topic).
- `c-mod-flag-resolution.6` @moderator #rest: POST /moderation/flags/{id}/resolve with status=dismissed, then the same call again -> the first returns 200, the second returns the "Flag not found." or already-resolved error and sends no second notification.
- `c-mod-flag-resolution.7` @member_b #ui #rest !refuse: report your own reply -> UI refuses and REST returns 4xx "You cannot report your own content.", no flag is created.
- `c-mod-flag-resolution.8` @member_b #rest !refuse: report the same topic twice -> second POST /flags returns 4xx "You have already reported this content.".
- `c-mod-flag-resolution.9` @member_b #rest !refuse: POST /flags for a reply id in Customer Advisory Board (bob is not a member, alice is not either) -> 404 identical to a missing id, never 201 or 403.
- `c-mod-flag-resolution.10` @silenced #rest !refuse: finn POSTs /flags -> 403 jetonomy_user_silenced with the message shown to him.
- `c-mod-flag-resolution.11` @guest #rest !refuse: POST /flags logged out -> 401, and no Report control is shown to guests on a topic.
- `c-mod-flag-resolution.12` @member #rest !refuse: bob POSTs /moderation/flags/{id}/resolve -> 403 jetonomy_forbidden, the flag stays pending.
- `c-mod-flag-resolution.13` @moderator #rest !refuse: test_moderator resolves a flag on a topic in Partner Program (private, not readable by the moderator) -> 404 as if the flag did not exist.
- `c-mod-flag-resolution.14` @moderator #ui: lift a space-level restriction from the restriction list -> the button reads "Lift restriction" (never "Unban" or "Remove ban" on a silence), and the member can act again.
- `c-mod-flag-resolution.15` @moderator #ui +long: flag a topic with the 159-character title and the unbroken 100-character URL -> the flag card wraps the title and URL inside the card at 1440 and 390 with no horizontal scroll.
- `c-mod-flag-resolution.16` @moderator #ui +deleted: flag a reply, then its author deletes it, then open Flags -> the card shows the reply as removed (no PHP notice, no blank card) and can be dismissed.
- `c-mod-flag-resolution.17` @moderator #ui +rtl: flag Omar's Arabic topic -> the flagged text renders right-to-left inside the card and the buttons keep their position.
- `c-mod-flag-resolution.18` @moderator #ui +dark: open a flag card in dark mode -> reason chips, note and buttons keep readable contrast (4.5:1).
- `c-mod-flag-resolution.19` @moderator #rest !refuse +concurrent: resolve or dismiss the same flag twice -> the second call answers 409 jetonomy_already_handled, and the reporter gets exactly one bell row and one email.
- `c-mod-flag-resolution.20` @moderator #ui: remove a flag on a reply, then open Trash -> the Trash tab opens on the Replies sub-tab (or the one that holds the item) instead of 'No topics in the trash.'.

### C.admin.plugin-pages
**Covers:** includes/admin/class-admin.php, includes/admin/views/*.php, assets/css/admin.css, assets/js/admin-common.js, assets/js/admin-confirm.js, assets/js/admin-content.js, assets/js/admin-dashboard.js, assets/js/admin-media-grid.js, assets/js/admin-nav-menus.js, assets/js/admin-replies.js, assets/js/admin-revisions.js, assets/js/admin-settings.js, assets/js/admin-tags.js, includes/admin/class-activity-list-table.php, includes/admin/class-revisions-list-table.php, includes/admin/ajax/class-content-handler.php, includes/admin/ajax/class-moderation-handler.php, includes/admin/ajax/class-tags-handler.php, includes/class-activity-tracker.php, includes/class-admin-bar.php, includes/class-nav-menus.php, includes/models/class-activity-log.php, includes/models/class-revision.php, pro:includes/extensions/white-label/*.php, pro:includes/extensions/analytics/views/*.php, pro:includes/extensions/custom-fields/views/*.php, pro:includes/extensions/private-messaging/views/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** every Jetonomy admin page (overview, spaces, categories, content, users, moderation, settings, plus any Pro extension pages) renders without PHP Notice / Warning / Fatal. Every tab on those pages loads its content without AJAX errors. The Spaces, Users and Extensions screens open for staff holding Manage spaces, Manage community users and Manage extensions respectively. The Dashboard's "Review flags" link opens the Flags tab. Anonymous authors stay anonymous in the admin lists, Activity Log and Revisions. Every Settings sidebar row has an icon. Labels follow Terminology (topics, not posts), and the brand name set by White Label (`jetonomy_brand_name`) replaces "Jetonomy" in admin headings. Settings > Emails lists every email type: reactions, report reviewed, join request, join answer and "Confirm your email (new accounts)"; saved templates for each are kept. The Activity Log shows titles and "Gained/Lost N reputation points" with plain reasons, and its retention setting is its own card. The Permissions role table keeps its first column visible while scrolling sideways, and the trust level name fields are styled. Revisions show the stored title with "(deleted)" when the topic is gone. The setup wizard calls the type "Feed".

**Checks:**
- `c-admin-plugin-pages.1` @admin #admin: open every Jetonomy admin page (Overview, Spaces, Categories, Content, Users, Moderation, Settings) with WP_DEBUG on -> each renders with no PHP Notice, Warning or Fatal in the page or debug.log.
- `c-admin-plugin-pages.2` @admin #admin: click every tab on Settings, Content and Moderation -> each loads its panel with no AJAX error toast and no failed request in the network log.
- `c-admin-plugin-pages.3` @admin #admin: with Pro active open every extension page (Analytics, Custom Fields, Private Messaging, White Label) -> each renders without a notice and respects the same layout as the Free pages.
- `c-admin-plugin-pages.4` @admin #admin: click the Dashboard "Review flags" link -> it opens the Moderation screen on the Flags tab.
- `c-admin-plugin-pages.5` @admin #admin: read the Settings sidebar -> every row has an icon, and labels say "topics" and "replies" per Terminology, not "posts".
- `c-admin-plugin-pages.6` @admin #admin: open Settings > Emails -> it lists reactions, report reviewed, join request, join answer and "Confirm your email (new accounts)", and a saved template for each is still there after reload.
- `c-admin-plugin-pages.7` @admin #admin: open the Activity Log after a reputation change -> rows read "Gained N reputation points" or "Lost N reputation points" with a plain reason, titles are shown, and the retention setting sits in its own card.
- `c-admin-plugin-pages.8` @admin #admin +390: open the Permissions role table at a narrow width and scroll sideways -> the first column stays visible, and the trust level name fields are styled like other inputs.
- `c-admin-plugin-pages.9` @admin #admin +deleted: open Revisions for a topic that was deleted -> the stored title shows with "(deleted)" after it, not a blank row.
- `c-admin-plugin-pages.10` @admin #admin: in a topic posted anonymously open the admin Content list, Activity Log and Revisions -> the author shows as anonymous in all three, never the real login.
- `c-admin-plugin-pages.11` @admin #admin +settings-off: set White Label brand name to "Harbor Community" -> admin headings say "Harbor Community" where they said "Jetonomy", and clearing it restores "Jetonomy".
- `c-admin-plugin-pages.12` @admin #admin: open the setup wizard step list -> the space type is called "Feed", not "Social".
- `c-admin-plugin-pages.13` @space_admin #admin !refuse: test_editor (a front-end space admin with no WordPress capability) opens the wp-admin Spaces, Users and Extensions screens -> each refuses with "Sorry, you are not allowed to access this page." and the Jetonomy menu is absent; they manage their space from the front end instead.
- `c-admin-plugin-pages.14` @member #admin !refuse: bob opens /wp-admin/admin.php?page=jetonomy -> WordPress refuses with "Sorry, you are not allowed to access this page.".
- `c-admin-plugin-pages.15` @admin #admin +many: with 2000 topics open the Content list -> it pages at 20 per page with a total, loads under 2 seconds, and sorting by date works.
- `c-admin-plugin-pages.16` @admin #admin +dark: open Overview in a dark admin colour scheme -> cards and charts keep readable contrast and no raw white panels.
- `c-admin-plugin-pages.17` @admin #admin +offline: on Settings, edit a value and Save while the server is unreachable -> the save is a normal form post, so the browser reports the failure and Back returns to the form; once the server answers, Save stores the value and the screen shows 'Settings saved.'.
- `c-admin-plugin-pages.18` @admin #admin: open Settings > Permissions -> capability labels read 'Create topics', 'Edit own topics' etc. per Terminology, never 'posts'.
- `c-admin-plugin-pages.19` @admin #admin: open admin.php?page=jetonomy-pro-conversations while WPMediaVerse is active -> the dormant notice shows AND the browser tab title is 'Conversations', not blank ('‹ forums — WordPress').
- `c-admin-plugin-pages.20` @admin #admin: open Activity Log filtered to Anonymous Author Revealed -> the row never prints the real author's ID or login in the list (reveal is audited, identity kept out of the table).
- `c-admin-plugin-pages.21` @admin #admin +many: Spaces list with 50+ spaces -> a search box and sortable columns exist (currently 3 pages of 20, no search, no sort).
- `c-admin-plugin-pages.22` @admin #admin: Content and Spaces list column headers sort (date, replies) with the active sort shown.
- `c-admin-plugin-pages.23` @admin #admin: open the Activity Log row for Anonymous Author Revealed where the real author still exists -> the row names the revealed member as an audit record visible to admins only, and is not visible in any member-facing or exported surface (today it prints the display name as a profile link).

### C.admin.category-and-space-crud
**Covers:** includes/admin/ajax/class-categories-handler.php, includes/admin/ajax/class-spaces-handler.php, includes/admin/views/categories.php, includes/admin/views/spaces.php, includes/models/class-space.php, includes/models/class-category.php, includes/api/class-categories-controller.php, includes/class-space-purge.php, includes/models/class-model.php, pro:includes/class-space-relations.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an admin can create, edit, reorder, and delete categories and spaces. Deleting a non-empty category handles its spaces per contract (cascade or refuse). Space settings edits persist and MERGE with existing settings (editing one key does not drop others - this is a hard contract). The space form keeps visibility and join policy consistent: choosing Hidden forces Invite only, choosing Private turns Open into Approval required, each with a note. The last admin of a space cannot be removed or demoted from wp-admin. Deleting a space with more than 500 topics locks it and finishes in the background, and the screen says so; a database error stops the delete with the space intact. Archive and hand-over moves ownership to a valid heir, and the former owner stays as a moderator.

**Checks:**
- `c-admin-category-and-space-crud.1` @admin #admin: create a category named "Product Questions" -> it saves with the toast "Category created." (label follows Terminology) and appears on the community home.
- `c-admin-category-and-space-crud.2` @admin #admin #rest: rename and reorder categories by drag, then GET /categories -> the order persists after reload and REST returns the same order.
- `c-admin-category-and-space-crud.3` @admin #admin: delete a category that still has spaces -> it is refused (spaces remain) or the spaces are handled per the contract, and a category with sub-categories cannot be deleted from under them.
- `c-admin-category-and-space-crud.4` @admin #admin: create a Forum space "Gardening Tips" with an icon in a category -> toast "Space created.", it opens at /community/s/gardening-tips/ and shows in My Spaces.
- `c-admin-category-and-space-crud.5` @admin #admin: choose Hidden in the visibility field -> join policy switches to Invite only and the note "Hidden spaces must use Invite Only. Join policy switched." shows.
- `c-admin-category-and-space-crud.6` @admin #admin: choose Private while join policy is Open -> join policy switches to Approval required with the note "Private spaces need approval or an invite. Join policy switched to Approval Required.".
- `c-admin-category-and-space-crud.7` @admin #admin #rest: PATCH a space settings key via the admin edit, then GET /spaces/{id} -> the edited key changed and every other settings key kept its value (merge, not replace).
- `c-admin-category-and-space-crud.8` @admin #admin: demote or remove the only admin of Help Desk from wp-admin -> refused with a message, and the roster still has an admin.
- `c-admin-category-and-space-crud.9` @admin #admin +many: delete a space with more than 500 topics -> the screen says "This space is locked and its content is being deleted in the background.", the space stops accepting posts, and it is gone after the background job finishes.
- `c-admin-category-and-space-crud.10` @admin #admin +offline: delete a space while the database delete fails -> the delete stops with an error and the space and its topics are intact.
- `c-admin-category-and-space-crud.11` @admin #admin: archive and hand over 2025 Hackathon -> ownership moves to a valid heir, the former owner stays a moderator, and the space shows as archived.
- `c-admin-category-and-space-crud.12` @admin #admin +archived: open the archived 2025 Hackathon as a member -> topics are readable, the composer is absent, and a reply attempt returns 403 jetonomy_space_restricted.
- `c-admin-category-and-space-crud.13` @admin #admin +long: save Help Desk with its 288-character description -> the saved description is complete and the Spaces list row truncates it without breaking the table.
- `c-admin-category-and-space-crud.14` @admin #admin: save a category name that is already in use -> it saves with its own slug (for example product-questions-2), not an error and not overwriting the other.
- `c-admin-category-and-space-crud.15` @admin #admin +empty: open Local Chapters' five empty city spaces -> the admin Spaces list shows 0 topics, the front end says "No topics yet", and edit and delete both work.
- `c-admin-category-and-space-crud.16` @space_admin #admin !refuse: test_editor deletes Moderators' Room (not her space) -> refused with "Permission denied.".
- `c-admin-category-and-space-crud.17` @member #admin #rest !refuse: bob POSTs /spaces and /categories -> 403 jetonomy_forbidden for both.
- `c-admin-category-and-space-crud.18` @admin #admin: create a space titled "Q&A: Roadmap & Plans" -> the stored title has no `&amp;` and the Spaces list, page title and breadcrumb all show "Q&A: Roadmap & Plans".
- `c-admin-category-and-space-crud.19` @admin #admin +concurrent: two admins edit the same space settings in two tabs and save -> both edits persist (merge) and neither silently reverts the other.
- `c-admin-category-and-space-crud.20` @admin #admin #cli: after deleting a space check topics, members and the home counts -> no orphan topics, and `wp jetonomy space list` no longer lists it.
- `c-admin-category-and-space-crud.21` @admin #admin: save a category, then a space -> a success toast stays visible (not removed by the immediate reload or redirect), or the page shows the notice after reload.
- `c-admin-category-and-space-crud.22` @admin #admin +concurrent: save the space form from a stale second tab -> fields the second tab did not change keep the first tab's newer values, or the stale save is refused with a message.
- `c-admin-category-and-space-crud.23` @admin #rest !refuse: bob POSTs /categories and /spaces -> both return the same error code.
- `c-admin-category-and-space-crud.24` @admin #cli: after deleting a space, jt_space_members, jt_subscriptions and jt_read_status hold no rows for its id (251 orphan member rows exist for spaces 179, 306-555).
- `c-admin-category-and-space-crud.25` @admin #admin +many: Spaces list with 50+ spaces -> a search box exists.
- `c-admin-category-and-space-crud.26` @admin #cli: after deleting spaces 179, 306-555, 3061-3064 earlier, jt_space_members still holds 256 rows for spaces that no longer exist -> a one-off cleanup (or recount step) removes them and a count query returns 0.
- `c-admin-category-and-space-crud.27` @member #ui +archived: open a topic inside an archived space -> the topic is readable, no reply composer shows, and a reply POST returns 403 jetonomy_space_restricted (needs a topic in the archived 2025 Hackathon; the reseed left it empty).
- `c-admin-category-and-space-crud.28` @admin #admin: delete a space that has a sub-space -> the sub-space moves up to the deleted space's own parent (top level when it had none) and is not left pointing at a missing parent; setting a space as its own parent, or a parent that loops back to it, is refused.

### C.admin.user-management
**Covers:** includes/admin/ajax/class-users-handler.php, includes/admin/views/users.php, includes/trust/class-trust-levels.php, includes/permissions/class-capabilities.php, includes/trust/class-trust-evaluator.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an admin can search users, promote trust level, ban / unban, and see the correct state in the listing; bans prevent access to gated actions as expected. The dialog title follows the action (Ban User, Silence User, Ban IP address) and offers site-wide actions only (a space ban is made from that space's Members screen). "Ban IP address" exists only for site administrators: an IPv4 or IPv6 address is typed in (an invalid one, your own current address or one already banned is refused), and the ban is listed and liftable with the other restrictions (REST POST /moderation/ban with type=ip_ban and ip; WP-CLI --ip). Staff with only delegated user management cannot raise their own or another staff member's trust level; the Users screen opens with the Manage community users permission. Trust levels show their owner-set names.

**Checks:**
- `c-admin-user-management.1` @admin #admin: search the Users screen for "Grace" -> "Grace Oluwaseun Adeyemi-Whitfield" is listed with her full name wrapped in the row, no avatar placeholder broken.
- `c-admin-user-management.2` @admin #admin #rest: promote alice from trust level 1 to 3 -> toast "Trust level updated.", the listing shows level 3 and its owner-set name, and POST /admin/users/trust-level reports the change.
- `c-admin-user-management.3` @admin #admin: ban carlos site-wide -> the dialog is titled "Ban User", toast "User banned.", and the listing shows Banned.
- `c-admin-user-management.4` @admin #admin: unban carlos -> toast "Ban lifted." and the listing shows him active again.
- `c-admin-user-management.5` @admin #admin: silence finn from the dialog titled "Silence User" -> toast "User silenced." and the listing shows Silenced.
- `c-admin-user-management.6` @admin #admin: open "Ban IP address" and enter an IPv4 address -> toast "IP address banned." and the ban is listed with the other restrictions and liftable.
- `c-admin-user-management.7` @admin #admin #rest !refuse: ban an invalid address, your own current address, and then an already-banned address -> each is refused with a message, and REST POST /moderation/ban type=ip_ban returns a 4xx for each.
- `c-admin-user-management.8` @admin #cli !refuse: run `wp jetonomy mod ban --type=ip_ban --ip=127.0.0.1 --target=<bob> --issuer=1` -> refused as a loopback address with that reason, and no ban row is created.
- `c-admin-user-management.9` @admin #admin: open the ban dialog and look at the choices -> only site-wide actions are offered, and a space ban is made from that space's Members screen.
- `c-admin-user-management.10` @space_admin #admin !refuse: test_editor tries "Ban IP address" -> the option is absent for non-administrators, and POST /moderation/ban type=ip_ban returns 403.
- `c-admin-user-management.11` @moderator #admin !refuse: a staff member with only delegated user management raises their own trust level, then another staff member's -> both refused, and the Users screen still opens with the Manage community users permission.
- `c-admin-user-management.12` @space_banned #ui !refuse +banned: omar (banned from Off-Topic Lounge only) opens Off-Topic Lounge and tries to post -> refused with "You have been banned from this space and cannot take part in it." (REST jetonomy_user_space_banned), while posting in General Discussion still works.
- `c-admin-user-management.13` @silenced #ui #rest !refuse: finn tries to create a topic, reply and vote -> create and reply return 403 jetonomy_user_silenced, and vote is a disabled button titled "Your account is currently silenced and cannot post." (REST vote gives the same 403).
- `c-admin-user-management.14` @admin #rest: ban a member and GET /moderation/ban?type=global_ban -> the ban is listed with the issuer name, and a deleted issuer reads "[deleted]".
- `c-admin-user-management.15` @admin #admin +many: with 500 members open the Users screen -> it pages, the search still finds a member on page 5, and trust level filters work.
- `c-admin-user-management.16` @admin #admin +dark: open the Ban dialog in dark mode -> labels and buttons keep contrast.
- `c-admin-user-management.17` @member #rest !refuse: bob POSTs /admin/users/trust-level -> 403 jetonomy_forbidden and no level changes.
- `c-admin-user-management.18` @admin #admin: ban carlos and silence finn -> the Users listing shows a Banned / Silenced label on the row, not only a changed row action.
- `c-admin-user-management.19` @admin #admin: ban an IP address -> the Banned Users list shows the address and issuer and no unrelated member name; REST accepts ip_ban without user_id.
- `c-admin-user-management.20` @admin #cli: run `wp jetonomy mod ban --type=ip_ban --target=<id> --issuer=1` with no --ip -> refused, naming --ip; with `--ip=127.0.0.1` -> refused with a loopback/reserved message, not 'insert failed'; an unknown flag is rejected, never ignored.
- `c-admin-user-management.21` @admin #admin: change a trust level -> the success toast is visible after the page reloads.
- `c-admin-user-management.22` @admin #admin: lift a silence -> toast says 'Restriction lifted' wording that matches a silence, not 'Ban removed.'.

### C.admin.invite-links
**Covers:** includes/models/class-invite-link.php, templates/views/invite.php, includes/api/class-spaces-controller.php, templates/views/space-edit.php, templates/partials/space-header.php
**Verified:** 83f00772
**What to verify:** an admin can mint an invite link for a space (optionally with a use limit and an expiry date), copy it, and the link works through the full accept flow: opening it shows the space with a Join button, and the member is added only when they click Join (sign up or log in first if needed), landing in the space with correct membership. An expiry date means the link works until the end of that day in the site's timezone; an unreadable or past date is refused, and the invite list shows the date in the site's format. A link past its limit or date is refused, and two people using the last use at the same moment cannot exceed the limit. The invite and join-request lists are paginated. A request the member withdrew leaves the list.

**Checks:**
- `c-admin-invite-links.1` @admin #admin #ui: mint an invite link for Customer Advisory Board with a limit of 2 and tomorrow's expiry -> toast "Invite link generated." and the link shows in the invite list with the date in the site's format.
- `c-admin-invite-links.2` @guest #ui: open the invite link logged out -> an invitation card names the space and its description with a "Log in to accept invite" link that returns to the invite, and no membership is created yet.
- `c-admin-invite-links.3` @member #ui: bob opens the invite link and clicks Join -> he lands in the space as a member and the use count goes to 1.
- `c-admin-invite-links.4` @member #ui: open the link and do not click Join -> bob is not a member (a link visit alone adds nobody).
- `c-admin-invite-links.5` @member_b #ui !refuse: alice opens a link whose limit is reached -> "This invite link has expired or reached its usage limit." and she is not added.
- `c-admin-invite-links.6` @member_b #ui +concurrent: two people click Join on the last use at the same moment -> exactly one is added and the other gets the limit message.
- `c-admin-invite-links.7` @admin #admin !refuse: enter an expiry date in the past, then an unreadable date -> refused with "Choose an expiry date in the future." and nothing is saved.
- `c-admin-invite-links.8` @admin #ui: set expiry to today and open the link in the evening site time -> it still works until the end of that day in the site's timezone and fails the next morning.
- `c-admin-invite-links.9` @admin #admin: revoke a link -> toast "Invite link revoked.", the link then returns "Invalid invite link." or the expired message.
- `c-admin-invite-links.10` @space_admin #rest: bob (space admin of Partner Program) GETs /spaces/{id}/invites -> he sees his space's links, and test_editor GET for Partner Program returns 403.
- `c-admin-invite-links.11` @member #rest !refuse: bob POSTs /spaces/{id}/invite on Customer Advisory Board where he is not a member -> 403 jetonomy_forbidden (private spaces are listed publicly, so the refusal reveals nothing), nothing is created.
- `c-admin-invite-links.12` @admin #admin +many: create 60 invite links and 40 join requests -> the front-end Members tab pages the links with a total, and the 40 join requests page in wp-admin.
- `c-admin-invite-links.13` @member #ui: bob requests to join Insiders / Beta, then withdraws -> the request leaves the admin's join-request list and the staff "new join request" notice goes away.
- `c-admin-invite-links.14` @member #ui +deleted: open an invite link after its space was deleted -> the page says "Invalid invite link.", no PHP error, and no invite or join-request rows remain for that space.
- `c-admin-invite-links.15` @member #ui +390: open the invite page at 390px -> the Join button is at least 40px tall and the space description wraps.
- `c-admin-invite-links.16` @guest #ui: open the invite link logged out -> the page shows the space name, description and either a Join button that leads to login or the text of the login link, and says what the link is.
- `c-admin-invite-links.17` @member #rest !refuse: a non-member POSTs /spaces/{id}/invite for a hidden space and for a non-existent id -> both answer the same 404 jetonomy_not_found (a listed private space answers 403, see .11).
- `c-admin-invite-links.18` @admin #admin: delete a space that has an invite -> its invite rows and join requests are removed with it (no orphan rows).
- `c-admin-invite-links.19` @admin #admin: space Members tab -> a Join Requests list with approve/deny and a total exists in wp-admin as well as the front end.
- `c-admin-invite-links.20` @guest #ui +390: open a valid invite link logged out at 390px -> the invitation card fills the width, is centred and the Log in button is reachable.

### C.admin.recount
**Covers:** includes/class-recount.php, includes/class-recount-backfill.php, includes/admin/ajax/class-settings-handler.php, includes/api/class-admin-controller.php, includes/class-cache.php, assets/js/admin-settings.js
**Verified:** 64212bb5
**What to verify:** running the manual recount tool fixes any drift in denormalized counters (member_count, reply_count, vote_score); a deliberately corrupted counter is restored to the correct value.

**Checks:**
- `c-admin-recount.1` @admin #admin: set Help Desk member_count to 999 and a topic reply_count to 99 in the database, then click "Recount counters" on the Overview -> a notice reads "Counters rebuilt from your content." and the values return to the true counts.
- `c-admin-recount.2` @admin #rest: POST /admin/recount with type=votes after corrupting vote_score on "Dark mode for the community on phones" -> 200 with status ok and the score equals the sum of votes.
- `c-admin-recount.3` @admin #rest: POST /admin/recount with type=spaces -> the response lists space_post_counts and space_member_counts, and a type outside all/posts/spaces/votes/users is refused by the enum.
- `c-admin-recount.4` @admin #cli: corrupt a user's post_count then run `wp jetonomy recount --type=users` -> the command prints the rows it updated and the profile count is correct.
- `c-admin-recount.5` @guest #ui card:10415367229: load the guest home, corrupt the member count, click Recount, reload the guest home -> the welcome strip's member and topic counts equal the true totals straight away, not an hour later.
- `c-admin-recount.6` @admin #admin #ui card:10415367229: load demo data from the setup/overview card, then open the guest home in a private window -> the member and topic counts include the demo content immediately.
- `c-admin-recount.7` @admin #admin #ui card:10415367229: run a bbPress import to the end, then open the guest home logged out -> member and topic counts match Jetonomy's Overview totals with no wait.
- `c-admin-recount.8` @admin #ui +old-data: after upgrading a site that has drifted counters, run recount -> topic reply counts, space post counts and profile counts all match, and "1 reply" is singular for one.
- `c-admin-recount.9` @member #rest !refuse: POST /admin/recount as bob -> 403 jetonomy_forbidden and nothing changes.
- `c-admin-recount.10` @moderator #admin !refuse: test_moderator opens the Overview -> the "Recount counters" button is absent (manage_options only) and POST /admin/recount returns 403.
- `c-admin-recount.11` @guest #rest !refuse: POST /admin/recount logged out -> 401.
- `c-admin-recount.12` @admin #ui +many: with 2000 topics click Recount -> it finishes without a timeout, and the button shows a progress state and is disabled while running.
- `c-admin-recount.13` @admin #ui +concurrent: click Recount twice quickly -> the second click is ignored or queued and the results stay correct.
- `c-admin-recount.14` @admin #admin +offline: click Recount with the request failing -> the button recovers and an error message shows, counters unchanged.
- `c-admin-recount.15` @admin #ui: write a new topic as bob, do not recount, and reload the guest home -> the counts update within the hour (cache), and the Overview total is already right (negative case: recount is not needed for normal writes).
- `c-admin-recount.16` @admin #rest !refuse: POST /admin/recount with type=bogus -> 400 rest_invalid_param, no counters touched.
- `c-admin-recount.17` @admin #cli: run `wp jetonomy recount --type=users` -> prints rows updated; the positional form `wp jetonomy recount users` is rejected with usage help (checks should use --type).
- `c-admin-recount.19` @admin #admin +offline: recount request fails -> the error dialog names the cause (offline / server error) rather than 'Could not get a valid response from the server.'.

### C.admin.akismet-staff-bypass
**Covers:** includes/moderation/class-akismet.php, includes/api/class-base-controller.php
**Verified:** 75301a65
**What to verify:** staff-role submissions are NOT routed through Akismet; they post instantly with no spam-check lag. Staff includes site admins, space admins and moderators, and site moderators (the Moderate capability). This is a customer-experience contract, not an optimization.

**Checks:**
- `c-admin-akismet-staff-bypass.1` @admin #ui +settings-off: with Akismet active and a forced-spam test string, post a topic as admin -> it publishes at once and Akismet logs no check for it.
- `c-admin-akismet-staff-bypass.2` @space_admin #ui: test_editor posts a reply in Help Desk -> published instantly, no Akismet call.
- `c-admin-akismet-staff-bypass.3` @moderator #rest: test_moderator POSTs a reply in Bug Reports -> 201 and published, no Akismet check.
- `c-admin-akismet-staff-bypass.4` @moderator #ui: test_moderator posts in General Discussion, a space where he has only the site Moderate capability -> published instantly with no spam lag.
- `c-admin-akismet-staff-bypass.5` @member #ui !refuse: bob posts the Akismet test string "akismet-guaranteed-spam@example.com" as a topic -> it is held or marked spam and he sees it awaiting moderation, not public.
- `c-admin-akismet-staff-bypass.6` @member_b #ui: alice posts a normal reply -> published and Akismet is called once.
- `c-admin-akismet-staff-bypass.7` @moderator #ui: test_moderator (site Editor holding the Moderate capability) posts the Akismet spam test string in Help Desk, where he is not a space moderator -> it still publishes instantly, because the Moderate capability makes him staff in every space he can read.
- `c-admin-akismet-staff-bypass.8` @admin #ui +settings-off: deactivate Akismet and post as bob -> posts publish normally with no error or notice.
- `c-admin-akismet-staff-bypass.9` @member #rest +offline: make the Akismet API time out and post as bob -> the post still publishes (fails open) and no fatal appears.
- `c-admin-akismet-staff-bypass.10` @admin #cli: before running the journey check `wp option get wordpress_api_key` is non-empty, otherwise mark checks 1-7 and 9 untested (no key = Akismet::is_available() false).
- `c-admin-akismet-staff-bypass.11` @admin #ui: Settings > Anti-Spam with Akismet active but no key -> the page says Akismet is active but not protecting the community because no key is set.

### C.notifications
**Covers:** includes/notifications/class-notifier.php, includes/models/class-notification.php, includes/api/class-notifications-controller.php, templates/views/notifications.php, includes/notifications/class-community-notification-contract.php, includes/api/class-updates-controller.php, pro:includes/extensions/web-push/*.php, includes/functions.php, assets/js/jetonomy-rest.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** every notification-triggering event (reply to subscribed thread, mention, flag resolution, badge award, admin announcement) produces a new notification for the correct recipient; the bell badge count updates; clicking through takes the user to the correct destination. Join requests and their answers, reactions and reviewed reports also produce notifications that read correctly. Withdrawing a join request removes the staff's "new join request" notices. Unread count endpoint is accurate; mark-all-read zeroes it out. Names in notifications follow the Name display setting.

**Checks:**
- `c-notifications.1` @member_b #ui: alice replies to bob's topic -> bob's bell count goes up by 1 and /community/notifications/ lists "Alice replied to your topic".
- `c-notifications.2` @member_c #ui: maya @-mentions bob in a reply -> bob gets one notification "mentioned you" under the Mentions filter.
- `c-notifications.3` @member #ui: click a notification -> it opens the reply on the right page of the thread (for the 45-reply Dark mode topic, page 2) and the item turns read.
- `c-notifications.4` @member #ui #rest: open /community/notifications/ with 3 unread and click "Mark all as read" -> every badge (bell, host bar, tab) drops to 0 without reload, and GET /notifications/unread-count returns 0.
- `c-notifications.5` @member #rest: PATCH /notifications/{id} read=true on one item -> unread-count falls by exactly 1, and bulk with ids marks only those.
- `c-notifications.6` @member #ui +empty: a new member with no notifications opens the page -> "No notifications yet" with the explanation line, and Unread filter shows "Nothing new since you last checked. Switch to All to see your history.".
- `c-notifications.7` @member #ui +many: with 80 notifications open the page -> it pages, "Select all on page" selects only the page, and Delete removes the selected items.
- `c-notifications.8` @moderator #ui #email: dismiss a report -> the reporter gets "Your report was reviewed. Thanks for helping keep the community healthy." in the bell, once.
- `c-notifications.9` @member #ui: carlos requests to join Insiders / Beta -> that space's admin sees "<Name> requested to join <Space>" (join request), and when carlos withdraws the notice disappears.
- `c-notifications.10` @member #ui: a space admin approves a join request -> carlos gets "Your request to join Insiders / Beta was approved", and for a decline "Your request to join ... was not approved".
- `c-notifications.11` @member #ui: earn a badge or trust promotion -> a "You earned a badge" or "Congratulations! You have been promoted to ..." notice appears once.
- `c-notifications.12` @member_b #ui: a moderator removes alice's reply -> alice gets "Your reply was removed by a moderator" using the owner's terminology, once.
- `c-notifications.13` @member #ui +private: bob follows a thread in Partner Program, then loses membership -> notices about it disappear from the list, bell and host bell, and are not re-created for new replies.
- `c-notifications.14` @member #ui !refuse: a reply in Customer Advisory Board (bob not a member) mentions bob -> no notification is created for bob and the topic title is never revealed.
- `c-notifications.15` @member_b #ui +deleted: a notifying reply is deleted by its author -> the notification is hidden or reads as removed, with no broken link.
- `c-notifications.16` @member #ui: set Name display to username in settings, then trigger a reply notice -> the notice names "alice", and with full name it names "Alice ..." (follows the Name display setting).
- `c-notifications.17` @member_c #ui: an anonymous-posted reply notifies the topic owner -> it reads "Someone" or "Anonymous", never maya's name.
- `c-notifications.18` @member #ui +long: Grace (long display name) replies -> the notification row wraps her full name and does not overflow at 390.
- `c-notifications.19` @member #ui +concurrent: open the page in two tabs and mark all read in one -> the second tab's bell updates when that tab regains focus or becomes visible (there is no timer polling by design).
- `c-notifications.20` @member #ui: a reply is posted to a big thread (200 followers) -> every follower gets exactly one notification, delivered in batches, no duplicates.
- `c-notifications.21` @guest #rest !refuse: GET /notifications and /notifications/unread-count logged out -> 401.
- `c-notifications.22` @member_b #rest !refuse: PATCH bob's notification id as alice -> 404 not found (not 403) and bob's item stays unread.
- `c-notifications.23` @admin #admin: send an admin announcement -> every member gets one "admin announcement" notice and the bell increments.
- `c-notifications.24` @member #ui +dark: open the page in dark mode -> the unread row tint is dark (average channel under 100) and text stays readable.
- `c-notifications.25` @member #ui +offline: click "Mark all as read" while offline -> the badge rolls back to its old count and an error shows.
- `c-notifications.26` @member #ui +long: a notice about a topic with a long title cuts the title at a word boundary with an ellipsis, not mid-word with a closing quote ("...the route br").
- `c-notifications.27` @member #ui: the header bell and the Alerts tab pick up a mark-all-read done in another tab or device when the page regains focus or becomes visible (no timer polling by design).
- `c-notifications.28` @member_b #rest !refuse: PATCH a notification id that belongs to someone else and a nonexistent id -> the same 404, never a 403 for the existing one.

### C.notifications.email
**Covers:** includes/adapters/class-wp-mail-adapter.php, templates/emails/*.php, includes/notifications/class-notifier.php, includes/notifications/class-verification-reminder.php, pro:includes/extensions/email-digest/*.php, pro:includes/extensions/reply-by-email/*.php, pro:includes/extensions/email-digest/class-extension.php
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** notifications that should email (per user preferences) actually arrive in Mailpit / configured trap. Respect the user's digest/instant preference. Unsubscribe links in email work. A member is emailed the answer to a join request (approved or declined), headed "Your join request was answered" (the Settings > Emails name), never "Join Request Result". Mention emails use the owner's saved subject and body. When a moderator removes or approves a member's content, the member's email names it with the owner's terminology ("Your topic was removed by a moderator") and its button reads "Open the Community", never "Review in Mod Queue" (staff flag emails keep that button). The confirmation email's subject and intro follow Settings > Emails, with the link always added.

**Checks:**
- `c-notifications-email.1` @member_b #email: alice replies to bob's topic with instant emails on -> Mailpit shows "[site] Alice replied to your topic" to bob once, with a View Topic button.
- `c-notifications-email.2` @member #email +settings-off: bob ticks "Pause all email notifications" and a reply arrives -> no email is sent, the bell notice still appears.
- `c-notifications-email.3` @member #email: bob picks daily or weekly digest on his profile edit page -> a reply to his topic and a new topic in a followed space arrive only in the digest (no instant email), a reply in a topic he follows still emails at once, and a member on the site default gets both.
- `c-notifications-email.4` @member #email: click the digest unsubscribe link logged out -> a confirmation page shows and the next digest is not sent.
- `c-notifications-email.5` @member #email: carlos is approved to join Insiders / Beta -> he receives "Your join request was answered" (never "Join Request Result") with the approval text.
- `c-notifications-email.6` @member #email: a request is declined -> the same subject "Your join request was answered" with the decline text.
- `c-notifications-email.7` @member_c #email: maya mentions bob -> the email uses the owner's saved subject and body for mention emails, and "{actor_display_name}" is filled with her name.
- `c-notifications-email.8` @moderator #email: remove bob's topic -> bob's email says "Your topic was removed by a moderator" and its button reads "Open the Community", never "Review in Mod Queue".
- `c-notifications-email.9` @moderator #email: member_b flags a topic -> the staff email keeps the "Review in Mod Queue" button and goes to moderators only.
- `c-notifications-email.10` @guest #email: register a new account -> the confirmation email subject and intro follow Settings > Emails, with the confirm link always present, and clicking it activates the account.
- `c-notifications-email.11` @member #email: alice replies once to bob's topic that bob also follows and is mentioned in -> Mailpit shows exactly one email to bob for that reply, never two.
- `c-notifications-email.12` @member #email +private: a reply in Customer Advisory Board mentions bob (non-member) -> no email is sent.
- `c-notifications-email.13` @member #email +rtl: Omar's Arabic reply notifies bob -> the email body renders the Arabic text correctly and the footer remains readable.
- `c-notifications-email.14` @admin #email: with Learnomy active and a distinct sender configured, send a notification -> From is the configured sender, and a plain wp_mail() in the same request keeps its own sender.
- `c-notifications-email.15` @member #email +long: Grace's 159-character topic title in a reply notice -> subject and body show it whole without breaking the layout.
- `c-notifications-email.16` @silenced #email !refuse: finn tries to trigger a mention email -> no content is created, so no email is sent.
- `c-notifications-email.17` @admin #admin: open Settings > Emails > preview for each type -> every email type renders in the preview and "Send test email" delivers one to Mailpit.
- `c-notifications-email.18` @member #email +dark: view the email in a dark-mode mail client -> the accent button and text keep contrast.
- `c-notifications-email.19` @member #ui #email: after unsubscribing from the digest, the profile settings page offers a way to pick a digest frequency again (the unsubscribe page says to re-enable it there).
- `c-notifications-email.20` @moderator #email: a member flags a topic -> the staff alert subject says a report needs review and does not read 'A moderator reviewed your content' or tell the staff to reply to a moderator.
- `c-notifications-email.21` @member #email +long: a reply to a topic with a title over 50 characters -> the intro sentence names the title with an ellipsis or whole, never cut mid-word.
- `c-notifications-email.22` @member #email !refuse: a member who lost access to a private space gets no email for a reply there, and another member's private topic never appears in a digest.

### C.search
**Covers:** templates/views/search.php, includes/search/class-fulltext-search.php, includes/search/class-search-indexer.php, includes/api/class-search-controller.php, includes/adapters/interface-search-adapter.php, includes/adapters/interface-search-query-adapter.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** the full search page handles query, tag filter, date filter, author filter, and sort (relevance / newest / votes) without breakage; relevance sort actually ranks by relevance, not just recency, and votes sort breaks ties by reply count. Private replies and unreadable spaces never appear in results, and tag results count only readable topics.

**Checks:**
- `c-search.1` @guest #ui: search "dark mode" at /community/search/ -> results with the count and highlighted snippets, ordered by relevance ("Dark mode toggle in user preferences" first).
- `c-search.2` @member #ui: filter by tag, by author "Grace", and by date range together -> only matching topics show and each filter is shown as active and removable.
- `c-search.3` @member #ui: sort Relevance, then Newest, then Votes -> relevance does not just equal recency, newest is by created date, and votes ties break by reply count.
- `c-search.4` @member #rest: GET /search?q=dark%20mode&sort=votes -> results ordered by vote score with the same ids as the page.
- `c-search.5` @guest #ui !refuse: search a phrase that exists only in Customer Advisory Board and Moderators' Room -> "No results for "..." " and no space name, title or count of those spaces appears.
- `c-search.6` @guest #rest !refuse: GET /search for the same phrase and with space_id of a private space that is publicly listed -> 403 jetonomy_forbidden (the same refusal as /spaces/{id}/posts), and with a hidden space -> 404 identical to a missing id; no result rows either way.
- `c-search.7` @member #ui !refuse: bob searches a phrase that appears only in a private reply -> the topic is not listed through that reply.
- `c-search.8` @member #ui: open a tag page and read the tag count -> the count equals the readable topics on the page, not including private ones.
- `c-search.9` @member #ui !refuse: q=a shows "Search query must be at least 2 characters."; an ordinary no-result search shows "Try different or fewer keywords, or browse the community." and no length hint.
- `c-search.10` @member #ui +empty: open /community/search/ with no query -> "Enter a search term above to find discussions, spaces, and tags.".
- `c-search.11` @member #ui: filter-only search (a tag with no text) -> returns that tag's topics instead of an error.
- `c-search.12` @member #ui +rtl: search for an Arabic word -> Omar's Arabic topic is found and the snippet renders right-to-left.
- `c-search.13` @member #ui +long: search a word inside Grace's 159-character title -> the result card wraps the title and the unbroken URL does not widen the page.
- `c-search.14` @member #ui +old-data: create a new topic and search for its unique word immediately -> it is found (the full-text index is current), not just after OPTIMIZE TABLE.
- `c-search.15` @member #ui +many: search "the" style common term with 200 results -> results page with Next, and a page-two link opens page 2.
- `c-search.16` @member #ui +deleted: delete a topic and search its title -> it no longer appears.
- `c-search.17` @member #ui +390: search at 390px -> input, filters and cards stack and nothing scrolls sideways.
- `c-search.18` @member #ui +dark: search in dark mode -> highlighted matches keep contrast.
- `c-search.19` @guest #ui +empty: a long query with no hits shows "No results for ..." without the 2-character hint.
- `c-search.20` @member #ui +many: the "N results" header equals the sum of the REST /search?type=all `totals` (posts + spaces + tags) on every page.

### C.cron
**Covers:** includes/class-cron.php, includes/class-queue.php
**Verified:** 64212bb5
**What to verify:** after plugin activation, every expected cron event (digest, reminders, reputation recalc, etc.) is scheduled; none are orphaned after deactivation; cron actually executes when triggered manually.

---

**Checks:**
- `c-cron.1` @admin #cli: after activating Jetonomy run `wp action-scheduler list --group=jetonomy --status=pending` -> pending actions exist for jetonomy_trust_evaluation, jetonomy_cleanup_expired, jetonomy_prune_activity, jetonomy_cleanup_notifications, jetonomy_verification_reminder, jetonomy_reconcile_rosters and jetonomy_cleanup_media.
- `c-cron.2` @admin #cli: with Pro active run `wp cron event list` and `wp action-scheduler list --status=pending` -> Pro's digest, badge and licence jobs are each scheduled once (the AI batch review only while an AI review feature is on), none duplicated after a second activation.
- `c-cron.3` @admin #cli: deactivate Jetonomy and re-run the list -> no pending Jetonomy action remains and the Pro jobs go with Pro, with no "class not found" fatal.
- `c-cron.4` @admin #cli: run each recurring hook once with `wp action-scheduler run` -> every action completes with status complete and debug.log gains no error.
- `c-cron.5` @admin #cli: activate, deactivate and reactivate in one command -> the events are rescheduled and `/community/messages/` still resolves.
- `c-cron.6` @admin #cli +old-data: run jetonomy_cleanup_expired with an expired temporary ban and an expired invite -> the ban is lifted; the invite row is kept so its link says "expired" rather than "invalid".
- `c-cron.7` @admin #cli: run jetonomy_reconcile_rosters with a lapsed membership rule -> the person's roster row is removed.
- `c-cron.8` @admin #cli +many: seed 5000 users and run the trust evaluation -> it runs in batches (cursor option updates) and each run stays under the time limit.
- `c-cron.9` @member #ui: schedule a topic for 5 minutes ahead and run jetonomy_publish_scheduled -> the topic goes live at its publish time, not early, and followers are notified once.
- `c-cron.10` @admin #cli +settings-off: with Action Scheduler missing the plugin shows a notice and the site still loads, no WSOD.
- `c-cron.11` @admin #cli: run `wp action-scheduler action list --group=jetonomy --status=failed` after a deactivate/reactivate of Pro -> no failed 'no callbacks are registered' rows are added for jetonomy_pro_* hooks (older rows from 2026-10-10 show Pro jobs failing while Pro was off).
- `c-cron.12` @admin #cli +old-data: run jetonomy_cleanup_expired with an expired invite link -> the expired jt_invite_links row is removed, or the check says expired invites are kept by design.
**Depth note:** cron is not a role-gated surface; the person who runs it is the site admin through #cli, so there is no refusal case.

### C.cli.qa-actions
**Covers:** includes/cli/**, includes/class-cli.php
**Verified:** pending - new journey, not walked yet
**What to verify:** Site owner/QA runs `wp jetonomy qa-actions` and expects every phase (including Phase 4 journeys and Phase 5 refusal paths RF1-RF10) to report only PASS, with a phase that ran zero checks failing loudly. Then run `wp jetonomy scenario run` for each scenario (voting thread, pending join request, flags for moderation, notification sweep, membership approval) and check the scenario result lists each step and cleans up. Spot-check one subject command per root (space, post, reply, vote, flag, member, mod, tag, category, privacy, notification, config, user) and confirm it returns the same data the member and moderator screens show. CLI has no viewport, so check 1440 and 390 only on a page the command created (for example a seeded post).

**Checks:**
- `c-cli-qa-actions.1` @admin #cli: run `wp jetonomy qa-actions` on the seeded site -> every phase (including Phase 4 journeys and Phase 5 refusal paths RF1-RF10) prints no FAIL line and the exit code is 0 (SKIP is allowed for PV1 without a hidden-space fixture and BB1 without bbPress content); run it with no other walker writing to the site.
- `c-cli-qa-actions.2` @admin #cli: break one guard on a scratch copy so a phase runs zero checks -> the run fails loudly naming the phase, not a silent PASS.
- `c-cli-qa-actions.3` @admin #cli: run `wp jetonomy scenario run multi-user-voting-thread --cleanup` -> the result lists each step and the data it made is removed afterwards (without --cleanup it is left behind).
- `c-cli-qa-actions.4` @admin #cli: run the pending join request, flags for moderation, notification sweep and membership approval scenarios in turn -> each lists its steps, each passes, and each cleans up (the space is deleted, not archived).
- `c-cli-qa-actions.5` @admin #cli: run `wp jetonomy space list --category=<id>` for each category (--category is required), then open /community/ -> the same spaces and counts appear in the CLI and the home page.
- `c-cli-qa-actions.6` @admin #cli: `wp jetonomy post get`, `member`, `mod`, `tag`, `category`, `privacy scan`, `notification`, `config`, `user` each run one read command -> each returns the same data the member and moderator screens show (`vote` and `flag` are write-only).
- `c-cli-qa-actions.7` @admin #cli: `wp jetonomy notification unread-count --user=bob` after a reply -> equals the bell badge bob sees in the browser.
- `c-cli-qa-actions.8` @admin #cli !refuse: `wp jetonomy mod ban --user=<admin>` and a ban of yourself -> refused with "Administrators cannot be restricted." or "You cannot restrict your own account.".
- `c-cli-qa-actions.9` @admin #cli !refuse: `wp jetonomy post create` into the archived 2025 Hackathon space -> refused with jetonomy_space_restricted and no row is added.
- `c-cli-qa-actions.10` @admin #cli: create a seeded post with the CLI, then open it at 1440 and 390 -> it renders on both widths with no overflow.
- `c-cli-qa-actions.11` @admin #cli +many: run `wp jetonomy post get <id>` and `wp jetonomy recount` on a 2000-topic seed (bin/seed-scale.php) -> each returns in under 3 seconds and the recounted totals match the Overview.
- `c-cli-qa-actions.12` @admin #cli: run `wp jetonomy recount` -> prints the rows updated per counter and the guest home counts match straight away.
- `c-cli-qa-actions.13` @admin #cli !refuse: `wp jetonomy reply create` into an archived or closed topic, and `wp jetonomy post create` as a silenced or site-banned user -> refused with the same code the REST route gives and no row is added.
- `c-cli-qa-actions.14` @admin #cli: `wp jetonomy reply create --author=alice` on bob's topic -> bob gets the reply notification, same as a reply made in the browser.
- `c-cli-qa-actions.15` @guest #ui: compare the home page topic total with the sum of the topics listed under the visible spaces -> the totals agree, or private and hidden space topics are left out of the guest total; each category grid caps its cards with a "View all N spaces" link, so the visible cards can sum to less than the total.
**Depth note:** the CLI is admin-only by design (WP-CLI runs as the server user), so there is no member-versus-owner pair.

### C.admin.setup-wizard
**Covers:** includes/admin/ajax/class-setup-handler.php, includes/admin/ajax/class-demo-content.php, includes/admin/ajax/class-demo-seeder.php, assets/js/setup-wizard.js, assets/css/setup-wizard.css
**Verified:** pending - new journey, not walked yet
**What to verify:** A site admin on a fresh install walks the setup wizard end to end: pick the community base, create the first category and space, optionally seed demo content, and finish on a working /community/ page. A non-admin cannot reach the wizard or its AJAX handlers. Demo content can be removed again without leaving orphans. Check the wizard at 1440 and 390 widths (steps stack, buttons reachable, no horizontal scroll).

**Depth note:** remove-demo-data checks (c-admin-setup-wizard.6, .7) wipe the shared community, so they run on the disposable Docker site, never on the shared walk site.

**Checks:**
- `c-admin-setup-wizard.1` @admin #admin: on a fresh install open the wizard -> it shows "Welcome to ..." with the Community URL Slug field, the Default Community Type choices (Forum, Q&A, Ideas, Feed) and a Next button.
- `c-admin-setup-wizard.2` @admin #admin: submit the first-category step with the category name empty -> refused with "Please fill in the category and space name." and nothing is created.
- `c-admin-setup-wizard.3` @admin #admin: create "Product Questions" and the space "Getting Started" -> the final screen reads "Your community is ready!" with Visit Community and Go to Dashboard buttons.
- `c-admin-setup-wizard.4` @admin #ui: click Visit Community -> /community/ renders with the new category and space, and the guest home also lists them.
- `c-admin-setup-wizard.5` @admin #admin: choose "Import the demo community instead" -> toast "Demo data imported.", the guest home counts include the demo content straight away, and the demo members have avatars and bios.
- `c-admin-setup-wizard.6` @admin #admin: click remove demo data -> "All demo data has been removed.", and no demo topic, reply, vote, bookmark, subscription or notification remains.
- `c-admin-setup-wizard.7` @admin #admin +old-data: remove demo data on a site that also has real topics -> only demo rows go, real rows and counts are unchanged.
- `c-admin-setup-wizard.8` @member #admin #rest !refuse: bob posts to admin-ajax jetonomy_setup_save and jetonomy_setup_create_sample -> both refused (403, -1 from the nonce check) with nothing created, and the /wp-admin wizard page refuses him.
- `c-admin-setup-wizard.9` @guest #admin !refuse: call jetonomy_setup_save logged out -> admin-ajax returns 0 or -1 and nothing changes.
- `c-admin-setup-wizard.10` @admin #admin !refuse: post jetonomy_setup_save with a bad nonce -> request is refused and no option is saved.
- `c-admin-setup-wizard.11` @admin #admin +390: walk the wizard at 390px -> steps stack, buttons are at least 40px tall, and no horizontal scroll.
- `c-admin-setup-wizard.12` @admin #admin +long: enter a 150-character category name and a space description of 288 characters -> both save in full and the final screen wraps them.
- `c-admin-setup-wizard.13` @admin #admin +offline: lose the connection on the create step -> an error shows, the button re-enables, and retrying creates the category once (no duplicates).
- `c-admin-setup-wizard.14` @admin #admin +rtl: set the site to Arabic and walk the wizard -> layout mirrors and progress steps run right to left.
- `c-admin-setup-wizard.15` @admin #admin +dark: walk the wizard in a dark admin scheme -> inputs, cards and buttons keep contrast.
- `c-admin-setup-wizard.16` @admin #admin +rtl: in the Arabic admin the URL preview under the slug field reads http://forums.local/community/ left to right with the slash at the end.
- `c-admin-setup-wizard.17` @admin #admin !refuse: run the wizard twice with the same category and space names -> the second run says the names already exist or merges, and does not add a duplicate 'Getting Started' category (a second 'Getting Started' space was created beside the seeded one).
- `c-admin-setup-wizard.18` @admin #admin +390: the floating Messages button does not cover the Create Space or Next buttons at 390px.

### C.admin.import
**Covers:** includes/import/*.php, includes/admin/ajax/class-import-handler.php, includes/models/class-import-map.php
**Verified:** pending - new journey, not walked yet
**What to verify:** Site admin imports a seeded bbPress, Asgaros or wpForo forum from the Import admin screen, sees progress, and finds categories, spaces, topics, replies and authors on the front end with correct counts. Re-running the import must not duplicate rows (import map), and a non-admin cannot start it. Check the import screen at 1440 and 390 and a few imported threads as a guest and a member.

**Checks:**
- `c-admin-import.1` @admin #admin: open the Import screen -> it offers bbPress, Asgaros and wpForo sources, and a source whose plugin is absent is marked unavailable, not selectable.
- `c-admin-import.2` @admin #admin: start a bbPress import of a seeded forum with 120 topics -> the step list runs Forums, Members, Topics, Replies, Profiles, Finalize with a moving progress bar and ends on "Import complete!".
- `c-admin-import.3` @admin #ui: after the import compare category, space, topic and reply totals with the source -> they match, and the guest home member and topic counts match straight away.
- `c-admin-import.4` @guest #ui: open three imported threads logged out -> topics, replies and original authors show, dates keep the source order, and a closed source topic shows the "Closed" badge.
- `c-admin-import.5` @admin #admin: run the same import a second time -> no duplicate categories, spaces, topics or replies (import map), totals unchanged.
- `c-admin-import.6` @admin #admin: import a source with private bbPress forums -> they arrive as private spaces and members are placed per "Adding members to private and group spaces...".
- `c-admin-import.7` @member #admin !refuse: bob posts jetonomy_import_batch to admin-ajax -> refused (403, -1 from the nonce check) and nothing is imported.
- `c-admin-import.8` @admin #admin !refuse: post jetonomy_import_batch with source=bogus -> "Unknown import source." and no data is written.
- `c-admin-import.9` @admin #admin +many: import 2000 topics -> the run proceeds in batches without a timeout and the progress bar never freezes, and reply counts per topic are right.
- `c-admin-import.10` @admin #admin +old-data: import wpForo topics that were held for moderation -> they arrive in Awaiting approval, not published.
- `c-admin-import.11` @admin #admin +offline: drop the connection mid-run and reload -> the screen shows where it stopped, and resuming does not duplicate rows.
- `c-admin-import.12` @admin #admin +390: open the Import screen at 390px and 1440px -> steps stack and the buttons are reachable with no horizontal scroll.
- `c-admin-import.13` @member #ui +rtl: open an imported topic with Arabic text -> it renders right to left.
- `c-admin-import.14` @admin #admin: look for imported HTML with `&amp;` in titles -> no row in spaces, categories or topics has an entity-encoded title.
- `c-admin-import.15` @admin #admin: look at the step list during a run with a private forum -> a Members step is shown before Topics.
- `c-admin-import.16` @admin #admin: after a re-import, the "imported before (N)" count equals the "N already imported" result and the Last imported line.
- `c-admin-import.17` @member #ui +rtl: an imported or posted Arabic topic is right-aligned (dir=auto on the post body) on an LTR site.
- `c-admin-import.18` @admin #admin: the imported category slug is readable (it is imported-bbpress-1792234737 here, a timestamp) and the category name is 'Imported from bbPress'.
- `c-admin-import.19` @admin #cli: delete an imported space with `Space::delete` / the admin Delete button -> its topics and replies go with it (here the 127 topics and 47 replies stayed behind after the space row was deleted).

### C.admin.blocks-shortcodes-widgets
**Covers:** includes/class-blocks.php, includes/class-shortcodes.php, includes/class-widgets.php, includes/widgets/*.php, assets/js/blocks-editor.js, assets/js/compose-topic-block.js, assets/css/blocks.css, assets/css/blocks-editor.css
**Verified:** pending - new journey, not walked yet
**What to verify:** Site admin inserts each Jetonomy block in the editor, each shortcode on a page, and each widget in a sidebar, then views them as a guest and as a member: they render the same content the community shows, honour private/hidden spaces (a guest never sees private space titles), and the compose-topic block posts a topic. The editor preview must match the front end. Check 1440 and 390 widths and dark mode.

**Checks:**
- `c-admin-blocks-shortcodes-widgets.1` @admin #block: insert each Jetonomy block (forum feed, trending, space list, leaderboard, navigation, user panel, login, compose topic) in the editor -> each shows its labelled placeholder in the editor and renders the community content on the published page.
- `c-admin-blocks-shortcodes-widgets.2` @admin #block: put [jetonomy_spaces], [jetonomy_recent_posts], [jetonomy_trending_posts], [jetonomy_leaderboard], [jetonomy_user_profile], [jetonomy_space_members] and [jetonomy_widget] on a page -> each renders on the front end for a member.
- `c-admin-blocks-shortcodes-widgets.3` @admin #admin: add each widget to a sidebar -> each saves, renders in the sidebar, and its settings persist.
- `c-admin-blocks-shortcodes-widgets.4` @guest #block !refuse: view the Spaces and Recent topics blocks logged out -> no private or hidden space title (Customer Advisory Board, Moderators' Room) and no topic from them appears.
- `c-admin-blocks-shortcodes-widgets.5` @member #block: view the same blocks as bob -> Partner Program (his private space) shows, Customer Advisory Board does not.
- `c-admin-blocks-shortcodes-widgets.6` @member #block: use the compose-topic block to post "Which phone browsers support dark mode best?" in Feature Requests -> the topic is created and appears in the space, and a guest sees a login prompt in the block instead.
- `c-admin-blocks-shortcodes-widgets.7` @silenced #block !refuse: finn uses the compose-topic block -> he is refused with "Your account is currently silenced and cannot post.".
- `c-admin-blocks-shortcodes-widgets.8` @admin #block: as a user who can edit posts add [jetonomy_space_members] and [jetonomy_widget] with no attributes -> each shows a `.jt-shortcode-notice` naming the attribute to add.
- `c-admin-blocks-shortcodes-widgets.9` @guest #block !refuse: view the same two misconfigured shortcodes logged out -> they render zero bytes.
- `c-admin-blocks-shortcodes-widgets.10` @admin #block: use [jetonomy_widget id="bogus_widget_id"] and [jetonomy_user_profile user_id="999999"] -> the first lists the valid ids, the second names the missing user (editor only).
- `c-admin-blocks-shortcodes-widgets.11` @admin #block: [jetonomy_space_members space_id="help-desk"] and with the numeric id -> both render the roster.
- `c-admin-blocks-shortcodes-widgets.12` @member #block +empty: view the Space members block on an empty Local Chapters city space -> an empty message, not a blank box.
- `c-admin-blocks-shortcodes-widgets.13` @member #block +dark: view blocks and widgets on Reign dark mode -> the card colours follow the theme dark palette and titles stay readable (4.5:1).
- `c-admin-blocks-shortcodes-widgets.14` @member #block +390: view the blocks at 390px on Reign -> cards stack and nothing scrolls sideways.
- `c-admin-blocks-shortcodes-widgets.15` @member #block +long: the Recent topics block lists Grace's 159-character title -> it wraps or truncates inside the card and the URL does not widen the block.
- `c-admin-blocks-shortcodes-widgets.16` @member #block +many: set the Recent topics block to 50 items on a 2000-topic site -> it renders in one query pass and under 1 second.
- `c-admin-blocks-shortcodes-widgets.17` @member #block !refuse: open the compose-topic block or [jetonomy_compose_topic] -> archived spaces (2025 Hackathon) are not in the space picker.
- `c-admin-blocks-shortcodes-widgets.18` @silenced #block: the compose-topic block shows the silenced notice instead of 'You have not joined any space yet'.
- `c-admin-blocks-shortcodes-widgets.19` @admin #block: seeded QA pages use stale ids ([jetonomy_space_members space_id="1"], user_profile user_id="1"); re-seed or rewrite them with slugs so the pages show the roster.
- `c-admin-blocks-shortcodes-widgets.20` @admin #block: the check names eight blocks that do not exist; replace with login, compose-topic, leaderboard, forum-feed, space-list, trending, user-panel and navigation.

### C.anon.quick-login-and-captcha
**Covers:** includes/api/class-auth-controller.php, includes/captcha/*.php, assets/js/login-block.js
**Verified:** pending - new journey, not walked yet
**What to verify:** A guest uses the Login block to sign in and (if enabled) register without leaving the page; wrong password and duplicate email show clear errors and no account is revealed beyond the standard message. With reCAPTCHA or Turnstile configured, the challenge appears on register and on topic/reply submission, a failed challenge blocks the submit, and with captcha off nothing extra shows. Check 1440 and 390 widths.

**Checks:**
- `c-anon-quick-login-and-captcha.1` @guest #block: sign in with a valid member through the Login block -> the page updates in place to the logged-in state without leaving the page.
- `c-anon-quick-login-and-captcha.2` @guest #block !refuse: sign in with a wrong password -> "Incorrect username or password." and the same message for an unknown user, so no account is revealed.
- `c-anon-quick-login-and-captcha.3` @guest #block !refuse: submit the form empty -> "Enter your username and password." (400).
- `c-anon-quick-login-and-captcha.4` @guest #rest !refuse: POST /auth/login 12 times in a minute with a wrong password -> a 429 jetonomy_rate_limited reading "Too many attempts. Please wait a minute and try again.".
- `c-anon-quick-login-and-captcha.5` @guest #block: register a new account "priya.raman@example.com" -> the account is created and, if confirmation is on, the email "[site] Confirm your email to finish signing up" arrives in Mailpit and login says confirm first until clicked.
- `c-anon-quick-login-and-captcha.6` @guest #block !refuse: register with an email that already has an account -> "That email is unavailable." and the same wording for a taken username "That username is unavailable.".
- `c-anon-quick-login-and-captcha.7` @guest #block !refuse: register with a 5-character password -> "Password must be at least 8 characters." and with a disposable address "Please use a permanent email address. Disposable mailboxes are not accepted.".
- `c-anon-quick-login-and-captcha.8` @guest #rest !refuse +settings-off: POST /auth/register with registration closed in WordPress -> 403 "Registration is disabled on this site." and the block hides the register tab.
- `c-anon-quick-login-and-captcha.9` @guest #block: with Turnstile configured open the Login block, the register form, a new topic and a reply -> the challenge shows on register and on topic and reply submit.
- `c-anon-quick-login-and-captcha.10` @guest #block !refuse: submit register with the captcha failing -> "CAPTCHA verification failed. Please try again." (400) and no account is created.
- `c-anon-quick-login-and-captcha.11` @guest #block +settings-off: turn captcha off -> no challenge or extra field renders anywhere and posting works.
- `c-anon-quick-login-and-captcha.12` @member_b #block !refuse: a banned account logs in -> the answer says the account is banned (jetonomy_user_banned) without a generic invalid-password message.
- `c-anon-quick-login-and-captcha.13` @guest #block +dark: view the Login block on Reign dark mode -> the card follows the dark surface and the title keeps contrast of at least 4.5:1.
- `c-anon-quick-login-and-captcha.14` @guest #block +390: use the Login block at 390px -> fields and the submit button are at least 40px tall and nothing scrolls sideways.
- `c-anon-quick-login-and-captcha.15` @guest #block +offline: submit login with the request failing -> the form shows an error that takes focus and is announced (role=alert), the typed username and password stay in their fields, and the button is enabled again.
- `c-anon-quick-login-and-captcha.16` @guest #block +long: register with a 60-character username -> the form accepts it or refuses it with a message, and the profile shows the name wrapped, never overflowing.
- `c-anon-quick-login-and-captcha.17` @guest #block: the Login block 'Register' form asks for username, email and password only (no display name field); the display name defaults to the username and is edited on the profile.
- `c-anon-quick-login-and-captcha.18` @guest #rest !refuse: POST /auth/register 6 times in a minute -> 429 from the 6th (register bucket limit 5).
- `c-anon-quick-login-and-captcha.19` @guest #block: Resend confirmation email after registering -> 'If an account is waiting on confirmation for that email, a new link is on its way.' and a new email arrives.
- `c-anon-quick-login-and-captcha.20` @guest #block: click Resend confirmation email -> the 'a new link is on its way' sentence replaces the error and is styled as a success (today it is appended in red under the error).
- `c-anon-quick-login-and-captcha.21` @guest #block: click Resend confirmation email twice within 60 seconds -> the second click tells the member to wait (today it shows the same success sentence and sends nothing).

### C.member.space-create-and-join
**Covers:** templates/views/new-space.php, templates/views/my-spaces.php, templates/partials/icon-picker.php, assets/js/jetonomy-icon-picker.js, includes/models/class-join-request.php
**Verified:** 64212bb5
**What to verify:** A member (where space creation is allowed) creates a space with an icon, sees it under My Spaces, and a second member requests to join a private space; the space admin sees the request and approves or declines it, and the requester sees the outcome. A member without the capability cannot reach new-space; a guest is sent to login. Check 1440 and 390 widths.

**Checks:**
- `c-member-space-create-and-join.1` @admin #ui: with space creation allowed for members open /community/new-space/ -> the form lists Forum, Q&A, Ideas and Feed with the icon picker.
- `c-member-space-create-and-join.2` @member #ui: bob creates "Weekend Hikers" with an icon -> the space page opens, and My Spaces lists it under spaces he runs.
- `c-member-space-create-and-join.3` @member #ui: choose Public with Open join, then Hidden -> hidden forces Invite only with the note "Hidden spaces are invite-only, so the join policy was switched to Invite only.".
- `c-member-space-create-and-join.4` @member #ui: pick Private while Open -> the join policy flips to Approval required with its note.
- `c-member-space-create-and-join.5` @member_b #ui: alice opens the private Insiders / Beta page and clicks Request to join with an answer -> the button reads as pending, and carlos's earlier request is shown unchanged.
- `c-member-space-create-and-join.6` @space_admin #ui: the admin opens /community/s/<space>/members/ -> alice's request and answer show; Approve and Deny (with a confirm) work and the row leaves, and a confirmation toast appears.
- `c-member-space-create-and-join.7` @member_b #ui: after approval alice opens My Spaces -> the space is listed, and after a decline she sees the outcome message and can request again.
- `c-member-space-create-and-join.8` @member_b #ui +concurrent: two admins approve and deny the same request at once -> one wins, the other sees "This request has already been handled.", and alice is either a member or not, never both states.
- `c-member-space-create-and-join.9` @member #ui +settings-off: with space creation reserved for admins open /community/new-space/ as bob -> "Creating spaces is reserved for community administrators." and no form.
- `c-member-space-create-and-join.10` @member #rest !refuse: bob POSTs /spaces when creation is admin-only -> 403 jetonomy_forbidden.
- `c-member-space-create-and-join.11` @guest #ui !refuse: open /community/new-space/ logged out -> redirected to login and, after login, back to the form.
- `c-member-space-create-and-join.12` @space_banned #ui !refuse: omar requests to join Off-Topic Lounge -> refused, because he is banned there.
- `c-member-space-create-and-join.13` @member #ui +archived: try to join the archived 2025 Hackathon -> no Join button, a notice that it is archived, and POST join returns jetonomy_space_restricted.
- `c-member-space-create-and-join.14` @member #ui +empty: open My Spaces as a new member -> "You are not in any spaces yet." with a "Browse spaces" link.
- `c-member-space-create-and-join.15` @member #ui +long: create a space whose title is 120 characters -> My Spaces card and space header wrap the title, and the 288-character description is shown in full on the space page.
- `c-member-space-create-and-join.16` @member #ui +390: create a space at 390px -> the icon picker and form fit, and buttons are at least 40px tall.
- `c-member-space-create-and-join.17` @member #ui: withdraw a pending request -> it leaves the admin's request list and no stale notice is left.
- `c-member-space-create-and-join.18` @member_b #email: the approval is emailed "Your join request was answered", once.
- `c-member-space-create-and-join.19` @member #ui +long: the My Spaces card wraps a 120-character title instead of cutting it with an ellipsis.

### C.mod.space-moderation
**Covers:** templates/views/space-moderation.php, includes/api/class-space-moderation-controller.php
**Verified:** pending - new journey, not walked yet
**What to verify:** A space moderator opens /community/s/{slug}/mod/, sees only that space's flags and pending items, can resolve flags and page through a long queue, and cannot see or act on another space. Space moderators handle content only: the page offers no way to restrict a member (space admins do that from the space's Members screen). A plain member and a guest get no access, and a silenced member cannot moderate. Check 1440 and 390 widths.

**Checks:**
- `c-mod-space-moderation.1` @moderator #ui: test_moderator opens /community/s/bug-reports/mod/ -> only Bug Reports' flags and held items show, with the topic title and the start of the flagged text.
- `c-mod-space-moderation.2` @moderator #ui #rest: resolve a flag with Remove, then with Dismiss -> the card leaves, REST POST /spaces/{id}/moderation/flags/{flag_id}/resolve returns 200, and the reporter is notified once.
- `c-mod-space-moderation.3` @moderator #ui: Remove a flag, then click the Trash tab without reloading -> the removed topic is listed with Restore, and switching Flags, Awaiting approval and Trash stays a soft navigation.
- `c-mod-space-moderation.4` @moderator #ui: dismiss two pending flags one by one -> the subtitle, the badge and the "Flags N" tab fall together ("1 pending flag" at one, "0 pending flags" at none, tab count gone).
- `c-mod-space-moderation.5` @moderator #ui +many: seed 45 flags in the space -> the queue pages, Next links to `?pg=2`, and page 2 shows the rest.
- `c-mod-space-moderation.6` @moderator #ui: look for any restrict/ban/silence control on the page -> none is offered (space moderators handle content only).
- `c-mod-space-moderation.7` @space_admin #ui: test_editor (space admin of Help Desk) opens Help Desk /mod/ -> the queue shows, and the restriction controls live on the space's Members screen, not here.
- `c-mod-space-moderation.8` @moderator #ui !refuse: a space-only moderator (alice given the moderator role in Bug Reports; test_moderator is a site-wide Editor moderator) opens /community/s/help-desk/mod/ (not his space) -> "You do not have permission to moderate this space." or a 404 page, and no item is listed.
- `c-mod-space-moderation.9` @moderator #rest !refuse: POST /spaces/{help-desk id}/moderation/trash/post/{id} as a space-only moderator of another space (not test_moderator, who is site-wide) -> 403 jetonomy_forbidden or 404 for a space he cannot read, and the post is unchanged.
- `c-mod-space-moderation.10` @moderator #rest !refuse: GET /spaces/{Moderators' Room id}/moderation/flags as bob (not a member of the hidden space) -> 404 as if the space did not exist.
- `c-mod-space-moderation.11` @member #ui !refuse: bob opens /community/s/bug-reports/mod/ -> permission message, no queue.
- `c-mod-space-moderation.12` @guest #ui !refuse: open the same URL logged out -> redirected to login.
- `c-mod-space-moderation.13` @silenced #rest !refuse: finn (silenced, a moderator of nothing) POSTs a moderation action -> 403, and a silenced real moderator is refused with "Your account is currently silenced and cannot post.".
- `c-mod-space-moderation.14` @member #ui +closed card:10415367196: open the closed topic "Scheduled maintenance on Saturday" in Announcements as bob -> a "Closed" badge shows, a notice reads "This topic is closed and cannot receive new replies.", and no reply composer is rendered.
- `c-mod-space-moderation.15` @member #rest +closed !refuse card:10415367196: bob POSTs /posts/{closed id}/replies -> 403 jetonomy_post_closed with "This topic is closed and cannot receive new replies.", and no reply is stored.
- `c-mod-space-moderation.16` @member #ui #rest +closed card:10415367196: bob upvotes the closed topic -> the vote registers (POST /posts/{id}/vote value 1 returns 200), the score changes by 1, and it persists after reload.
- `c-mod-space-moderation.17` @member #ui #rest +closed card:10415367196: bob bookmarks the closed topic -> POST /bookmarks returns 200, the bookmark icon shows pressed, and the topic is listed under his bookmarks.
- `c-mod-space-moderation.18` @member_b #ui +closed card:10415367196: alice opens Share on the closed topic -> the dropdown opens with a copy link and share targets, copy works, and scrolling detaches the dropdown.
- `c-mod-space-moderation.19` @member_b #ui #rest +closed card:10415367196: alice reports the closed topic with reason "Spam" -> the toast confirms it, POST /flags returns 201, and the flag appears in the moderator queue.
- `c-mod-space-moderation.20` @moderator #ui #rest +closed card:10415367196: test_moderator (moderator of that space) opens the closed topic -> the composer shows with "This topic is closed. As a moderator, you can still take part.", his POST reply returns 201, and the topic is still closed afterwards.
- `c-mod-space-moderation.21` @space_admin #ui: close an open topic with the "Close topic" control, then "Reopen topic" -> the badge appears and disappears and members can reply again after reopening.
- `c-mod-space-moderation.90` @moderator @member_c #ui #rest +concurrent: two moderators have the same topic open; one closes it, the other (stale tab still showing "Close topic") clicks Close -> the topic stays closed, and the same holds for Pin; POST /posts/<id>/close with closed:true twice leaves is_closed true.
- `c-mod-space-moderation.91` @space_admin #rest !refuse: a space-only moderator (WordPress role Subscriber, moderator row in Bug Reports) POSTs a moderation action in Help Desk -> 403, and the Help Desk /mod/ page says 'You do not have permission to moderate this space.' (test_moderator is an Editor, so use a persona that is not.)
- `c-mod-space-moderation.92` @member #rest !refuse: GET /spaces/{hidden id}/moderation/flags and GET /spaces/99999/moderation/flags answer the same status and body.
- `c-mod-space-moderation.93` @moderator #rest !refuse: a silenced moderator POSTs /spaces/{id}/moderation/trash/post/{id} -> refused and the topic is unchanged.
- `c-mod-space-moderation.94` @moderator #ui: after resolving a flag from the space queue, the Flags, Awaiting approval and Trash tabs still switch without a full page load (soft navigation).

### C.member.block-user
**Covers:** includes/api/class-blocks-controller.php, includes/models/class-blocked-user.php
**Verified:** 64212bb5
**What to verify:** A member blocks another member from a post or profile; that author's content collapses to a tombstone for the blocker only, and unblocking from the tombstone restores it after reload. The blocked member sees no change and cannot tell, and a member cannot block themselves or a moderator-level account in a way that hides moderation. Check 1440 and 390 widths.

**Checks:**
- `c-member-block-user.1` @member #ui: bob opens alice's profile, More actions > "Block Alice Chen", confirms "Block Alice Chen?" -> her replies collapse to "Content hidden because you blocked this user." with an Unblock button for bob only.
- `c-member-block-user.2` @member #ui: click Unblock on the tombstone and reload -> alice's replies show again.
- `c-member-block-user.3` @member #rest: POST /users/me/blocks {user_id} then GET /users/me/blocks and DELETE /users/me/blocks/{id} -> 200 with blocked true, the list contains her, and deleted true removes her.
- `c-member-block-user.4` @member #rest: block the same person twice -> both calls return 200 (already blocked is fine) and only one row exists.
- `c-member-block-user.5` @member_b #ui: alice views the thread after being blocked -> she sees bob's content normally and no sign she was blocked.
- `c-member-block-user.6` @member #rest !refuse: bob blocks himself -> 400 jetonomy_cannot_block_self "You cannot block yourself.".
- `c-member-block-user.7` @member #rest !refuse: bob blocks test_moderator -> 403 jetonomy_cannot_block_moderator "You cannot block a moderator." and the mod's content stays visible.
- `c-member-block-user.8` @member #rest !refuse: block user id 999999 -> 404 "User not found.".
- `c-member-block-user.9` @guest #rest !refuse: POST /users/me/blocks logged out -> 401.
- `c-member-block-user.10` @member #ui +deleted: block a member then the member is deleted -> the tombstone reads as a deleted member and the block row is cleaned, no PHP notice.
- `c-member-block-user.11` @member #ui: a topic by a blocked author in a search result, notification and tag page -> it is hidden or collapsed on those surfaces too, and a deep link to it shows the tombstone.
- `c-member-block-user.12` @member #ui +long: block Grace (long name) -> the tombstone and Blocked list wrap her full name.
- `c-member-block-user.13` @member #ui +390: view a tombstone at 390px -> the Unblock button is at least 40px and the text wraps.
- `c-member-block-user.14` @member #ui +dark: view a tombstone in dark mode -> text and border keep contrast.
- `c-member-block-user.15` @member #ui +offline: block while offline -> the reply does not collapse and an error shows.
- `c-member-block-user.16` @member #email: a blocked person's reply to bob's topic -> bob gets no notification or email from her.
- `c-member-block-user.17` @member #ui +long +390: the Blocked members list on the edit-profile page wraps a long name instead of cutting it with an ellipsis.

### C.member.connect-app
**Covers:** templates/views/connect-app.php, assets/js/connect-app.js, includes/integrations/class-app-connect.php, includes/api/class-app-config-controller.php, includes/api/class-rest-auth.php
**Verified:** 64212bb5
**What to verify:** A signed-in member opens /community/connect-app, generates an app credential, and the mobile app config endpoint returns the right base URL and features for that member; revoking the credential makes the app lose access. A guest is redirected to login and cannot read member-only config. Check 1440 and 390 widths.

**Checks:**
- `c-member-connect-app.1` @member #ui: open /community/connect-app?scheme=jetonomy with a valid app link as bob -> the page asks "Connect Jetonomy app to your account?" and names "You are signed in as Bob (bob@...)".
- `c-member-connect-app.2` @member #ui #rest: click Connect -> POST /auth/app-connect returns site_url, user_login, uuid and a deep_link, and the app opens the deep link.
- `c-member-connect-app.3` @guest #rest: GET /app/config logged out -> returns the base URL, app name, labels, features, accent colour and terms and privacy URLs, but nothing member-only.
- `c-member-connect-app.4` @member #rest: GET /app/config with the new app credential -> features and attachments (max 5 files, 10 MB) match the plan; with Pro off pro_active is false.
- `c-member-connect-app.5` @member #ui: revoke the credential from the profile Application Passwords list, then call the API with it -> 401, and the app loses access.
- `c-member-connect-app.6` @guest #ui !refuse: open /community/connect-app logged out -> redirected to login, and after login returned to the connect page.
- `c-member-connect-app.7` @member #ui !refuse: open the page with a scheme this site does not allow -> "This connection link is not valid" and nothing is minted.
- `c-member-connect-app.8` @member #rest !refuse: POST /auth/app-connect with an expired bridge -> 410 "This connection screen has expired. Go back to the app and try connecting again.".
- `c-member-connect-app.9` @member #rest !refuse: POST /auth/app-connect 15 times in a minute -> 429 "Too many connection attempts. Please wait a while and try again.".
- `c-member-connect-app.10` @member #rest !refuse +settings-off: connect where Application Passwords are disabled -> 403 "Application passwords are not available on this site.".
- `c-member-connect-app.11` @silenced #rest: finn connects the app -> he can connect (read-only) but cannot post through it, same as on the web.
- `c-member-connect-app.12` @member #ui +long: Grace connects -> "You are signed in as Grace Oluwaseun Adeyemi-Whitfield (...)" wraps inside the card.
- `c-member-connect-app.13` @member #ui +390: open the connect page at 390px -> Connect (there is no Cancel button) and the "Not you? Sign in as someone else" link are at least 40px tall and no sideways scroll.
- `c-member-connect-app.14` @member #ui +dark: view the page in dark mode -> card and text keep contrast.
- `c-member-connect-app.15` @member #ui +offline: click Connect while offline -> "Something went wrong. Please try again." and the button re-enables.
- `c-member-connect-app.16` @member #ui: with BuddyNext active open /community/connect-app -> it forwards to BuddyNext's connect URL with the same query.
- `c-member-connect-app.17` @member #ui +390: the connect page has a Cancel button and the "Not you? Sign in as someone else" link is at least 40px tall.

### C.member.link-preview
**Covers:** includes/services/links/*.php, includes/services/links/providers/*.php
**Verified:** 64212bb5
**What to verify:** A member pastes a URL into a topic or reply and the published post shows a preview card (title, image, site) for normal pages, oEmbed providers and X posts. Internal or private-network URLs (localhost, 169.254.x, file:) must never be fetched, a slow or broken site must not block posting, and the card is the same for guests. Check 1440 and 390 widths.

**Checks:**
- `c-member-link-preview.1` @member #ui: bob pastes https://wordpress.org/news/ into a new topic "Release notes worth reading" and publishes -> the published topic shows a card with title, image and site name.
- `c-member-link-preview.2` @member #rest: GET /link-preview?url=<normal page> -> 200 with title, description, image, site_name, favicon and provider.
- `c-member-link-preview.3` @member #ui: paste a YouTube URL and an X post URL -> the card shows the oEmbed player or the X card.
- `c-member-link-preview.4` @guest #ui: view the same published topic logged out -> the card is identical to what the member sees.
- `c-member-link-preview.5` @member #rest !refuse: GET /link-preview with http://localhost/, http://127.0.0.1/, http://169.254.169.254/latest/meta-data/, a 10.x address and file:///etc/passwd -> each returns 200 with an empty preview (no title, description, image or embed), identical for all, and nothing is fetched.
- `c-member-link-preview.6` @member #rest !refuse: use a hostname that resolves to a private address, and a redirect from a public URL to 127.0.0.1 -> both refused with the same message.
- `c-member-link-preview.7` @member #ui +offline: paste a URL whose server hangs for 30 seconds -> posting is not blocked, the fetch gives up near 6 seconds, and the topic publishes with a plain link.
- `c-member-link-preview.8` @member #rest: request a URL that returns HTTP 500 -> the response is a quiet failure, not a PHP error, and a retry within 10 minutes uses the short negative cache.
- `c-member-link-preview.9` @member #rest +old-data: request the same good URL twice -> the second answer is served from cache (12 hours) with the same data.
- `c-member-link-preview.10` @member #rest !refuse: request a redirect loop and a 6-redirect chain -> 400 "Redirect loop or invalid redirect." and "Too many redirects.".
- `c-member-link-preview.11` @guest #rest !refuse: GET /link-preview for a private URL logged out -> 401 or the same blocked-URL answer, never a fetch.
- `c-member-link-preview.12` @member #ui +long: paste a 100-character unbroken URL -> the card and link text wrap inside the topic and do not widen the page.
- `c-member-link-preview.13` @member #ui +390: view a card at 390px -> image scales, text wraps, tap target is at least 40px.
- `c-member-link-preview.14` @member #ui +dark: view a card in dark mode on Reign -> card background follows the dark surface and the title stays readable.
- `c-member-link-preview.15` @member #ui +rtl: link to an Arabic page -> the card title renders right to left.
- `c-member-link-preview.16` @guest #rest: GET /link-preview for a public URL logged out -> either 401 or a rate-limited answer (today it is an open server-side fetch).
- `c-member-link-preview.17` @member #ui: a standalone wordpress.org post link shows a card with title, image and site name (today only a quote box with the link because the oEmbed iframe is blocked).

### C.member.privacy-export-erase
**Covers:** includes/class-privacy.php
**Verified:** 64212bb5
**What to verify:** Site admin runs Tools > Export Personal Data and Erase Personal Data for a seeded member and expects the export to list their posts, replies, votes, bookmarks and profile, and erase to remove or anonymise them without breaking threads other people replied in. The privacy policy suggested text appears in the Policy Guide. Check the admin screens at 1440 and 390.

**Checks:**
- `c-member-privacy-export-erase.1` @admin #admin: run Tools > Export Personal Data for bob -> the file lists Jetonomy Profile, Jetonomy Posts, Replies, Bookmarks, Votes, Subscriptions, Notifications, Activity Log and Blocked Users groups with his real rows.
- `c-member-privacy-export-erase.2` @admin #admin +many: export a member with 800 replies -> the export pages through all of them without a timeout and the counts match the profile.
- `c-member-privacy-export-erase.3` @admin #admin: run Erase Personal Data for Hana (a demo member no persona depends on) -> her bookmarks, votes, subscriptions, notifications, blocks and profile details are removed, and posts she wrote are removed or anonymised.
- `c-member-privacy-export-erase.4` @guest #ui: open a thread Hana wrote in that others replied to -> the other replies remain and her items show "[deleted]" or an anonymised name, with no broken threading.
- `c-member-privacy-export-erase.5` @member #ui: bob's own reply to Hana's erased topic -> still visible and its vote score unchanged.
- `c-member-privacy-export-erase.6` @admin #admin: after erasing, run `wp jetonomy recount` -> counts for spaces and topics match the remaining content.
- `c-member-privacy-export-erase.7` @admin #admin: open Settings > Privacy > Policy Guide -> the Jetonomy suggested text appears ("When you open a discussion topic in our community, your browser notes the topic ID in session storage ...").
- `c-member-privacy-export-erase.8` @member #rest: a member account registered for this check (Lucas Moreau, not bob) DELETEs itself through /users/me -> his content is handled like Erase and he gets signed out, and a 6th attempt in an hour is rate limited.
- `c-member-privacy-export-erase.10` @member_b #ui +deleted: a user deleted from the WordPress Users screen -> their Jetonomy rows are cleaned, and an old deleted account is cleaned by the orphan purge.
- `c-member-privacy-export-erase.11` @admin #admin +private: erase Hana after giving her a reply in Customer Advisory Board -> the private space's rows are anonymised too.
- `c-member-privacy-export-erase.12` @admin #admin +long: export Grace (long name, no avatar) -> the export file has her full name and an empty avatar field without PHP notices.
- `c-member-privacy-export-erase.13` @admin #admin +390: open the export and erase screens at 390px -> tables scroll inside their container and the page does not.
- `c-member-privacy-export-erase.14` @admin #cli: run `wp jetonomy privacy scan` after erasing alice -> it reports no Jetonomy rows left for her, and `wp jetonomy privacy purge-orphans` removes rows of a user deleted outside WordPress's eraser.
- `c-member-privacy-export-erase.15` @member #admin !refuse: as bob, open wp-admin Tools > Export Personal Data -> refused by WordPress (no access), so a member cannot export or erase anyone else's data.
- `c-member-privacy-export-erase.16` @member_b #rest !refuse: as alice, call DELETE on bob's account or GET his export through the jetonomy REST API -> refused (403 or 404); only /users/me acts, and only on the caller.

### C.anon.sitemap
**Covers:** includes/seo/class-sitemap.php, includes/seo/class-sitemap-emitter.php
**Verified:** pending - new journey, not walked yet
**What to verify:** A guest or crawler opens the sitemap index and finds the Jetonomy sitemap listing public spaces and public topics with correct URLs and lastmod, paginated for large forums; private and hidden spaces and their topics never appear. Check that the sitemap loads fast with 2000+ topics. Browser width is irrelevant for XML; check the linked pages at 1440 and 390.

**Checks:**
- `c-anon-sitemap.1` @guest #rest: GET /community-sitemap.xml -> 200, content type XML, a `<sitemapindex>` root listing the spaces and posts child sitemaps.
- `c-anon-sitemap.2` @guest #rest: GET /community-sitemap-spaces-1.xml and /community-sitemap-posts-1.xml -> `<urlset>` with loc, lastmod, changefreq and priority for each URL, and each loc returns 200.
- `c-anon-sitemap.3` @guest #rest !refuse: search the sitemaps for Customer Advisory Board, Moderators' Room, Insiders / Beta and Partner Program URLs -> none of them or their topics is listed.
- `c-anon-sitemap.4` @guest #rest !refuse: GET a child sitemap number that does not exist (page 99) -> 404, not an empty 200.
- `c-anon-sitemap.5` @guest #rest +many: seed 12,000 public topics -> the posts sitemap splits into three pages of up to 5000 URLs, page 3 holds the rest, and the union has no duplicates.
- `c-anon-sitemap.6` @guest #rest +many: request /community-sitemap.xml with 2000+ topics -> it answers in under 1 second.
- `c-anon-sitemap.7` @guest #rest +archived: check the archived 2025 Hackathon -> its topics follow the site's public/archived policy and the lastmod is the last activity date.
- `c-anon-sitemap.8` @guest #rest +deleted: delete a public topic and wait for cache TTL or flush -> it leaves the sitemap.
- `c-anon-sitemap.9` @guest #rest +settings-off: turn off "seo_sitemap" in Settings -> /community-sitemap.xml returns 404 and robots.txt loses its Sitemap line.
- `c-anon-sitemap.10` @guest #rest: read /robots.txt -> it contains a `Sitemap:` line for /community-sitemap.xml when enabled.
- `c-anon-sitemap.11` @guest #rest: with Yoast SEO active GET /community-sitemap.xml and /sitemap_index.xml -> both return 200 and Yoast's own sitemaps still work.
- `c-anon-sitemap.12` @guest #ui +390: open three sitemap-listed pages at 1440 and 390 -> each renders without overflow.
- `c-anon-sitemap.13` @guest #rest: read the response headers -> `X-Robots-Tag: noindex, follow` is present on the sitemap.
- `c-anon-sitemap.14` @guest #rest +rtl: Omar's Arabic topic URL in the sitemap -> the loc is percent-encoded and valid XML.
**Depth note:** the sitemap is a public read-only surface with one role; the refusal cases above (private spaces absent, page 99) stand in for a role ladder.

### C.site.host-integrations
**Covers:** includes/integrations/class-buddypress.php, includes/integrations/class-fluent-community.php, includes/integrations/class-layout-css.php, includes/integrations/class-theme-integration.php, includes/integrations/class-divi-theme-builder.php, assets/css/buddypress.css, assets/css/fluent-community.css
**Verified:** pending - new journey, not walked yet
**What to verify:** On a BuddyPress site, a Fluent Community site, and under BuddyX or Reign themes, community pages pick up the theme's colours and fonts, the layout settings (width, sidebar) set by the admin apply, and the BuddyPress profile tab shows the member's Jetonomy activity. Nothing breaks when the host plugin is absent. Check 1440 and 390 widths, light and dark mode.

**Checks:**
- `c-site-host-integrations.1` @member #ui: on the Reign theme open /community/ -> fonts and colours follow the theme, and the layout width and sidebar chosen in Settings apply.
- `c-site-host-integrations.2` @member #ui: repeat on BuddyX and Twenty Twenty-Five -> same settings apply, no double headings, no theme sidebar clashing with the community sidebar.
- `c-site-host-integrations.3` @admin #admin: change layout width and sidebar position in Settings and save -> the community page changes on reload and a cached page is not stale.
- `c-site-host-integrations.4` @member #ui: with BuddyPress active open bob's BuddyPress profile -> a Jetonomy tab shows his topics, replies and reputation, matching his /community/u/bob/ profile.
- `c-site-host-integrations.5` @member #ui +empty: open a new member's BuddyPress Jetonomy tab -> an empty message, no PHP notice.
- `c-site-host-integrations.6` @admin #admin: with BuddyPress Groups active open Settings -> an "Integrations" tab appears with two checked checkboxes, and it is absent when BuddyPress is off.
- `c-site-host-integrations.7` @member #ui: join a BuddyPress group linked to a space -> bob becomes a space member, but not into an archived, locked or banned-for-him linked space.
- `c-site-host-integrations.8` @space_banned #ui !refuse: omar joins a BuddyPress group linked to Off-Topic Lounge -> he is not added to the space.
- `c-site-host-integrations.9` @member #ui: with Fluent Community active open the community -> it picks up Fluent's colours and its space joins reconcile with Jetonomy membership.
- `c-site-host-integrations.10` @member #ui +settings-off: deactivate BuddyPress and Fluent Community -> the community loads with no PHP error and no empty tab.
- `c-site-host-integrations.11` @member #ui +dark: toggle the theme's dark mode on Reign and BuddyX -> community cards, tints and borders adopt the host dark palette and space titles keep 4.5:1 contrast.
- `c-site-host-integrations.12` @member #ui +390: open /community/ and a topic at 390px on each theme -> no horizontal scroll, 40px tap targets.
- `c-site-host-integrations.13` @member #ui +long: open Grace's 159-character topic on the boxed Reign theme -> the title wraps in the header and the 100-character URL does not break the box.
- `c-site-host-integrations.14` @member #ui +rtl: switch the site to Arabic on Reign -> the community mirrors and the sidebar swaps sides.
- `c-site-host-integrations.15` @guest #ui !refuse: view the community while the site is in maintenance mode -> the maintenance page shows and Jetonomy pages do not leak.
- `c-site-host-integrations.16` @admin #ui: set "Community as homepage" -> the root `/` shows the community and still obeys the maintenance gate.
- `c-site-host-integrations.18` @guest #ui: on the Divi theme with a Theme Builder global header and footer, open /community/ and a topic -> the Divi header and footer render with their module styles, and debug.log has no warning from an empty Divi menu.
- `c-site-host-integrations.17` @admin #admin: set Settings > Appearance > Sidebar to 'Hide on community pages' on a theme that shows a sidebar (BuddyX) -> the theme sidebar goes and the Jetonomy sidebar stays, or the option says which one it hides.

### C.integration.abilities-api
**Covers:** includes/class-abilities.php
**Verified:** 64212bb5
**What to verify:** On WordPress 6.9+ the abilities list includes the Jetonomy abilities, an authorised user or agent can run each (create post, reply, vote, search) and gets the same result as the UI, and a guest or under-privileged caller is refused. On older WordPress the plugin loads without errors. Verify through the abilities REST endpoint; no viewport needed.

**Checks:**
- `c-integration-abilities-api.1` @admin #rest: GET /wp-json/wp-abilities/v1/abilities on WordPress 6.9+ -> the list includes jetonomy/create-post, create-reply, vote, search, list-spaces, get-space, join-space and the rest.
- `c-integration-abilities-api.2` @member #rest: run jetonomy/create-post as bob into Feature Requests -> the result matches POST /spaces/{id}/posts in the UI and the topic appears.
- `c-integration-abilities-api.3` @member #rest: run jetonomy/create-reply and jetonomy/vote on that topic -> a reply is stored and the score changes by 1.
- `c-integration-abilities-api.4` @member #rest: run jetonomy/search q="dark mode" -> same ids and order as /search.
- `c-integration-abilities-api.5` @member_b #rest: run jetonomy/flag-content on bob's reply -> a flag is created once and a repeat returns jetonomy_ prefixed error.
- `c-integration-abilities-api.6` @moderator #rest: run jetonomy/moderate-content and jetonomy/list-flags -> work for a space he moderates.
- `c-integration-abilities-api.7` @guest #rest !refuse: run jetonomy/create-post logged out -> 401 and no topic.
- `c-integration-abilities-api.8` @member #rest !refuse: run jetonomy/moderate-content and jetonomy/list-flags as bob -> 403 rest_ability_cannot_execute (moderate-content is a destructive ability and needs the DELETE method; POST gives 405).
- `c-integration-abilities-api.9` @silenced #rest !refuse: run jetonomy/create-post and jetonomy/vote as finn -> 403 rest_ability_cannot_execute (the Abilities API's generic permission refusal, not the REST route's jetonomy_user_silenced).
- `c-integration-abilities-api.10` @member #rest !refuse +closed: run jetonomy/create-reply on the closed Announcements topic -> 403 jetonomy_post_closed, while a moderator succeeds.
- `c-integration-abilities-api.11` @member #rest !refuse +archived: run jetonomy/create-post into the archived 2025 Hackathon -> jetonomy_space_restricted.
- `c-integration-abilities-api.12` @member #rest !refuse +private: run jetonomy/get-space and jetonomy/list-posts for Customer Advisory Board as bob -> 403 rest_ability_cannot_execute, identical to a nonexistent space id so nothing leaks.
- `c-integration-abilities-api.13` @member #rest: run jetonomy/join-space on Feature Requests (open) and on Insiders / Beta or Partner Program (approval) -> the first joins, the others return pending_approval.
- `c-integration-abilities-api.14` @admin #rest +settings-off: on WordPress below 6.9 activate the plugin -> no abilities are registered and no PHP error shows.
- `c-integration-abilities-api.15` @member #rest +many: run jetonomy/list-posts with limit=100 on a 2000-topic space -> returns 100 with paging data in under 1 second.
- `c-integration-abilities-api.16` @member #rest !refuse: run jetonomy/vote on your own topic with value 1 and -1 -> refused with the same jetonomy_self_vote message as POST /posts/{id}/vote, score unchanged.
- `c-integration-abilities-api.17` @member_b #rest: run jetonomy/flag-content with reason off-topic and with reason inappropriate -> a flag is created with the REST enum value (off_topic, offensive) and shows in the moderator queue with the reporter's name.
- `c-integration-abilities-api.18` @member_b #rest !refuse: run jetonomy/flag-content on your own reply, on a reply you cannot read (Customer Advisory Board) and on content you already reported -> the same refusals as POST /flags.
- `c-integration-abilities-api.20` @admin #rest: run jetonomy/list-flags -> every flag carries the reporter's user id (`user_id`), not 0.
- `c-integration-abilities-api.21` @guest #rest !refuse +private card:10430601579: run jetonomy/search with filter=tags and a query matching a tag used only in a private space -> that tag is not in the result, and a tag used in a public space still is, with its public count.
**Depth note:** no viewport applies; the surface is REST only.

### C.admin.uninstall
**Covers:** uninstall.php
**Verified:** 64212bb5
**What to verify:** Site admin deactivates and deletes the plugin: with "remove data on uninstall" off, tables and options stay so a reinstall restores the forum; with it on, every jt_ table, option, capability and cron hook is gone and the rest of the site still loads. Check the Plugins screen at 1440 and 390.

**Checks:**
- `c-admin-uninstall.1` @admin #admin +settings-off: with "remove data on uninstall" off, deactivate and delete the plugin from the Plugins screen -> tables and options stay, and reinstalling restores the forum with every space and topic.
- `c-admin-uninstall.2` @admin #cli: with the setting on, delete the plugin and run `wp db query "SHOW TABLES LIKE '%jt_%'"` -> no jt_ table remains.
- `c-admin-uninstall.3` @admin #cli: after deletion run `wp option list --search=jetonomy*` -> no option remains, including jetonomy_settings, jetonomy_db_version, jetonomy_demo_data and licence and widget settings.
- `c-admin-uninstall.4` @admin #cli: check roles after deletion -> no capability starting with `jetonomy_` remains on any role.
- `c-admin-uninstall.5` @admin #cli: check cron -> `wp cron event list` and Action Scheduler show no jetonomy_ hook.
- `c-admin-uninstall.6` @admin #ui: after deletion load the home page, /wp-admin/ and the Plugins screen -> all load and /community/ returns a normal 404, with no fatal.
- `c-admin-uninstall.7` @admin #cli: with Pro active delete Free only -> Pro does not fatal, and Free's uninstall leaves Pro's scheduled jobs to Pro.
- `c-admin-uninstall.8` @admin #admin: delete Pro with its own remove-data setting on -> Pro tables and options go and Free's data stays.
- `c-admin-uninstall.9` @member #admin !refuse: bob opens /wp-admin/plugins.php -> WordPress refuses, and no uninstall can be started.
- `c-admin-uninstall.10` @admin #admin +many: delete with 2000 topics and 500 members -> uninstall finishes without a timeout.
- `c-admin-uninstall.11` @admin #admin +390: open the Plugins screen at 390px and 1440px -> the Delete confirmation and the row actions are reachable.
- `c-admin-uninstall.12` @admin #cli: run the PHPUnit UninstallKeepsDataTest (and an opt-in test that also asserts every jt_ table is dropped and no jetonomy_ capability remains) in the wp-env tests-cli container -> all green, so checks 2 to 5 have an automatic proof.

## C.gating - Access rules and who gets in

**This whole section was missing until 1.9.1, and a privilege escalation shipped through the gap** (Basecamp 10169081143). Gating is how owners sell access; it is the subsystem where being wrong costs money or trust.

**Every expected result below is quoted from what the product tells the owner** on the space Access Rules tab. That is deliberate: if a step fails, either the code broke a promise we display, or the promise is wrong and the copy needs changing. Both are worth filing. You do not need to know how permissions work internally to run this section - if the screen says it and the site does not do it, that is a bug.

Setup once: a **public** space with open joining, a **private** space with invite-only joining, and a plain subscriber-role test user who is a member of neither.

### C.gating.rule-never-locks-out
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/permissions/class-content-gate.php, includes/admin/class-admin.php, includes/api/class-rest-auth.php, assets/js/admin-common.js, assets/css/admin.css
**Verified:** 64212bb5
**What to verify:** note what the subscriber can do in the public space. Add a Logged In rule to that space. Everything they could do before, they can still do. (A Role, Capability, Trust Level or Membership rule switches a public space to Private by design - the Access Rules tab says so - so a member outside the roster can then read but not post.)
**Promise:** "A rule lets more people in to this space: anyone who matches it gets what it grants, without being added to the members list. Who else gets in is set by the space's visibility and join policy."
**Why it matters:** owners reach for rules to restrict. They do not restrict, and an owner who believes otherwise has left a space open while thinking it is closed.

**Checks:**
- `c-gating-rule-never-locks-out.1` @member #ui: as a fresh subscriber (not in either space) note what he can do in the public open space: read, post, reply, vote -> all four work.
- `c-gating-rule-never-locks-out.2` @admin #admin: add a Logged In rule to the public space -> toast "Access rule added." and the Access Rules tab lists the rule.
- `c-gating-rule-never-locks-out.3` @member #ui: repeat read, post, reply, vote after the rule -> all four still work.
- `c-gating-rule-never-locks-out.4` @guest #ui: view the public space after the Logged In rule -> guest can still read it (the rule did not close the space).
- `c-gating-rule-never-locks-out.5` @admin #admin: add a Role, Capability, Trust Level or Membership rule to the public space -> toast "Access rule added. This space was switched to Private so the rule can restrict access. A rule cannot gate a public space.".
- `c-gating-rule-never-locks-out.6` @member #ui !refuse: after that switch the subscriber outside the roster and not matched by the rule opens the space -> the private-space refusal for read and post (REST 403 jetonomy_forbidden); the space is now Private with join policy Requires Approval, as the add-rule toast said.
- `c-gating-rule-never-locks-out.7` @member_b #ui: an existing roster member of the space keeps posting after any rule is added -> yes, unchanged.
- `c-gating-rule-never-locks-out.8` @admin #admin: open the Access Rules tab intro -> it says a rule lets more people in and does not restrict.
- `c-gating-rule-never-locks-out.9` @member #rest !refuse: POST /spaces/{id}/access-rules as the subscriber -> 403 jetonomy_forbidden and no rule is created.
- `c-gating-rule-never-locks-out.10` @space_admin #rest !refuse: test_editor (space admin of Help Desk) GETs and POSTs /spaces/{id}/access-rules -> 403 jetonomy_forbidden "Only site administrators can manage access rules for this space."; the wp-admin Access Rules tab URL shows that sentence with a "Back to Help Desk" link.
- `c-gating-rule-never-locks-out.11` @admin #admin +390: open the Access Rules tab at 390px -> the form stacks and buttons are 40px.
- `c-gating-rule-never-locks-out.12` @admin #admin: add a Role rule to a public open-join space -> the toast and the General tab say the join policy changed too (it silently moves from Open to Approval today).

### C.gating.read-on-public
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php
**Verified:** 64212bb5
**What to verify:** with a **Read** rule on the public open-join space, the subscriber can still post.
**Promise:** "Lets matching people in to read. It takes nothing away from people who already get in another way, such as members on the roster."

**Checks:**
- `c-gating-read-on-public.1` @admin #admin: add a Read rule (Everyone or Logged In) to the public open-join space -> toast "Access rule added.".
- `c-gating-read-on-public.2` @member #ui: the subscriber opens the space and posts a topic "What is the best phone for testing?" -> it publishes.
- `c-gating-read-on-public.3` @member #ui: the subscriber replies and votes -> both still work.
- `c-gating-read-on-public.4` @member_b #ui: a roster member keeps all abilities -> unchanged.
- `c-gating-read-on-public.5` @guest #rest: guest GET the space posts -> still readable, guest POST a reply -> 401.
- `c-gating-read-on-public.6` @admin #admin: delete the Read rule -> toast "Access rule deleted.", subscriber still posts.
- `c-gating-read-on-public.7` @member #rest !refuse: subscriber POST /spaces/<id>/access-rules -> 403 jetonomy_forbidden and no rule is stored.

### C.gating.read-on-private
**Covers:** includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/permissions/class-content-gate.php
**Verified:** 64212bb5
**What to verify:** with a **Read** rule on the private space, the subscriber can read it and cannot post, reply or vote.

**Checks:**
- `c-gating-read-on-private.1` @member #ui !refuse: before any rule the subscriber opens the private space -> he sees the locked state (or 404 for hidden) and no topics.
- `c-gating-read-on-private.2` @admin #admin: add a Read rule -> toast "Access rule added.".
- `c-gating-read-on-private.3` @member #ui: the subscriber reads the space and its topics -> topics show.
- `c-gating-read-on-private.4` @member #ui !refuse: the subscriber tries to post, reply and vote -> the composer is absent, and the vote shows a message instead of changing the score.
- `c-gating-read-on-private.5` @member #rest !refuse: POST a topic, a reply and a vote -> each returns 403 jetonomy_forbidden "You cannot post in this space." and no row is stored.
- `c-gating-read-on-private.6` @member #rest: a Read-rule member votes -> 403 jetonomy_forbidden; POST /flags on a topic there -> 201 and the report is stored (Read is read and report).
- `c-gating-read-on-private.7` @guest #ui !refuse: a guest opens the same private space -> not readable (login prompt or 404), the rule did not apply to guests.
- `c-gating-read-on-private.8` @admin #admin: remove the rule -> the next load by the subscriber is a refusal again.
- `c-gating-read-on-private.9` @member #ui +long: read a topic with the 159-character title in it -> the title wraps and no link is clipped.
- `c-gating-read-on-private.10` @member #ui +390: read the space at 390px -> layout stacks.

### C.gating.participate
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/adapters/class-adapter-registry.php, includes/adapters/interface-membership-adapter.php
**Verified:** 64212bb5
**What to verify:** with a **Participate** rule on the private space, the subscriber can read, post, reply, vote and report - all five - and can edit and delete their own posts (the Edit own posts and Delete own posts permissions come with Participate and Full).
**Promise:** "Participate - Read, plus post, reply, vote and report."

**Checks:**
- `c-gating-participate.1` @admin #admin: add a Participate rule to the private space -> toast "Access rule added.".
- `c-gating-participate.2` @member #ui: the subscriber reads the space -> topics show with no Join step.
- `c-gating-participate.3` @member #ui: the subscriber posts a topic "Anyone using the new roadmap view?" -> it publishes.
- `c-gating-participate.4` @member #ui: reply, vote and report on another member's topic -> all three work.
- `c-gating-participate.5` @member #ui: edit and delete his own topic -> both work.
- `c-gating-participate.6` @member #ui !refuse: edit, delete, close or pin another member's topic -> none of those controls show, and the REST calls return 403 jetonomy_forbidden.
- `c-gating-participate.7` @member_b #ui: the roster member's own abilities -> unchanged.
- `c-gating-participate.8` @silenced #rest !refuse: finn matched by the same rule posts -> 403 jetonomy_user_silenced, the rule does not override a silence.
- `c-gating-participate.9` @space_banned #rest !refuse: omar (banned from the space) matched by the rule posts -> refused 403 jetonomy_user_space_banned with "You have been banned from this space and cannot take part in it." (the "community" wording is the site-wide ban copy).
- `c-gating-participate.10` @member #rest !refuse +closed: the subscriber replies to a closed topic in the space -> 403 jetonomy_post_closed.
- `c-gating-participate.11` @member #ui +archived: archive the space -> the composer disappears and POST returns jetonomy_space_restricted.
- `c-gating-participate.12` @guest #ui !refuse: a guest on the space sees no composer and a login prompt.
- `c-gating-participate.13` @member #ui +concurrent: the rule is deleted while the subscriber is typing and he submits -> refused with the forbidden message and his text is kept in the box.

### C.gating.full-is-not-moderation
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php
**Verified:** 64212bb5
**What to verify:** the old **Full** grant is gone (2.0.7): the Access Rules screen offers Read and Participate only, a rule saved as Full before 2.0.7 (or sent as `grants=full` by an old API client) is stored and acts as Participate, and an ordinary subscriber under it cannot edit, delete, close or pin anyone else's topic. It is recorded as Member for every rule type, never Moderator.
**Promise:** "A rule can open a space; it never hands out moderation."
**Why it matters:** "Full" read like "everything". It never was, so 2.0.7 removed it.

**Checks:**
- `c-gating-full-is-not-moderation.1` @admin #admin: open the private space's Access Rules tab -> the add form and each rule's grant select offer Read and Participate only; the help table has no Full row.
- `c-gating-full-is-not-moderation.2` @admin #rest +old-data: POST /spaces/{id}/access-rules with grants=full on a role rule -> 201 and the stored grant is participate, role Member.
- `c-gating-full-is-not-moderation.3` @member #ui: under that rule the subscriber posts, replies, votes, reports, edits and deletes his own -> all work.
- `c-gating-full-is-not-moderation.4` @member #ui !refuse: open another member's topic -> no Edit, Delete, Close or Pin control is shown.
- `c-gating-full-is-not-moderation.5` @member #rest !refuse: PATCH and DELETE another member's topic, POST /posts/{id}/close and /pin -> each returns 403 jetonomy_forbidden and the topic is unchanged.
- `c-gating-full-is-not-moderation.6` @member #ui !refuse: open /community/s/{slug}/mod/ -> permission message, no queue; POST /moderation/trash/post/{id} -> 403.
- `c-gating-full-is-not-moderation.7` @admin #cli +old-data: set a rule's grants to 'full' in the database (a row saved before 2.0.7) and run the 2.0.7 migration -> the row reads participate and the rules list shows Participate.
- `c-gating-full-is-not-moderation.8` @moderator #ui: test_moderator (site Moderate capability) in the same space moderates -> yes, from his WordPress role, not from the rule.

### C.gating.no-rule-hands-out-moderation
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/class-membership-roster-sync.php, includes/admin/ajax/class-spaces-handler.php
**Verified:** 64212bb5
**What to verify:** try every grant level, and set the rule's Space Role as high as the screen allows. A subscriber matched by that rule still cannot moderate, edit others' posts, delete others' posts, or pin. (A site moderator holding the Moderate capability does moderate in spaces they can read, but that comes from their WordPress role, never from a rule.) Full is recorded as Member for every rule type, never Moderator.
**Promise:** "A rule can never hand out moderation."
**Why it matters:** **this is the 1.9.1 escalation.** Before the fix, a rule set to Read with an elevated space role made every matched person a space admin. If this step ever fails again, it is a security bug - file it immediately and say so in the title.

**Checks:**
- `c-gating-no-rule-hands-out-moderation.1` @admin #admin: add a Read rule and set the Space Role as high as the screen allows -> the rule saves.
- `c-gating-no-rule-hands-out-moderation.2` @member #rest !refuse: the matched subscriber calls moderate, edit others' posts, delete others' posts and pin -> each returns 403 jetonomy_forbidden.
- `c-gating-no-rule-hands-out-moderation.3` @admin #admin: repeat with Participate (and grants=full over REST) at the highest role -> same refusals; the roster role stored is member (or viewer for Read), never admin or moderator.
- `c-gating-no-rule-hands-out-moderation.4` @admin #cli: fire the membership enrolment hook for a plain subscriber with a rule grants=read, space_role=admin -> the roster role is viewer and moderate, edit_others_posts, delete_others_posts, pin_posts are all denied.
- `c-gating-no-rule-hands-out-moderation.5` @member #cli: repeat with grants=participate -> roster role is member and he CAN post and reply.
- `c-gating-no-rule-hands-out-moderation.6` @admin #admin: open the Members tab after the matches -> nobody admitted by the rule is Moderator or Admin.
- `c-gating-no-rule-hands-out-moderation.7` @member #ui: the subscriber opens the space /mod/ page -> permission message.
- `c-gating-no-rule-hands-out-moderation.8` @moderator #ui: test_moderator, in a space he can read (public, or he is on the roster), still moderates -> yes, via the Moderate capability (a site moderator does not reach a private space he cannot read; it answers 404).
- `c-gating-no-rule-hands-out-moderation.9` @admin #admin: on the Access Rules tab choose Participate for each rule type and read the live preview -> it says "They are recorded as Member." and never Moderator.

### C.gating.roster-role-is-a-per-person-decision
**Covers:** includes/admin/views/space-edit.php, includes/class-membership-roster-sync.php, includes/models/class-space-member.php, templates/views/space-members.php
**Verified:** d44f151c
**What to verify:** after anyone is admitted by a rule - including via an LMS or membership plugin enrolment if one is installed - open the space **Members** tab. Nobody added by a rule is listed as Moderator or Admin. Full is recorded as Member for every rule type, never Moderator.
**Promise:** "To give one person a different role, change it on the Members tab; that keeps it a visible, per-person decision rather than a side effect of a rule."

**Checks:**
- `c-gating-roster-role-is-a-per-person-decision.1` @admin #admin: let a rule admit three subscribers (including through an LMS or membership enrolment if installed) and press Sync Members -> they are listed on the Members tab.
- `c-gating-roster-role-is-a-per-person-decision.2` @admin #admin: read the roles of those three -> all show Member (or Viewer for Read), none Moderator or Admin.
- `c-gating-roster-role-is-a-per-person-decision.3` @admin #admin: change one person's role to Moderator on the Members tab -> toast "Role updated." and he keeps that role after the next sync.
- `c-gating-roster-role-is-a-per-person-decision.4` @admin #rest: GET the members list -> the roles match the Members tab.
- `c-gating-roster-role-is-a-per-person-decision.5` @space_admin #admin: bob (space admin of Partner Program) changes a member's role there -> works; in another space refused 403 jetonomy_forbidden "You do not have permission to perform this action." ("Permission denied." is the wp-admin AJAX string, not reachable for bob).
- `c-gating-roster-role-is-a-per-person-decision.6` @member #rest !refuse: a member PATCHes his own role to admin -> 403 jetonomy_forbidden and the roster is unchanged.
- `c-gating-roster-role-is-a-per-person-decision.7` @admin #admin: demote the last admin of a space -> refused.
- `c-gating-roster-role-is-a-per-person-decision.8` @admin #admin +many: the Members tab with 300 people -> it pages and search finds Grace by name.
- `c-gating-roster-role-is-a-per-person-decision.9` @admin #admin +long: Grace's full name in the Members tab -> wraps in the row.
- `c-gating-roster-role-is-a-per-person-decision.10` @admin #admin +390: the Members tab at 390px -> rows stack.
- `c-gating-roster-role-is-a-per-person-decision.11` @admin #rest: POST /spaces/{id}/members with another user's id -> the person is added to the roster (today the route only joins the caller and answers 409 'You are already in this space.' for anyone a rule already admits).
- `c-gating-roster-role-is-a-per-person-decision.12` @admin #admin +many: the space Members tab with more than 50 people pages at 50 rows and its search box finds a member on a later page.
- `c-gating-roster-role-is-a-per-person-decision.13` @admin #rest !refuse: POST /spaces/{id}/members with a user_id as staff -> 201 and the person is on the roster; the same call as a subscriber -> 403; repeating it for the same user -> 409.

### C.gating.admission-without-a-roster-row
**Covers:** includes/admin/views/space-edit.php, includes/class-membership-roster-sync.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/adapters/class-adapter-registry.php, includes/adapters/class-member-press-adapter.php, includes/adapters/class-pmpro-adapter.php, includes/adapters/class-wp-roles-adapter.php, includes/adapters/interface-membership-adapter.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a person matched by a rule can use the space **without** appearing on the Members tab, unless Sync Members was pressed.
**Promise:** "The \"Sync Members\" button on a membership rule below is only for listing those people on the roster as well."

**Checks:**
- `c-gating-admission-without-a-roster-row.1` @admin #admin: add a Participate rule and do not press Sync Members -> the Members tab does not list the matched subscriber.
- `c-gating-admission-without-a-roster-row.2` @member #ui: the matched subscriber uses the space -> reads, posts and replies without being listed.
- `c-gating-admission-without-a-roster-row.3` @admin #admin: press Sync Members -> he is now listed as Member and the count rises by that number.
- `c-gating-admission-without-a-roster-row.4` @admin #admin: read the Sync Members help -> "The "Sync Members" button on a membership rule below is only for listing those people on the roster as well.".
- `c-gating-admission-without-a-roster-row.5` @guest #ui !refuse: a guest uses the space -> no admission without login.
- `c-gating-admission-without-a-roster-row.6` @member_b #rest !refuse: a person who does not match the rule still gets 403 jetonomy_forbidden on creating a topic, and 404 jetonomy_not_found on replying to a topic he cannot see.
- `c-gating-admission-without-a-roster-row.7` @admin #admin +many: Sync Members for a rule matching 2000 people -> it runs in batches and the member count equals the matches.
- `c-gating-admission-without-a-roster-row.8` @admin #admin +concurrent: press Sync Members twice -> no duplicate roster rows.
- `c-gating-admission-without-a-roster-row.9` @admin #admin +old-data: a space from before 2.0 with a membership rule -> the matched person still gets in without a roster row.
- `c-gating-admission-without-a-roster-row.10` @space_banned #ui !refuse: omar matches a rule for Off-Topic Lounge -> the ban wins, he cannot enter.

### C.gating.access-ends-by-itself
**Covers:** includes/admin/views/space-edit.php, includes/models/class-access-rule.php, includes/permissions/class-permission-engine.php, includes/class-membership-roster-sync.php, includes/models/class-space-member.php, includes/notifications/class-notifier.php, pro:includes/adapters/class-tag-space-integration.php
**Verified:** 64212bb5
**What to verify:** remove the rule (or let the membership lapse where a plugin provides one). Access closes on the next page load, with no admin action.
**Promise:** "Access begins the moment a plan becomes active and ends when it lapses. There is nothing to sync or undo by hand."

**Checks:**
- `c-gating-access-ends-by-itself.1` @admin #admin: delete the rule that admitted the subscriber -> toast "Access rule deleted.".
- `c-gating-access-ends-by-itself.2` @member #ui: the subscriber reloads the space -> closed on the next load, with no admin action.
- `c-gating-access-ends-by-itself.3` @member #rest !refuse: the subscriber POSTs a reply right after -> refused with 404 jetonomy_not_found (the topic is no longer visible to him) and nothing is stored.
- `c-gating-access-ends-by-itself.4` @member #ui +old-data: let a membership plan lapse (or move expires_at into the past) and wait for the hourly reconcile -> the roster row is removed and access closes.
- `c-gating-access-ends-by-itself.5` @member #ui: his bookmarks, subscriptions and notifications from that space disappear from the lists.
- `c-gating-access-ends-by-itself.6` @member_b #ui: another person who is a roster member (not rule-admitted) keeps access.
- `c-gating-access-ends-by-itself.7` @member #ui: the topic URL he had open returns the same 404 page as a missing topic.
- `c-gating-access-ends-by-itself.8` @member #email: no email arrives for new replies in the space after access ended.
- `c-gating-access-ends-by-itself.9` @member #ui +concurrent: access ends while he is typing a reply -> the submit is refused with a message and his text is preserved.
- `c-gating-access-ends-by-itself.10` @admin #admin: re-add the rule -> access returns on the next load without Sync.
- `c-gating-access-ends-by-itself.11` @admin #admin: delete a membership rule after Sync Members was pressed -> the synced people lose access on the next load (today the roster rows stay until the hourly reconcile, so they keep posting).
- `c-gating-access-ends-by-itself.12` @member #email: after access ends, a reply to the person's own topic in that space sends no email and no bell row.
- `c-gating-access-ends-by-itself.13` @member #rest: a member admitted only by a Role, Capability, Trust Level or Logged In rule (no roster row) still sees their own follows, bookmarks and reply notifications from that space; one removed from a roster-only space sees none of them (adding a manual roster row must not be needed for the rule-admitted member).

### C.gating.builder-explains-itself
**Covers:** includes/admin/views/space-edit.php, includes/admin/views/spaces.php, includes/models/class-access-rule.php, includes/admin/class-admin.php, includes/api/class-rest-auth.php, assets/js/admin-common.js, assets/css/admin.css
**Verified:** 64212bb5
**What to verify:** on the Access Rules tab, step through every entry in the Rule type dropdown. The tab intro and empty state say a rule only lets more people in; visibility and join policy decide who else gets in. Each type shows a second sentence under the live preview saying **who** the rule catches, and the value box carries an example for that type. **Everyone** and **Logged In** show no value box at all. A rule needs a value that can work: a trust level rule with no level (or outside 1-5), a role that does not exist, or an empty capability or membership value is refused with a message, so it can never admit every member.
**Why it matters:** picking the wrong rule is silent - it saves, the space looks configured, and the mistake only surfaces when the wrong people are in the room.

**Checks:**
- `c-gating-builder-explains-itself.1` @admin #admin: open the Access Rules tab -> the intro and empty state say a rule only lets more people in, and that visibility and join policy decide who else gets in.
- `c-gating-builder-explains-itself.2` @admin #admin: step through every Rule type entry (Everyone, Logged In, Role, Capability, Trust Level, Membership) -> each shows a second sentence under the live preview saying who the rule catches and an example in the value box.
- `c-gating-builder-explains-itself.3` @admin #admin: choose Everyone and Logged In -> no value box is shown.
- `c-gating-builder-explains-itself.4` @admin #admin !refuse: save a Trust Level rule with no level, level 0 and level 9 -> refused with "Choose a trust level from 1 to 5.".
- `c-gating-builder-explains-itself.5` @admin #admin !refuse: save a Role rule with the role "ghost_role" -> refused with "Choose a role that exists on this site.".
- `c-gating-builder-explains-itself.6` @admin #admin !refuse: save an empty Capability and an empty Membership value -> refused with "Enter a value for this rule." and no rule is created.
- `c-gating-builder-explains-itself.7` @admin #rest !refuse: POST /spaces/{id}/access-rules with the same bad values -> 400 with the same messages, and GET /spaces/{id}/access-rules does not list them.
- `c-gating-builder-explains-itself.8` @space_admin #admin !refuse: test_editor opens the Access Rules tab URL for Help Desk -> "Only site administrators can manage access rules for this space." and a link back to the space; no builder.
- `c-gating-builder-explains-itself.9` @member #rest !refuse: the subscriber POSTs a rule -> 403.
- `c-gating-builder-explains-itself.10` @admin #admin +390: the builder at 390px -> dropdown, preview and sentence stack and the Add button is 40px.
- `c-gating-builder-explains-itself.11` @admin #admin +dark: builder in a dark scheme -> text readable.
- `c-gating-builder-explains-itself.12` @admin #admin +rtl: builder in Arabic -> the example text is right to left.
- `c-gating-builder-explains-itself.13` @admin #admin: pick Participate with any rule type (Logged In, Role, Capability, Trust Level, or a membership plan) -> the preview says "They are recorded as Member."; after Add Rule the Space Role column shows Member; POST /spaces/{id}/access-rules with grants=full and space_role=admin stores grants participate and space_role member.

### C.gating.surfaces-agree
**Covers:** includes/class-visibility.php, includes/permissions/class-permission-engine.php, includes/search/class-fulltext-search.php, templates/views/home.php, templates/partials/sidebar.php, includes/api/class-search-controller.php, includes/api/class-base-controller.php, includes/api/class-posts-controller.php, includes/class-template-loader.php, includes/models/class-space.php
**Verified:** 83f00772
**What to verify:** for a person who is NOT admitted to a private space, confirm every surface agrees they cannot see its topics: the space page, its topics, site search, tag pages and tag counts, the community home feed, the sidebar, the sitemap and the live-updates feed. A topic URL inside a hidden space returns the same 404 page as a missing topic. The one allowed difference is the optional locked preview card for logged-out visitors (Settings > General > Access Control, off by default), which shows the space name and how to request access but never its topics. A surface that shows content another surface refuses is a bug even when neither is obviously "wrong".
**Why it matters:** admission is answered in more than one place in the code. Several past bugs were one surface disagreeing with another, and only a human comparing screens catches that.

---

**Checks:**
- `c-gating-surfaces-agree.1` @member #ui !refuse: bob (not a member of Customer Advisory Board, private and invite-only) opens its space page -> the invite-only message with the space title and description, and no topics; the hidden Moderators' Room shows the same page as a missing space (404).
- `c-gating-surfaces-agree.2` @member #ui !refuse: bob opens a topic URL in Customer Advisory Board -> the same 404 page as a missing topic, and the page title does not reveal that it exists.
- `c-gating-surfaces-agree.3` @member #ui !refuse: bob searches a unique word from a topic there -> "No results for ...".
- `c-gating-surfaces-agree.4` @member #ui !refuse: bob opens the tag pages for tags used there -> the topics are not listed and the tag count excludes them.
- `c-gating-surfaces-agree.5` @member #ui !refuse: bob loads the community home feed and the sidebar (trending, recent) -> no topic or space name from there appears.
- `c-gating-surfaces-agree.6` @guest #rest !refuse: the guest fetches /community-sitemap.xml and /wp-json/jetonomy/v1/updates?scope=global -> neither lists that space's topics (updates answers 401 for global).
- `c-gating-surfaces-agree.7` @member #rest !refuse: bob GET /posts/{id} and /posts/{id}/replies in Partner Program -> 404; GET /spaces/{id}, /spaces/{id}/posts and /search?space_id={id} -> 403 jetonomy_forbidden; the same three for a hidden space or id 99999 -> 404 with the same body.
- `c-gating-surfaces-agree.8` @guest #rest !refuse: GET /updates?scope=post&id=<private post> -> refused (404 jetonomy_not_found for a private post, matching the 404 posture for reads) and the 15-second poll on a public topic gives 200.
- `c-gating-surfaces-agree.9` @guest #ui +settings-off: with the locked preview off (default) open Partner Program logged out -> no preview card, no title.
- `c-gating-surfaces-agree.10` @guest #ui: turn on Settings > General > Access Control locked preview -> a card shows the space name and how to request access, and still no topics.
- `c-gating-surfaces-agree.11` @member_b #ui: alice (member of Partner Program) sees its topics on the space page, search, tag and REST -> all agree (the home page is a spaces grid with no topic feed, and the sidebar Trending is a top-voted cut, so a topic missing there is not a disagreement).
- `c-gating-surfaces-agree.12` @moderator #ui: test_moderator sees Moderators' Room topics on all those surfaces, while bob sees none -> agreement on both sides.
- `c-gating-surfaces-agree.13` @member #ui +concurrent: remove bob from Partner Program in one tab and reload each surface in another -> every surface drops its topics together on the next load.
- `c-gating-surfaces-agree.14` @member #rest +old-data: topics created before a space became private -> they vanish from every surface for non-members, not just the space page.
- `c-gating-surfaces-agree.15` @member #ui +many: with 2000 topics across private spaces, open home -> counts and lists load in one pass and no topic leaks on page 2.
- `c-gating-surfaces-agree.16` @guest #ui: open a private space URL logged out with the locked preview off -> the document title is the site name only, never the space name.
- `c-gating-surfaces-agree.17` @guest #ui !refuse: open a private space URL logged out with the locked preview off -> the page shows only "This space is private", with no space name or description anywhere in the markup.

## D - Known-regression guards

Each row is a repro of a past bug that caused customer pain. These rows stay specific on purpose: the exact fixture IS the contract.

| ID | Bug | Fixture + assertion |
|----|-----|---------------------|
| D.rewrite-flush | 1.3.5 rewrite rules not flushed on activation | Clean reactivate; first `/community/s/<slug>/` request returns 200 |
| D.ppg-stacking | IntersectionObserver loaded page 2 when trigger already visible (scroll loading was removed; Load More is click-only) | With `posts_per_page=1`, page loads 1 row; scrolling to the bottom loads nothing; one Load More click adds 1 row, not 2 |
| D.share-scroll-detach | Share dropdown stayed in place while page scrolled | After opening share dropdown, scrollBy(0, 200) removes the dropdown from DOM |
| D.login-dark-follows-theme | **Inverted in 1.8.0 — read this before testing.** The original 1.x guard asserted the login block "stays light" in dark mode. That pinning WAS the bug: the block re-declared the token set instead of inheriting it, so a hand-written per-block rule in `jetonomy.css` held it light and it rendered a white card on a dark page (`assets/css/blocks.css:25-46`; free `CLAUDE.md` → CSS Token Rules → "Never declare tokens on `.jt-app`"). The parallel `--jtb-*` namespace was deleted and the block now inherits `--jt-*` from `:root`. | With `emulateMedia({ colorScheme: 'dark' })`, the login block background FOLLOWS dark mode — it must NOT stay light. Assert `.jt-login-block` computed `background-color` is the dark surface (tracks `--jt-bg`), and that `.jt-login-title` still contrasts it at ≥ 4.5:1 (measured ~11:1 on 1.9.4). Structurally, no rule anywhere in `assets/css/*.css` may pin `.jt-login-block` to a fixed background, and `blocks.css` must contain zero live `--jtb-*` declarations (the only two matches are historical comments). |
| D.profile-tabs-clipped | Profile tabs fell off-screen at 390px | At 390px on /community/u/admin/drafts/, the active Drafts tab is scrolled into view |
| D.is-online-misaligned | Dot rendered under the avatar name text | Dot center within 12px of avatar's top-right corner on all 4 avatar sizes, all 3 contexts (topic, profile, home) |
| D.firefox-time-picker | `<input type="time">` had no native popup in Firefox | Composer uses `<select name="published_hour">` + `<select name="published_minute">`; REST accepts ISO and stores as MySQL datetime |
| D.messaging-trust-asymmetry | TL0 participant could not reply to their own DM | Seed a conversation with a TL0 member, POST a reply from that member, expect HTTP 201 |
| D.akismet-staff-block | Admin posts routed through Akismet causing lag | `Base_Controller::author_bypasses_spam_check(admin, admin)` returns true |
| D.mod-queue-spam-filter | `status=spam` filter returned pending too | Seed a spam reply; GET moderation queue with `status=spam` includes only spam, not pending |
| D.space-titles-entity-encoded | Legacy rows had `&amp;` stored in title | No row in `wp_jt_spaces.title` / `wp_jt_categories.name` / `wp_jt_posts.title` matches `%&amp;%`, `%&quot;%`, `%&#039;%`, `%&lt;%`, `%&gt;%` |
| D.search-or-mode-relevance | Similar-topics typeahead returned unrelated posts | Type "E2E time picker test" in new-post title; no returned title's only overlap is "test"; all suggestions share ≥ 2 tokens of length ≥ 4 with the query |
| D.space-settings-merge | PATCH /spaces/:id replaced settings JSON instead of merging | Pre-fill settings with two keys; PATCH with one key; re-read confirms both keys present |
| D.custom-fields-fatal-on-create | sanitize_title() received WP_REST_Request object | POST /jetonomy/v1/fields without a slug body param; expect HTTP 201 and no new fatal in debug.log |
| D.sort-enum-oldest-newest | REST schema advertised sort=oldest/newest, model silently no-op'd them | GET /jetonomy/v1/spaces/1/posts?sort=oldest and ...?sort=newest return distinct id orders from sort=latest; GET ...?sort=unanswered returns HTTP 200 (previously 400 - enum blocked a valid filter); GET /jetonomy/v1/bookmarks?sort=unanswered still returns HTTP 400 (scope-limited override on posts route only) |
| D.space-title-dark-mode-contrast | Reign theme inline customizer hardcoded h1..h6 colour; plugin had no .jt-dark override, so space titles rendered near-black on near-black in Reign dark mode (contrast ~1.01:1) | On a Reign-active site with body class `jt-dark`, navigate to `/community/s/welcome/`; assert `getComputedStyle(document.querySelector('.jt-app h1')).color` yields a WCAG AA-passing contrast (>= 4.5:1) against body background. Plugin CSS override `.jt-dark .jt-app h1..h6 { color: var(--jt-text) }` must stay scoped to `.jt-app` so theme pages outside plugin surfaces are not affected. |
| D.accent-tint-dark-mode | `--jt-accent-light` / `--jt-accent-muted` tokens mixed accent with white in the light-mode defaults, and the dark-mode block did not re-derive them, so every surface using them (unread notifications, pinned rows, vote hover, composer focus, tag hover, accent badges) rendered a bright mint patch on the dark panel | With body `jt-dark`, open `/community/notifications/` and inspect `.jt-notif-item.unread` computed background: average RGB channel must be under 100 (dark tint), not near-white. Dark-mode block in `assets/css/jetonomy.css` must override both tokens to mix accent with the dark panel colour, not white. |
| D.warn-notice-dark-contrast | `--jt-warn-dark` used as text on dark-mode `--jt-warn-light` bg (locked-space banner, `.jt-notice-warning`) failed WCAG AA contrast at 3.28:1 | In dark mode, inject a `.jt-status-banner--locked` into `.jt-app`, read its `getComputedStyle` text and background colours, compute WCAG contrast ratio; assert >= 4.5:1. Dark-mode override for `--jt-warn-dark` must re-derive against white so the warm text stays legible on the dark warn background. |
| D.rule-role-escalation | 1.9.1 SECURITY. All nine membership adapters wrote the access rule's raw `space_role` onto the roster, so a rule set to Read with `space_role=admin` made every enrolling learner a space admin. `Permission_Engine` Layer 0d reads the roster role and grants moderation before the capability check | Store a membership rule with `grants=read, space_role=admin`. Activate a matching level for a plain subscriber (fire the adapter's enrolment hook; no LMS need be installed - `apply_level()` only reads `jt_access_rules` and writes the Jetonomy roster). Assert the roster role is `viewer`, and that `moderate`, `edit_others_posts`, `delete_others_posts` and `pin_posts` are all denied. Repeat with `grants=participate` and assert the roster role is `member` and the person CAN post and reply |
| D.reaction-chip-delegation | 1.9.1. The reaction chip carried its own `data-wp-on--click`. The Interactivity API wires those during its render pass, so the chip the optimistic path builds with `createElement()` after that pass had the attribute and no live listener - it looked right and did nothing until a reload. The handler now lives on the always-server-rendered `.jt-reactions` wrapper and resolves the clicked control from the event, so no descendant may carry a `toggleReaction` binding (a second one would fire the action twice and cancel the reaction out) | On a post with no reactions: open the picker, pick an emoji, then click the chip that appears WITHOUT reloading - assert it disappears and, after a reload, the server still shows no chip. Then react again, reload so the chip is server-rendered, click it, reload - assert gone. Assert the picker click persists exactly one reaction (count 1 after reload, not 0 - a double-fire toggles on then off). Structurally: `.jt-reactions[data-wp-on--click]` is present and `.jt-reactions [data-wp-on--click="actions.toggleReaction"]` matches ZERO descendants |
| D.sidebar-undefined-space | 1.9.1. The trending-widget cache captured `$space` in a closure `use()` clause. `$space` only exists on space-scoped templates, so every other page rendering the sidebar raised `Undefined variable $space`. The capture happens at closure creation, so cache hits produced it too - one log line per page view on any site with logging on | With `WP_DEBUG_LOG` on, load community home, category, leaderboard, search, notifications, member profile and tag. Assert ZERO occurrences of `Undefined variable $space` in `debug.log`, and that the Trending widget still returns items on BOTH a space page and a non-space page |

| D.email-optout-wiring | 1.5.0: the verification reminder honours `jetonomy_email_opt_out` user meta, which previously had no surface to set it. Three entry points must all write the same meta | Frontend: on `/community/u/{me}/edit/` the notification card shows a "Pause all email notifications" checkbox; checking it + Save sets the meta to 1, unchecking + Save clears it (verify via `wp eval` get_user_meta). REST: `PATCH /jetonomy/v1/users/me {email_opt_out:true}` sets the meta and `GET /users/me` echoes `email_opt_out:true`; `false` clears it (qa-actions E27b). Admin: `wp-admin/user-edit.php?user_id=N` shows a "Jetonomy > Community emails" checkbox that saves the same meta (nonce + edit_user gated). |
| D.bp-integration-toggles | 1.5.0: `jetonomy_bp_broadcast` and `jetonomy_bp_comment_bridge` options (default ON) had no UI - only `wp option update` could disable them | With BuddyPress Groups active, Settings shows an "Integrations" sidebar tab (absent when BuddyPress is inactive) with two checkboxes in a BuddyPress card, both checked by default. Unchecking either + Save writes `'0'` to the matching option (verify via `wp option get`); the read methods `Integrations\BuddyPress::broadcast_enabled()` / `comment_bridge_enabled()` return false. The options live in their own `jetonomy_integrations` settings group, so saving any other settings tab does NOT reset them. |
| D.block-title-theme-contrast | 1.5.0: block titles use a bare single-class selector (.jt-login-title etc., specificity 0,1,0) that loses to theme content-heading rules like `.entry-content h3` (0,2,1); on dark surfaces the theme's dark heading colour rendered the title dark-on-dark (the login block's "Join the conversation" was near-invisible on Reign) | On a logged-out page containing the login block (e.g. `/jt-qa-block-login/`), read `.jt-login-title` computed `color` and its `.jt-login-block` computed `background-color`; the title luminance must contrast the card (not within ~30 of each other). Inject a test rule `.page .entry-content h3 { color: rgb(35,40,48); }` and re-read - the title colour MUST NOT change (the `.jt-login-block.jt-app .jt-login-title` rule at 0,3,0 outranks it). Same guarantee for `.jt-nav-title`, `.jt-userpanel-title`, `.jt-feed-title`, and `.jt-compose-topic-login-title`. |
| D.avatar-square-crop | 1.5.0: uploading a profile avatar opens a square-crop dialog (drag-to-reposition + zoom slider) and saves a square image; the page must not lose the existing "upload then save" staging flow, and animated GIFs must bypass the cropper to keep animation | On `/community/u/{me}/edit/`, click "Upload photo" and choose a non-square PNG: a `<dialog>` titled "Crop your photo" opens with a circular crop ring over a square viewport; the zoom slider scales the image; "Crop and upload" POSTs to `/jetonomy/v1/media`, closes the dialog, and updates `#jt-avatar-preview` + the hidden `avatar_url` input with the new URL; the uploaded file is square (width == height). "Cancel" and Esc both close the dialog with zero console errors and no upload. Verify at 390px: dialog is full-width with >=40px buttons. A `.gif` upload skips the dialog and uploads directly. |
| D.space-rss-feed | 1.5.0: space RSS feeds at `/community/s/{slug}/feed/` gate on what an ANONYMOUS visitor may read - a member's cookie must never widen what the URL exposes, and private/hidden spaces must 404 rather than leak titles to feed readers/aggregators | Anonymous: `GET /community/s/<public-space>/feed/` returns HTTP 200, content-type `application/rss+xml`, body parses as XML with `<rss` root and at most 20 `<item>` entries whose links resolve to live post URLs. `GET /community/s/<private-space>/feed/` and a nonexistent slug both return 404. Space page `<head>` contains `<link rel="alternate" type="application/rss+xml">` for public spaces only. Covered by qa-actions E32. |
| D.anon-updates-401 | 1.4.5: `/updates` permission_callback admitted anonymous callers in public mode but the handler unconditionally `require_auth()`'d, so every logged-out visitor on a single-post page got a 401 each 15s poll cycle and the new-replies banner never worked for them | Anonymous (cookies cleared): `GET /jetonomy/v1/updates?scope=post&id=<public-post-id>&since=<ISO>` returns HTTP 200; same with a private-space post id returns 403; `scope=global` returns 401. NOTE: the route arg is `id`, NOT `post_id` - probing with the wrong arg name 401s by design (id missing -> auth path) and is a false failure. Browser check: anonymous single-post page over one full 15s poll cycle produces zero 401 responses on `/updates`. |
| D.sitemap-rule-priority | 1.9.5 SEO. `add_rewrite_rule( …, 'top' )` only prepends against the rules that exist WHEN IT RUNS, so 'top' is a last-writer-wins race. Yoast does not use `rewrite_rules_array` at all - it filters `option_rewrite_rules`, injecting on every READ, after any generation-time ordering. Its catch-all `([^/]+?)-sitemap([0-9]+)?\.xml$` landed at rule #2 while ours sat at #44, so /community-sitemap.xml 404'd on every site running an SEO plugin. Rank Math and AIOSEO register the same shape | With Yoast SEO ACTIVE (this is the whole point - the bug is invisible without a second plugin present): `GET /community-sitemap.xml` returns 200 and parses as XML with a `<sitemapindex>` root; `/community-sitemap-spaces-1.xml` and `/community-sitemap-posts-1.xml` return 200 with `<urlset>` roots. Yoast's OWN `/sitemap_index.xml`, `/post-sitemap.xml` and `/page-sitemap.xml` must all still return 200 - the reorder must not evict them. Then re-walk the community routes and assert no regression: a rule-ordering change touches EVERY route. Unit cover: `tests/unit/RouterRulePriorityTest.php` |
| D.plugin-deactivation-cycle | 1.9.5. Jetonomy Pro 1.9.4 could not be deactivated at all: `Extension::deactivate()` called `Batch_Reviewer::unschedule()` with no `require_once`, and jetonomy-pro has no autoloader, so turning Pro off threw `Class ... Batch_Reviewer not found` and left the plugin ACTIVE. Nothing anywhere exercised deactivation - the boot smoke only activates and boots - so a fatal in a `deactivate()` path was structurally invisible | For BOTH plugins, and in both orders: `wp plugin deactivate <slug>` then `wp plugin activate <slug>`. Assert each command reports success, `wp plugin list` shows the expected state after each step, and `debug.log` gains ZERO `Fatal error` lines across the whole cycle. **A fatal-free cycle is NOT sufficient** - the 1.9.5 smoke found that deactivating and reactivating both plugins in ONE command silently drops Pro extension rewrite rules from the compiled table: `/community/messages/` went 200 -> 301 with the rule simply absent and no error logged, and an owner updating both plugins loses those routes until permalinks are flushed. So after the cycle ALSO assert that routes still RESOLVE: `/community/`, `/community/messages/` (Pro) and `/community-sitemap.xml` each return their expected status, and `get_option('rewrite_rules')` still contains `jetonomy_route` entries. Deactivate Pro while Free stays active, and deactivate Free while Pro is active (Pro must degrade, not fatal). This cycle is cheap and catches an entire bug class that no other check reaches |
| D.shortcode-misconfig-notice | 1.9.5. Five paths returned an empty string when a shortcode attribute was missing or wrong, so 2 of the 8 documented shortcodes rendered NOTHING on a default paste and an owner could not tell a broken shortcode from an empty one. `jetonomy_space_members` also demanded a numeric `space_id` that no admin screen displays | As a user who CAN `edit_posts`: `[jetonomy_space_members]` and `[jetonomy_widget]` each render a `.jt-shortcode-notice` naming the attribute to add; `[jetonomy_widget id="bogus_widget_id"]` renders the UNKNOWN-ID message listing valid ids (not the "needs an id" message - the branch was wrong once already); `[jetonomy_user_profile user_id="999999"]` names the missing user. As an anonymous visitor, every one of those renders ZERO bytes - editor guidance must never surface publicly. Configured cases still render: `[jetonomy_space_members space_id="<slug>"]` and `space_id="<numeric id>"` both produce the roster |
| D.closed-topic-moderator-reply | 1.9.7. `templates/views/single-post.php` renders the reply composer plus an explicit "This topic is closed. As a moderator, you can still take part." notice on a closed topic, while `Content_Gate::check()` rejected every writer unconditionally - so the UI offered a reply the server answered with 403. An enforce-vs-display split: the template was right and the gate was wrong. The exemption went into the GATE, not the controller, because three writers share it (REST, the WP Abilities writer, the inbound-email writer) and a controller fix would have left two behind | Close a topic in a space where the test user holds the space role `moderator`. As that moderator: the single-post page renders `.jt-reply-composer` AND the `.jt-closed-notice--staff` notice, and `POST /jetonomy/v1/posts/<id>/replies` returns **201**. As an ordinary member of the same space: the composer is ABSENT, the plain `.jt-closed-notice` shows instead, and the same POST returns **403 `jetonomy_post_closed`**. Both directions are required - fixing only the first turns a display bug into a permission hole. After the moderator's reply, re-read the post and assert `is_closed` is STILL 1: replying must not silently reopen the topic and undo the moderator's own action. Unit cover: `tests/unit/permissions/ContentGateClosedPostTest.php` |
| D.route-render-priority | 1.9.7. `Router::handle_request()` renders and `exit`s, so its `template_redirect` priority is the point past which NO other callback on that hook runs. At the default 10 it silently swallowed everything registered after it: a maintenance-mode plugin at 11+ never saw the request and Jetonomy pages stayed public while the rest of the site was closed. Same hole for any access gate (WP Fusion's content restriction sits at 13 and 15 on the reference install) and for core's header emitters at 11. Worse on a site using "Community as homepage", where `/` itself is a Jetonomy route and so bypassed the gate too. Rendering moved to 9999 - but that alone would trade one bug for another, because `WP_Query::handle_404()` can flag a paginated route 404 and BuddyX Pro's `buddyx_404_redirect()` (priority 10) would 301 it to the theme's custom 404 page. So the not-404 assertion split out to priority 0 and the render trails at 9999 | Register a throwaway mu-plugin that `exit`s a 503 on `template_redirect` at a priority read from a query arg, skipping admins. Logged OUT, request a Jetonomy topic URL at priorities **5, 9, 10, 11, 20 and 100** - ALL SIX must return 503, not just those below 10. Repeat on the site root with `front_page` mapping enabled. Control: a plain WP page must 503 identically at the same priority, and with no gate registered the Jetonomy page must still render 200. Regression side: arm the theme's custom-404 redirect (`set_theme_mod('buddyx_404_page', <id>)`) and confirm paginated Jetonomy routes behave EXACTLY as before the change - the pre/post output must be byte-identical, since two of them legitimately 404. Remove the probe mu-plugin afterwards. Unit cover: `tests/unit/RouterHookPriorityTest.php` |
| D.mail-sender-scoped | 1.9.7. A `From:` header alone does not survive: `wp_mail()` parses it, then passes the address through `wp_mail_from`, so any plugin filtering site-wide gets the last word. Learnomy's `Notification_Service::filter_mail_from()` returns its own sender at priority 20 without inspecting the incoming value, so a community configured as community@example.com sent as the WordPress admin address, breaking SPF/DKIM alignment on every notification. Jetonomy now registers `wp_mail_from` / `wp_mail_from_name` at `PHP_INT_MAX` around its OWN send and removes them in a `finally` | With Learnomy active and a distinct sender configured in Jetonomy Settings > Email, trigger a Jetonomy notification and read the delivered message (Mailpit at `:10112`, or `wp_mail` capture): the From must be the CONFIGURED sender. **Then send a non-Jetonomy `wp_mail()` in the same request and assert it is UNTOUCHED** - if the scoped filters leaked we have simply pointed the same bug outward, which is the same bug. Third: make a send throw from `phpmailer_init` and assert the filters are still gone afterwards, since one failed send would otherwise strand them for the whole request. Unit cover: `tests/unit/adapters/WpMailAdapterSenderTest.php` |
| D.tags-deep-pagination | 1.9.7. `GET /tags` accepted a `limit` capped at 100 and hard-coded `offset => 0` while returning a real `COUNT(*)` as the total, so the response advertised a total the caller could never reach. Tag 101 was unreachable at every parameter combination and the truncation was SILENT - the mobile app fetched one alphabetical page with no way to know more existed. A bbPress import passes 100 tags easily | Seed 150+ tags. For BOTH `sort=alphabetical` and `sort=popular`: `GET /jetonomy/v1/tags?limit=100&offset=0` returns 100 rows, `&offset=100` returns the remainder, `meta.offset` echoes the request, and the two pages share ZERO ids. Walking every page must reproduce `meta.total` exactly - no gaps, no repeats; a per-page eyeball would miss overlapping windows. `offset` past the end returns an empty page, not an error. Remove the seeded tags afterwards. Unit cover: `tests/unit/models/TagListPopularOffsetTest.php` |
| D.list-pagination-pg | 2.0.2 (Zoho #42138). Members, tag, notifications, moderation and space-moderation lists linked Next with WordPress's `?paged`; sites that redirect it to `/page/N/` (a path no community route has) sent Next to a 404 | On a space with 26+ members, `/community/s/{slug}/members/` Next is `?pg=2` and loads "Page 2 of N". No Prev/Next link on those five lists contains `paged=`. A legacy `?pg` link and an old `?paged=2` link (on a site that does not rewrite it) both open page 2. Regression test: `OwnerPassFixesTest::test_list_pagination_never_links_with_the_wordpress_paged_param` |
| D.join-request-claim-first | 2.0.2 developer clearance. `JoinRequest::approve()` added the member before settling the request, so a deny or withdraw landing between the two left a denied (or deleted) request with a live membership | Approve a pending request while a deny for it lands in the same moment (a `jetonomy_before_join_space` hook that calls deny does this): the request ends approved AND the person is a member, or neither. Same for withdraw. Approving a request on a locked space returns an error and the request stays pending. Regression tests: `DevClearanceFixesTest` (deny, withdraw, refused admission) |
| D.group-sync-archived | 2.0.2 developer clearance. BuddyPress group join (and unban, promote, demote of a non-member) and FluentCommunity join/reconcile called `SpaceMember::add()` directly, so a linked archived or locked space, or one that bans the person, still took them in | Link a BuddyPress group to a space, archive the space, join the group as a new user: the user is NOT a space member. Same for a locked space and a space-banned user. An active linked space still adds them. Regression test: `DevClearanceFixesTest::test_linked_group_join_cannot_enter_an_archived_or_locked_space` |
| D.handover-rollback | 2.0.2 developer clearance. `Space::hand_over()` promoted the heir first and ignored the result of the ownership and archive-marker writes, so a failed hand-over left the heir as a space admin, and a missing marker left a new owner unable to unarchive | Force the ownership UPDATE to fail: hand_over returns an error, the heir is back to their previous role (or not a member), the owner is unchanged. Force the marker write to fail on an archive hand-over: the space is back to its previous owner and status. Regression tests: `DevClearanceFixesTest::test_failed_*` |
| D.vote-denied-listing | 2.0.2 developer clearance. Topic lists and feed cards showed a silenced or read-only member bare chevrons that did nothing; only the topic page explained | As a silenced member, a space's topic list shows the score with "Voting is not available to you here." as label and tooltip, and a click shows that toast. Members who can vote keep the live buttons; guests keep the inert score |
| D.guest-reactions | 2.0.2 developer clearance (Pro). A visitor could open the reaction picker, pick an emoji, and only then be sent to the login screen | Logged out on a topic with reactions: chips are plain counts (no button, clicking does nothing), the React control is a "Log in to react" link back to this topic, and no picker exists. Logged in, chips and the picker toggle as before. Regression test: `ReactionsTest::test_guest_sees_static_counts_and_a_login_link` |
| D.poll-voted-bar | 2.0.2 developer clearance (Pro). The voted option's background used the same tint as the result bar, so its bar always looked 100% full | Vote in a poll where your option has under half the votes: its bar width matches its percentage, and the option is marked only by its accent border and checked input |
| D.rest-no-login | 2.0.2 smoke walk. `author_login` (topics, replies), `actor_login` (notifications) and `/users/suggest` `login` returned `user_login`, which is an email on email-as-username sites, to anyone who could read the content | Logged out, `GET /wp-json/jetonomy/v1/spaces/{public}/posts` and `/posts/{id}/replies`: no field contains a login that differs from the nicename; `author_login` equals the author's handle (as in their `/u/{handle}/` profile link). Test: `OwnerPassFixesTest::test_public_rest_payloads_never_carry_an_email_login` |
| D.mod-trash-after-remove | 2.0.2 smoke walk. In a space's mod queue, Remove on a flag card and then the Trash tab (no reload) rendered "Topics 1" over an empty list: the card was removed by hand inside the router region and the soft navigation patched into the detached node | As a moderator on `/community/s/{slug}/mod/`, Remove a flag, click Trash: the removed topic is listed with Restore. Switching Flags / Awaiting approval / Trash with no Remove in between stays a soft navigation (no full reload) |
| D.breadcrumb-filter-seam | 1.9.7. Eighteen views render the trail through one shared partial and nothing filtered it, so a site wanting to restyle or relocate the breadcrumb had to override all eighteen templates. `jetonomy_breadcrumb_html` now wraps the partial. Its KNOWN CEILING is the point of this row: every view calls the partial immediately BEFORE opening `<main>` and no view exposes a hook inside `<main>`, so suppressing here and re-emitting inside the main region still needs one template override - this removes the need to override eighteen files, not the need to override any | On a category page, assert `.jt-crumb` renders by default. Add a filter returning `''` and assert `.jt-crumb` count is 0. Add a filter returning custom markup and assert it renders WITH the `$crumbs` array passed through (count matches the real depth, so a consumer can rebuild rather than reparse). Remove the filter and assert the default is restored. Structurally the hook must appear in `audit/manifest.json` `hooks_fired` and in `docs/website/developer-guide/02a-hooks-index.md` - a seam a developer cannot discover is not a seam |
| D.breadcrumb-placement | 2.0.1. Placement is now a filter: `jetonomy_breadcrumb_placement` returning `inside_main` moves the trail to the first child of `<main>` on all 18 views; the default stays before `<main>`. Check: on category, space, topic, roadmap, profile, search, subscriptions and moderation, `nav.jt-crumb` sits immediately before the content by default (the previous sibling of `main`, or of `.jt-two-col` on views with a sidebar) and `main.firstElementChild` with the filter. Roadmap gained the `<main>` landmark it never had, and My Subscriptions shows its trail (it passed the crumbs under the wrong key and printed nothing). |
| D.mod-queue-live-counts | 2.0.1. The flags panel shows the pending count in three places: the subtitle ("N pending flags"), the queue badge and the "Flags N" section tab. Check: as a moderator with 2+ pending flags in one space, dismiss them one by one without reloading. All three fall together, singular at 1 ("1 pending flag"), and at 0 the subtitle reads "0 pending flags" and the tab count is gone, matching a fresh load. |

Every customer-visible fix ships a matching D row in the same PR. After 2 clean releases of a D row, promote it into the main C/E flow.

---

## E - Pro extensions

Every active Pro extension gets a check here. Each contract covers the customer-visible promise, not the implementation.

### E.private-messaging
**Covers:** includes/class-router.php, templates/partials/header.php, pro:includes/extensions/private-messaging/*.php, pro:includes/extensions/private-messaging/views/*.php, pro:assets/js/pro-view.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a user can start a DM with another user, send a message, receive a reply, see read receipts, and block/mute. A TL0 user in an existing conversation can still reply. Unread count endpoint tracks correctly.

**Checks:**
- `e-private-messaging.1` @member #ui: open Messages (/community/messages/) as bob with no conversations -> the empty list reads "No conversations yet." and the right pane reads "Select a conversation or start a new one"; the Active/Archived tabs are present.
- `e-private-messaging.2` @member #ui: click new message, type "Ali" in the recipient field, pick alice, write "Hi Alice, saw your answer on the Help Desk thread about SMTP settings - could you share the plugin you used?" and press "Send Message" -> the conversation opens with the message shown as sent by bob, and the list row shows alice with a "just now"-style time.
- `e-private-messaging.3` @member_b #ui: as alice open Messages -> the conversation from bob is bold/unread, the header Messages badge shows 1, opening it clears the unread state and the badge drops to 0 without reload.
- `e-private-messaging.4` @member_b #rest: `GET /jetonomy-pro/v1/conversations/unread-count` before and after opening the thread, twice within 30s -> `data.unread_count` goes 1 then 0 (the 30s cache is busted by the read), never stays at 1.
- `e-private-messaging.5` @member_b #ui: reply "Thanks Bob, it was WP Mail SMTP with the Brevo preset" -> message appears at once; as bob the unread badge shows 1 and the conversation moves to the top of the list.
- `e-private-messaging.6` @member #rest: `POST /conversations` with the same recipient again -> HTTP 200 with `reused: true` and the same `conversation_id` (no second thread with alice), the message is appended.
- `e-private-messaging.7` @member #rest !refuse: `POST /conversations` with `recipient_ids: [alice]` but no `message` -> 400 `rest_missing_callback_param` "Missing parameter(s): message" (an empty-string message gives `jetonomy_validation` "Message content is required."); with no `recipient_ids` -> 400 `rest_missing_callback_param` (an empty list gives "At least one recipient is required."); with recipient id 99999 -> 400 "No valid recipients provided.".
- `e-private-messaging.8` @member #ui +old-data: a freshly registered trust level 0 member opens Messages and tries to start a conversation with a stranger -> refused with "You need a higher trust level to send messages. Participate in the community first." in the picker and on send (same text both places), REST 403 `jetonomy_insufficient_trust`.
- `e-private-messaging.9` @member #ui +old-data: a trust level 0 member who was invited into a thread by test_moderator replies in it -> the reply sends (HTTP 201), the trust gate applies to starting threads only.
- `e-private-messaging.10` @member #rest !refuse: bob `POST /conversations` to a member he shares no space with -> 403 `jetonomy_messaging_not_connected` "You can only message people you share at least one space with."; the recipient typeahead (`/messaging/recipient-suggestions`) does not list that member either.
- `e-private-messaging.11` @admin #rest: admin `POST /conversations` to the same stranger -> 201, the shared-space rule is bypassed for admins and global moderators only.
- `e-private-messaging.12` @member #ui: from a conversation, open the "More actions" menu and choose "Block user" -> the control flips to "Unblock user"; as the blocked member, sending in that thread returns 403 `jetonomy_blocked` "You can no longer send messages in this conversation." and the blocker receives no notification.
- `e-private-messaging.13` @member_b #rest !refuse: alice blocked bob site-wide from a profile, then bob `POST /conversations` to alice and `POST /conversations/{existing}/messages` -> both 403 (`jetonomy_messaging_blocked` "You cannot start a conversation with this person." / `jetonomy_blocked`), the old thread is not reopened.
- `e-private-messaging.14` @member #ui: choose "Mute notifications" then send a message from alice -> bob gets no bell item and no email for that thread; the menu now shows "Unmute notifications"; unmute and the next message notifies again.
- `e-private-messaging.15` @member #ui: "Archive conversation" -> the thread leaves Active and appears under Archived; an empty Archived tab reads "No archived conversations."; a new message from alice returns it to Active or keeps it archived consistently with the REST `archive` flag.
- `e-private-messaging.16` @member #ui +many: create a group conversation with maya and alice, then "Leave conversation" as bob -> bob's list no longer shows it, the others see "Bob left the conversation.", and opening the thread URL as bob now answers "You are not a participant in this conversation." on the page (REST answers 404 "Conversation not found.", the same as a missing id).
- `e-private-messaging.17` @member #rest !refuse: `POST /conversations/{direct id}/leave` on a one-to-one thread -> 400 "Only group conversations can be left."; `POST .../block` on a group -> 400 "Only direct conversations can be blocked. To leave a group, use the leave action.".
- `e-private-messaging.18` @member_c #rest !refuse: maya (not a participant) `GET /conversations/{bob-alice id}` and `/messages` -> 404 `jetonomy_not_found` "Conversation not found."; a nonexistent id -> the identical 404, so the two answers do not let her tell which ids exist.
- `e-private-messaging.19` @silenced #ui !refuse +silenced: finn opens an existing thread and sends -> refused with "Your account is currently silenced and cannot post." (REST 403 `jetonomy_user_silenced`, the same wording as topics and replies) and the composer text is kept.
- `e-private-messaging.20` @silenced #rest !refuse +silenced: finn `POST /conversations` to a member he shares a space with (new thread, first message) -> must be refused with the same silenced error; confirm no conversation row or message is created.
- `e-private-messaging.21` @member #ui +long: send a 1,500-character message with a 100-character unbroken URL and a paragraph break -> the bubble wraps inside the pane at 1440 and 390 with no horizontal scroll, the list preview is cut to one line ending in an ellipsis.
- `e-private-messaging.22` @member #ui +rtl: send "مرحبا، هل يمكنك مساعدتي في إعدادات البريد؟" to omar -> the bubble renders right-to-left with correct alignment and the list preview does not flip the layout.
- `e-private-messaging.23` @member #ui: send the text `if (a < b && c > d) { print("5 < 6"); }` -> the message reads back with the `<`, `&&` and `>` intact (not stripped or HTML-escaped twice).
- `e-private-messaging.24` @member #ui +many: with 60 messages in one thread, open it -> the newest messages show with "Load older messages" at the top; clicking it prepends older ones without losing scroll position; the list view shows "Page 1 of N" paging once there are more than a page of conversations.
- `e-private-messaging.25` @member #ui +deleted: open a thread whose other participant's account was deleted -> the name shows "Deleted User", the avatar is the default, and no PHP notice appears; sending is still allowed or refused with a clear message.
- `e-private-messaging.26` @member #ui +390 +dark: Messages at 390px -> list and conversation are separate screens with a back control, the composer is not hidden by the keyboard area, and dark mode uses token colours (no white bubbles).
- `e-private-messaging.27` @member #email: alice replies while bob is offline -> exactly one email "new message" arrives in Mailpit for the burst (a second and third message while unread add none), and opening the thread clears the bell item.
- `e-private-messaging.28` @member #ui +offline: send while the network is blocked -> the bubble is not left looking sent, an error shows, and the typed text stays so it can be resent.
- `e-private-messaging.29` @member_b #ui +concurrent: alice has the thread open in two tabs; bob sends -> both tabs show the message after poll/refresh and the unread count is not double-counted.
- `e-private-messaging.30` @guest #rest !refuse: logged-out `GET /conversations` and `/conversations/unread-count` -> 401 (not 200 with empty data), and /community/messages/ redirects to log in.
- `e-private-messaging.31` @member #ui: send "a < b && c > d" -> the thread shows it as that literal text, not HTML-decoded or cut off; a sender blocked by the other person cannot write into an existing thread; a failed send keeps the typed text in the box; a deleted account's name reads "Deleted User" in the conversation title and list.
- `e-private-messaging.32` @member #rest: unblocking a direct conversation needs POST /conversations/{id}/block with blocked:false; a bare POST re-blocks.
- `e-private-messaging.33` @member #ui: in a group thread the More actions menu lists Leave conversation for a participant who is not the last row of the list.
**Depth note:** "read receipts" in the runbook contract: the views only keep `last_read_at` for unread state; walk must record whether a "seen" indicator exists in the UI, and if not, raise it as a contract mismatch rather than skipping it. With WPMediaVerse active the extension is dormant (E.extensions-manager), so this walk needs WPMediaVerse inactive.

### E.polls
**Covers:** templates/partials/compose-fields.php, templates/views/single-post.php, pro:includes/extensions/polls/*.php, pro:assets/js/polls-frontend.js, pro:assets/js/pro-view.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an author can attach a poll to a new topic, multiple options render on the topic page, a member can vote once, vote counts update, results display honors the "hide results until vote" mode if set. A composer poll with a question but fewer than two options blocks the post with "Question and at least 2 options required.". The Polls admin list shows topic titles.

**Checks:**
- `e-polls.1` @member #ui: in the new-topic composer of General Discussion choose Add poll, ask "Which day suits the monthly community call?" with options "Tuesday 17:00 UTC", "Thursday 09:00 UTC", "Saturday 15:00 UTC", pick Single choice and press "Create Poll" -> the topic publishes and the poll shows all three options on the topic page.
- `e-polls.2` @member #ui !refuse: in the composer enter a question and only one option, press "Create Poll" -> the post is blocked with "Question and at least 2 options required." and no topic is created.
- `e-polls.3` @member #rest !refuse: `POST /posts/{id}/poll` with 2 options where one is blank -> 400 "A poll requires at least 2 non-empty options."; question over 500 characters -> 400 "Poll question must be 500 characters or fewer."; an option over 255 characters -> 400 "Poll option must be 255 characters or fewer."; bad `closes_at` -> 400 "Invalid close date format.".
- `e-polls.4` @member #rest !refuse: `POST /posts/{id}/poll` on a post that already has a poll -> 409 `jetonomy_duplicate` "This topic already has a poll."; the topic page still shows exactly one poll.
- `e-polls.5` @member_b #ui: alice votes for "Thursday 09:00 UTC" -> that option's count goes to 1 and its bar updates, "total voters" shows 1, and alice's choice is marked as hers.
- `e-polls.6` @member_b #ui: alice clicks a different option on a single-choice poll -> her vote moves (old option -1, new option +1), total voters stays 1, never 2.
- `e-polls.7` @member_b #rest: `DELETE /polls/{id}/vote` then again -> first returns success and counts drop; the second returns 404 "No vote found to remove.".
- `e-polls.8` @member_c #ui: on a Multiple choice poll maya ticks two options and submits, then unticks all and submits -> counts rise by one each, then return to zero; vote_count never goes below 0.
- `e-polls.9` @member_c #rest !refuse: single-choice poll, `POST /polls/{id}/vote` with `option_ids` of two options -> 400 "This poll allows only one choice."; an option id from another poll -> 400 "Option N does not belong to this poll."; no option -> 400 "At least one option is required.".
- `e-polls.10` @member_c #ui +concurrent: double-click Vote or submit from two tabs at once -> the option count rises by exactly one (no double count) and the percentages still sum to 100.
- `e-polls.11` @member #ui +closed: a poll with a past `closes_at` -> shows as closed and voting is refused with "This poll has closed." (REST 409/403 `jetonomy_closed`); unvote refused with "This poll has closed. Votes cannot be changed."; results still readable.
- `e-polls.12` @member #rest: `PATCH /polls/{id}` by the topic author to close and then reopen -> closed flag flips both ways and the page reflects it without a cache delay.
- `e-polls.13` @member_b #rest !refuse: alice `POST /posts/{bob's post}/poll` and `PATCH /polls/{id}` on bob's poll -> 403 "You do not have permission to create a poll on this post." / "You do not have permission to modify this poll."; moderator and space_admin succeed.
- `e-polls.14` @guest #ui !refuse: logged out, the poll results -> are visible on a public topic but the vote control asks the guest to log in; `POST /polls/{id}/vote` returns 401.
- `e-polls.15` @member #rest !refuse +private: bob votes on, and `GET /polls/{id}` for, a poll in Customer Advisory Board (bob not a member) -> 404 with the same code and message as a poll id that does not exist, and the same for the guest, so the answer reveals nothing.
- `e-polls.16` @silenced #rest !refuse +silenced: finn `POST /polls/{id}/vote` -> must be refused with the silenced reason; record what actually happens.
- `e-polls.17` @space_banned #rest !refuse +banned: omar votes on a poll inside Off-Topic Lounge -> refused, count unchanged.
- `e-polls.18` @member #ui +long: a poll with a 480-character question and a 250-character option -> wraps inside the card at 390px on Reign with no overflow; an Arabic question with Arabic options is right-aligned (+rtl).
- `e-polls.19` @admin #admin: wp-admin Polls -> list shows the topic title (not "Post #id") for each poll, with paging when there are more than a page; a poll whose topic was deleted shows a clear placeholder instead of a blank or fatal.
- `e-polls.20` @member #ui +settings-off: the "hide results until vote" mode is switched on -> before voting no counts or percentages show to a non-voter; after voting they appear; a setting that does not exist must be raised (see report).
- `e-polls.21` @member #ui: vote on a single-choice poll -> its count rises by exactly one and the total by one; change the vote -> one count moves from the old option to the new and the total is unchanged.
**Depth note:** the runbook names a "hide results until vote" mode; the extension code read for this checklist exposes no such setting, so check 20 is a contract question for the owner, not a walk step that can pass.

### E.reactions
**Covers:** templates/views/single-post.php, templates/partials/reply-card.php, assets/js/view.js, pro:includes/extensions/reactions/*.php, pro:assets/js/pro-view.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** a member can add and remove a reaction on any post or reply they can see; per-reaction counts are accurate; hovering, focusing (Tab) or long-pressing a reaction shows who chose it ("Like: Maya, Bob and 3 others", up to 10 names), Escape closes it, a quick tap still toggles, and the list refreshes after you react. Removing must work on a reaction you just added, *without reloading the page* - that chip is built in JS and is exactly the one a person is most likely to click again to undo.

**Checks:**
- `e-reactions.1` @member #ui: bob opens the reaction bar on alice's reply in "Dark mode for the community on phones" and picks Like -> the chip shows Like 1 highlighted as his, and reloading the page keeps it.
- `e-reactions.2` @member #ui: bob clicks the same chip again WITHOUT reloading -> the reaction is removed, the chip disappears or drops to its previous count, and a second click adds it back (the JS-built chip must undo).
- `e-reactions.3` @member #ui: bob has Like, then picks Celebrate on the same reply -> Like is replaced (one reaction per member per post), counts show Like 0 / Celebrate 1, not both.
- `e-reactions.4` @member_b #ui: alice and maya add Like to bob's reply -> the chip reads 2 with names; each count matches `GET /replies/{id}/reactions`.
- `e-reactions.5` @member #ui: hover (mouse), Tab focus, and long-press (touch) on a Like chip with 13 reactors -> a tooltip reads "Like: <10 names> and 3 others"; with 2 reactors "Like: Maya and Bob"; Escape closes it; a quick tap still toggles instead of opening the tooltip.
- `e-reactions.6` @member #ui: add a reaction and, without reloading, hover the chip -> the names list already includes you (it refreshed after reacting).
- `e-reactions.7` @member #rest !refuse: `POST /posts/{id}/reactions` with `emoji: "not-real"` or a disabled reaction -> 400 `jetonomy_invalid_emoji` "Invalid or disabled emoji.".
- `e-reactions.8` @member #rest !refuse +private: bob `POST /posts/{id}/reactions` and `GET` on a topic in Customer Advisory Board, and on a nonexistent id -> both return the identical 404 `jetonomy_not_found` "Content not found." (never 403) for POST and GET.
- `e-reactions.9` @guest #rest !refuse: logged-out `POST /posts/{id}/reactions` -> 401; logged-out `GET` of a public topic's reactions -> 200 with counts (`reactors` is included only when `?emoji=` names a reaction that has reactors); of a hidden-space topic -> 404.
- `e-reactions.10` @member #rest !refuse +deleted: react to a trashed or unpublished reply -> 409 (target not live), no row written; the chip does not appear.
- `e-reactions.11` @silenced #ui !refuse +silenced: finn reacts to a reply -> refused with the silenced reason and no reaction recorded; also when finn's own post scored -4 is reacted to by others, the count still shows correctly.
- `e-reactions.12` @member_b #email: the FIRST reaction on bob's reply notifies bob once; a second and third reaction and an emoji swap by the sole reactor -> send no further notification or email; reacting to your own post sends none.
- `e-reactions.13` @admin #admin: Settings > Reactions: save with every emoji unticked -> the notice reads "No reactions were selected, so Like was kept - a reactions bar needs at least one. Disable the Reactions extension if you want none."; saving normally shows "Reaction settings saved."
- `e-reactions.14` @admin #admin +old-data: disable the Celebrate reaction after members used it -> existing Celebrate counts are not shown as broken chips, and members cannot add it again.
- `e-reactions.15` @member #ui +many: a reply with 40 reactions across 6 emoji -> wraps the chips onto lines at 390px without overflowing the reply card on Reign; names tooltip stays inside the viewport (max 280px).
- `e-reactions.16` @member #ui +dark: tooltip and highlighted chip -> use token colours in dark mode with readable contrast.
- `e-reactions.17` @member #ui +offline: toggle a reaction with the network blocked -> the chip rolls back to its previous state and an error is shown.
- `e-reactions.18` @member #ui +concurrent: bob and alice react to the same reply at the same instant -> final count is exactly 2 and neither sees a stale count after reload.
- `e-reactions.19` @member #ui: the reaction chips on a post and on a reply in the 45-reply Feature Requests topic page 2 -> work the same as page 1 (JS bound after paging).

### E.analytics
**Covers:** includes/admin/views/dashboard.php, pro:includes/extensions/analytics/*.php, pro:includes/extensions/analytics/views/*.php, pro:assets/js/admin-analytics.js
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** an admin sees a dashboard with non-zero metrics on a site with demo data, can switch date range, and can export the underlying data as CSV. Widget values are internally consistent (total topics ≥ topics in last 30d, etc.), and widget labels use the owner's topic label.

**Analytics page width (1.4.5 regression guard, Basecamp 9941705191).** **What to verify:** the Analytics admin page (`admin.php?page=jetonomy-pro-analytics`) uses the full available wp-admin width. Admin pages are WordPress backend - they always fill the wrap, per WP standard; the frontend container-width setting never applies to them.

**Pass (assert at a wide viewport, e.g. 1881px):** `getComputedStyle(document.querySelector('.jtp-analytics')).maxWidth === 'none'`; the container's right edge is within ~20px (standard wrap margin) of the wrap's right edge - no blank band. The "Verify dual-path" toggle and `#jtp-diff-panel` are NOT present for site admins (internal dev tool, opt-in only via the `jetonomy_pro_analytics_dual_path_verify` filter).

**Why it bounced twice before this row existed:** "empty space" reports are axis-ambiguous - the first two fixes attacked vertical space (the dev panel) while QA meant the horizontal `max-width: 1200px` cap. Measure BOTH axes at the reporter's viewport before fixing any layout-gap report.

**Checks:**
- `e-analytics.1` @admin #admin: open admin.php?page=jetonomy-pro-analytics on the seeded site -> overview shows non-zero topics, replies, new members and votes, each with a change versus the previous period.
- `e-analytics.2` @admin #admin: topics in last 30d -> never exceeds total topics; replies and members follow the same rule; the "Avg Reply Time" label and the section nouns use the owner's renamed Topic/Reply/Member labels.
- `e-analytics.3` @admin #admin: switch the range 7d, 30d, 90d quickly three times -> after the last click the numbers belong to 90d only (never the previous range's data), and the active range button matches.
- `e-analytics.4` @admin #rest: `GET /jetonomy/v1/analytics/overview?range=7d` then `30d` -> `days` and totals differ and the 7d total is <= the 30d total.
- `e-analytics.5` @admin #rest: custom range with `start` and `end` inside the seeded data -> numbers match the sum of the daily rows; an end before start silently becomes 30d, so verify the page tells the person which range is showing.
- `e-analytics.6` @admin #admin: export CSV -> file jetonomy-analytics-YYYY-MM-DD.csv downloads with header Date, Topics, Replies, New Users, ...; row count equals the days in range; values equal the dashboard totals.
- `e-analytics.7` @admin #cli: `wp jetonomy-pro analytics export --format=csv` -> prints the same CSV to stdout without PHP warnings.
- `e-analytics.8` @admin #admin: top spaces and top contributors -> lists are capped at the ranked-list limit, Grace's long name wraps inside its row without breaking the table or page at 1440 and 390 (tables scroll inside their wrapper), and the Local Chapters appear only when active in range (needs several chapters active in range, else mark that part untested).
- `e-analytics.9` @admin #admin +empty: on a fresh site or an empty period (the dashboard offers only 7/30/90 days; a custom range is REST-only and returns zeroes) -> each widget shows "No data for this period." instead of blank charts or NaN%.
- `e-analytics.10` @admin #admin +offline: block the analytics request -> the panel shows "Could not reach the server. Check your connection and reload."; a 500 shows "Could not load analytics. Try refreshing the page.".
- `e-analytics.11` @admin #admin: at 1881px `getComputedStyle(document.querySelector('.jtp-analytics')).maxWidth` -> is `none` and the container's right edge is within ~20px of the wrap edge; no `#jtp-diff-panel` or "Verify dual-path" toggle appears (card 9941705191).
- `e-analytics.12` @moderator #rest !refuse: test_moderator (Editor, no analytics capability) `GET /analytics/overview` and `/analytics/export` -> 403 and the menu entry is absent; a user granted `jetonomy_view_analytics` succeeds.
- `e-analytics.13` @member #rest !refuse: bob and a guest on every analytics route -> 403 / 401 with no data; typing the admin URL shows the WordPress permission screen.
- `e-analytics.14` @admin #rest !refuse: `GET /analytics/diff-report` as a non-admin -> 403; as admin -> 200 (the route is admin-only; the dual-path filter only shows or hides the UI panel).
- `e-analytics.15` @admin #admin +390: dashboard at 390px stacks cards, the range control and Export button -> stay reachable, tables scroll inside their wrapper without moving the page.
- `e-analytics.16` @admin #admin +dark: charts and tiles -> use token colours in dark admin schemes with readable axis labels.
- `e-analytics.17` @admin #admin +old-data: open the dashboard on data created before the aggregator table existed -> totals still count those topics (counts come from live tables, not only the daily aggregate).
- `e-analytics.18` @admin #admin: set the site timezone away from UTC and open the dashboard -> an item created just before local midnight counts in that local day's bucket, not the next UTC day.

### E.custom-badges
**Covers:** templates/views/user-profile.php, templates/partials/post-badges.php, pro:includes/extensions/custom-badges/*.php, pro:assets/js/badges-admin.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an admin can create a badge (name, icon, description, criteria), award it manually to a user, and that badge appears on the recipient's profile AND in the public badge list. Badges are once-only: a member never holds a duplicate (existing duplicates are repaired), and "Earned by" counts members.

**Checks:**
- `e-custom-badges.1` @admin #admin: Badge Builder: create "Conversationalist" with a Lucide icon, a description, criteria posts >= 25 and replies >= 50, press "Save Badge" -> notice "Badge saved." and the badge appears in the admin list with its criteria summary.
- `e-custom-badges.2` @admin #admin: create a badge with no criteria -> the list shows "Manual award only".
- `e-custom-badges.3` @admin #admin: with zero custom badges the -> list shows "No badges yet" and "Create your first badge to start rewarding members milestones." with a create link.
- `e-custom-badges.4` @admin #admin: Award Badge dialog: search "Maya", pick her, press Award Badge (picking the user and pressing the button is the confirmation, there is no second confirm) -> alert "Badge awarded." and after OK the page reloads with the recipient count on the badge up by one.
- `e-custom-badges.5` @member_c #ui: maya opens her profile -> the badge shows with name, icon and description; the public badge list also shows it; the post cards by maya show the badge where badges are displayed.
- `e-custom-badges.6` @member_b #ui: alice's profile does NOT -> show maya's badge and her badge count is unchanged (negative case).
- `e-custom-badges.7` @admin #rest !refuse: `POST /badges/{id}/award` for maya a second time on a non-repeatable badge -> 409 `jetonomy_duplicate` "Badge already awarded to this user."; maya still holds exactly one and "Earned by" counts members, not awards.
- `e-custom-badges.8` @admin #rest: award a repeatable badge twice -> both succeed up to max_awards; confirm max_awards is enforced on manual award, or record that it is not.
- `e-custom-badges.9` @admin #rest !refuse: award with badge id 99999 -> 404 "Badge not found."; with user id 99999 -> 404 "User not found.".
- `e-custom-badges.10` @admin #admin: revoke from maya via the Revoke Badge dialog -> alert "Badge revoked." (then the page reloads); her profile no longer shows it; any reputation bonus the badge gave is reversed once; revoking again returns 404 "This user has not earned that badge.".
- `e-custom-badges.11` @admin #admin: deactivate the badge ("Deactivate this badge?" confirm) -> toast "Badge deactivated."; it disappears from public lists but existing awards stay in the data; reactivate shows "Badge activated.".
- `e-custom-badges.12` @member #rest !refuse: bob `POST /badges`, `PATCH`, `DELETE`, `POST /badges/{id}/award`, each with a complete valid body (an incomplete body gets a 400 from WordPress param validation before the permission check) -> 403; a moderator without `jetonomy_manage_badges` also 403; `GET /badges` and `GET /users/{id}/badges` as guest -> 200.
- `e-custom-badges.13` @admin #rest !refuse: `POST /badges` with criteria that is not an object -> 400 `rest_invalid_param`, and with an object holding a bad condition (unknown metric) -> 400 `jetonomy_validation` "Invalid criteria format."; `PATCH` with no fields -> 400 "No fields to update.".
- `e-custom-badges.14` @member #ui: bob reaches the threshold (post count) -> the badge is awarded automatically by the scheduled check (every 6 hours) exactly once, a notification is sent once, and no duplicate row appears after the job runs again.
- `e-custom-badges.15` @member #ui +old-data: open the profile of a member who held duplicate awards before the repair -> one badge is shown and the "Earned by" count equals distinct members.
- `e-custom-badges.16` @admin #admin +long: a badge named "Community Champion of the Year for Outstanding Helpfulness" with a 300-character description -> wraps inside the card and the profile badge strip on Reign at 390px.
- `e-custom-badges.17` @admin #admin +offline: save with the request blocked -> after 30 seconds "Save timed out after 30 seconds. The server did not respond. Reload the page and try again." (or the blocked-request text naming the endpoint) and the form values stay.
- `e-custom-badges.18` @member #ui +390 +dark: profile badge strip at 390px -> wraps without overflow and uses token colours in dark mode.
- `e-custom-badges.19` @member_c #email: the award notifies maya once in the bell, and an email only if her notification preferences allow it; revoke -> sends nothing.
- `e-custom-badges.20` @admin #admin: create a badge with the manual-only criterion -> it saves, appears in the list, and no activity ever auto-awards it; only Award on a profile gives it.

### E.white-label
**Covers:** includes/admin/class-admin.php, pro:includes/extensions/white-label/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** the White Label extension is BACKEND-ONLY by design (its own class docblock: "Backend-only; it does not touch the community frontend or emails"). It renames the Jetonomy presence in wp-admin only. Set `community_name`, `admin_label`, `admin_icon`, `footer_text`, then confirm each is reflected in wp-admin: the brand name (`jetonomy_brand_name`) replaces "Jetonomy" in admin headings, banners and help text, the sidebar menu label, the plugin name on the Plugins page, and the admin footer (on Jetonomy screens the default footer reads "Thank you for building your community with Jetonomy.", which is the text White Label's brand substitution replaces). Confirm the public `/community/` render is UNCHANGED — there is no brand-color or frontend-logo field, and none is expected. (A frontend brand-color/logo capability would be a new paid feature to scope, not a bug in this extension.)

**Checks:**
- `e-white-label.1` @admin #admin: Jetonomy > Settings > Branding: set community name "Hearthside Community", admin label "Hearthside", icon `dashicons-groups`, footer text "Built with care by the Hearthside team" and save -> notice "Branding settings saved." and the fields keep their values.
- `e-white-label.2` @admin #admin: after saving, the sidebar menu label -> reads "Hearthside" with the chosen icon, admin headings and banners read "Hearthside Community" instead of "Jetonomy", and the Plugins page lists the plugin under the new name.
- `e-white-label.3` @admin #admin: on a Jetonomy screen the footer -> shows the custom footer text; with footer text blank and community name set, the default footer text has "Jetonomy" replaced by the community name; with both blank the default "Thank you for building your community with Jetonomy." returns.
- `e-white-label.4` @admin #admin: a footer text containing `&` and an apostrophe ("Bob's Q&A") -> renders once-escaped, not as `&amp;amp;`.
- `e-white-label.5` @admin #admin: the Settings sidebar, Data card and trust-level hint -> use the brand name (card 10415335395).
- `e-white-label.6` @guest #ui: /community/ -> renders UNCHANGED with the rebrand on (no brand name, colour or logo change on the frontend, in emails or in page titles).
- `e-white-label.7` @admin #email: send a test notification email -> the From name, subject and footer still use the site's own email settings, not the white-label name.
- `e-white-label.8` @admin #rest: `GET /jetonomy/v1/settings/white-label` -> returns the four fields; `PATCH` with `community_name: "<b>Hearth</b>"` stores plain text (tags stripped).
- `e-white-label.9` @moderator #rest !refuse: test_moderator and bob `GET` and `PATCH /settings/white-label` -> 403; guest -> 401.
- `e-white-label.10` @admin #admin +long: a 120-character community name -> does not push the admin menu, header or banner into overflow at 1440 and 390.
- `e-white-label.11` @admin #admin +settings-off: clear all four fields and save -> every admin surface returns to "Jetonomy" (no empty menu label or blank icon).
- `e-white-label.12` @admin #cli: `wp jetonomy-pro white-label get` -> the values match the admin page and GET /settings/white-label, and `white-label update` changes all three.
- `e-white-label.13` @admin #admin: an `admin_icon` that -> is a full image URL renders as an image in the menu; an invalid icon string falls back to a default icon.

### E.advanced-moderation
**Covers:** includes/moderation/class-moderation-service.php, includes/api/class-base-controller.php, pro:includes/extensions/advanced-moderation/*.php, pro:assets/js/moderation-admin.js
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** an admin can create a moderation rule (pattern + action), posting content matching the rule triggers the action (hold / spam / auto-delete), and rule stats reflect hits over time.

**Checks:**
- `e-advanced-moderation.1` @admin #admin: Add Auto-Moderation Rule: type Keyword Filter, pattern "free followers, crypto giveaway, dm me for signals", action "Hold for Approval", scope global, save -> notice "Rule saved." and the rule lists with its type, action and 0 hits.
- `e-advanced-moderation.2` @member #ui: bob posts a real-looking topic "Anyone tried the crypto giveaway on Telegram?" in General Discussion -> the post does not appear publicly; bob is told it is awaiting approval; it shows in the moderation queue; the rule hit count goes to 1.
- `e-advanced-moderation.3` @member #ui: bob posts "Notes on the community giveaway of swag for contributors" (shares no keyword) -> publishes normally and the hit count does not move (negative case).
- `e-advanced-moderation.4` @member #ui: a rule with action "Block (reject)" matching the content -> the post is refused with a message, no row is saved, and the author keeps their text in the composer.
- `e-advanced-moderation.5` @member #ui: a rule with action "Mark as Spam" -> the post is saved as spam, hidden from the space and from the author's profile, and visible in the spam list for moderators.
- `e-advanced-moderation.6` @member #ui: a rule with action "Flag for Review" -> the post publishes immediately and a flag appears in the mod queue.
- `e-advanced-moderation.7` @member #ui: content matching a Flag rule and a Block rule at once -> the stronger action (Block) wins; with Hold plus Spam, Spam wins.
- `e-advanced-moderation.8` @member #ui: a keyword rule "ass" against the sentence "Our class assignment reminder" -> record whether it holds the post (substring match) so the owner is warned that short keywords over-match.
- `e-advanced-moderation.9` @member #ui +rtl: a keyword rule in Arabic -> matches an Arabic reply by omar and case-insensitive Latin keywords match upper-case text.
- `e-advanced-moderation.10` @admin #admin: Regex rule `\b(buy|cheap|discount)\b` -> matches "cheap hosting deal" and not "cheapest"; an invalid regex `([a-z` is refused on save with "Invalid regex pattern." (REST 400).
- `e-advanced-moderation.11` @admin #rest !refuse: Link limit and New user rules with a non-numeric pattern -> 400 "Pattern must be a number for this rule type."; PATCH changing only the type to link_limit on an existing text pattern -> the same 400.
- `e-advanced-moderation.12` @member #ui: Link limit of 2 and a reply containing three https:// links -> held/actioned; two links pass.
- `e-advanced-moderation.13` @member #ui: New user restriction with minimum trust 1: a trust level 0 member's post -> is actioned, a trust level 2 member's is not.
- `e-advanced-moderation.14` @admin #admin: a space-scoped rule for Help Desk without choosing a space -> refused with "Choose the space for this space-scoped rule, or set the scope to global. A rule with no space selected would never match anything."; a rule scoped to Help Desk does not fire in Troubleshooting.
- `e-advanced-moderation.15` @admin #rest: `GET /moderation/rules/{id}/stats` after three matching posts -> `hit_count` 3; deactivating the rule stops the count.
- `e-advanced-moderation.16` @admin #admin: delete a rule -> one confirm "Permanently delete this rule?" (not two), toast "Rule deleted.", the rule stops firing; `DELETE` on a nonexistent id returns an error, not success.
- `e-advanced-moderation.17` @moderator #rest: test_moderator can -> list and create rules (`jetonomy_moderate`) while bob and a guest get 403/401; record whether the owner intends moderators to author rules.
- `e-advanced-moderation.18` @admin #admin +many: with 200 rules the -> list paginates, filters by type/action, and loads within a second.
- `e-advanced-moderation.19` @admin #admin: exempt behaviour - posts by admins and by test_moderator -> are not held by a keyword rule (or the owner is shown they are), and edits to an existing post re-run the rules.
- `e-advanced-moderation.20` @admin #admin +old-data: replies and topics edited after the rule was created -> are re-checked; the debug.log stays empty (no PHP 8.1 null-key deprecation per post).
- `e-advanced-moderation.21` @admin #admin +390: the rules table and the Add Rule form at 390px -> scroll inside their wrapper and the type-dependent pattern fields switch correctly.
- `e-advanced-moderation.22` @admin #admin +many: with more than 50 Auto-Rules the list pages at 50 rows with working next and previous links.
**Depth note:** the runbook says "auto-delete"; the extension has no delete action (flag, hold, block, spam), so "Block (reject)" is the check for that outcome.

### E.ai
**Covers:** includes/adapters/interface-ai-adapter.php, includes/adapters/class-ollama-ai-adapter.php, pro:includes/extensions/ai/*.php, pro:includes/adapters/class-anthropic-ai-adapter.php, pro:includes/adapters/class-openai-ai-adapter.php, pro:includes/adapters/class-custom-ai-adapter.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** with an AI provider configured, obviously spammy content is correctly flagged; AI usage endpoint reports consistent numbers. With no provider configured, the extension silently no-ops (no fatals, no false positives).

**Checks:**
- `e-ai.1` @admin #admin: Settings > AI Integration: enter a real provider key (OpenAI, Anthropic, Ollama or Custom), tick Activate this provider, save -> the key is stored masked ("Leave blank to keep" style placeholder), and ticking "clear api key" removes it.
- `e-ai.2` @member #ui: bob posts an obvious spam topic ("Make $5000 a week from home, click here, limited slots") with AI spam detection on -> the post is held or marked spam per the thresholds (default hold 0.5, spam 0.8) and appears in the mod queue with the AI reason "Flagged by AI review: ...".
- `e-ai.3` @member #ui: bob posts a genuine long question (a real Help Desk issue about SMTP settings) -> published normally; no false positive.
- `e-ai.4` @admin #admin: set Auto-spam Threshold below the hold threshold or above 1 -> the form rejects or clamps with a visible message.
- `e-ai.5` @admin #rest: `GET /jetonomy/v1/ai/usage` and `/ai/usage/summary` after several calls -> request and token counts add up to the daily rows, and the cost respects the configured cost-per-1k values.
- `e-ai.6` @admin #admin: set a Daily Budget of $0.01 and run requests past it -> AI calls stop once the budget is hit and content passes through unchecked with an admin notice rather than erroring to members.
- `e-ai.7` @admin #admin +settings-off: with no provider configured, post as bob -> no fatal, no delay, no false hold, nothing in debug.log; the AI buttons are absent from the composer.
- `e-ai.8` @admin #admin +offline: break the key or provider URL -> posting still succeeds (fails open), an admin notice "Jetonomy AI is not reaching any provider." with time since last failure appears, and it disappears once a provider answers again.
- `e-ai.9` @member #ui: with reply suggestions on, an unanswered Q&A topic in Help Desk -> shows "AI Suggested Answers" with a "Use as reply" button that fills the composer without posting.
- `e-ai.10` @member #ui: thread summaries on a long thread (Feature Requests, 45 replies) -> show a TL;DR card; posting a new reply invalidates the summary; a short thread below the min words shows none.
- `e-ai.11` @member #rest !refuse: `POST /ai/suggest-reply` with a nonexistent post -> 404 "Topic not found." (the noun is the owner-set topic label); with a post in Customer Advisory Board bob cannot read -> must answer exactly like a missing post (404), not 403 "You do not have permission to read this post.".
- `e-ai.12` @member #rest !refuse +limit: after the hourly limit (default 5) bob's sixth successful `suggest-reply` (only successful provider calls count; needs a working provider, otherwise mark untested) -> 429 "You have reached the hourly limit for AI requests. Please try again later."; admins are exempt; the counter is shared across features.
- `e-ai.13` @member #rest !refuse: set minimum trust 3 and call as a trust level 1 member -> 403 "You need trust level 3 or higher to use AI features."; the AI button is hidden in the UI for that member.
- `e-ai.14` @guest #rest !refuse: `POST /ai/suggest-reply` and `GET /ai/usage` logged out -> 401; as bob `GET /ai/usage` -> 403.
- `e-ai.15` @admin #admin: provider order and per-feature override: set default Anthropic, order "anthropic, openai", override spam detection to openai -> a spam test goes through openai; when the first provider is down the next in order is used.
- `e-ai.16` @guest #ui +private: request the AI summary/suggestion of a post in a private space the requester cannot read -> no AI call is made, nothing is shown, and a summary generated for a private post is not shown to guests.
- `e-ai.17` @member #ui +rtl +long: summarise and moderate an Arabic topic and the 159-character General Discussion topic -> output renders in the right direction and the card wraps at 390px.
- `e-ai.18` @admin #cli: `wp jetonomy-pro ai` subcommands (usage/test) -> respond with plain output and no PHP notices, including with no provider.
- `e-ai.19` @admin #admin: batch review scheduler -> flags past content, retries failed items next cycle and honours the daily budget.
- `e-ai.20` @admin #admin: AI usage and daily-budget day buckets follow the site timezone, not UTC.
- `e-ai.21` @admin #admin: saving AI settings with a threshold that gets kept shows one outcome notice, not both the threshold-kept notice and "AI settings saved."
**Depth note:** this walk needs a real provider key (OpenAI, Anthropic, Ollama or a Custom OpenAI-compatible endpoint); without one only checks 7, 11 to 14 and the settings screens can pass.

### E.custom-fields
**Covers:** assets/js/lib/custom-fields.js, templates/views/edit-profile.php, templates/views/user-profile.php, pro:includes/extensions/custom-fields/*.php, pro:includes/extensions/custom-fields/views/*.php, pro:assets/js/admin-custom-fields.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an admin can define a custom field (text / select / url / etc.), a user can set a value in their profile, and the value shows on their profile to other members. Creating a field via REST must not fatal when optional params (like `slug`) are omitted.

**Custom fields lifecycle (1.4.5 regression guards, Basecamp 9947496606 / 9942068436).** **What to verify:** a post created from the frontend composer with a custom field value persists that value; the inline post editor pre-populates and can change it after update; at 390px a long URL value wraps inside its container (`scrollWidth === clientWidth`, no horizontal overflow). Space-context fields render and save on space create, edit, and display.

**Checks:**
- `e-custom-fields.1` @admin #admin: Add New Field: name "Operating system", type Select with options Windows, macOS, Linux, context Post, required, all spaces -> notice "Field created." and the field lists with its slug.
- `e-custom-fields.2` @admin #rest: `POST /jetonomy/v1/fields` with name, field_type and context (no slug) -> 201 with a generated slug, no fatal; missing context -> 400 "Missing parameter(s): context"; missing name -> 400 `rest_missing_callback_param` "Missing parameter(s): name"; unknown `field_type` -> 400 `rest_invalid_param` "field_type is not one of ..." (the param is `field_type`).
- `e-custom-fields.3` @admin #admin: create one field per type (text, textarea, number, email, url, select, checkbox, radio, date) -> each renders the right control in the composer and on the topic.
- `e-custom-fields.4` @member #ui: bob starts a Help Desk topic with Operating system = Linux -> the topic shows "Additional Fields" with Linux; reload keeps it.
- `e-custom-fields.5` @member #ui !refuse: submit the composer with the required field empty -> blocked with "Operating system is required." (REST 400 `jetonomy_field_required`) and no topic created.
- `e-custom-fields.6` @member #ui: edit the topic inline -> the field is pre-populated, can be changed to macOS, and the change persists after update; clearing an optional field removes it from the display.
- `e-custom-fields.7` @member_b #rest !refuse: alice `PATCH /posts/{bob's id}/fields` -> 403 "You do not have permission to update fields on this post."; a missing post id -> 404 "Post not found."; for a post in a space alice cannot read the answer must equal the missing-post 404.
- `e-custom-fields.8` @member #rest !refuse: `PATCH /posts/{id}/fields` with a number field value "abc", an email field "not-an-email", a url field "javascript:alert(1)", a select value outside the options -> 400 "Some fields failed validation." naming the fields; nothing saved.
- `e-custom-fields.9` @moderator #rest: test_moderator and space_admin (edit others' posts in their space) -> may update fields on a topic in that space; test_moderator outside Moderators' Room/Bug Reports may not.
- `e-custom-fields.10` @member #ui +390 +long: a url field holding a 100-character unbroken URL -> wraps inside its container (`scrollWidth === clientWidth`) at 390px on Reign, on the topic and on the profile.
- `e-custom-fields.11` @member #ui: Edit Profile shows profile-context fields under "More about you"; bob saves "Timezone/Location: Lagos, Nigeria" -> other members see it on his profile under "Profile Details"; a member who left it blank shows no empty row.
- `e-custom-fields.12` @member_b #ui: alice views bob's profile -> sees his field values; fields marked private/hidden to others do not show to alice or the guest.
- `e-custom-fields.13` @member #rest !refuse: `PATCH /users/me/fields` with an unknown slug only -> 400 "No known profile field matched the request. Send a flat { field_slug: value } object."; with an array body -> 400 "Request body must be a JSON object of field_slug: value pairs.".
- `e-custom-fields.14` @admin #admin: Space-context field -> renders and saves on space create, edit and display (card 9947496606/9942068436); a space-scoped field does not appear on topics in other spaces.
- `e-custom-fields.15` @admin #admin: deactivate a field -> message "Field deactivated."; existing values are kept in the data, hidden from display and the composer, and not required any more; reactivating restores them.
- `e-custom-fields.16` @admin #admin: edit a searchable field -> notice "Field updated. Existing content is being re-indexed in the background so this field becomes searchable everywhere, not only in new posts."; searching the value finds an old topic after the job runs.
- `e-custom-fields.17` @guest #rest: `GET /fields` public -> 200 with active fields only; `GET /posts/{id}/fields` of a hidden-space topic -> 404; `POST /fields` as bob -> 403.
- `e-custom-fields.18` @admin #admin +empty: with no fields the admin page -> shows "No custom fields yet" with a hint; the composer shows no empty "Additional Fields" heading.
- `e-custom-fields.19` @member #ui +rtl: an Arabic text value -> displays right-to-left in the field list and in the edit input.
- `e-custom-fields.20` @admin #admin +many: 30 fields -> keep the composer usable at 390px (fields collapse or scroll) and the admin list paginates.

### E.email-digest
**Covers:** includes/adapters/interface-email-adapter.php, includes/notifications/class-notifier.php, pro:includes/extensions/email-digest/*.php, pro:assets/js/admin-email-digest.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** users subscribed to the digest actually receive one at the configured cadence; a manual test-send from admin dispatches within 30s; the stats endpoint reports sends without error. When the digest's own sender is blank it uses the sender from Settings > Emails.

**Checks:**
- `e-email-digest.1` @admin #admin: Settings > Email Digest: set default frequency Weekly and send time, save -> settings persist; the sender is blank -> digests use the sender from Settings > Emails.
- `e-email-digest.2` @admin #rest: `POST /admin/digest/test` with frequency daily -> 200 `success: true` "Test digest sent to <admin email>." and the message is in Mailpit within 30 seconds with subject for a test digest.
- `e-email-digest.3` @admin #email: open the test digest -> sections New in Spaces You Follow, top topics, replies to you, badges earned, poll engagement and stats render with real seeded titles (Dark mode for the community on phones; Scheduled maintenance on Saturday) and the header "Community Digest: <date>", no broken images, no `&amp;` in titles.
- `e-email-digest.4` @member #rest: `PATCH /users/me/digest-preferences` frequency none, daily, weekly -> each saves and `GET` returns it; "monthly" -> 400 `rest_invalid_param` "frequency is not one of none, daily, and weekly.".
- `e-email-digest.5` @member #cli: `wp cron event run` (or the digest command) for daily as bob with frequency daily and activity since the last digest -> one email arrives, and running it again immediately sends nothing (since-date moves forward).
- `e-email-digest.6` @member #email +empty: a member with no activity in range -> gets no digest at all (not an empty shell).
- `e-email-digest.7` @member #email +private: bob's digest -> never lists topics from Customer Advisory Board, Moderators' Room or Insiders / Beta, nor from spaces he follows but was removed from; a Partner Program member's digest does include it.
- `e-email-digest.8` @member #email: the footer link "unsubscribe" -> works logged out: visiting it sets frequency to none and shows "You have been unsubscribed from community digest emails. You can re-enable them anytime from your profile settings."; the next run sends nothing to bob.
- `e-email-digest.9` @guest #ui !refuse: an unsubscribe link with a wrong token -> "Invalid or expired unsubscribe link." (403); with no token -> "Invalid unsubscribe link." (400); frequency unchanged.
- `e-email-digest.10` @member #email: a member with "Pause all email notifications" on -> receives no digest.
- `e-email-digest.11` @silenced #email +silenced: run the digest for finn -> he still receives it (silenced is a posting restriction) and it does not list his hidden or removed posts.
- `e-email-digest.12` @member #email +blocked: run the digest for bob after he blocked an author -> that author's topics do not appear in his digest.
- `e-email-digest.13` @member_b #email +many: 3,000 subscribers: the -> send is batched through Action Scheduler pages, each user gets one email, and a transient SMTP failure does not lose the buffered event signal (retried next run).
- `e-email-digest.14` @admin #rest: `GET /admin/digest/stats` -> returns counts of none/daily/weekly users and sends without error; non-admin gets 403; `POST /admin/digest/test` as bob 403.
- `e-email-digest.15` @member #email +rtl +long: a digest containing the Arabic topic and the 159-character topic title -> renders right-to-left in that block and wraps in a 390px mail client without horizontal scroll; dark-mode clients keep readable text.
- `e-email-digest.16` @admin #email +settings-off: with the extension disabled, no digest cron -> runs and the unsubscribe URL of an old email still lands on a clear page instead of a 404 fatal.
- `e-email-digest.17` @admin #email: with PMPro active, the digest's text/plain part is real plain text (no HTML tags) for an admin recipient as well as a member.
**Depth note:** real inbox delivery (SPF/DKIM, client rendering) needs a real mailbox, not only Mailpit.

### E.reply-by-email
**Covers:** templates/emails/reply-to-post.php, includes/models/class-reply.php, pro:includes/extensions/reply-by-email/*.php, pro:assets/js/admin-reply-by-email.js
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** an inbound email matching a reply token produces a new reply on the correct post with the correct author; an inbound email without a valid token is rejected cleanly.

**Checks:**
- `e-reply-by-email.1` @admin #admin: Settings > Reply by Email: choose Inbound webhook, set domain and signing secret, save -> "Reply by Email settings saved."; the IMAP password field shows "Leave blank to keep the current password" when set.
- `e-reply-by-email.2` @member #email: a reply notification to bob -> carries `Reply-To: reply+<token>@<domain>`; the token differs per user and per post.
- `e-reply-by-email.3` @member #rest: send a correctly signed `POST /reply-by-email/inbound` with bob's token and body "Thanks, restarting the queue worker fixed it for me." -> 200 `success: true`, and a new reply by bob (not the admin) appears on the right topic, with notifications sent.
- `e-reply-by-email.4` @member #rest: inbound body with a quoted section (lines beginning ">") and a "-- " signature -> the created reply contains only the new text, paragraphs preserved.
- `e-reply-by-email.5` @guest #rest !refuse: inbound with an invalid token -> 400 `jetonomy_reply_token_invalid` "Malformed token." / "Invalid token structure." / "Token signature mismatch."; an expired token (older than 7 days) -> 400 `jetonomy_reply_token_expired`; no reply is created.
- `e-reply-by-email.6` @guest #rest !refuse: inbound with a tampered expiry or user id in the token -> signature mismatch; a valid token for bob replayed against another post id -> rejected.
- `e-reply-by-email.7` @guest #rest !refuse: webhook not signed -> 403/400 `jetonomy_webhook_missing_signature` "Webhook request is not signed."; wrong signature -> 403 "Webhook signature mismatch."; timestamp outside the window -> "Webhook timestamp is outside the accepted window."; no secret configured -> "Inbound webhook is not configured with a signing secret.".
- `e-reply-by-email.8` @admin #rest !refuse +settings-off: extension disabled -> 503 `jetonomy_extension_disabled`; method set to IMAP while the webhook is called -> 400 "Inbound webhook method is not enabled.".
- `e-reply-by-email.9` @guest #rest !refuse: payload without a To address -> 400 "No To address found in payload."; To without a token -> 400 "No reply token found in To address."; empty body -> 400 "Email body is empty.".
- `e-reply-by-email.10` @member #rest !refuse +limit: more than 10 inbound replies within the hourly rate window for bob (reset the `jt_rbe_rl_<uid>` transient first, earlier checks count toward it) -> 429 "Rate limit exceeded or content empty."; other members unaffected.
- `e-reply-by-email.11` @silenced #rest !refuse +silenced: a valid token for finn -> the endpoint must not create a reply; note the REST answer is still success:true even though free drops it silently, so the sender gets no bounce (see report).
- `e-reply-by-email.12` @space_banned #rest !refuse +banned: a valid token for omar posting into Off-Topic Lounge -> no reply created; likewise on the closed topic "Scheduled maintenance on Saturday" and on a topic in the archived 2025 Hackathon space.
- `e-reply-by-email.13` @member #rest +private: a valid token for bob on a topic in a space he has since left -> no reply created and no leak of the topic's existence in the response.
- `e-reply-by-email.14` @member #email: a held reply (require approval) created by email -> lands in the mod queue, not live; the flagged reply from a rule is flagged.
- `e-reply-by-email.15` @member #email: an emailed reply that @mentions alice -> sends alice exactly one notice and one email, and followers are not notified twice.
- `e-reply-by-email.16` @member #rest +rtl +long: an inbound Arabic body and a 5,000-character body -> decode correctly (quoted-printable and base64) and render without mojibake.
**Depth note:** IMAP polling needs the PHP imap extension and a real mailbox; the signed webhook path can be walked with a script or Mailgun/SendGrid sandbox. Reply-by-Email webhook verification beyond the signature is a 2.1 item.

### E.seo-pro
**Covers:** includes/seo/class-schema-markup.php, includes/class-template-loader.php, pro:includes/extensions/seo-pro/*.php, pro:assets/js/admin-seo-pro.js
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** space-level SEO overrides (meta description, og:image) appear in the rendered HTML `<head>` on that space's pages. Defaults apply when overrides are absent.

**Checks:**
- `e-seo-pro.1` @admin #admin: Help Desk space edit > SEO tab: set meta title "Help Desk - Get answers from the community | {site_name}", description "Ask a question and get answers from experienced members.", an og:image, save -> values persist (REST `PATCH /spaces/{id}/seo` returns them).
- `e-seo-pro.2` @guest #ui: view source of /community/s/help-desk/ -> `<title>`, `meta name="description"`, `og:title`, `og:description`, `og:image`, `twitter:card` summary_large_image and `link rel="canonical"` match the overrides; one of each tag only.
- `e-seo-pro.3` @guest #ui: view source of a topic in Help Desk -> title, og:title and description follow the space's meta template (the topic title shows only if the template contains {post_title}), og:type article, article:author is the member's public name, published and modified times are ISO 8601, article:section is the space title.
- `e-seo-pro.4` @guest #ui +old-data: a space with no overrides (General Discussion) -> defaults apply (space name | site name, trimmed description, no empty meta tags like `content=""`).
- `e-seo-pro.5` @guest #ui: the 288-character Help Desk description trimmed to 30 words in the default meta description, ending "..." and properly escaped; the 159-character topic title -> does not break the title tag.
- `e-seo-pro.6` @guest #ui: noindex and nofollow ticked on a space -> `<meta name="robots" content="noindex, nofollow">` appears; unticked removes it; a canonical base override is used on that space only.
- `e-seo-pro.7` @guest #ui: sitemap controls: exclude_sitemap on a space -> removes it and its topics from /community sitemap; priority and changefreq overrides show in the sitemap entries; a space left at defaults keeps 0.7/age-based values.
- `e-seo-pro.8` @guest #ui !refuse +private: view source of a topic in Customer Advisory Board and Moderators' Room as a guest -> the response is the not-found page with no topic title in `<title>`, og tags or JSON-LD; the space's overrides do not leak either.
- `e-seo-pro.9` @guest #ui +hidden: an anonymous topic -> emits no `article:author` and no author in schema.
- `e-seo-pro.10` @admin #rest !refuse: `PATCH /spaces/{id}/seo` as bob and as test_moderator without `jetonomy_manage_spaces` -> 403; invalid `sitemap_priority` ("high") is reset to default; `GET` as a guest -> 401/403.
- `e-seo-pro.11` @admin #admin: a meta description containing quotes and `<script>` -> is stored and echoed escaped in `<head>`.
- `e-seo-pro.12` @admin #ui +rtl: an Arabic meta title and description -> appear intact (UTF-8, not `&#...;` doubled) in the head of the Arabic topic's page.
- `e-seo-pro.13` @guest #ui: robots.txt -> contains the Pro rules for excluded spaces and does not disallow public spaces.
- `e-seo-pro.14` @admin #ui +settings-off: with the extension disabled, no Pro meta, no leftover noindex, and the default free schema still -> prints.
- `e-seo-pro.15` @admin #admin: saving the SEO tab busts the space cache -> the change appears on the frontend at once without a manual cache clear.

### E.web-push
**Covers:** includes/notifications/class-notifier.php, includes/models/class-notification.php, pro:includes/extensions/web-push/*.php, pro:assets/js/push-frontend.js, pro:assets/js/push-service-worker.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** the browser push subscription flow completes end-to-end, a test push reaches the subscribed browser. VAPID keys and service worker endpoints respond correctly.

**Checks:**
- `e-web-push.1` @admin #admin: Settings > Web Push: enable, set notification title and icon, save -> "Web Push settings saved."; VAPID public/private keys are generated automatically on first enable and persist across saves.
- `e-web-push.2` @guest #rest: `GET /jetonomy-pro/v1/push/vapid-key` -> 200 public key only (no private key in the body); `GET /push/service-worker.js` -> 200 with a JavaScript content type and a scope header.
- `e-web-push.3` @member #ui: on an HTTPS site, press the push subscribe control -> the browser permission prompt appears, accepting registers the subscription and the control flips to enabled; denying shows a clear message and leaves it off.
- `e-web-push.4` @member #rest: `POST /push/subscribe` with a real endpoint, p256dh and auth -> 201 `subscribed:true, created:true`; the same endpoint again -> 201 with `created:false` and no duplicate row; `DELETE /push/subscribe` -> `unsubscribed:true` and no more pushes.
- `e-web-push.5` @member #rest !refuse: `POST /push/subscribe` with `endpoint: "not a url"` -> 400 `jetonomy_push_endpoint_invalid` "Invalid push endpoint URL."; logged out -> 401.
- `e-web-push.6` @member_b #ui: alice replies to bob's topic while bob has a subscription -> a push arrives with the site title, the message text and a URL that opens that reply; a non-subscribed member gets none.
- `e-web-push.7` @member #ui +offline: bob's browser is closed (offline) -> the push is delivered when it reconnects within the TTL; sends continue through the async queue and a failed send does not block the post.
- `e-web-push.8` @member #rest: push service returns 410 Gone -> the dead subscription row is deleted; other subscriptions of bob still receive.
- `e-web-push.9` @member #ui +private: a notification about a topic bob cannot -> read (he was removed from Partner Program) never sends a push with its title; mentions in readable topics do.
- `e-web-push.10` @member #ui +hidden: a reply by an anonymous author -> pushes with the masked name "Anonymous", not the real member.
- `e-web-push.11` @member #ui: a direct message push -> shows what the owner intends on a lock screen (full text vs "New message") -- record it; muting the conversation or pausing all email notifications stops the push.
- `e-web-push.12` @silenced #ui +silenced: finn's reply (if any) and notifications about content removed by a moderator -> do not push.
- `e-web-push.13` @member #rest: `POST /push/register-device` with an `ExponentPushToken[...]` -> registered; a malformed token -> 400 "Invalid Expo push token."; `DELETE` unregisters; a native push goes to the Expo API with a deep link {type,id}.
- `e-web-push.14` @member #rest !refuse +concurrent: alice subscribes the browser bob used earlier (same endpoint, other account) -> the subscription must move to alice and bob must stop receiving alice's pushes on that browser; record what actually happens.
- `e-web-push.15` @admin #cli: `wp jetonomy-pro web-push` subcommands (status, test) -> report the key state and send a test push to a subscribed browser.
- `e-web-push.16` @admin #admin +settings-off: disable the extension -> the service worker route is unregistered and all sends stop, with subscriptions kept.
**Depth note:** the end-to-end subscription flow needs HTTPS and a real browser push service; on http://forums.local only the REST, key and queue checks (1, 2, 4, 5, 8, 13) can pass.

### E.webhooks
**Covers:** includes/notifications/class-notifier.php, includes/models/class-post.php, pro:includes/extensions/webhooks/*.php
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** an admin can create a webhook bound to one or more events; triggering one of those events actually fires a request to the configured URL; `POST /jetonomy/v1/webhooks/{id}/test` delivers a payload the receiver can parse (there is no test button in the admin list).

---

**Checks:**
- `e-webhooks.1` @admin #admin: Add Webhook: URL https://hooks.example.test/jetonomy (a request-catcher), events post.created and reply.created, save -> "Webhook saved."; the list shows the URL, events, active state and a one-time/visible secret for signature checks.
- `e-webhooks.2` @admin #admin !refuse: save with a malformed URL -> "Enter a valid webhook URL. The webhook was not saved."; with no events ticked -> "Choose at least one event. A webhook with no events would never fire, so it was not saved.".
- `e-webhooks.3` @admin #rest !refuse: `POST /webhooks` with `url: "ftp://x"` -> 400 `jetonomy_webhook_url_invalid` "Invalid webhook URL."; `events: ["bogus"]` -> 400 "At least one valid event is required."; a loopback or private-IP URL is refused at delivery (logged as failure, not fetched).
- `e-webhooks.4` @admin #rest: `POST /webhooks/{id}/test` -> `success:true, status_code:200` and the receiver gets `{"event":"test","timestamp":...,"data":{"message":...}}` with `X-Jetonomy-Signature: sha256=<hmac of body with the secret>`; a receiver returning 500 -> `success:false, status_code:500`; an unreachable host -> `success:false, error:<message>`.
- `e-webhooks.5` @member #ui: bob publishes "Anyone using the new SMTP preset?" in General Discussion -> within a minute the receiver gets `post.created` with id, title, author_id = bob, space_id, created_at and a valid signature and `X-Jetonomy-Event: post.created`.
- `e-webhooks.6` @member #ui: bob edits the topic -> `post.updated`; deleting it -> `post.deleted`; replying -> `reply.created`; each fires exactly once, never twice for one action.
- `e-webhooks.7` @member #ui: a held topic (moderation hold or require approval) -> sends nothing until a moderator approves it, then `post.created` fires once; a spam or draft post never fires.
- `e-webhooks.8` @member #ui +private: a private topic/reply (is_private) -> fires no webhook; record whether topics inside private/hidden spaces (Customer Advisory Board, Moderators' Room) fire, as they leave the site to an external URL.
- `e-webhooks.9` @member #ui +hidden: an anonymous topic -> fires with `author_id: 0` (real author not sent).
- `e-webhooks.10` @admin #admin: a receiver that fails (500) five times in a row -> the webhook auto-disables (`is_active` 0, fail_count 5) and the list shows it as disabled with the last status code; one later success on an active hook resets fail_count to 0.
- `e-webhooks.11` @admin #rest: `GET /webhooks/{id}/deliveries` -> lists recent deliveries with event, status code, and a response snippet of at most 500 characters; rows older than 30 days are pruned by the daily job.
- `e-webhooks.12` @admin #rest !refuse: `PATCH /webhooks/{id}` for id 99999 -> 404 `jetonomy_webhook_not_found` "Webhook not found."; `DELETE` removes it and later events no longer fire.
- `e-webhooks.13` @member #rest !refuse: bob and test_moderator `GET/POST/PATCH/DELETE /webhooks` and `/test` -> 403; guest 401; the secret is never returned in the API list.
- `e-webhooks.14` @admin #rest: `user.registered`, `user.trust_level_changed`, `space.member_joined` and `space.member_left` -> each deliver their payload when triggered (register a user; promote bob to trust level 2; join and leave Partner Program).
- `e-webhooks.15` @admin #admin +many: with 20 webhooks and 5,000 delivery rows the -> list loads fast and paginates; `Last Triggered` shows in the site timezone.
- `e-webhooks.16` @admin #admin +offline: a slow receiver (over 15 seconds) times out without delaying the member's post (dispatch -> is async); the delivery log records status 0 with the error.
- `e-webhooks.17` @admin #admin +390: the Webhooks admin form and -> list at 390px stack without clipping the secret or events checkboxes.

### E.pro-license-and-lockstep
**Covers:** pro:jetonomy-pro.php, pro:includes/class-jetonomy-pro.php, pro:includes/license/*.php, pro:includes/functions-multisite.php, pro:uninstall.php
**Verified:** pending - new journey, not walked yet
**What to verify:** As site admin, Pro and Free versions match (lockstep), and activating Pro without Free active shows the dependency notice instead of a fatal. Enter a valid license key, confirm it shows Active with an expiry, then deactivate and confirm Pro features are gated with a clear message. Confirm the EDD update check routes to the Pro product, every Pro REST route still boots with Free-only data (no fatal on /wp-json/jetonomy/v1 or /jetonomy-pro/v1), and on multisite a newly created sub-site gets Pro tables. Deleting Pro with "remove data" removes its tables and nothing else. Check the license screen at 1440 and 390 widths.

**Version lockstep.** **What to verify:** the free and Pro version constants are identical. Pro never ships at a version that diverges from free.

```
wp eval 'echo "free:" . JETONOMY_VERSION . " pro:" . JETONOMY_PRO_VERSION;'
```

**Pass:** both constants print the same `x.y.z`. **Fail:** drift, halt, file a card.

**License activation.** **What to verify:** an admin can activate, deactivate, and reactivate a license key from `wp-admin > Jetonomy > License`. Invalid keys produce a clear error, not a fatal. Tier-gated extensions reflect the active tier on the Extensions page.

**Pro dependency guard.** **What to verify:** deactivating free while Pro stays active surfaces a clean admin notice ("Jetonomy Pro requires Jetonomy") and Pro bails out without fataling. Reactivating free restores Pro automatically.

**Lockstep upgrade path.** **What to verify:** updating one plugin's zip without updating the other is either prevented by the updater or handled gracefully. Version drift warning renders in the admin bar.

**EDD updater routing.** **What to verify:** the Pro auto-updater queries the correct SL endpoint, reports the right current version, and successfully fetches a newer version when one exists on the store.

**REST boot resilience (1.4.5 fatal guard, Basecamp 9953887096).** **What to verify:** Pro extension REST routes never reference free's `REST_Auth` eagerly. `curl -s <site>/wp-json/jetonomy/v1/` returns the route index (HTTP 200, no 500) with both plugins active, AND with Pro active while free is deactivated mid-request cycle the REST API still responds (Pro bails with an admin notice instead of fataling). `php ../jetonomy/bin/audit-rest-routes.php includes/` reports OK.

**Checks:**
- `e-pro-license-and-lockstep.1` @admin #cli: `wp eval 'echo "free:" . JETONOMY_VERSION . " pro:" . JETONOMY_PRO_VERSION;'` -> both print the identical `x.y.z`; any drift is a halt.
- `e-pro-license-and-lockstep.2` @admin #admin: deactivate Jetonomy (free) while Pro stays active -> a clean admin notice "Jetonomy Pro requires Jetonomy" appears, no fatal on any wp-admin page or on the frontend; reactivating free restores Pro without a manual step.
- `e-pro-license-and-lockstep.3` @guest #rest: with both active `curl <site>/wp-json/jetonomy/v1/` -> HTTP 200 route index; with free deactivated mid-cycle the REST API still responds (200/404 for jetonomy routes, never 500); `/wp-json/jetonomy-pro/v1/` boots with Free-only data.
- `e-pro-license-and-lockstep.4` @admin #cli: `php ../jetonomy/bin/audit-rest-routes.php -> includes/` in Pro reports OK, no route references free's REST_Auth eagerly.
- `e-pro-license-and-lockstep.5` @admin #admin: Jetonomy > License: enter a valid key -> status Active with tier label (Starter/Growth/Agency/Lifetime/Pro) and an expiry date, the key is shown masked; Deactivate -> status inactive, Reactivate works.
- `e-pro-license-and-lockstep.6` @admin #admin !refuse: enter an invalid key -> a clear error message, no fatal, no stored "valid" state; an expired key shows "expired" with the date and a renew link.
- `e-pro-license-and-lockstep.7` @admin #admin: with the licence lapsed and `JETONOMY_PRO_LICENSE_BYPASS` removed, Pro features -> keep working for the 30-day grace period with a visible "days left" warning, then gate with a clear message on day 31; re-validating a good key clears the grace marker (the walk must unset the bypass constant first).
- `e-pro-license-and-lockstep.8` @admin #admin: Extensions page reflects the tier: extensions the tier -> does not include show as locked with an upgrade message, not as a blank card.
- `e-pro-license-and-lockstep.9` @admin #admin: the updater queries the Pro product endpoint, -> reports the installed version, and offers a newer version when the store has one; the free updater is not confused by it.
- `e-pro-license-and-lockstep.10` @admin #admin +old-data: updating only one plugin's zip -> either prevented or a drift warning appears in the admin; the site does not fatal.
- `e-pro-license-and-lockstep.11` @admin #admin: multisite: a newly created sub-site -> gets the Pro tables (`wp_N_jt_polls`, `jt_conversations`...), and the network-activated licence applies per the multisite rule.
- `e-pro-license-and-lockstep.12` @admin #admin: delete Pro with "remove data" off -> everything kept; on -> Pro tables, options, cron/Action Scheduler jobs and the licence check are removed and nothing of free's data is touched. `jetonomy-pro_license_grace_started` stays by design, so a reinstall cannot restart the 30-day grace window.
- `e-pro-license-and-lockstep.13` @admin #admin +390: the License screen at 1440 and 390 -> shows key, status and buttons without overflow and with token colours in dark mode.
- `e-pro-license-and-lockstep.14` @member #rest !refuse: bob `GET/POST` any license endpoint or the admin-post action -> 403; the key never appears in any REST response or page source.
**Depth note:** needs a valid Jetonomy Pro licence key against the live EDD store and a multisite install for check 11.

### E.extensions-manager
**Covers:** pro:includes/class-extension.php, pro:includes/class-extensions-admin.php, pro:assets/js/pro-admin.js, pro:assets/css/pro-admin.css, pro:includes/functions.php, pro:includes/class-queue.php
**Verified:** pending - new journey, not walked yet
**What to verify:** As admin, open the Pro Extensions page, use the category filter, toggle each extension off and on, and confirm its menu pages, routes and frontend UI disappear and return with no fatal and no lost settings. Every Pro admin page keeps the shared admin styling. Renamed product nouns (Topic, Reply, Member) and the brand name show on Pro screens. Background work (digest, push, AI batch, badges) runs through Action Scheduler or the WP-Cron fallback and completes with an empty debug.log. Check 1440 and 390 widths.

**Checks:**
- `e-extensions-manager.1` @admin #admin: open the Pro Extensions page -> every extension card lists name, description, category tag and an on/off toggle with an aria-label naming the extension; the category filter narrows the grid and shows counts.
- `e-extensions-manager.2` @admin #admin: toggle Polls off -> notice "Extension settings updated."; the Polls admin menu, REST routes (`/polls/...` -> 404) and the composer poll control disappear; toggle on -> they return with the settings and existing polls intact.
- `e-extensions-manager.3` @admin #admin: repeat check 2 for every extension (private-messaging, polls, reactions, analytics, custom-badges, white-label, advanced-moderation, ai, custom-fields, email-digest, reply-by-email, seo-pro, web-push, webhooks, site-announcements, anonymous-posting, attachments) -> no fatal and an empty debug.log after each toggle.
- `e-extensions-manager.4` @admin #admin +old-data: switch an extension off with data (reactions on 40 posts) and back on -> previous reactions reappear; switching off does not delete tables.
- `e-extensions-manager.5` @admin #admin: with WPMediaVerse active the Private Messaging card -> shows "WPMediaVerse is active and its messaging engine takes over all direct messages. This extension stays dormant while WPMediaVerse runs." and the toggle does not pretend to enable messaging.
- `e-extensions-manager.6` @member #rest !refuse: bob and test_moderator POST the toggle form / call the toggle action -> refused (nonce and `manage_options`); the capability is checked, not only the nonce.
- `e-extensions-manager.7` @admin #admin: every Pro admin page (Analytics, Badges, Custom Fields, Moderation Rules, Webhooks, Polls, Conversations) -> uses the shared admin styling: same page header, cards and buttons as free's screens, no unstyled tables.
- `e-extensions-manager.8` @admin #admin: renamed product nouns (Topic, Reply, Member, Space) set under Settings -> show on Pro screens (Analytics column headers, custom field admin, badge hints) and the brand name shows where White Label is set.
- `e-extensions-manager.9` @admin #cli: background work (digest, push, AI batch, badge award check) -> runs through Action Scheduler (or the WP-Cron fallback): `wp action-scheduler list --status=pending` shows the jobs, `wp action-scheduler run` completes them, and debug.log stays empty.
- `e-extensions-manager.10` @admin #admin +many: the Extensions page with 17 cards and a long translated description -> wraps cleanly at 390px, the toggle stays reachable, and the page reloads after toggling with the same category filter kept.
- `e-extensions-manager.11` @admin #admin +dark: cards, toggle and category tags -> use tokens in dark mode.
- `e-extensions-manager.12` @admin #admin +concurrent: two admins toggle different extensions at the same moment -> both changes persist (one does not overwrite the other's option write).

### E.pro-privacy-and-abilities
**Covers:** pro:includes/class-privacy.php, pro:includes/class-abilities.php
**Verified:** pending - new journey, not walked yet
**What to verify:** As admin, run Tools > Export Personal Data for a member who has DMs, reactions, poll votes, badges, custom field values and a push subscription, and confirm each appears. Run Erase Personal Data and confirm those Pro rows are removed or anonymised and other members' data is untouched. On WP 6.9+, the Pro abilities list only for enabled and licensed extensions, and an ability call respects the caller's capabilities. Check admin screens at 1440 and 390 widths.

**Checks:**
- `e-pro-privacy-and-abilities.1` @admin #admin: Tools > Export Personal Data for maya (who has DMs, reactions, poll votes, badges, custom field values and a push subscription) -> the export lists groups "Jetonomy Pro: Private Messages", "Reactions", "Custom Fields", "Badges", "Poll Votes" and "Push Devices" (with "Browser subscription"), each with real values.
- `e-pro-privacy-and-abilities.2` @admin #admin: the export for a member with none of those -> has no empty group headings and no PHP notice; a member with >100 reactions pages correctly via `done:false`.
- `e-pro-privacy-and-abilities.3` @admin #admin: the exported messages -> include only maya's own side and what she can lawfully receive; other participants' private data (their email, their other threads) is not in her file.
- `e-pro-privacy-and-abilities.4` @admin #admin: Erase Personal Data for Jamal (a demo member no persona depends on) -> result text "Jetonomy Pro: Messages anonymized. Reactions, custom fields, badges, poll votes, and push subscriptions/devices deleted."; her reactions, field values, badges, poll votes and subscriptions are gone.
- `e-pro-privacy-and-abilities.5` @member #ui: after erasure, bob's thread with Jamal still -> shows bob's messages and Jamal's as "Deleted User"; bob's own data, reactions and votes are untouched, and poll totals drop by exactly maya's votes.
- `e-pro-privacy-and-abilities.6` @admin #cli: `wp user delete <a member registered for this check> --reassign=1` -> clears the same Pro rows (orphan columns) with no orphan rows left in `jt_reactions`, `jt_poll_votes`, `jt_user_badges`, `jt_push_subscriptions`.
- `e-pro-privacy-and-abilities.7` @admin #admin +many: erase a member with 5,000 reactions across several requests (the per-request row limit) -> eventually done, no timeout.
- `e-pro-privacy-and-abilities.8` @admin #admin: anonymous posts and attachments authored by the erased user -> are handled per the owner's policy (anonymised or removed) and not left linking to a real author id.
- `e-pro-privacy-and-abilities.9` @admin #rest: on WP 6.9+, the abilities -> list contains messaging, analytics, polls, badges, webhooks, reactions, custom fields and moderation abilities only for extensions that are enabled and licensed; disabling Polls removes its abilities; with WPMediaVerse messaging active no messaging abilities are listed.
- `e-pro-privacy-and-abilities.10` @member #rest !refuse: an ability call as bob for analytics or export CSV -> denied (needs `jetonomy_manage_settings`); "Get Conversation Messages" for a conversation bob is not in -> denied; a guest call denied.
- `e-pro-privacy-and-abilities.11` @silenced #rest !refuse +silenced: finn calls the Send Private Message ability -> refused with the same silenced rule as REST.
- `e-pro-privacy-and-abilities.12` @admin #admin +390: Tools > Export/Erase screens at 390px -> remain usable with the Pro groups listed.
**Depth note:** abilities need WordPress 6.9 or newer; on older cores the list is simply absent and only checks 1 to 8 apply.

### E.membership-gating-adapters
**Covers:** pro:includes/adapters/class-adapter-url.php, pro:includes/adapters/class-buddynext-pro-adapter.php, pro:includes/adapters/class-learndash-adapter.php, pro:includes/adapters/class-learnomy-adapter.php, pro:includes/adapters/class-lifterlms-adapter.php, pro:includes/adapters/class-masterstudy-adapter.php, pro:includes/adapters/class-rcp-adapter.php, pro:includes/adapters/class-sensei-adapter.php, pro:includes/adapters/class-suremembers-adapter.php, pro:includes/adapters/class-suremembers-space-integration.php, pro:includes/adapters/class-tag-membership-adapter.php, pro:includes/adapters/class-tag-space-integration.php, pro:includes/adapters/class-tutor-adapter.php, pro:includes/adapters/class-woocommerce-adapter.php, pro:includes/adapters/class-wpfusion-adapter.php, pro:includes/adapters/class-wpfusion-space-integration.php, pro:assets/js/admin-integrations.js
**Verified:** pending - new journey, not walked yet
**What to verify:** For each membership or LMS plugin installed on the dev site (WooCommerce, RCP, LearnDash, Tutor, LifterLMS, Sensei, MasterStudy, Learnomy, BuddyNext Pro, WP Fusion, SureMembers), as admin pick its level in an Access Rule on the Integrations tab (the toast confirms the save) and as a member who holds the level versus one who does not, confirm the space opens only for the holder, the locked teaser shows a working "buy or enrol" link, and removing the level ends access. A rule never grants moderation or a role above its Space Role (D.rule-role-escalation). The per-space WP Fusion and SureMembers tab links both ways. Plugins not installed must not fatal or list. Walk 1440 and 390 widths.

**Checks:**
- `e-membership-gating-adapters.1` @admin #admin: Integrations tab of Partner Program > Access Rules: the dropdown -> lists only plugins actually installed on this site (WooCommerce plans, LearnDash courses, Tutor courses, etc.); uninstalled ones are not listed and cause no fatal in the page.
- `e-membership-gating-adapters.2` @admin #admin: pick a level (for example a WooCommerce plan "Gold Membership") in Add Access Rule and save -> the toast confirms the save and the rule appears with the level name and the Space Role it grants.
- `e-membership-gating-adapters.3` @member #ui: bob holds the level -> the gated space opens, he can read and post per its Space Role; he appears in the member roster.
- `e-membership-gating-adapters.4` @member_b #ui !refuse: alice does not hold the level -> the space shows the locked teaser with a working "buy or enrol" link to the product page; the link reaches checkout/enrolment (not a 404) and works for guests too.
- `e-membership-gating-adapters.5` @guest #rest !refuse: guest `GET` of a gated space's topics and `GET /spaces/{id}` -> a private space the guest may see in listings answers 403 `jetonomy_forbidden` (request-access flow); a hidden/concealed space answers exactly like the space-not-found 404; the teaser page shows only the public description.
- `e-membership-gating-adapters.6` @member #ui: remove the level from bob (cancel the membership) -> his access ends on the next request (cache not stale), his earlier posts stay but he cannot open the space, and the roster no longer lists him.
- `e-membership-gating-adapters.7` @member #ui +concurrent: bob buys the level while on the teaser -> after payment completes the space opens without a manual cache clear or re-login.
- `e-membership-gating-adapters.8` @member #rest !refuse: a rule with Space Role "member" never grants moderation: bob with the level calls the moderation endpoints in that space -> 403 (D.rule-role-escalation); a rule must not raise a site Editor/other role.
- `e-membership-gating-adapters.9` @admin #admin: per-space WP Fusion and SureMembers tabs link both ways (space tab -> integration settings and back) with the correct space preselected.
- `e-membership-gating-adapters.10` @admin #admin +deleted: delete the plan/course that a rule points to -> the rule is shown as pointing at a missing level (or removed), does not grant access to everyone, and does not fatal.
- `e-membership-gating-adapters.11` @admin #admin +many: a site with 500 courses/plans: -> the level picker searches/paginates instead of loading all options at once.
- `e-membership-gating-adapters.12` @member #ui +390 +dark: the locked teaser and the Access Rules screen at 390px -> wrap the buy button and level name, with token colours in dark mode.
**Depth note:** the walk needs each membership or LMS plugin installed with a test level and an enrolled and a non-enrolled user: WooCommerce (Memberships/Subscriptions), MemberPress, PMPro, RCP, LearnDash, Tutor LMS, LifterLMS, Sensei, MasterStudy, Learnomy, BuddyNext Pro, WP Fusion, SureMembers. Only those installed can be walked; the dev site does not have most of them, so say which were walked.

### E.lms-course-spaces-and-automator
**Covers:** pro:includes/integrations/*.php
**Verified:** pending - new journey, not walked yet
**What to verify:** As admin, publish a course in an installed LMS (LearnDash, Tutor, LifterLMS, Sensei, MasterStudy, Learnomy) and confirm exactly one discussion space is created, with the right type and access, and that republishing never makes a second. An enrolled student sees the course space and an unenrolled member does not. With Uncanny Automator active, the Jetonomy add/remove member and set-role actions and the role-changed trigger work in a recipe. Check the course space page at 1440 and 390 widths.

**Checks:**
- `e-lms-course-spaces-and-automator.1` @admin #admin: publish a course "Introduction to WordPress Plugin Development" in the installed LMS -> exactly one discussion space is created with the course title, the type and visibility (private) chosen in the integration settings, and an access rule bound to that course.
- `e-lms-course-spaces-and-automator.2` @admin #admin: update and re-publish the same course (draft then publish again) -> still one space; no second space and no duplicate access rule.
- `e-lms-course-spaces-and-automator.3` @member #ui: an enrolled student opens the course page and /community -> sees the course space and its topics; the course shows the "Course discussion" / "View course discussion" link to it.
- `e-lms-course-spaces-and-automator.4` @member_b #ui !refuse: an unenrolled member searching for the course space -> gets the not-found/locked teaser, never the topic list; the space is not listed in the space directory.
- `e-lms-course-spaces-and-automator.5` @member #ui: unenrol the student -> access ends at once; re-enrol -> returns; their posts remain.
- `e-lms-course-spaces-and-automator.6` @admin #admin +deleted: trash the course -> the space is archived or hidden per the documented behaviour, dead access rules are dropped, and republishing a restored course reuses the live space rather than making another.
- `e-lms-course-spaces-and-automator.7` @admin #admin: the bulk "create spaces for existing courses" action (if offered) -> creates one per course and is safe to run twice.
- `e-lms-course-spaces-and-automator.8` @admin #rest !refuse: a course author calling the Learnomy REST route for a course they do not own -> 403 "You can only change courses you own."; unknown course -> "That course could not be found."; Learnomy inactive -> "Learnomy is not active.".
- `e-lms-course-spaces-and-automator.9` @admin #admin: Uncanny Automator recipe "Add the user to <space>" fired for a test user -> the user becomes a member of that space with the chosen role; "Remove the user from <space>" removes them; "Set role" changes the role; each shows in the recipe log as completed.
- `e-lms-course-spaces-and-automator.10` @admin #admin: the "role changed" trigger -> fires a recipe when test_editor is promoted to space admin of Troubleshooting, exactly once.
- `e-lms-course-spaces-and-automator.11` @admin #admin !refuse: an Automator recipe that tries to grant a role above what the rule allows, or targets a space that -> does not exist, fails in the recipe log with a message and changes nothing.
- `e-lms-course-spaces-and-automator.12` @member #ui +390 +dark: the course space page header, "View course discussion" link and Your discussions -> list at 390px wrap a 90-character course title and use token colours in dark mode.
**Depth note:** needs LearnDash or Tutor LMS (or LifterLMS, Sensei, MasterStudy, Learnomy) plus a course and an enrolled student, and Uncanny Automator active with the Jetonomy integration for checks 9 to 11. None of these are installed on the dev site by default.

### E.site-announcements
**Covers:** pro:includes/extensions/site-announcements/*.php, pro:assets/js/announcements-frontend.js
**Verified:** pending - new journey, not walked yet
**What to verify:** As admin or moderator, mark a topic as a site announcement and confirm it is pinned at the top of every space listing, not only its own, for member and guest views. Unmarking removes it everywhere, and dismissing (if offered) persists for that member. Normal per-space pinned topics are unchanged. Walk 1440 and 390 widths.

**Checks:**
- `e-site-announcements.1` @admin #ui: on the topic "Scheduled maintenance on Saturday" use "Pin to community" -> the label flips to "Unpin from community" and a success state shows without reload.
- `e-site-announcements.2` @member #ui: bob opens the listing of General Discussion, Help Desk and Feature Requests -> the announcement is pinned at the top of each with an "Announcement" pill (title "Pinned site-wide by an administrator"), even though it lives in Announcements.
- `e-site-announcements.3` @guest #ui: the guest sees the same pinned announcement on public space listings but not when the announcement's space -> is private/hidden to guests.
- `e-site-announcements.4` @admin #ui: "Unpin from community" -> it leaves every listing at once (no stale five-minute cache), and the topic still shows in its own space normally.
- `e-site-announcements.5` @member #ui: normal per-space pinned topics -> keep their pin order and are not moved or duplicated by the announcement; an announcement that is also pinned in its own space appears once.
- `e-site-announcements.6` @admin #rest !refuse +limit: pin past the limit (default max pins) -> 400 `jetonomy_pin_limit` "You can only pin %d announcements at a time. Unpin one first." and the UI shows the message; pinning a draft, trashed or nonexistent post -> 404 "Post not found or not published.".
- `e-site-announcements.7` @moderator #rest: test_moderator `POST/DELETE /jetonomy-pro/v1/site-announcements/{id}` -> 403 unless the role has `jetonomy_manage_spaces` (pinning needs `manage_options` or that cap, moderators do not have it by default) and bob gets 403 on `POST/DELETE /site-announcements/{id}` and `GET /site-announcements`.
- `e-site-announcements.8` @member #rest !refuse +private: `GET /announcements/active` as bob -> never lists an announcement from Customer Advisory Board or Moderators' Room; as a member of the space, it lists it.
- `e-site-announcements.9` @member #ui +blocked: if bob blocked the author of the announcement, it -> does not appear at the top of his listings.
- `e-site-announcements.10` @member #ui: announcements have no dismiss control (none exists in this release) -> confirm none is rendered on the pill or card in the listing; that is the pass state. If a dismiss is ever added, it must persist across reload and devices for that member and not hide the announcement from others.
- `e-site-announcements.11` @member #ui +long +rtl: a 159-character announcement title and the Arabic topic pinned site-wide -> wrap inside the card on Reign at 390px and the pill does not overlap the title; RTL pill sits on the correct side.
- `e-site-announcements.12` @member #ui +deleted: delete or unpublish a pinned topic -> it disappears from all listings within the cache window and the pin list does not show a ghost entry.
- `e-site-announcements.13` @member #ui +empty: an empty space (the last five Local Chapters) -> shows the announcement above the empty state, and the empty-state text is still present.
- `e-site-announcements.14` @member #ui +390 +dark: pill and card -> use token colours in dark mode and listing layout holds at 390px.
- `e-site-announcements.15` @admin #rest: trash or delete a pinned topic -> it drops out of GET /site-announcements and /site-announcements, and its pin slot is free to pin another topic.

### E.anonymous-posting
**Covers:** pro:includes/extensions/anonymous-posting/**
**Verified:** pending - new journey, not walked yet
**What to verify:** As space admin, enable anonymous posting in a space's settings. As a member, post a topic and a reply anonymously, and confirm other members, guests and the profile page show no author identity, avatar or link, including in REST output, search and notifications. A moderator or admin can reveal the real author, and that action is audited. A space with the setting off shows no anonymous toggle in the composer. Check the composer and space setting at 1440 and 390 widths.

**Anonymous Posting (1.7.0).** Extension id `anonymous-posting` (`includes/extensions/anonymous-posting/`). Each row is a contract: verify the UI behavior AND the server-side effect.

**What/why:** members can author a topic or reply with their identity hidden from every other member, gated by a global master switch (the extension's own enable toggle — `Gate::global_enabled()` reads `jetonomy_pro_extensions` option, no separate global option) AND a per-space opt-in (`allow_anonymous` key inside the space's `settings` JSON, `Gate::space_allows()`). Real `author_id` is always stored; only display is masked, via the single seam `\Jetonomy\Author::for_display()`.

| ID | What to verify | Expected |
|----|-----------------|----------|
| E.anon.space-toggle | Space edit admin has an "Anonymous" tab (`jetonomy_admin_space_edit_tabs` / `_tab_content`, `views/space-setting.php`) with an "Allow anonymous posts in this space" checkbox, nonce `jetonomy_pro_anon_space_nonce`, field `allow_anonymous` | Saving persists into the space's `settings` JSON (`allow_anonymous: true/false`) via `Space::update()` — a MERGE, not an overwrite (an unrelated existing settings key must survive the save). Verify via `wp eval` reading `Space::get_settings($id)`. Unchecking + save clears the key to false, not removing the whole JSON. |
| E.anon.composer-gate | `.jt-anon-toggle` (checkbox, new-topic composer, `jetonomy_new_post_fields`) and `.jt-anon-toggle-btn` (icon button, reply composer toolbar, `jetonomy_composer_toolbar`, `data-jt-anon-toggle`, `aria-pressed`) render ONLY when both gates pass for the target space AND the viewer is logged in (`Gate::can_author_anonymously()`) | In a space with the per-space setting OFF, or with the Anonymous Posting extension disabled, neither control is present in the composer DOM. In a space with both ON, the control renders for a logged-in member. |
| E.anon.post-persist | Submit a new topic with the toggle on (`is_anonymous` mirrored onto the create payload by `assets/anonymous.js` via the `jetonomy:before-post-submit` CustomEvent) | `wp_jt_posts` row for the new post has `is_anonymous = 1` AND `author_id` = the real posting user's ID (never 0, never rewritten). Server re-validates via `enforce_post_anonymity()` on `jetonomy_before_create_post` — client value is never trusted; posting the same payload in a space where the per-space gate is OFF must persist `is_anonymous = 0` even though the client sent 1. |
| E.anon.reply-persist | Same as above for a reply (`jetonomy:before-reply-submit`, `enforce_reply_anonymity()` on `jetonomy_before_create_reply`, space resolved from the parent post) | `wp_jt_replies` row has `is_anonymous = 1`, real `author_id` intact. Gate re-validated server-side against the parent post's space, not the client. |
| E.anon.mask-feed-and-single | View the space listing / home feed card, and the single-post page, for an anonymous topic | Author name renders "Anonymous" with no avatar image and no profile link (`Author::for_display()` returns `id: 0, name: 'Anonymous', avatar: '', url: ''`); confirm via `templates/partials/feed-card.php` (feed) and `templates/views/single-post.php` (`$jt_author_display`) — neither template bypasses `Author::for_display()`. No real display name or `get_userdata()` output appears in the rendered HTML. |
| E.anon.mask-reply-card | View a reply card for an anonymous reply | Same masking as above (reply-card partial routes through `Author::for_display()` too — same seam as post/feed). |
| E.anon.mask-search | Search for a term that matches the anonymous topic/reply's content | Result appears (content is searchable) but author is masked the same as the listing card — no real name/avatar in the result row. |
| E.anon.mask-rss | `GET /community/s/{slug}/feed/` for a space containing an anonymous post | The RSS `<item>` for that post shows author "Anonymous" (`includes/class-feed.php:80` routes through `Author::for_display()`), never the real display name. |
| E.anon.excluded-from-profile-stream | View the anonymous author's own public profile (`/community/u/{login}/`) | The anonymous post/reply does NOT appear in their public posts/replies stream — confirmed by the `is_anonymous = 0` guard in the profile-stream SQL (`includes/models/class-post.php` / `class-reply.php`, `WHERE ... AND p.is_anonymous = 0`, and `templates/views/user-profile.php`). |
| E.anon.mask-notification | Trigger a notification from the anonymous post/reply (e.g. reply to a subscribed thread, or a mention) | The notification's REST payload (`class-notifications-controller.php`) reports `actor_id: null` and `actor_name: "Anonymous"` when `actor_anonymous` is set; verify the underlying `wp_jt_notifications` row has `actor_anonymous = 1` (persisted at creation from the post/reply's `is_anonymous` flag via `Notifier::create_and_maybe_email()`'s `$actor_anonymous` param) while `actor_id` still stores the real user id server-side (masking is display-only). The notifications template masks the same way. |
| E.anon.reveal-admin-only | As a site admin, click "Reveal author" (`.jt-anon-reveal`, rendered only for `manage_options` + `is_anonymous` rows, `Reveal::render_button()`) on an anonymous post/reply | `POST /jetonomy/v1/anonymous/reveal` with `{object_type, object_id}` returns HTTP 200 `{success:true, author:{id,name}}` with the REAL author; a `wp_jt_activity_log` row is written with `action = 'anonymous_author_revealed'`, `user_id` = the revealing admin, and `metadata` holding `{"real_author": <id>}` (`ActivityLog::log()` call in `Reveal::reveal()`). As a non-admin (member/moderator without `manage_options`), the same request returns HTTP 403 (`rest_auth_mutation('manage_options')` gate) and no activity-log row is written. Confirm the reveal is NOT persistent: reloading the page as a non-revealing viewer still shows "Anonymous" (reveal context is request-scoped, `Reveal::$context`). |

**Checks:**
- `e-anonymous-posting.1` @space_admin #admin: test_editor opens the Anonymous tab of Help Desk space edit, ticks "Allow anonymous posts in this space", saves -> persisted as `allow_anonymous: true` in the space settings; other settings keys in the JSON survive the save (merge, not overwrite); unticking sets it to false.
- `e-anonymous-posting.2` @member #ui: in Help Desk the new-topic composer -> shows the "post anonymously" checkbox and the reply toolbar shows the anonymous icon button (`aria-pressed` toggles); in a space with the setting off (General Discussion) neither appears in the DOM.
- `e-anonymous-posting.3` @member #ui: bob posts the topic "Is it normal for my first plugin review to take three weeks?" anonymously in Help Desk -> the card and the topic show author "Anonymous" with no avatar and no profile link; no real name appears anywhere in the HTML.
- `e-anonymous-posting.4` @member #ui: bob replies anonymously to another topic -> reply card shows "Anonymous", no avatar, no link.
- `e-anonymous-posting.5` @member_b #ui: alice and a guest see the same masking in the space listing, home feed, single topic, reply -> list, search results and the space RSS feed (`<author>` Anonymous).
- `e-anonymous-posting.6` @member #ui: bob's public profile /community/u/bob/ -> does not list the anonymous topic or reply in posts/replies streams and the post count tooltip does not reveal it; bob's own "my posts" view (if any) shows it with a marker only to him.
- `e-anonymous-posting.7` @member_b #rest: `GET /posts/{id}` and `/replies` for the anonymous topic -> `author` is `{id:0, name:"Anonymous", avatar:"", url:""}`; the response contains no real `author_id` or `display_name` anywhere (including `_embedded` and links).
- `e-anonymous-posting.8` @member_b #email: alice replying to the anonymous topic notifies bob; bob's notification -> shows normal info; when bob (anonymous) replies to alice, her bell and email say "Anonymous" and `actor_id` is null in `GET /notifications`.
- `e-anonymous-posting.9` @member_b #ui: mentions, quotes and the "reply to" header on an anonymous reply -> do not reveal the author; the topic's HTML title/og tags carry no author.
- `e-anonymous-posting.10` @member #rest !refuse: `POST /posts` with `is_anonymous: 1` in a space where the setting is OFF -> the post is created with `is_anonymous = 0` (never trusted from the client); the author is shown by name.
- `e-anonymous-posting.11` @guest #rest !refuse: logged-out create with `is_anonymous` -> 401; the toggle is not rendered for guests.
- `e-anonymous-posting.12` @silenced #ui !refuse +silenced: finn cannot post anonymously to evade a silence: the post -> is refused with the silenced reason; a banned omar likewise in Off-Topic Lounge.
- `e-anonymous-posting.13` @admin #ui: admin sees "Reveal author" on anonymous posts and replies; clicking it calls `POST /anonymous/reveal` and -> shows the real author for that page view only; an activity log row `anonymous_author_revealed` is written with the revealing admin and `{"real_author": id}`.
- `e-anonymous-posting.14` @moderator #rest !refuse: test_moderator and space_admin `POST /anonymous/reveal` -> 403 (only `manage_options`); no log row; member 403; guest 401. Record whether the owner expects moderators to be able to reveal (the runbook says moderator or admin).
- `e-anonymous-posting.15` @member_b #ui: reloading as alice after the admin's reveal still -> shows "Anonymous" (reveal is request-scoped, not persisted).
- `e-anonymous-posting.16` @member #ui: editing an anonymous topic -> keeps it anonymous; moving it to a space where anonymity is off re-masks or unmasks per the owner's rule and shows no real name in the move notice.
- `e-anonymous-posting.17` @moderator #ui: the mod queue, -> flag report email and user-facing "report" dialog show "Anonymous" for the content author to normal moderators; the report still resolves to the real author for admin action.
- `e-anonymous-posting.18` @admin #admin: disabling the Anonymous Posting extension after content exists -> existing anonymous posts stay masked (or are shown per the documented rule) and no composer toggle appears.
- `e-anonymous-posting.19` @member #ui +390 +dark: composer toggle and the "Anonymous" byline at 390px and dark mode -> keep contrast and 40px tap size.
**Depth note:** no `wp jetonomy-pro` CLI group covers anonymous posting; entry points are ui, admin and rest only. Cross-check the CLI list in E.pro-cli.

### E.attachments
**Covers:** pro:includes/extensions/attachments/**, pro:assets/css/attachments.css, pro:assets/js/attachments-admin.js, pro:assets/js/attachments-frontend.js, pro:assets/js/pdf-viewer.js
**Verified:** pending - new journey, not walked yet
**What to verify:** As admin, set allowed types and size limits on the Attachments settings page. As a member, attach an image, a PDF and a disallowed file type to a topic and a reply. Confirm the allowed ones upload and render, the PDF opens in the viewer modal, and the disallowed or oversized one is refused with a clear message. A guest cannot upload, a user who cannot see the topic cannot download the file, and deleting the post removes the file. Walk 1440 and 390 widths.

**File Attachments (1.7.0).** Extension id `attachments` (`includes/extensions/attachments/`). Option key `jetonomy_pro_attachments` (`Extension::OPTION` / `Settings::OPTION`), fields `allowed_types` (array of extensions), `max_size_bytes` (int), `max_files` (int, default 5). Link table `wp_jt_attachments` (FREE owns it from 1.7.1 — it is the old `wp_jt_attachments`, renamed by free's Migration_1_7_1; same schema, no rows copied) (`object_type` enum post/reply, `object_id`, `attachment_id`, `sort`, `created_at`, `UNIQUE KEY object_attachment (object_type,object_id,attachment_id)`).

| ID | What to verify | Expected |
|----|-----------------|----------|
| E.attach.settings-persist | Settings tab `wp-admin/admin.php?page=jetonomy-settings&tab=attachments` (`views/settings.php`, nonce `jetonomy_pro_attachments_settings`/`jt_attachments_nonce`, fields `jt_attach_types[]`, `jt_attach_max_mb`, `jt_attach_max_files`) | Saving persists `allowed_types`, `max_size_bytes` (MB × `MB_IN_BYTES`), `max_files` into the `jetonomy_pro_attachments` option via `Settings::sanitize()`. `max_size_bytes` is clamped to `[MB_IN_BYTES, wp_max_upload_size()]`; `max_files` clamped to `[1,20]`. |
| E.attach.svg-never-allowed | Attempt to add `svg` to `jt_attach_types[]` via a raw POST to the settings save handler (bypassing the UI, which doesn't render an SVG checkbox at all) | `Settings::sanitize()` intersects against `Uploader::ext_mime_map()`, which has no `svg`/`svgz` key — `svg` is silently dropped from the saved `allowed_types` regardless of what was posted. `Uploader::extend_allowed_types()` also explicitly skips `svg`/`svgz` even if it somehow reached the option. Confirm `wp option get jetonomy_pro_attachments` never contains `svg` after the attempt. |
| E.attach.upload-validates | Upload a file via the composer's attach button (`data-jt-attach`, `.jt-attach-trigger`) through the existing hardened `POST /jetonomy/v1/media` (widened by `jetonomy_upload_allowed_types` / `jetonomy_upload_max_size` filters registered in `Uploader::register()`), then `POST /jetonomy-pro/v1/attachments` with `{object_type, object_id, attachment_id, sort}` | Disallowed extension (e.g. `.exe`) → `/media` rejects it (existing free upload gate) before Pro even sees it. Oversized file (> configured `max_size_bytes`) → rejected the same way. Allowed type within size → `/media` returns HTTP 201 with an attachment id; `POST /attachments` then returns HTTP 200 with `{link_id, attachment:{...}}`. Attaching a file NOT owned by the current user (and the actor lacks `moderate_comments`) returns HTTP 403 `jetonomy_attach_forbidden`. Attaching a file whose stored type fails `Media_Controller::validate_upload()` re-check returns HTTP 400 `jetonomy_attach_type` (defense in depth — the endpoint never trusts the earlier upload alone). Attaching a file count at/above `max_files` for the object returns HTTP 400 `jetonomy_attach_limit`. |
| E.attach.create-links-idempotent | Create a post/reply with pending attachment ids: browser path links them via an explicit `POST /jetonomy-pro/v1/attachments` call once the create response resolves (`attachments-frontend.js`'s `restFetch` wrapper); API/app path can instead pass `attachment_ids` (CSV) inline on the create request (`Extension::link_on_create_post()` / `link_on_create_reply()`, hooked on `jetonomy_after_create_post` / `jetonomy_after_create_reply`) | Either path results in `wp_jt_attachments` rows with `object_type`/`object_id` matching the new post/reply and `attachment_id` matching the uploaded file(s). Linking the SAME `(object_type, object_id, attachment_id)` twice (e.g. both paths firing for the same request) does NOT create a duplicate row — `Model::link()` checks for an existing row first and the table's `UNIQUE KEY object_attachment` backstops it; assert `COUNT(*)` for that triple stays at 1. |
| E.attach.render-cards | View a post/reply with attachments | Server-rendered `.jt-attach-strip` (role="list") renders one card per attachment with zero extra per-row queries when the reply list was primed via `jetonomy_before_replies` → `Renderer::prime()` → `Model::prime_for_post()`. Image type → `.jt-attach-img` inline thumbnail + lightbox link (works with zero JS: plain `<a target="_blank">`). PDF type → `.jt-attach-pdf` button with thumbnail/page-count/size meta. Office/text type → `.jt-attach-chip` download link with `download` attribute, no inline preview. |
| E.attach.pdf-lazy-viewer | Click a `.jt-attach-pdf` card | `attachments-frontend.js` (loaded as an ES module, `wp_enqueue_script_module`) dynamically `import('./pdf-viewer.js')` ONLY on click — confirm via network panel that `pdf-viewer.js` and the vendored `assets/lib/pdfjs/pdf.min.mjs` / `pdf.worker.min.mjs` are NOT requested on initial page load, only after the click. The modal opens (`.jt-modal-box.jt-pdf-box`, `role="dialog"`, `aria-modal="true"`), traps focus, and Esc/overlay-click closes it. If pdf.js fails to load, falls back to `window.open(url, '_blank')` (never a broken/blank modal). |
| E.attach.cascade-delete | Delete a post/reply that has linked attachments | `jetonomy_after_delete_post` / `jetonomy_after_delete_reply` fire `Extension::on_delete_post()` / `on_delete_reply()` → `Model::unlink_all()`. All `wp_jt_attachments` rows for that `object_type`/`object_id` are gone (the underlying WP media attachment itself is left in the library, not deleted). |
| E.attach.gc-sweep | Trigger the recurring GC job (`jetonomy_pro_attachments_gc`, scheduled daily via `Queue::recurring()` in `Extension::activate()`) — e.g. `wp cron event run jetonomy_pro_attachments_gc` or call `Extension::gc()` directly | Two independent sweeps: (1) any `wp_jt_attachments` row whose `attachment_id` no longer resolves to a WP post row is deleted (orphan link cleanup); (2) any WP attachment carrying **`_jetonomy_media_origin = 'upload'`** — the provenance meta that `Media_Library::tag_upload()` records when WE created the file — that is NOT linked in `wp_jt_attachments` AND is older than 24h is hard-deleted via `wp_delete_attachment( $id, true )`. An abandoned upload younger than 24h must survive (confirm the boundary). **CRITICAL — the sweep must NOT key on `_jetonomy_media`.** That flag is also applied by free's backfill, which infers "community upload" from the author lacking `upload_files` — true of every subscriber-authored attachment on the site, INCLUDING another forum plugin's (wpForo authors its media as the posting member). Keying on it made the GC force-delete wpForo's own files off disk 24h after a customer enabled the extension: the very files a migration exists to rescue. **Regression check:** create an attachment authored by a subscriber, tag it `_jetonomy_media` only (no origin meta), age it >24h, leave it unlinked, run the sweep — it MUST survive. Then tag one via `Media_Library::tag_upload()`, age it, leave it unlinked — it MUST be reclaimed. Both are covered by `AttachmentsModelTest::test_gc_never_deletes_media_we_did_not_upload()` and `::test_gc_still_reclaims_our_own_abandoned_upload()`. |

**Checks:**
- `e-attachments.1` @admin #admin: Settings > Attachments: tick pdf, png, jpg, docx, set max 8 MB and max files 5, save -> values persist; max size clamps to between 1 MB and the server upload limit; max files clamps to 1 to 20.
- `e-attachments.2` @admin #rest !refuse: POST the settings form with `svg` ticked via a raw request -> `svg` is dropped; `wp option get jetonomy_pro_attachments` never contains svg.
- `e-attachments.3` @member #ui: bob attaches a photo "Dashboard layout.png" (1.2 MB) and a PDF "Community guidelines v3.pdf" to a Help Desk topic and posts -> both render under the post: the image as a thumbnail with a lightbox link, the PDF as a card with page count and size.
- `e-attachments.4` @member #ui: click the PDF card -> a dialog (role dialog, aria-modal) opens with the PDF, focus is trapped, Esc and overlay click close it and return focus to the card; on the initial page load `pdf-viewer.js` and `pdf.min.mjs` are not requested.
- `e-attachments.5` @member #ui !refuse: attach `setup.exe` or `notes.svg` -> refused before upload with a clear message naming the allowed types; a 20 MB file -> refused with the size limit; nothing is linked to the post.
- `e-attachments.6` @member #rest !refuse: `POST /jetonomy-pro/v1/attachments` linking someone else's media id (alice's upload) as bob -> 403 `jetonomy_attach_forbidden`; a file whose stored type fails the re-check -> 400 `jetonomy_attach_type`; a sixth file on a post with max_files 5 -> 400 `jetonomy_attach_limit`.
- `e-attachments.7` @member #rest: link the same (object, attachment) twice (browser call plus inline `attachment_ids`) -> one row in `wp_jt_attachments` (count stays 1), 200 both times.
- `e-attachments.8` @member #ui: attach a PDF to a reply in the 45-reply Feature Requests topic -> the strip renders on that reply and page 2 loads attachments without one query per row (primed in batch).
- `e-attachments.9` @guest #ui !refuse: a guest sees no attach button and `POST /media` or `/attachments` -> 401; the guest can download attachments of public topics.
- `e-attachments.10` @member #rest !refuse +private: bob requests the file URL/download of an attachment on a Customer Advisory Board topic -> not found/denied exactly like a missing file, not a direct 200 from the uploads folder; the topic's attachment list endpoint gives 404.
- `e-attachments.11` @member #ui: bob deletes the post -> its `wp_jt_attachments` rows are removed; the media file remains in the library until the 24-hour garbage sweep reclaims abandoned uploads.
- `e-attachments.12` @admin #cli: `wp cron event run jetonomy_pro_attachments_gc` -> orphan links are removed; our own unlinked upload older than 24 h (tagged `_jetonomy_media_origin = upload`) is deleted; one younger than 24 h survives; a subscriber-authored attachment tagged `_jetonomy_media` only (another forum plugin's file) is NEVER deleted.
- `e-attachments.13` @member #ui +long: a file named "Quarterly community engagement -> report - final draft v7 (reviewed by moderators).pdf" truncates with an ellipsis and keeps its extension visible in the chip at 390px; a name with Arabic characters displays right-to-left.
- `e-attachments.14` @member #ui +many: five attachments of mixed types on one post -> wrap in the strip at 390px without overflow; the sixth is refused with the limit message.
- `e-attachments.15` @member #ui +offline: attach with the network dropped mid-upload -> the chip shows failed with a retry, no half-linked row, and the post can still be sent without it.
- `e-attachments.16` @silenced #ui !refuse +silenced: finn attaches a file to a reply -> refused with the silenced reason, nothing uploaded or linked.
- `e-attachments.17` @member #ui +dark: image thumbnails, PDF card and chip -> use token colours in dark mode with 40px tap targets.
- `e-attachments.18` @admin #admin: the Attachments admin -> list/search by post lets an admin see and remove an attachment; removal updates the post immediately.
**Depth note:** no `wp jetonomy-pro` CLI group covers attachments; entry points are ui, admin and rest only, and the GC is exercised through `wp cron`.

### E.pro-cli
**Covers:** pro:includes/cli/**
**Verified:** 64212bb5 pro:4d9b361
**What to verify:** On the dev site run `wp jetonomy-pro` for each command group (extension, polls, reactions, messaging, webhooks, and the rest), then `wp jetonomy-pro journeys` (or the equivalent qa subcommand) for all extension journeys. Confirm every journey passes, output names roles and extensions plainly, a disabled extension is skipped rather than failing, and debug.log stays empty. Any failure blocks the Pro walk.

**Checks:**
- `e-pro-cli.1` @admin #cli: `wp jetonomy-pro` -> lists every command group present in the Pro repo (extension, analytics, polls, reactions, messaging, webhooks, ai, custom-badges, custom-fields, email-digest, reply-by-email, seo-pro, web-push, white-label, advanced-moderation) with a short description; no PHP notice.
- `e-pro-cli.2` @admin #cli: run each group's primary subcommand (for example `wp jetonomy-pro polls get <id>`, `polls results <id>`, `white-label get`, `webhooks` and `reactions` read commands) -> plain readable output naming roles and extensions, exit code 0, and the numbers equal what wp-admin shows.
- `e-pro-cli.3` @admin #cli: `wp jetonomy qa-actions` with Pro active runs the Pro journey phase -> each extension journey passes, a disabled extension is reported as skipped rather than failed, and no phase runs zero checks.
- `e-pro-cli.4` @admin #cli !refuse: run a command with a bad argument (unknown id, wrong type) -> a clear `Error:` line and non-zero exit, no stack trace.
- `e-pro-cli.5` @admin #cli: `wp jetonomy-pro extension disable polls` then `list` -> shows it off, journeys skip it, `enable` restores it; debug.log stays empty throughout.
- `e-pro-cli.6` @admin #cli +many: `wp jetonomy-pro analytics export --format=csv` and `webhooks deliveries` on 5,000 rows -> finish within a reasonable time and respect limits/pagination.
- `e-pro-cli.7` @admin #cli: confirm which Pro extensions -> have no CLI group (anonymous-posting, attachments, site-announcements) and record them against the three-entry-point rule.
- `e-pro-cli.8` @admin #cli +settings-off: with Pro licence invalid or free deactivated, `wp jetonomy-pro` -> prints a clean notice instead of a fatal.
**Depth note:** CLI has one role (site administrator running WP-CLI), so no owner-versus-other check applies; a failing journey blocks the whole Pro walk.

## F - Cross-browser, RTL, accessibility

### F.chromium
**Covers:** includes/class-router.php, includes/class-template-loader.php, includes/template-canvas.php
**Verified:** 64212bb5
Already covered by Sections A-E. Chromium is the default engine.

**Checks:**
- `f-chromium.1` @guest #ui: load /community/, a space, a topic and a profile in Chromium at 1440, 1024 and 390 -> pages render with no console errors and no failed requests.
- `f-chromium.2` @member #ui: composer, vote, reaction and reply submit -> work in Chromium (the default engine) with no uncaught promise rejection in the console.
- `f-chromium.3` @member #ui +dark: dark mode toggles and -> persists across reload in Chromium.
**Depth note:** already covered by Sections A to E; Chromium is the default engine, so this journey only records that the console stayed clean across the walk.

### F.firefox-desktop and F.safari-ios
**Covers:** assets/js/composer.js, assets/js/lib/smart-dropdown.js, templates/views/user-profile.php
**Verified:** dc84ba02
Playwright MCP is Chromium-only. These cannot be walked by the agent. Populate `manual_required[]` with the critical flows a human must spot-check:
- Composer time picker in Firefox (native select behavior)
- Share dropdown on Safari iOS at 390px
- Profile tabs horizontal scroll on Safari iOS
- Messages list pane scrolling independently on Safari iOS
- Any flow that relies on a browser-native control whose behavior diverges between engines.

**Checks:**
- `f-firefox-desktop-and-f-safari-ios.1` @member #ui: Firefox desktop - open the composer time picker (scheduled post) -> the native select/date control opens, a value can be chosen and saved, and the scheduled time displays correctly after save.
- `f-firefox-desktop-and-f-safari-ios.2` @member #ui +390: Safari iOS - open the Share dropdown on a topic at 390px -> it opens inside the viewport, each option is tappable, and it closes on outside tap.
- `f-firefox-desktop-and-f-safari-ios.3` @member #ui +390: Safari iOS - swipe the profile tabs row horizontally -> it scrolls smoothly, the active tab scrolls into view, and no page-wide horizontal scroll appears.
- `f-firefox-desktop-and-f-safari-ios.4` @member #ui +390: Safari iOS - the Messages -> list pane scrolls independently of the conversation pane and the page behind does not scroll with it; the composer is visible above the keyboard.
- `f-firefox-desktop-and-f-safari-ios.5` @member #ui +390: Safari iOS - long-press a reaction chip -> the names tooltip opens, release does not toggle the reaction, and a quick tap toggles.
- `f-firefox-desktop-and-f-safari-ios.6` @member #ui: Firefox desktop - keyboard focus rings -> are visible on buttons, links and the report dialog radios (Firefox draws them differently).
- `f-firefox-desktop-and-f-safari-ios.7` @member #ui: any browser-native control (date input, select, file picker for attachments) -> behaves the same in Firefox and Safari as in Chromium.
**Depth note:** Playwright MCP is Chromium-only, so these cannot be walked by the agent; they go into `manual_required[]` for a human with Firefox and a real iPhone. One role (member) is enough.

### F.rtl
**Covers:** assets/css/jetonomy.css, assets/css/jetonomy-tokens.css, assets/js/dark-mode-mirror.js
**Verified:** pending - not walked since its Covers widened on 2026-10-16
**What to verify:** on an RTL locale (e.g. `ar`), the primary templates render right-to-left without horizontal overflow, text flows correctly, icons mirror where appropriate and stay fixed where they should not (brand logos, directional glyphs).

**Checks:**
- `f-rtl.1` @member #ui +rtl: on an `ar` locale open /community/, a space listing, a topic and a profile -> text flows right to left, the container has `dir="rtl"`, and `document.documentElement.scrollWidth <= clientWidth` (no horizontal overflow) at 1440 and 390.
- `f-rtl.2` @member #ui +rtl: the Arabic topic by Omar -> renders with the author, meta row, tags, vote column and reply cards mirrored; list bullets, quote border and code block (which stays left-to-right) are correct.
- `f-rtl.3` @member #ui +rtl: look at the back arrow, next/previous, breadcrumb chevron and reply arrow in RTL -> they mirror, while brand logos, the search glass, checkmarks and numbers stay unmirrored.
- `f-rtl.4` @member #ui +rtl: the composer toolbar, editor caret, @mention popup and attachment chips -> follow RTL; mixed Arabic and Latin text (a URL inside an Arabic sentence) does not scramble punctuation.
- `f-rtl.5` @member #ui +rtl +dark: the header, nav, user menu and dropdowns -> open on the correct side and stay inside the viewport; dark mode mirror works the same in RTL.
- `f-rtl.6` @member #ui +rtl +long: Grace's long name and the 159-character topic title -> wrap correctly in RTL on Reign at 390px.
- `f-rtl.7` @member #ui +rtl: pagination, vote buttons, reaction chips, polls bars and the Messages pane -> mirror; poll bars fill from the right.
- `f-rtl.8` @admin #admin +rtl: wp-admin Jetonomy and Pro screens (Analytics, Badges, Settings) -> lay out correctly in RTL with `margin-inline-*` and no clipped tables.
**Depth note:** a real `ar` locale must be installed; simulating dir=rtl on an English locale is not a pass. Only one role is needed.

### F.a11y
**Covers:** templates/partials/header.php, templates/partials/composer.php, templates/partials/reply-card.php, assets/js/view.js, assets/css/header.css, assets/js/header.js, assets/js/header-font-scale.js, assets/js/dark-mode-mirror.js, templates/partials/breadcrumb.php
**Verified:** 64212bb5
**What to verify:** the main interactive surfaces have a visible keyboard focus ring (not suppressed by theme), tab order is logical, main content is reachable within a reasonable number of tabs from the top of the page, and icon-only buttons have `aria-label`. Screen-reader critical labels are present on composers, voting controls, share controls, and moderation actions. The report dialog is a labelled radio group, focuses its first reason on open, and closes on Escape.

---

**Checks:**
- `f-a11y.1` @guest #ui: from the top of /community/ press Tab -> a "skip to content" link (or main landmark reachable) appears within the first 3 Tab presses and moves focus to the main content.
- `f-a11y.2` @member #ui: Tab through the header, a space listing and a topic -> every interactive element gets a visible focus ring (not suppressed by the Reign, BuddyX or Twenty Twenty-Five theme) in light and dark, and tab order matches reading order.
- `f-a11y.3` @member #ui: icon-only buttons (theme toggle, font-size control, vote up/down, share, bookmark, notifications bell, "More actions", composer toolbar buttons, anonymous toggle) each -> have an `aria-label` that says what they do and `aria-pressed`/`aria-expanded` where they are toggles.
- `f-a11y.4` @member #ui: the topic and reply editors -> are announced as named edit text (aria-label), the title input has a label, and the poll option inputs have labels like "Option 1".
- `f-a11y.5` @member #ui: the vote controls -> announce current score and state (voted/not), and the reaction chips announce "Like, 12 reactions, you reacted" rather than only an emoji.
- `f-a11y.6` @member #ui: open the report dialog on a reply -> it is a labelled radio group, focus moves to the first reason, Tab stays inside, Escape closes it and returns focus to the Report control.
- `f-a11y.7` @moderator #ui: moderation actions (approve, -> remove, mark spam, ban, close) have accessible names including the target and are reachable by keyboard; destructive confirms are real dialogs, not `alert`.
- `f-a11y.8` @member #ui: the share dropdown, the user menu and the notification panel -> open with Enter/Space, arrow keys move within, Escape closes and returns focus to the trigger.
- `f-a11y.9` @member #ui +dark: text and control contrast -> meets WCAG AA 4.5:1 (3:1 for large text and UI borders) on cards, badges, pills (Announcement), pinned/closed labels, the "Content hidden because you blocked this user." line and placeholders in light and dark.
- `f-a11y.10` @member #ui +390: zoom text to 200% and tap the controls at 390px -> no horizontal scrolling, tap targets are at least 40px, and the font-size control changes all text.
- `f-a11y.11` @member #ui: status messages (toast "Badge awarded.", "Reaction settings saved.", form errors such as "Question and at least 2 options required.") -> are announced through an `aria-live` region and linked to their field with `aria-describedby`.
- `f-a11y.12` @silenced #ui +silenced: the refusal message for a silenced or banned member -> is exposed to assistive tech (not only a coloured banner) and the disabled composer explains why.
- `f-a11y.13` @member #ui: breadcrumbs -> are a labelled `nav` with the current page marked `aria-current="page"`; headings follow a single `h1` per page with no skipped levels on topic and profile pages.
- `f-a11y.14` @member #ui +rtl: a keyboard-only user can complete post-create, reply, vote and -> report end to end in RTL.
**Depth note:** screen-reader output (VoiceOver, NVDA) cannot be fully asserted by the Chromium agent; the checks above list the DOM and keyboard facts that are asserted, and a human spot check with a real screen reader should accompany the release.

## G - Post-release monitoring (first 24h after tag)

Runs on the production host, not this runbook. Watch for new debug.log entries, orphaned cron events, "broke after update" support tickets, and activity-signal drops. Any red signal opens a `<version>.1` patch cycle.

---

## Failure protocol

1. On ANY failure, `browser_take_screenshot({ filename: "fail-<id>.png", fullPage: false })`.
2. **Triage origin: `from` vs `for` our plugin.**
   - `from` = our code is at fault (our REST, our JS, our SQL, our CSS, our template). Always ours to fix.
   - `for` = failure surfaces while our plugin runs but root cause is elsewhere (theme override, other-plugin conflict, browser limitation, legacy imported data, hosting quirk). Warrants a judgement call.
3. Record in `failures[]` with `{ id, origin, triage_note, expected, actual, url, screenshot }`.
4. **Never halt.** Collect all failures in one pass.
5. Emit a Basecamp draft per failure:
   ```
   ### Bug: <id>
   **Origin:** from | for our plugin
   **Environment:** Jetonomy <version>, Chromium, <viewport>px
   **Expected:** <contract from the runbook>
   **Actual:** <measured behavior>
   **URL:** <tested URL>
   **Screenshot:** <filename>
   **Steps to reproduce:** <minimal repro>
   **Triage note:** <one line on the from/for call>
   ```

Triage is Sonnet's job; the fix/no-fix decision is Opus's (the calling session's) job.

## Step ID format

`<Section>.<persona>.<feature>` e.g. `C.member.post-create`. D rows use `D.<descriptor>`. E rows use `E.<extension>`.

## Maintenance rule

Every customer-visible bug fix ships with:
1. A matching **D** row in this runbook (fixture + assertion).
2. If the flow was not already covered, a **C** or **E** contract in this runbook.
3. Both land in the same PR as the fix.

After 2 clean releases of a D row, the row graduates into C/E and the D row is marked `graduated`.

## wp-admin responsive contract sweep (added 2026-07-30 — root cause Basecamp 10146443346)

Every pre-release smoke includes this admin sweep, as ADMIN, at **320 / 390 / 768**:

Screens: Dashboard, Categories, Spaces, Moderation (all four tabs, POPULATED —
seed a pending post + a flag if the queues are empty), Settings → Permissions,
Settings → Email, Pro Polls, Pro Badges, Pro Custom Fields, Pro Analytics.

Per screen, all of:
1. `document.documentElement.scrollWidth <= viewport width` — zero page-level
   horizontal overflow.
2. Every `wp-list-table` emitted by `jetonomy_admin_table()` collapses: one
   `column-primary` cell per row, `.toggle-row` expander ≥40px, expanding shows
   every secondary column as a labelled sheet row, and row actions render ≥40px
   INSIDE the expanded sheet at 320.
3. `jt-settings-matrix` tables stack into labelled ≥44px field rows under 782px.
4. No table clipped by `overflow:hidden` — a not-yet-migrated table may
   horizontally scroll inside `.jt-content-table-wrap` (temporary fallback),
   never lose content.

Static guard: `php bin/audit-admin-tables.php includes/admin/views` and
`php bin/audit-admin-tables.php ../jetonomy-pro/includes` must both print OK —
a raw `<table>` outside the contract fails the release build (build-release.sh
step 5c2). The baseline inside the script is shrink-only.
