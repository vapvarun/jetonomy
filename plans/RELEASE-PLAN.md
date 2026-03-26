# Jetonomy v1.0.0 — Release Plan

**Last updated:** 2026-03-24

---

## Release Status

| Plugin | Status | Blocker |
|--------|--------|---------|
| Jetonomy (free) | Code complete | None — admin class split is post-v1 |
| Jetonomy Pro | Code complete | Meta key migration must run before release |

**Target:** Ready to release once the single blocker below is resolved and QA passes.

---

## What's Built in v1.0.0

### Free Plugin — Core Engine

| Area | What's Shipped |
|------|---------------|
| Database | 22 custom `wp_jt_*` tables |
| Spaces | CRUD, visibility, join policy, sub-spaces (max 3-tier: Category → Space → Sub-Space) |
| Space Types | Forum (discussion), Q&A (voting + accepted answer), Ideas (status tracking), Feed (social feed) |
| Posts & Replies | Rich editor, threading, accept answer |
| Voting | Posts + replies, undo, score denormalization |
| Trust Levels | 0–5 auto-evaluated (Newcomer → Moderator); Levels 0–3 auto-earned; Levels 4–5 admin-granted |
| Permissions | 3-layer: WP Caps → Space Roles → Trust Levels; 18 WP Abilities |
| Search | All-mode, tags, instant-as-you-type |
| User Profiles | Profile pages + leaderboard |
| Notifications | Event-driven, batched votes, @mention |
| Activity Tracker | Centralized via hooks |
| Imports | bbPress + wpForo (progress tracking, resume) |
| Membership | WooCommerce, MemberPress, PMPro adapters |
| Admin | Setup wizard, demo data seeder + one-click cleanup, uninstall cleanup (tables, options, caps, cron) |

### Free Plugin — Competitive Gap Features

| ID | Feature |
|----|---------|
| G1 | Drag-drop + paste-to-upload images in editor |
| G2 | Auto-embed URLs (oEmbed: YouTube, Twitter, Vimeo) |
| G3 | Instant search-as-you-type |
| G4 | RTL stylesheet |
| G5 | Quote selected text → blockquote in composer |
| G6 | User hover cards |
| G7 | IP tracking + Akismet integration |
| G8 | Keyboard shortcuts (j/k navigate, l vote, r reply) |
| G9 | Emoji picker in editor |
| G10 | Invite links with expiry per space |

### Free Plugin — Scalability

| ID | What it does |
|----|-------------|
| S1 | Cache class (get/set/delete/remember/flush) |
| S2 | Eager loading — `batch_load_users/profiles` (no N+1) |
| S3 | Cursor-based pagination (`?after=ID&limit=20`) |
| S4 | Background queue (Action Scheduler / WP-Cron) |
| S5–S7 | Batch email digest, badge evaluator, cached unread counts |

### Pro Plugin — Extensions

| Extension | Category | Tier | Status |
|-----------|----------|------|--------|
| Private Messaging | Communication | starter | Done |
| Analytics | Administration | starter | Done |
| Reactions | Engagement | starter | Done |
| Polls | Engagement | starter | Done |
| Custom Badges | Gamification | starter | Done |
| Custom Fields | Content | starter | Done |
| Webhooks | Administration | starter | Done |
| Advanced Moderation | Administration | starter | Done |
| White Label | Content | — | Done |
| Email Digest | Communication | — | Done |
| Web Push | Communication | — | Done |
| SEO Pro | Content | — | Done |
| Reply by Email | Communication | — | Done |

### Pro Plugin — Infrastructure

- License system (EDD-based, tier checks)
- EDD auto-updater
- WooCommerce, Restrict Content Pro, LearnDash adapters
- Background queue (Action Scheduler / WP-Cron)
- Admin UI: 3-col card grid with category filter tabs, sidebar nav + `jt-settings-card`

---

## Must-Do Before Release

