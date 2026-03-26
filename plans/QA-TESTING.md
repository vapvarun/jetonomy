# QA Testing Guide

> Master guide for testing `jetonomy` (free) and `jetonomy-pro` before release.
> Detailed test cases live in the checklists — this document covers environment, order, tooling, and what to prioritize.

---

## Checklists

| Checklist | Covers | Cases |
|-----------|--------|-------|
| [QA-CHECKLIST-FREE.md](QA-CHECKLIST-FREE.md) | Free plugin: install, forum, REST API, admin | ~180 |
| [QA-CHECKLIST-PRO.md](QA-CHECKLIST-PRO.md) | Pro: license, all 13 extensions, adapters, admin UI | ~160 |

---

## Test Environment

| Setting | Value |
|---------|-------|
| Site URL | `http://forums.local` |
| WP Version | 6.7+ |
| PHP Version | 8.1+ |
| DB | `local` / root / root |
| Debug mode | Enable before testing (see below) |
| Auto-login | `?autologin=1` — logs in as admin (mu-plugin: `dev-auto-login.php`) |

**Enable debug before testing:**
```php
// wp-config.php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```
Watch the log during testing:
```bash
tail -f /Users/varundubey/Local\ Sites/forums/app/public/wp-content/debug.log
```

**Browser testing (Playwright MCP):**
```
Navigate: mcp__plugin_playwright_playwright__browser_navigate
Click:     mcp__plugin_playwright_playwright__browser_click
Screenshot: mcp__plugin_playwright_playwright__browser_take_screenshot
```
Auto-login URLs: `http://forums.local/?autologin=1` (admin), `http://forums.local/wp-admin/...?autologin=1`

---

## Test Order for v1.0.0 Release

Run sections in order. **Do not proceed to the next section if any ❌ remains.**

### Stage 1 — Environment & Install
```
[ ] WP_DEBUG enabled, debug.log clean (no existing errors)
[ ] Both plugins deactivated and deleted
[ ] Fresh install: activate jetonomy (free) only
[ ] All 21 jt_* tables created
[ ] Activate jetonomy-pro
[ ] All 3 jt_pro_* messaging tables created
[ ] No errors in debug.log after both activations
```

### Stage 2 — Free Core (from QA-CHECKLIST-FREE.md)
Run these sections in order:
1. § 1 Installation & Activation
2. § 2 Setup Wizard / Demo Data
3. § 3–4 Categories & Spaces
4. § 5–6 Posts & Replies (create, edit, delete, threading)
5. § 7 Voting (upvote, downvote, undo, rep change)
6. § 8 Trust Levels (auto-eval, level up, admin grant)
7. § 9 Search (keyword, tag, all-mode, instant)
8. § 10 User Profiles & Leaderboard
9. § 11 Notifications (mentions, votes, moderation)
10. § 12 Admin pages (Categories, Spaces, Content, Users)
11. § 13 REST API spot checks

### Stage 3 — Pro License & Admin UI
```
[ ] Settings → License tab renders (card layout, no raw page look)
[ ] Enter valid license key → activates
[ ] Deactivate → status reverts
[ ] Extensions page loads: 3-col grid, 4 category filter tabs
[ ] Enable/disable each extension — verify toggle saves
[ ] Settings sidebar: all tabs switch correctly
[ ] All Pro settings tabs use jt-settings-card layout (not raw h2/form-table)
```

### Stage 4 — Pro Extensions (from QA-CHECKLIST-PRO.md §§ 2–11)
Test each extension in this order (enable one, test it, move to next):

| Order | Extension | QA Section | Key things to verify |
|-------|-----------|------------|---------------------|
| 1 | Reactions | § 4 | Emoji reactions on posts + replies, admin emoji toggle |
| 2 | Polls | § 5 | Create poll, vote, close date, results |
| 3 | Custom Badges | § 8 | Create badge, criteria, auto-eval, profile display |
| 4 | Custom Fields | § 10 | All 9 field types, contexts (post/profile/space), required + validation |
| 5 | Advanced Moderation | § 9 | Keyword rule, regex rule, link limit, actions (flag/hold/block) |
| 6 | Analytics | § 7 | Dashboard metrics, CSV export, all 6 API endpoints |
| 7 | Private Messaging | § 11 | DM flow, group chat, unread count, trust level gate, message page |
| 8 | Email Digest | § 6 | Send test, preview, cron status, settings save |
| 9 | Web Push | § — | VAPID config saves, subscription flow (needs HTTPS or localhost) |
| 10 | Webhooks | § — | Register endpoint, event fires, retry on failure |
| 11 | White Label | § 3 | Community name, logo, footer, admin menu label, accent color, custom CSS |
| 12 | SEO Pro | § 2 | Meta title/desc templates, OG image, noindex, canonical |
| 13 | Reply by Email | § — | Provider config saves (full functional test requires real email setup) |

### Stage 5 — Pro Adapters (QA-CHECKLIST-PRO.md §§ 12)
Only test adapters if the third-party plugin is active on the test site.

```
[ ] WooCommerce: membership plan → space auto-join
[ ] Restrict Content Pro: subscription level → space access
[ ] LearnDash: course enrollment → space auto-join
```

### Stage 6 — Cross-Module (QA-CHECKLIST-PRO.md § 15)
```
[ ] Reactions + Polls on same post: both render, layout not broken
[ ] Analytics captures Pro activity (reactions, messages sent)
[ ] Email digest includes badge earned notification
[ ] Custom fields + search: searchable field value returns in results
[ ] Advanced moderation + reactions: flagging a reacted post works
```

### Stage 7 — Edge Cases (QA-CHECKLIST-PRO.md § 16)
```
[ ] Activate Pro without Free → graceful error, admin notice, no crash
[ ] Deactivate Free while Pro active → Pro deactivates cleanly
[ ] No PHP errors in debug.log across entire test session
[ ] No JavaScript console errors on any Pro page
[ ] All Pro tables preserved after deactivation (not dropped)
```

---

## Pre-Release Gating Checklist

Before tagging v1.0.0:

```
[x] Execute email-digest meta key migration plan ✅ Done — constant renamed + maybe_migrate_meta_keys() wired in boot()
[ ] All Stage 1–7 tests passing
[ ] debug.log is empty after full test run
[ ] git log: no uncommitted changes in either plugin
[ ] Version numbers match in plugin headers, CLAUDE.md, and CHANGELOG.md
```

---

## What Is NOT Tested Here (Out of Scope for v1.0.0)

| Topic | Why deferred |
|-------|-------------|
| Multisite | Not a v1.0 target — single-site only |
| Automated unit tests | No test suite written yet — manual QA only for v1.0 |
| Load / performance testing | Scalability architecture in place; full load test deferred post-launch |
| Pro adapters (WC/RCP/LD) | Only test if those plugins available on test site |
| Reply by Email (live email) | Requires real IMAP/sendgrid setup — spot-check config save only |
| Web Push on localhost | Requires HTTPS; test config save + frontend script load only |
