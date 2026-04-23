# Jetonomy — Pre-Release Smoke Checklist

> **Run this before every tagged release. Every row must pass.**
> Any failure → file a Basecamp card in Bugs and **halt the release**.
> This is the "will customers get broken updates?" gate. If you skip it, expect the ticket.

**Target time:** 90 minutes end-to-end.

**Test matrix (run each row at least once):**
- **Personas:** Anonymous visitor, Member (trust level 1–2), Admin
- **Browsers:** Chrome Desktop, Firefox Desktop, Safari iOS (sim or real)
- **Viewports:** 1440px desktop, 390px mobile
- **Theme modes:** Light, Dark (where site offers the toggle)

**Environment needed:**
- Clean Local site with Jetonomy (+ Pro if shipping Pro) **already on the previous stable version** for the upgrade test
- A second clean Local site for the fresh-install test
- Access to `wp-content/debug.log` and DevTools Network tab
- Mailpit/Mailhog (Local ships it) open in a tab for email rows

---

## A — Fresh install (10 min)

Do this on a **clean** WP site with no Jetonomy data.

- [ ] Activate Jetonomy → no fatal, no PHP warning in `debug.log`
- [ ] Setup wizard offered on first admin load (unless `jetonomy_setup_complete` is already set)
- [ ] Complete wizard → 22+ `wp_jt_*` tables created: `wp db tables --all-tables | grep ^wp_jt_`
- [ ] `wp option get jetonomy_db_version` equals `JETONOMY_DB_VERSION` constant
- [ ] Visit `/community/s/<any-space>/` **as the very first front-end request** → **HTTP 200** (not 404). This is the rewrite-flush regression guard.
- [ ] Demo data seeds cleanly via `wp jetonomy demo seed` (or setup wizard) — no duplicate-key errors
- [ ] Deactivate → reactivate → no duplicate tables, no migration re-runs
- [ ] Activate Pro (if shipping) → no fatal, Pro tables created (`wp_jt_pro_conversations`, `wp_jt_pro_conversation_participants`, `wp_jt_pro_messages`)

## B — Upgrade from previous release (5 min)

Do this on a site that was running the previous stable version with real data.

- [ ] Drop the new zip → update via WP → no fatal, no white screen
- [ ] `wp option get jetonomy_db_version` updates to new constant
- [ ] Previously-created posts still render with correct author, content, timestamps
- [ ] Previously-created replies still render
- [ ] `wp_jt_space_members` count sane (each space has at least 1 member per author that has posted there — verifies migration 1.2.5-style backfills ran)
- [ ] Space `member_count` column matches actual member rows (no stuck-at-1)
- [ ] `jetonomy_activity_backfilled = 1`, no double backfill

## C — Core user flows (25 min)

Each flow must complete without a Network 4xx/5xx. Open DevTools Network tab, filter Fetch/XHR.

### C1. Anonymous visitor
- [ ] `/community/` renders, no console errors
- [ ] Click any space → space listing renders
- [ ] Click any post → single-post renders with replies
- [ ] Vote/reply/bookmark buttons hidden or redirect to login (never 403-toast silently)
- [ ] **Login block** on a site where OS is in dark mode → block renders in LIGHT mode (regression guard: no OS-auto-dark for logged-out)

### C2. Register → first post → first reply
- [ ] Register a new user via the Login block Register tab → account created + logged in
- [ ] Click "New Post" in a space → composer opens
- [ ] Publish a post → redirects to `/community/s/<space>/t/<slug>/` with content visible
- [ ] From another account, reply to that post → reply renders immediately, new-reply notification fires for the post author
- [ ] Switch back to original account → see unread notification badge in header

### C3. Member flow (trust level 1)
- [ ] Vote on a reply → vote count updates (Network: `POST /replies/<id>/vote` → 200)
- [ ] Bookmark a post → `POST /bookmarks` → 200; bookmark visible on profile
- [ ] Follow a space → `POST /subscriptions` → 200; header shows subscription
- [ ] Share a post → click share icon → dropdown opens anchored 4px below the button
- [ ] **Scroll the page while share dropdown is open → dropdown closes** (regression guard: scroll-detach fix)
- [ ] Click dropdown "Copy link" → toast "Link copied" (or fallback toast if clipboard API blocked)