| # | Task | Plan File |
|---|------|-----------|
| 1 | **Meta key migration** — old prefix `jetonomy_last_digest_*` still referenced; causes digest to re-send on every run. Fix: `maybe_migrate_meta_keys()` + constant rename | `jetonomy-pro/docs/superpowers/plans/2026-03-24-email-digest-meta-key-fix.md` |

No other hard blockers. Admin class split is explicitly deferred to post-v1.

---

## Post-v1 Technical Debt

| Issue | Severity | Plan File |
|-------|----------|-----------|
| Admin class is 750+ lines — needs AJAX handler split | Medium | `docs/superpowers/plans/2026-03-24-admin-class-split.md` |
| Admin listings UX / pagination improvements | Low | `docs/superpowers/plans/2026-03-24-admin-listings-ux-pagination.md` |
| REST API textdomain JIT notice on `init:10` race | Low | `~/.claude/plans/jazzy-foraging-dongarra.md` (1-line fix) |
| Email Digest at 1,594 lines — extract `Digest_Generator` class before next feature | Medium | `jetonomy-pro/docs/superpowers/plans/2026-03-24-email-digest-meta-key-fix.md` |
| Private Messaging at 1,112 lines — watch, split on next feature add | Low | — |

---

## QA Priority Order

Run in this sequence before cutting the release:

1. **Install & activate** — free first, then pro; no PHP errors, all `wp_jt_*` tables created
2. **License tab** — activate / deactivate / expire in Settings → License
3. **Core forum flow** — category → space → post → reply → vote → search
4. **Pro extensions** — each extension one by one
5. **Settings admin UI** — all setting tabs save and reload correctly
6. **Extensions page** — enable/disable modules, tier gating works
7. **Email Digest** — send test, preview, cron status; run meta key migration and verify no duplicate sends
8. **Cross-module** — Reactions + Polls on the same post; Analytics captures Pro events
9. **Edge cases** — Pro without Free (should bail with admin notice), expired license (gated features blocked), module disabled mid-use (no fatal)

---

## Pre-Release Security Checklist

- [ ] `WP_DEBUG` is `false` in `wp-config.php`; no debug output reaches the browser
- [ ] All `$_GET` / `$_POST` / `$_REQUEST` inputs are sanitized with the appropriate WordPress function (`sanitize_text_field`, `absint`, `wp_kses_post`, etc.) before use
- [ ] All database queries use `$wpdb->prepare()` with placeholders — no string interpolation in SQL
- [ ] All forms and AJAX handlers verify a nonce (`wp_verify_nonce` / `check_ajax_referer`) before processing
- [ ] All REST endpoints declare `permission_callback` — no endpoint uses `__return_true` in production
- [ ] Direct file access blocked at the top of every PHP file: `defined('ABSPATH') || exit;`
- [ ] File uploads validated for type and size; stored outside web root or with `.htaccess` protection
- [ ] Output is escaped at the point of output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` as appropriate — no raw `echo $variable`
- [ ] No hardcoded credentials, API keys, or secrets in source files
- [ ] Capability checks (`current_user_can`) applied before any privileged action — not just nonce checks
- [ ] Uninstall routine removes all plugin data (tables, options, user meta, cron events) — no orphaned data left behind
- [ ] Composer `vendor/` directory is not exposed publicly; `autoload.php` path is outside document root or protected
- [ ] License verification calls happen server-side; license tier is not determined by client-supplied data

---

## Out of Scope for v1.0

These are explicitly not being built for this release:

| Item | Planned For |
|------|------------|
| Courses / LMS integration | v1.2 |
| Mobile app | TBD |
| Real-time WebSocket (polling adapter is the v1 approach) | Post-v1 |
| Multi-site support | Post-v1 |
| Self-hosted video / media server | TBD |

---

## Architecture Reference

| Plugin | PHP Files | Classes | REST Endpoints | AJAX Handlers | DB Tables |
|--------|-----------|---------|---------------|---------------|-----------|
| Jetonomy (free) | 130 | 67 | 42 | 34 | 22 |
| Jetonomy Pro | 30 | 23 | 38 | — | 14 |
