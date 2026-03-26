# Jetonomy UX Audit — Uniform with BuddyNext

**Date:** 2026-03-25
**Goal:** Make Jetonomy feel like a native part of the BuddyNext platform, not a separate plugin
**Release:** Friday 2026-03-28

---

## Issues Found

### P0 — Critical UX Problems

| # | Issue | File | Fix |
|---|-------|------|-----|
| 1 | **Emoji reactions** — 8 Unicode emoji (👍❤️😄🎉🤔👀🚀👎) violate BuddyNext "No Emoji" rule. Must use SVG icons or text-only badges. | `templates/views/single-post.php`, `assets/js/view.js`, model/DB | Replace emoji with SVG icon reactions or simple text labels (Like, Love, etc.) |
| 2 | **Too many action icons** — clip, bookmark, pen, pin, flag, table, settings all showing on post view. Visual clutter. | `templates/views/single-post.php` | Collapse into a "..." more menu, show only primary actions (vote, reply, share) |
| 3 | **Up/down vote UI** — triangle arrows look dated. Should match BuddyNext's clean icon style. | `templates/views/single-post.php`, `assets/css/jetonomy.css` | Use Lucide chevron-up/chevron-down SVGs |
| 4 | **Nav font size mismatch** — when BuddyNext wraps Jetonomy, the BN nav font was inconsistent (now fixed) | `partials/nav.php` (BN side) | Fixed this session |
| 5 | **Container width** — Jetonomy uses 1200px, BuddyNext uses --bn-container (1100px). Override added but needs verification on all JT pages. | `jetonomy.css`, `bn-base.css` | Verify every JT page respects --bn-container |

### P1 — Missing Premium Feel

| # | Issue | Fix |
|---|-------|-----|
| 6 | No hover/transition effects on discussion cards | Add shadow + translateY hover like BuddyNext post cards |
| 7 | No toast notifications on JT actions (vote, reply, bookmark) | Wire window.bnToast() calls in view.js |
| 8 | Reply composer is basic textarea — no preview, no formatting bar | Keep for now, rich editor is Phase 6.1 |
| 9 | Breadcrumb styling could be cleaner | Lighter separator, smaller font |
| 10 | Sidebar "Trending" + "Top Members" — good content but could have card elevation like BN sidebar | Add box-shadow to sidebar cards |

### P2 — Integration Gaps

| # | Issue | Fix |
|---|-------|-----|
| 11 | JT A/A+/A++ control styling doesn't match BN exactly | Use same CSS class names (bn-font-scale) or sync styles |
| 12 | JT sidebar shows when BN is active — should use BN community sidebar instead | JetonomyBridge already suppresses JT sidebar via filter, verify |
| 13 | JT profile page (/discussion/u/alice/) doesn't show BN data (followers, connections) | Add BN stats to JT profile via bridge filter |

---

## Execution Priority for Friday Release

## Completed

- [x] Emoji reactions → Fluentui 3D PNG icons (JT Pro) — MIT license, 308KB bundled
- [x] Empty state + toolbar emoji → jetonomy_icon() SVG helpers (16 templates)
- [x] Reaction UX → single "React" button with hover picker (Facebook pattern)
- [x] Action clutter → edit/delete/pin into "..." dropdown (post + replies)
- [x] Container widths → verified correct (token-based, BN bridge overrides)
- [x] Hover effects → discussion cards lift on hover
- [x] Toast notifications → wired for vote, bookmark, follow, flag (debounced)
- [x] Icon system → jetonomy_icon() + 19 SVG files + edit.svg + settings.svg
- [x] All HTML entities → replaced with SVG icons across all templates

## Remaining — Premium UX (plan first, apply uniformly)

### Reply Card Redesign (SaaS-level)
Current: vote arrows + Reply + ... + React all inline, looks crowded
Target (Discourse/Circle pattern):
```
┌─────────────────────────────────────────────────────┐
│ [Avatar] Author Name  Trust Level  1 day ago    ... │
│                                                      │
│ Reply content here...                                │
│                                                      │
│ ▲ 3 ▼   [React chips: ❤️3 👍2]     Reply   React  │
└─────────────────────────────────────────────────────┘
```
- Reaction summary chips inline with action bar
- "..." dropdown for admin actions (edit/delete/report) in top-right corner
- Cleaner vertical separation between content and actions

### Missing Features (from 6-phase BuddyNext comparison)

**Done (this session):**
- [x] @mention auto-linking in post/reply content — `jetonomy_format_content()`
- [x] #hashtag auto-linking in content — same function

**Completed (session 2):**
- [x] Profile Replies + Votes tabs — model queries + template + router
- [x] Notification badge count in nav — already existed in header.php
- [x] Mobile bottom tab bar — 5-tab fixed bottom nav (≤640px)
- [x] Skeleton loading CSS — shimmer animations for topics, replies, sidebar, profile