### C4. Power user (profile, messages, notifications)
- [ ] Visit `/community/u/<login>/` → profile header shows 64px avatar; **if user is online, green dot sits at the avatar's top-right corner** (regression guard: is-online dot on `.jt-profile-av`)
- [ ] Profile tabs row: Posts / Replies / Votes / Bookmarks / Drafts all clickable
- [ ] **At 390px viewport** → tabs row is horizontally scrollable; landing on `/drafts/` auto-scrolls the active Drafts tab into view (regression guard: profile tabs mobile)
- [ ] Messages (Pro): open `/community/messages/` → conversations list renders
- [ ] Click a user's "Message" button on a profile → compose form opens prefilled with that recipient
- [ ] Admin sends message to a trust-level-0 Author user → recipient can reply back with HTTP 201 (regression guard: messaging reply asymmetry)

### C5. Moderator flow
- [ ] Report content: click flag on a reply → modal opens → submit → `POST /flags` → 200 + toast
- [ ] Flag the SAME reply again → user sees "already reported" feedback (or at minimum no silent failure)
- [ ] From admin `/wp-admin/admin.php?page=jetonomy-content`, hover a pending reply → **"Approve" inline action visible** (regression guard: admin list inline approve)
- [ ] Click Approve → row flips to publish status, AJAX response 200
- [ ] `GET /jetonomy/v1/moderation/queue?status=spam` returns spam-status rows (regression guard: moderation queue spam visibility)
- [ ] As a silenced user (`Restriction::ban` with silence flag) → POST `/flags` returns 403, POST `/replies/<id>/vote` returns 403
- [ ] **Admin REST replies bypass Akismet** even when content looks spammy (regression guard: trust-level staff bypass)

### C6. Advanced moderator flows
- [ ] Reply split: as moderator, open a reply's "…" menu → Split → enter title → **new topic created with child replies moved**; counters on both posts correct
- [ ] Topic merge: as admin, post "…" menu → Merge into target → **source topic gets status=trash**, target's `reply_count` recalculated
- [ ] Topic move: as moderator, post "…" menu → Move → pick target space → post's `space_id` updates, source/target `post_count` shift ±1, page redirects to new URL

### C7. Scheduled post flow (cross-browser)
- [ ] New Topic page → open the publish-mode menu → Schedule
- [ ] **"Publish on" panel shows a date field + two select dropdowns (HH : MM)** (regression guard: Firefox time picker — no native `<input type="time">` reliance)
- [ ] Pick date = tomorrow, hour = 14, minute = 30 → click Schedule
- [ ] Post created with `status=draft` and `published_at=2026-XX-XXT14:30:00` in `wp_jt_posts`
- [ ] **Repeat on Firefox Desktop** (this was the browser where the native `<input type="time">` has no popup picker)

### C8. posts_per_page setting
- [ ] In Admin → Spaces → edit a space → set **Posts Per Page = 1** → Save
- [ ] Visit that space page
- [ ] **Exactly 1 topic card renders, Load More button below, NO auto-preloaded page 2** (regression guard: `pagination.php` observer gate)
- [ ] Scroll the page 200px → pagination auto-loads page 2, row count goes to 2

### C9. Admin list pages
- [ ] Admin → Jetonomy → Categories → create, edit, delete → no JS errors, no 404 on AJAX
- [ ] Admin → Spaces → create a space with each type (public/private/restricted) → save works
- [ ] Admin → Content → filter by space + status → list renders, row actions work
- [ ] Admin → Moderation → pending tab, spam tab, trash tab → each populates
- [ ] Admin → Users → trust level promote → user profile updates
- [ ] Admin → Settings → SEO tab → Social Embeds (Instagram & Facebook) card visible with fb_app_id + fb_app_secret fields (regression guard: Instagram oEmbed settings)

---

## D — Known-regression guards (15 min)

These rows pin specific bugs that have been fixed once and must never regress. Every row is a Basecamp card that caused pain in production.

### D1. Online indicator dot — ALL four avatar sizes
- [ ] `/community/s/<space>/t/<post>/` with an online reply author → dot at top-right of 30px avatar
- [ ] `/community/` Top Leaders sidebar → dot at top-right of 30px avatar
- [ ] `/community/u/<login>/` profile header → dot at top-right of 64px avatar
- [ ] (md + lg sizes not currently used with is-online; add rows if product adds online status to leaderboard/members/post-header)

### D2. Load More / infinite-scroll
- [ ] With `posts_per_page=1` on a short space, initial render shows 1 topic (not 2)
- [ ] On a single post with few replies: reply-gap load trigger doesn't auto-fire on page load
- [ ] On all infinite-scroll views: first paint respects configured page size; subsequent pages load only on real user scroll

### D3. Rewrite flush
- [ ] Deactivate plugin → reactivate → visit `/community/s/<slug>/` as the first request → HTTP 200
- [ ] Option `jetonomy_permalinks_flushed_<VERSION>` exists after activation
- [ ] `get_option('rewrite_rules')` contains patterns with `jetonomy_route=`

