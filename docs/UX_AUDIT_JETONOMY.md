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

1. ~~Fix emoji reactions → SVG or text labels~~ DONE (text labels in JT Pro)
2. ~~Replace all empty state + toolbar emoji → jetonomy_icon() SVG helpers~~ DONE (16 templates)
3. **Redesign reaction UX** — single "React" button with hover picker (Facebook pattern), not 8 buttons shown at once. Match BuddyNext post-card reaction picker. (JT Pro Extension)
4. Clean up action icon clutter on single-post view (too many icons in toolbar)
5. Verify container width on all pages
6. Add hover effects to discussion cards
7. Wire window.bnToast() for JT actions (vote, reply, bookmark)
