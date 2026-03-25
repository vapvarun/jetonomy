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

**Next session (agents hit rate limit):**
- [ ] Profile Replies + Votes tabs
- [ ] Notification badge count in nav
- [ ] Mobile bottom tab bar
- [ ] Skeleton loading CSS

**Remaining (next batch):**
- [ ] Profile Media tab (needs WPMediaVerse bridge)
- [ ] User hover cards on author names
- [ ] Notification dropdown panel (bell → dropdown, not page)
- [ ] Search overlay (cmd+K)
- [ ] Keyboard shortcuts (/, n, j/k, ?)
- [ ] Infinite scroll (IntersectionObserver on pagination)
- [ ] Link preview cards (auto-fetch OG data)
- [ ] Content scheduling (published_at field + cron)
- [ ] Pin posts to space (is_sticky field exists)
- [ ] Beautiful empty states with SVG illustrations

### Unified Icon System (cross-plugin)
See: `buddynext/docs/ICON_SYSTEM_PLAN.md`
- Lucide for UI icons (line, monochrome)
- Fluentui 3D for reaction icons (colorful, premium)
- 30 shared icon slugs standardized across BN + JT + MVS