**Completed (session 2 — batch 2):**
- [x] Notification dropdown panel — bell button opens panel with REST-fetched notifications + mark all read
- [x] Search overlay (cmd+K) — modal overlay with debounced instant search, ESC to close
- [x] Keyboard shortcuts — /, Ctrl+K search; j/k navigate rows; Enter open; n home; ? help modal
- [x] Infinite scroll — IntersectionObserver on pagination partial, auto-fetches next page
- [x] Beautiful empty states — 5 contextual SVG illustrations (posts, replies, search, notifications, members)

**Completed (session 2 — batch 3):**
- [x] User hover cards — hover on .jt-user-link / .jt-mention shows card with avatar, trust, bio, stats via REST
- [x] Link preview cards — auto-detect bare URLs in post/reply, fetch OG metadata via /link-preview endpoint, render card
- [x] Pin posts UI — already existed (button + REST + view.js action), improved with toast feedback

**Remaining (future):**
- [ ] Profile Media tab (needs WPMediaVerse bridge)
- [ ] Content scheduling (published_at field + cron)

---

## Production QA Checklist (reusable)

### Per-Page Checks (Desktop 1280px + Mobile 390px)

| Page | URL | Check |
|------|-----|-------|
| Home | `/discussion/` | Categories render, space cards link, sidebar widgets, empty state SVG |
| Space | `/discussion/s/:slug/` | Follow button, sort pills, +New Post, topic rows, vote arrows, trust badges |
| Single Post | `/discussion/s/:slug/t/:slug/` | Vote up/down, follow, share (dropdown), bookmark (toggle), flag (modal), "..." menu (edit/pin/delete), reactions (picker opens, single-reaction, instant chip update) |
| Reply Card | (within single post) | Vote, Reply button, "..." menu (edit/delete), React button, thread collapse |
| Reply Composer | (within single post) | Toolbar (B/I/code/link/quote/image), placeholder, Post Reply, Ctrl+Enter |
| Search | `/discussion/search/?q=test` | Input, filter pills (All/Posts/Spaces/Tags), results, empty state |
| Category | `/discussion/category/:slug/` | Space cards, links |
| Tag | `/discussion/tag/:slug/` | Sort pills, post rows, tag hero |
| Profile | `/discussion/u/:login/` | Avatar, trust badge, bio, stats, tabs (Posts/Replies/Votes/Bookmarks) |
| Edit Profile | `/discussion/u/:login/edit/` | Form fields, notification prefs, save/cancel |
| Leaderboard | `/discussion/leaderboard/` | Rankings, reputation, avatar links |
| Notifications | `/discussion/notifications/` | Items, unread highlight, mark read |
| Moderation | `/discussion/mod/` | Flag queue, dismiss/remove buttons |
| Space Members | `/discussion/s/:slug/members/` | Member list, profile links |

### Global Features

| Feature | Check |
|---------|-------|
| Community nav | Links active state, correct highlighting |
| Notification bell | Dropdown opens, lazy-loads, mark all read, badge clears |
| Search overlay | Cmd+K or "/" opens, ESC closes, debounced search, results link |
| Keyboard shortcuts | j/k navigate rows, Enter opens, ? help modal, n → home |
| Font scale A/A+/A++ | Buttons persist via localStorage, font scales uniformly |
| Hover cards | Mouseover author names → card with avatar/trust/bio/stats (400ms delay) |
| Mobile bottom bar | 5 tabs (Home/Search/Ranks/Alerts/Profile) at ≤640px |
| Infinite scroll | Pagination auto-loads next page via IntersectionObserver |
| Skeleton loading | CSS shimmer classes available for placeholder UI |
| Toast notifications | All actions show toast (no browser alerts) |
| Custom modals | Delete confirm + flag report use styled modals (no browser confirm/prompt) |
| Link previews | Bare URLs in posts auto-fetch OG data and render preview card |
| Empty states | SVG illustrations on: no posts, no replies, no results, no notifications, no members |

### No-Go Criteria

- [ ] Zero `alert()` / `confirm()` / `prompt()` browser dialogs
- [ ] Zero JS console errors
- [ ] Zero PHP errors in debug.log
- [ ] No horizontal scroll at 390px
- [ ] All actions work without page reload (optimistic UI)
- [ ] Reactions: single per user, instant chip update
- [ ] i18n: all JS strings via state.i18n.* (translatable)
- [ ] RTL: jetonomy-rtl.css loaded when is_rtl()

### Unified Icon System (cross-plugin)
See: `buddynext/docs/ICON_SYSTEM_PLAN.md`
- Lucide for UI icons (line, monochrome)
- Fluentui 3D for reaction icons (colorful, premium)
- 30 shared icon slugs standardized across BN + JT + MVS
