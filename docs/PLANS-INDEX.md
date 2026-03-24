# Development Plans — Master Index

> Single source of truth for all implementation plans across `jetonomy` and `jetonomy-pro`.
> Plans are written using the `superpowers:writing-plans` format (checkbox tasks, exact file paths, TDD).

---

## How to Use This Index

1. Check **Phase 2** first — those must be done before any release.
2. Pick the next **Phase 3** item by priority when planning a sprint.
3. When starting a plan: open the file, use `superpowers:subagent-driven-development` or `superpowers:executing-plans`.
4. When a plan completes: mark it ✅ here, add to the **Completed** table.

---

## Phase 1 — v1.0.0 Build Plans ✅ All Complete

Executed during initial development (2026-03-18). Kept for reference only.

| Plan | What was built | File |
|------|---------------|------|
| v1 Core Engine | DB schema (21 tables), Models (15), Router, Permission Engine, Trust Levels, Cache, Activity Tracker | `docs/plans/2026-03-18-jetonomy-v1-core-engine.md` |
| v1 Frontend | Templates (12 views + 6 partials), Interactivity API store, CSS custom properties, RTL | `docs/plans/2026-03-18-jetonomy-v1-frontend.md` |
| v1 REST API | 35+ endpoints across 12 controllers, cursor pagination, eager loading | `docs/plans/2026-03-18-jetonomy-v1-rest-api.md` |
| v1 Integrations | Membership adapters (WC/MemberPress/PMPro), bbPress+wpForo import, WP-CLI | `docs/plans/2026-03-18-jetonomy-v1-integrations.md` |

---

## Phase 2 — Pre-Release ⚠️ Execute Before Shipping v1.0.0

| # | Plan | Plugin | Priority | Status | What it fixes |
|---|------|--------|----------|--------|---------------|
| 1 | [email-digest-meta-key-fix](../../../jetonomy-pro/docs/superpowers/plans/2026-03-24-email-digest-meta-key-fix.md) | jetonomy-pro | 🔴 HIGH | ✅ Done | Renames `jetonomy_last_digest_*` → `jetonomy_pro_last_digest_*` meta key. Without this, digest re-sends to users after upgrade. One-time migration guard included. |

**How to run:** Open the plan file, use `superpowers:subagent-driven-development`.

---

## Phase 3 — v1.1 Backlog (Post-Release)

Ranked by priority. Do not start during v1.0 release stabilization.

| # | Plan | Plugin | Priority | Est. | Status | What it does |
|---|------|--------|----------|------|--------|--------------|
| 1 | [admin-class-split](superpowers/plans/2026-03-24-admin-class-split.md) | jetonomy | 🟡 Medium | 4h | ⏳ Pending | Splits 2,132-line `Admin` class into 8 focused `Ajax\*_Handler` classes. Zero functionality change — pure code organization. Required before Admin grows further. |
| 2 | email-digest-split | jetonomy-pro | 🟡 Medium | 3h | ⚠️ No plan yet | Extracts `Digest_Generator` from 1,594-line `email-digest/class-extension.php`. Plan must be written before adding any feature to email-digest. |
| 3 | [admin-listings-ux-pagination](superpowers/plans/2026-03-24-admin-listings-ux-pagination.md) | jetonomy | 🟢 Low | 3h | ⏳ Pending | Admin list pages (Users, Content, Spaces) get consistent toolbar UX + safe server-side pagination for 100K+ records. |
| 4 | textdomain-jit-fix | jetonomy | 🟢 Low | 5min | ⚠️ No plan needed | Single-line fix: change `add_action('init', [$this,'load_textdomain'])` to priority `1`. Eliminates `_load_textdomain_just_in_time` notice. File: `includes/class-jetonomy.php:23`. |

### Writing a New Plan

```bash
# Save to the right location:
jetonomy plans:     docs/superpowers/plans/YYYY-MM-DD-<slug>.md
jetonomy-pro plans: docs/superpowers/plans/YYYY-MM-DD-<slug>.md

# Use the writing-plans skill:
/writing-plans
```

---

## v1.1 Feature Ideas (No Plan Yet)

These are validated product ideas, not yet scoped or planned. Pick one, run `superpowers:brainstorming`, then `superpowers:writing-plans`.

| Idea | Plugin | Notes |
|------|--------|-------|
| Space-level analytics (per-space dashboard) | Pro | Useful for community managers |
| Scheduled post publishing | Free | Post with future date → auto-publish |
| Post templates | Free | Starter content for Q&A, idea submission |
| Digest email template editor | Pro | Visual customization of digest HTML |
| AI moderation (LLM spam detection) | Pro | Extend Advanced Moderation with AI scoring |
| Two-factor auth integration | Free | Hook into existing WP 2FA plugins |
