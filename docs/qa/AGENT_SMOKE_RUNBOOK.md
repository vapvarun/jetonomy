# Agent Smoke Runbook - Jetonomy Pre-Release

**Audience:** a browser-capable agent (Claude Sonnet or equivalent) with Playwright MCP + WP-CLI Bash access, OR a human QA person with the same access. Both should be able to execute every step of this runbook.

## How to read this runbook

Each C and E step describes a **customer contract**: what the feature promises, why it matters, the surfaces it touches, and what "working" looks like in customer terms. It does NOT prescribe the exact Playwright calls, selectors, REST paths, or DB queries. Read the relevant plugin code, pick the right mechanism, and verify the contract. This freedom is the point: the verifier is expected to notice bugs we did not pre-imagine.

D (regression guards) stays specific - those are repros of past incidents; the exact fixture is the contract.

Infrastructure sections (preconditions, output contract, debug-log protocol, fixture cleanup, failure protocol) stay specific because they are the stable machinery the walk rides on.

## Global preconditions

- Working directory: `/Users/varundubey/Local Sites/forums/app/public`
- Site URL: `http://forums.local`
- WP-CLI template: `wp --path="$WP_PATH" <cmd>`
- Admin auto-login: `?autologin=1` on any front-end URL
- Per-user auto-login: `?autologin=<user_login>`
- Playwright: reuse one Chromium session. Restart with `browser_close` + `browser_navigate` if it dies.
- Debug log: `wp-content/debug.log`
- Release target: value of the `JETONOMY_VERSION` constant

## Agent output contract

At the end of the walk, write exactly one JSON file to
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

## Fixture cleanup (before every walk)

Delete any leftover test data from prior runs. Exact WP-CLI eval script is permitted here because this is infrastructure, not a feature check.

