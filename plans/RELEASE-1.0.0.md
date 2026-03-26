# Jetonomy v1.0.0 — Release Readiness

> **Last updated:** 2026-03-24
> Covers both `jetonomy` (free) and `jetonomy-pro`.
> Architecture docs: `jetonomy/docs/architecture/` and `jetonomy-pro/docs/architecture/`

---

## Release Status

| Plugin | Status | Blocker |
|--------|--------|---------|
| Jetonomy (free) | ✅ Code complete | None — admin class split is post-v1 |
| Jetonomy Pro | ✅ Code complete | Meta key migration plan should run before release |

---

## 1. Free Plugin — Feature Status

All 22 core tasks + 10 competitive gap features shipped.

### Core Engine
| Feature | Status |
|---------|--------|
| 21 custom DB tables (jt_*) | ✅ Done |
| Spaces & Categories (CRUD, visibility, join policy) | ✅ Done |
| Posts & Replies (rich editor, threading, accept answer) | ✅ Done |
| Voting (posts + replies, undo, score denormalization) | ✅ Done |
| Trust Levels 0–5 (auto-evaluation, rep, admin override) | ✅ Done |
| 3-layer permissions (WP Caps → Space Roles → Trust Levels) | ✅ Done |
| Search (all-mode, tags, instant-as-you-type) | ✅ Done |
| User profiles + leaderboard | ✅ Done |
| Notifications (event-driven, batched votes, mention) | ✅ Done |
| Activity Tracker (centralized via hooks) | ✅ Done |
| WP Abilities API (18 abilities) | ✅ Done |
| bbPress + wpForo import (progress tracking, resume) | ✅ Done |
| Membership adapters (WooCommerce, MemberPress, PMPro) | ✅ Done |
| Demo data seeder + one-click cleanup | ✅ Done |
| Setup wizard | ✅ Done |
| Uninstall cleanup (tables, options, caps, cron) | ✅ Done |

### Competitive Gap Features (shipped in v1.0.0)
| Feature | Status |
|---------|--------|
| G1: Drag-drop + paste-to-upload images in editor | ✅ Done |
| G2: Auto-embed URLs (oEmbed: YouTube, Twitter, Vimeo) | ✅ Done |
| G3: Instant search-as-you-type | ✅ Done |
| G4: RTL stylesheet | ✅ Done |
| G5: Quote selected text → blockquote in composer | ✅ Done |
| G6: User hover cards | ✅ Done |
| G7: IP tracking + Akismet integration | ✅ Done |
| G8: Keyboard shortcuts (j/k navigate, l vote, r reply) | ✅ Done |
| G9: Emoji picker in editor | ✅ Done |
| G10: Invite links with expiry per space | ✅ Done |

### Scalability (shipped in v1.0.0)
| Item | Status |
|------|--------|
| S1: Cache class (get/set/delete/remember/flush) | ✅ Done |
| S2: Eager loading — batch_load_users/profiles (no N+1) | ✅ Done |
| S3: Cursor-based pagination (?after=ID&limit=20) | ✅ Done |
| S4: Background queue (Action Scheduler / WP-Cron) | ✅ Done |
| S5–S7: Batch email digest, badge evaluator, cached unread | ✅ Done |

### Known Technical Debt (post-v1 roadmap)
| Issue | Severity | Plan |
|-------|----------|------|
| `Admin` class is 750+ lines — needs AJAX split | Medium | `docs/superpowers/plans/2026-03-24-admin-class-split.md` |
| Admin listings UX / pagination improvements | Low | `docs/superpowers/plans/2026-03-24-admin-listings-ux-pagination.md` |
| REST API textdomain JIT notice on init:10 race | Low | `~/.claude/plans/jazzy-foraging-dongarra.md` — fix is 1 line |

---

## 2. Pro Plugin — Extension Status

| Extension | Category | Tier | Status | Lines | Notes |
|-----------|----------|------|--------|-------|-------|
| Private Messaging | Communication | starter | ✅ Done | 1,112 | Watch limit — split before next feature |
| Analytics | Administration | starter | ✅ Done | — | 6 REST endpoints |
| Reactions | Engagement | starter | ✅ Done | — | |
| Polls | Engagement | starter | ✅ Done | — | |
| Custom Badges | Gamification | starter | ✅ Done | — | |
| Custom Fields | Content | starter | ✅ Done | — | |
| Webhooks | Administration | starter | ✅ Done | — | |
| Advanced Moderation | Administration | starter | ✅ Done | — | |
| White Label | Content | — | ✅ Done | — | |
| Email Digest | Communication | — | ✅ Done | 1,594 | **Must extract `Digest_Generator` before next feature** |
| Web Push | Communication | — | ✅ Done | — | |
| SEO Pro | Content | — | ✅ Done | — | |
| Reply by Email | Communication | — | ✅ Done | — | |