### D4. Migration end-to-end
- [ ] `wp option get jetonomy_db_version` matches `JETONOMY_DB_VERSION`
- [ ] `wp_jt_space_members` row count ≥ unique (space_id, author_id) pairs across posts + replies
- [ ] No `space.member_count` stuck at 1 when the space has real authors

### D5. Akismet staff bypass
- [ ] Post a reply as admin with content `buy viagra now click here` → reply status is `publish`, not `spam`
- [ ] Post the same reply as a trust-level-0 subscriber → Akismet runs (status may be `spam` if the real key flags it, which is the intended behavior)

### D6. Notification channel respected
- [ ] User sets web notification to off for `reply_to_post` → receive a reply → no row in `wp_jt_notifications` for that user/type
- [ ] Admin notification for flag → admin with `jetonomy_moderate` cap sees new notification
- [ ] Email unsubscribe link on a reply-notification email → click → disables `reply_to_post` email for that user

### D7. Theme dark-mode interaction
- [ ] Set OS to dark mode, log OUT, open a page with the Login block → block stays in LIGHT mode
- [ ] Click the site's dark-mode toggle (if present) → block flips to dark mode
- [ ] Log in, toggle back to light → block respects the site toggle, not OS

### D8. Share dropdown lifecycle
- [ ] Click share icon → dropdown opens 4px below the button, right-aligned to button's right edge
- [ ] Click outside → dropdown closes
- [ ] Scroll the page → dropdown closes (no visible detach)
- [ ] Resize the window → dropdown closes

---

## E — Pro extension smoke (20 min)

Walk the checklist in `jetonomy-pro/plans/PRO-EXTENSION-QA-CHECKLIST.md`. At minimum verify:

- [ ] Private Messaging: list, new DM, thread view, reply as low-trust participant
- [ ] Reactions: toggle, count updates, no duplicate per user
- [ ] Polls: create, vote once, results display
- [ ] Custom Badges: admin create, auto-award on condition, profile display
- [ ] Custom Fields: admin define, frontend render, save on post
- [ ] Analytics: dashboard loads, export CSV
- [ ] Advanced Moderation: rule auto-fires
- [ ] Email Digest: preview from admin, cron schedule
- [ ] Webhooks: fire on event
- [ ] Web Push: subscribe, receive
- [ ] White Label: logo replaces, footer text respected
- [ ] SEO Pro: per-space title/description renders in `<head>`
- [ ] Reply By Email: inbound message creates reply

---

## F — Cross-browser quick pass (10 min)

Run these five pages on **Chrome desktop + Firefox desktop + Safari iOS (sim or real)**:

1. `/community/` (home)
2. `/community/s/<slug>/` (space, with posts_per_page=1 to test Load More)
3. `/community/s/<slug>/t/<slug>/` (single post — test share dropdown + reply voting)
4. `/community/s/<slug>/new/` (new post with Schedule mode — **Firefox Desktop: verify HH:MM selects work**)
5. `/community/u/<login>/` (profile header avatar dot + tabs)

Expectations: no JS errors, no layout breaks, interactive elements work.

---

## G — Post-release verification (first 24h)

Within 24 hours of release:

- [ ] `wp-content/debug.log` clean of new warnings/notices/fatals
- [ ] `wp cron event list | grep jetonomy` — expected events still scheduled, no orphans
- [ ] `wp option get jetonomy_db_version` on customer sites (check support tickets) matches the constant
- [ ] Zoho Desk / Slack `#support` — no "broke after update" tickets for the first 24h
- [ ] Analytics dashboard shows continued activity (no "zero events" sign of breakage)

---

## Failure protocol

Any failed row:

1. **Stop.** Do not merge the release branch.
2. File a Basecamp card in **Bugs** with the failed row verbatim, environment, browser, user persona.
3. Fix + push to the release branch.
4. Re-walk the failed row + the related section (e.g. if C4 failed, re-walk all of C4 and D1).
5. Resume from the next section only after the failure is resolved.

## Version-specific additions

For each release, append a section below with regression guards specific to that cycle's fixes. After 2 releases of no regression on a row, it graduates to the main Section D guards.

### 1.3.7 additions
- is-online dot position on all four avatar sizes (D1)
- Posts per page=1 initial render (D2)
- Load More on scroll only — not on attach (D2)
- Rewrite flush activation (D3)
- Messaging reply as trust-level-0 participant (C4)
- Share dropdown scroll-close (D8)
- Login block OS-dark opt-in (D7)
- Profile tabs mobile horizontal scroll (C4)
- Firefox time picker — select-based HH:MM (C7)