```bash
wp --path="$WP_PATH" eval '
global $wpdb;
$wpdb->query("DELETE FROM wp_jt_posts WHERE title LIKE \"E2E %\" OR title LIKE \"Smoke %\"");
$wpdb->query("DELETE FROM wp_jt_replies WHERE content_plain LIKE \"smoke-%\" OR content_plain LIKE \"e2e-%\"");
$wpdb->query("DELETE FROM wp_jt_flags WHERE reason LIKE \"smoke-%\"");
$wpdb->query("DELETE FROM wp_jt_subscriptions WHERE user_id IN (3, 16) AND object_type IN (\"post\", \"space\")");
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
**What to verify:** after a clean activation, the plugin's front-end routes respond 200 on the very first request, without the user having to hit Settings > Permalinks.
**Why it matters:** rewrite-flush-on-activation regressions have broken customer sites before (1.3.5 incident).
**Acceptance:** any `/community/*` route returns HTTP 200 on the first request after activating, and `rewrite_rules` option contains the plugin's routes.

### A2 - Database schema is in place
**What to verify:** all expected custom tables exist and the stored db-version option matches the constant.
**Acceptance:** `wp_jt_*` tables count matches the schema class; `jetonomy_db_version` option equals `JETONOMY_DB_VERSION`.

### A3 - Pro pairs cleanly (if Pro is also being installed)
**What to verify:** activating Pro on top of free does not fatal, adds Pro-only tables, and both version constants agree.

---

## B - Upgrade from previous version (skip if no prior version)

### B1 - Migration runs quietly, existing data still works
**What to verify:** bumping from the prior stable version to this build completes with no debug.log entries during the activation HTTP request; pre-existing posts, spaces, users, and settings still render and function; denormalized counters remain in sync.

---

## C - Core customer flows

Persona ladder in this section: **Anonymous > Member > Moderator > Admin**. Pick a real test user from each persona (admin is user 1; create a subscriber-role user with a generated login if none exists). Cover both desktop 1280px and mobile 390px where relevant.

Each step below is a contract, not a script. When you verify it, exercise the UI as a user would AND confirm the server-side effect (DB row, REST response, email queued) to rule out a "looks right, didn't actually save" bug.

### C.anon.home
**What to verify:** the community home page renders for a logged-out visitor, shows real content (posts or spaces), and offers a clear way in (login/register/join).
**Why it matters:** first impression surface; a broken home means no acquisition.

### C.anon.category
**What to verify:** a category page renders its child spaces, with working links into each space.

### C.anon.space-listing
**What to verify:** a space page shows its topics in the configured order, with pagination that actually advances, and a visible "new post" invitation for authed users.

### C.anon.single-post
**What to verify:** a topic renders with its title, body, author, replies, and vote counts; auth-gated actions (reply, vote, subscribe) cleanly redirect a logged-out visitor to login rather than failing silently with 403.

### C.anon.user-profile
**What to verify:** any user's public profile is viewable by anonymous visitors and shows their posts/replies/badges without leaking private data (email, settings, drafts).

### C.anon.tag
**What to verify:** the tag page lists topics carrying that tag, or shows a clean empty state; no fatal on unknown tag slug.

### C.anon.leaderboard
**What to verify:** the leaderboard page renders a ranking, not an error; period selector (if present) switches the dataset.

### C.anon.search
**What to verify:** searching for a known-present term returns matching topics with relevance that actually looks relevant (share ≥ 1 meaningful token with the query). Searching for gibberish returns a clean empty state.
**Why it matters:** this is how customers find existing answers; a broken search drives support tickets.

### C.anon.invite-landing
**What to verify:** an invite link lands on the correct space context, clearly states what the visitor is being invited to, and offers signup. Invalid/expired invite codes get a clean error, not a fatal.

### C.member.post-create
**What to verify:** a logged-in member can compose a new post with title + content, pick a visibility / schedule / tags, submit, and land on the new topic. The post is persisted, appears in the space listing, and fires any expected notifications (subscribers notified, activity log updated).

### C.member.post-edit
**What to verify:** a post author can edit their own post; changes persist and are reflected everywhere the post is referenced (home feed, space list, search).

### C.member.post-delete
**What to verify:** a post author can delete their own post; it disappears from all listings and its single-post URL no longer serves the body (either 404 or "trashed" state per plugin contract); replies are handled consistently (cascaded or orphaned per contract).

### C.member.reply-create
**What to verify:** a member can post a reply on any topic they can see; the reply appears inline, increments the topic's reply count, and triggers a notification to the OP (unless OP unsubscribed).

### C.member.reply-quote
**What to verify:** a member can quote an existing reply and the quoted content is visually preserved as a blockquote in the new reply.

### C.member.mention
**What to verify:** using `@username` in a post or reply produces a notification for that user AND renders as a link to their profile in the saved content.

### C.member.vote
**What to verify:** a member can vote up/down on posts and replies, the score updates on screen, the vote persists across page reloads, and re-clicking the same vote toggles it off.

### C.member.flag
**What to verify:** a member can flag a post or reply as inappropriate, pick a reason, submit, and the flag reaches moderation (a moderator sees it in the queue). The flagging member gets acknowledgment, not a silent submit.

### C.member.subscribe-thread-and-space
**What to verify:** a member can subscribe to a thread and to a space; a subsequent reply (thread) or new post (space) produces a notification; unsubscribing stops new notifications.

### C.member.accept-answer
**What to verify:** on Q&A-typed spaces, the OP can mark a reply as the accepted answer, the reply is visibly promoted, and a second acceptance replaces the first per contract.

### C.member.profile-edit
**What to verify:** a member can edit their display name, bio, avatar, social links, and notification preferences; changes save, reload cleanly, and reflect across the site.

### C.member.bookmarks
**What to verify:** a member can bookmark a post, find it in their bookmarks view, and unbookmark it; count reflects the change.

### C.member.share
**What to verify:** the Share control on a topic opens a dropdown anchored to the button, offers Copy Link + X + Facebook + LinkedIn, Copy Link actually writes the URL to the clipboard, external links open on the right platform, and the dropdown closes when the user scrolls or clicks outside.

### C.member.scheduled-post
**What to verify:** a member can schedule a post for a future date/time using the composer's schedule mode, picking both hour and minute; the scheduled post is stored with the correct `published_at`, does not appear on public listings before that time, and appears automatically when the time arrives. The HH:MM picker works in every supported browser (Chromium proven here; Firefox + Safari iOS in `manual_required`).

### C.member.space-pagination
**What to verify:** on a space page, `posts_per_page` controls how many topics load initially; scroll-to-load reveals additional batches without doubling up on first load; the load-more behavior does not re-trigger until the user actually scrolls.

### C.member.profile-mobile-tabs
**What to verify:** on mobile (390px), the profile tabs bar is fully usable - no tab falls unreachable off-screen, the active tab is visible on load.

### C.member.online-indicator
**What to verify:** a user who is active within the threshold shows an online dot on every avatar context (topic page, profile header, home feed). The dot is visually attached to the avatar's top-right corner across all avatar sizes.

### C.mod.queue-and-actions
**What to verify:** a user with moderator capability sees the moderation queue, can filter by type (post/reply) and status (pending/spam/trash/resolved-flag), and can approve / trash / mark-spam from the queue. Every action updates the underlying record, disappears from its source tab, and appears in the destination tab. Silenced users cannot post until unsilenced.

### C.mod.flag-resolution
**What to verify:** a moderator sees flagged content with the flagging user's reason, can resolve the flag with an action (ignore / trash / spam / silence author), and the resolution is recorded and reflected on the reporter's side.

### C.admin.plugin-pages
**What to verify:** every Jetonomy admin page (overview, spaces, categories, content, users, moderation, settings, plus any Pro extension pages) renders without PHP Notice / Warning / Fatal. Every tab on those pages loads its content without AJAX errors.

### C.admin.category-and-space-crud
**What to verify:** an admin can create, edit, reorder, and delete categories and spaces. Deleting a non-empty category handles its spaces per contract (cascade or refuse). Space settings edits persist and MERGE with existing settings (editing one key does not drop others - this is a hard contract).

### C.admin.user-management
**What to verify:** an admin can search users, promote trust level, ban / unban, and see the correct state in the listing; bans prevent access to gated actions as expected.

### C.admin.invite-links
**What to verify:** an admin can mint an invite link for a space, copy it, and the link works through the full accept flow (sign up if needed > land in space with correct membership).

### C.admin.recount
**What to verify:** running the manual recount tool fixes any drift in denormalized counters (member_count, reply_count, vote_score); a deliberately corrupted counter is restored to the correct value.

### C.admin.akismet-staff-bypass
**What to verify:** staff-role submissions are NOT routed through Akismet; they post instantly with no spam-check lag. This is a customer-experience contract, not an optimization.

### C.notifications
**What to verify:** every notification-triggering event (reply to subscribed thread, mention, flag resolution, badge award, admin announcement) produces a new notification for the correct recipient; the bell badge count updates; clicking through takes the user to the correct destination. Unread count endpoint is accurate; mark-all-read zeroes it out.

### C.notifications.email
**What to verify:** notifications that should email (per user preferences) actually arrive in Mailpit / configured trap. Respect the user's digest/instant preference. Unsubscribe links in email work.

### C.search
**What to verify:** the full search page handles query, space-scoped filter, date filter, author filter, and sort (relevance / newest / votes) without breakage; relevance sort actually ranks by relevance, not just recency.

### C.cron
**What to verify:** after plugin activation, every expected cron event (digest, reminders, reputation recalc, etc.) is scheduled; none are orphaned after deactivation; cron actually executes when triggered manually.

---

## D - Known-regression guards

Each row is a repro of a past bug that caused customer pain. These rows stay specific on purpose: the exact fixture IS the contract.

| ID | Bug | Fixture + assertion |
|----|-----|---------------------|
| D.rewrite-flush | 1.3.5 rewrite rules not flushed on activation | Clean reactivate; first `/community/s/<slug>/` request returns 200 |
| D.ppg-stacking | IntersectionObserver loaded page 2 when trigger already visible | With `posts_per_page=1`, page loads 1 row; scroll triggers load of 1 more row, not 2 |
| D.share-scroll-detach | Share dropdown stayed in place while page scrolled | After opening share dropdown, scrollBy(0, 200) removes the dropdown from DOM |
| D.login-dark-leak | Login block went dark under `prefers-color-scheme: dark` | With `emulateMedia({ colorScheme: 'dark' })`, login block background stays light |
| D.profile-tabs-clipped | Profile tabs fell off-screen at 390px | At 390px on /community/u/admin/drafts/, the active Drafts tab is scrolled into view |
| D.is-online-misaligned | Dot rendered under the avatar name text | Dot center within 12px of avatar's top-right corner on all 4 avatar sizes, all 3 contexts (topic, profile, home) |
| D.firefox-time-picker | `<input type="time">` had no native popup in Firefox | Composer uses `<select name="published_hour">` + `<select name="published_minute">`; REST accepts ISO and stores as MySQL datetime |
| D.messaging-trust-asymmetry | TL0 participant could not reply to their own DM | Seed a conversation with a TL0 member, POST a reply from that member, expect HTTP 201 |
| D.akismet-staff-block | Admin posts routed through Akismet causing lag | `Base_Controller::author_bypasses_spam_check(admin, admin)` returns true |
| D.mod-queue-spam-filter | `status=spam` filter returned pending too | Seed a spam reply; GET moderation queue with `status=spam` includes only spam, not pending |
| D.space-titles-entity-encoded | Legacy rows had `&amp;` stored in title | No row in `wp_jt_spaces.title` / `wp_jt_categories.title` / `wp_jt_posts.title` matches `%&amp;%`, `%&quot;%`, `%&#039;%`, `%&lt;%`, `%&gt;%` |
| D.search-or-mode-relevance | Similar-topics typeahead returned unrelated posts | Type "E2E time picker test" in new-post title; no returned title's only overlap is "test"; all suggestions share ≥ 2 tokens of length ≥ 4 with the query |
| D.space-settings-merge | PATCH /spaces/:id replaced settings JSON instead of merging | Pre-fill settings with two keys; PATCH with one key; re-read confirms both keys present |
| D.custom-fields-fatal-on-create | sanitize_title() received WP_REST_Request object | POST /jetonomy/v1/fields without a slug body param; expect HTTP 201 and no new fatal in debug.log |
| D.sort-enum-oldest-newest | REST schema advertised sort=oldest/newest, model silently no-op'd them | GET /jetonomy/v1/spaces/1/posts?sort=oldest and ...?sort=newest return distinct id orders from sort=latest; GET ...?sort=unanswered returns HTTP 200 (previously 400 - enum blocked a valid filter); GET /jetonomy/v1/bookmarks?sort=unanswered still returns HTTP 400 (scope-limited override on posts route only) |
| D.space-title-dark-mode-contrast | Reign theme inline customizer hardcoded h1..h6 colour; plugin had no .jt-dark override, so space titles rendered near-black on near-black in Reign dark mode (contrast ~1.01:1) | On a Reign-active site with body class `jt-dark`, navigate to `/community/s/welcome/`; assert `getComputedStyle(document.querySelector('.jt-app h1')).color` yields a WCAG AA-passing contrast (>= 4.5:1) against body background. Plugin CSS override `.jt-dark .jt-app h1..h6 { color: var(--jt-text) }` must stay scoped to `.jt-app` so theme pages outside plugin surfaces are not affected. |
| D.accent-tint-dark-mode | `--jt-accent-light` / `--jt-accent-muted` tokens mixed accent with white in the light-mode defaults, and the dark-mode block did not re-derive them, so every surface using them (unread notifications, pinned rows, vote hover, composer focus, tag hover, accent badges) rendered a bright mint patch on the dark panel | With body `jt-dark`, open `/community/notifications/` and inspect `.jt-notif-item.unread` computed background: average RGB channel must be under 100 (dark tint), not near-white. Dark-mode block in `assets/css/jetonomy.css` must override both tokens to mix accent with the dark panel colour, not white. |
| D.warn-notice-dark-contrast | `--jt-warn-dark` used as text on dark-mode `--jt-warn-light` bg (locked-space banner, `.jt-notice-warning`) failed WCAG AA contrast at 3.28:1 | In dark mode, inject a `.jt-status-banner--locked` into `.jt-app`, read its `getComputedStyle` text and background colours, compute WCAG contrast ratio; assert >= 4.5:1. Dark-mode override for `--jt-warn-dark` must re-derive against white so the warm text stays legible on the dark warn background. |

Every customer-visible fix ships a matching D row in the same PR. After 2 clean releases of a D row, promote it into the main C/E flow.

---

## E - Pro extensions

Every active Pro extension gets a check here. Each contract covers the customer-visible promise, not the implementation.

### E.private-messaging
**What to verify:** a user can start a DM with another user, send a message, receive a reply, see read receipts, and block/mute. A TL0 user in an existing conversation can still reply. Unread count endpoint tracks correctly.

### E.polls
**What to verify:** an author can attach a poll to a new topic, multiple options render on the topic page, a member can vote once, vote counts update, results display honors the "hide results until vote" mode if set.

### E.reactions
**What to verify:** a member can add and remove a reaction on any post or reply they can see; per-reaction counts are accurate; the reactor's avatar appears in the hover-card listing reactors.

### E.analytics
**What to verify:** an admin sees a dashboard with non-zero metrics on a site with demo data, can switch date range, and can export the underlying data as CSV. Widget values are internally consistent (total posts ≥ posts in last 30d, etc.).

### E.custom-badges
**What to verify:** an admin can create a badge (name, icon, description, criteria), award it manually to a user, and that badge appears on the recipient's profile AND in the public badge list.

### E.white-label
**What to verify:** when an admin changes the brand color, the change actually flows through to the front-end (the `--jt-accent` token reflects the new color on public pages). Logo replacement works similarly.

### E.advanced-moderation
**What to verify:** an admin can create a moderation rule (pattern + action), posting content matching the rule triggers the action (hold / spam / auto-delete), and rule stats reflect hits over time.

### E.ai
**What to verify:** with an AI provider configured, obviously spammy content is correctly flagged; AI usage endpoint reports consistent numbers. With no provider configured, the extension silently no-ops (no fatals, no false positives).

### E.custom-fields
**What to verify:** an admin can define a custom field (text / select / url / etc.), a user can set a value in their profile, and the value is publicly visible where the field is marked public. Creating a field via REST must not fatal when optional params (like `slug`) are omitted.

### E.email-digest
**What to verify:** users subscribed to the digest actually receive one at the configured cadence; a manual test-send from admin dispatches within 30s; the stats endpoint reports sends without error.

### E.reply-by-email
**What to verify:** an inbound email matching a reply token produces a new reply on the correct post with the correct author; an inbound email without a valid token is rejected cleanly.

### E.seo-pro
**What to verify:** space-level SEO overrides (meta description, og:image) appear in the rendered HTML `<head>` on that space's pages. Defaults apply when overrides are absent.

### E.web-push
**What to verify:** the browser push subscription flow completes end-to-end, a test push reaches the subscribed browser. VAPID keys and service worker endpoints respond correctly.

### E.webhooks
**What to verify:** an admin can create a webhook bound to one or more events; triggering one of those events actually fires a request to the configured URL; the test-fire button delivers a payload the receiver can parse.

---

## F - Cross-browser, RTL, accessibility

### F.chromium
Already covered by Sections A-E. Chromium is the default engine.

### F.firefox-desktop and F.safari-ios
Playwright MCP is Chromium-only. These cannot be walked by the agent. Populate `manual_required[]` with the critical flows a human must spot-check:
- Composer time picker in Firefox (native select behavior)
- Share dropdown on Safari iOS at 390px
- Profile tabs horizontal scroll on Safari iOS
- Messages list pane scrolling independently on Safari iOS
- Any flow that relies on a browser-native control whose behavior diverges between engines.

### F.rtl
**What to verify:** on an RTL locale (e.g. `ar`), the primary templates render right-to-left without horizontal overflow, text flows correctly, icons mirror where appropriate and stay fixed where they should not (brand logos, directional glyphs).

### F.a11y
**What to verify:** the main interactive surfaces have a visible keyboard focus ring (not suppressed by theme), tab order is logical, main content is reachable within a reasonable number of tabs from the top of the page, and icon-only buttons have `aria-label`. Screen-reader critical labels are present on composers, voting controls, share controls, and moderation actions.

---

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