### License & Infrastructure
| Item | Status |
|------|--------|
| License system (EDD-based, tier checks) | ✅ Done |
| License tab embedded in Settings page | ✅ Done (2026-03-24) |
| EDD auto-updater | ✅ Done |
| WooCommerce membership adapter | ✅ Done |
| Restrict Content Pro adapter | ✅ Done |
| LearnDash adapter | ✅ Done |
| WP Abilities API (20 abilities) | ✅ Done |
| Background queue (Action Scheduler / WP-Cron) | ✅ Done |

### Admin UI (shipped 2026-03-24)
| Change | Status |
|--------|--------|
| Extensions page: 3-col card grid with 4 category filter tabs | ✅ Done |
| Settings page: sidebar nav + jt-settings-card layout for all tabs | ✅ Done |
| License page removed from main nav → moved to Settings → License tab | ✅ Done |
| All Pro extension settings tabs converted to jt-settings-card layout | ✅ Done |
| pro-admin.css enqueued on all Jetonomy admin pages (not just extensions) | ✅ Done |

### Pending Action Before Release
| Item | Priority | Plan |
|------|----------|------|
| Email digest meta key migration (`jetonomy_last_digest_*` → `jetonomy_pro_last_digest_*`) | **HIGH** | `docs/superpowers/plans/2026-03-24-email-digest-meta-key-fix.md` |

---

## 3. Pending Plans — What to Execute

### Must do before release
| Plan | Plugin | What it fixes |
|------|--------|---------------|
| `jetonomy-pro/docs/superpowers/plans/2026-03-24-email-digest-meta-key-fix.md` | Pro | Old meta key prefix `jetonomy_last_digest_*` still referenced in code — causes digest to re-send to users who already received it. Fix is `maybe_migrate_meta_keys()` + constant rename. |

### Post-v1.0 backlog
| Plan | Plugin | What it addresses |
|------|--------|-------------------|
| `jetonomy/docs/superpowers/plans/2026-03-24-admin-class-split.md` | Free | Admin class exceeds 750 lines — AJAX handlers need extraction into `Ajax\*_Handler` classes |
| `jetonomy/docs/superpowers/plans/2026-03-24-admin-listings-ux-pagination.md` | Free | Admin list pages (users, content) UX + pagination improvements |
| `~/.claude/plans/jazzy-foraging-dongarra.md` | Free | `_load_textdomain_just_in_time` notice — 1-line fix (init priority 1) |
| Email Digest split: extract `Digest_Generator` class | Pro | email-digest extension at 1,594 lines — must extract before adding features |

---

## 4. Architecture Health

### Free Plugin
- **130 PHP files**, 67 classes, 42 REST endpoints, 34 AJAX handlers, 22 DB tables
- Full reference: `jetonomy/docs/architecture/PLUGIN_ARCHITECTURE.md`
- Pattern: all DB access via Model classes, no raw SQL outside `includes/db/`
- Naming: options `jetonomy_*`, meta `jetonomy_*`, tables `jt_*`, hooks `jetonomy_*`

### Pro Plugin
- **30 PHP files**, 23 classes, 38 REST endpoints, 14 Pro DB tables
- Full reference: `jetonomy-pro/docs/architecture/PLUGIN_ARCHITECTURE.md`
- Pattern: extensions auto-discovered, all hooks via `jetonomy_admin_*` injection points
- Naming: options `jetonomy_pro_*`, meta `jetonomy_pro_*`, tables `jt_pro_*`

### File Size Watchlist
| File | Lines | Status |
|------|-------|--------|
| `email-digest/class-extension.php` | 1,594 | 🔴 Must split before next feature |
| `private-messaging/class-extension.php` | 1,112 | 🟡 Watch — split on next feature add |

---

## 5. QA Priority Order for Release

Run in this order. Each section must pass before moving to the next.

1. **Install & activate** (free then pro) — no errors, tables created, admin menus correct
2. **License tab** — activate/deactivate/expire scenarios in Settings → License
3. **Core forum** — full user flow: category → space → create post → reply → vote → search
4. **Pro extensions one by one** — enable each, run its QA checklist section
5. **Settings admin UI** — all tab switches, card layout, form saves
6. **Extensions page** — category filters, enable/disable toggles, 3-col grid
7. **Email digest** — send test, preview, cron status — then run meta key migration
8. **Cross-module** — reactions + polls on same post, analytics captures Pro events
9. **Edge cases** — Pro without Free (graceful error), expired license, disabled module mid-use

Full checklists:
- Free: `jetonomy/docs/QA-CHECKLIST-FREE.md`
- Pro: `jetonomy/docs/QA-CHECKLIST-PRO.md`
