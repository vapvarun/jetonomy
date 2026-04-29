# 1.4.1 Phase Completion Checklist

**Gate:** Each phase's safety checks must be signed off before proceeding to the next.

## Track A — Free Plugin

| Phase | Package | Status | Sign-off | Notes |
|-------|---------|--------|----------|-------|
| A1 | REST audit verdict pass | ✅ DONE | 2026-04-29 | `audit/REST_AUDIT.md` with 18 routes, all verdicts classified |
| A2 | Manifest schema v2 (auth/cap/ownership) | ✅ DONE | 2026-04-29 | All 64 endpoints updated; discrepancies documented in A2-COMPLETION.md |
| A3 | REST security fixes + access-matrix runner | ✅ DONE | 2026-04-29 | All 4 tasks PASS; runner at `bin/access-matrix-check.sh` (72/72), baseline saved at `plan/1.4.1-baselines/A3/access-matrix-baseline.log` |
| A4 | POST /moderation/bulk REST endpoint | ✅ DONE | 2026-04-29 (`698014e` → `fb1e241`) | REST parity for `wp_ajax_jetonomy_bulk_content_action`; AJAX handler retained. Per-item helpers (`approve_item`/`spam_item`/`trash_item`) extracted in `Moderation_Controller` so single + bulk paths fire the same `jetonomy_content_moderated` action and reputation penalty. Per-item failures reported in row's `status` field rather than aborting the batch. Runner extended (78/78 PASS in both `--mode=public` and `--mode=private`); `--diff-baseline` clean; qa-actions 210/210 green. |
| A5 | GET /posts/{id}/flags | ✅ DONE | 2026-04-29 (`698014e` → `fb1e241`) | New `Flag::find_for_post()` model method backs the mod-only endpoint; row shape matches `Flag::list_pending()` so frontend can swap data sources without remapping fields. Returns `[]` (200) — never 404 — when post has no flags. Runner row added; `COMMUNITY_NON_PUBLIC_SUFFIX_RE` exclusion ensures the route stays cap-gated (anon=403) in private mode rather than getting the visibility 401 flip. |
| A6 | Activity Log admin page | ✅ DONE | 2026-04-29 | New `?page=jetonomy-activity` admin page (sidebar slot between Moderation and Users). `Jetonomy\Admin\Activity_List_Table` extends `WP_List_Table` (read-only); columns Date · Actor · Type · Object · Details; filters by user_id / action type / date range; `screen_options` per-page (default 20, persisted via `jetonomy_activity_per_page`); CSV export (50k row cap, nonce-gated). Capability: `jetonomy_manage_settings`. No edit/delete actions in v1. Screenshot at `plan/1.4.1-baselines/A6/admin-activity-log.png`. qa-actions 210/210 PASS; access-matrix 78/78 PASS (identical to baseline). All 10 admin pages still load in <2s. |
| A7 | Revisions admin page | ⏳ PENDING | — | Per-post diff browser |
| A8 | Email Templates admin editor | ⏳ PENDING | — | UI for `jetonomy_email_templates` option |
| A9 | Frontend `?tab=drafts` and `?tab=bookmarks` | ✅ DONE | 2026-04-29 | New top-level routes `/community/drafts/` and `/community/bookmarks/` (router rewrite rules + template_loader map + auth-required gate). New templates `templates/views/drafts.php` and `templates/views/bookmarks.php` reuse `partials/post-card.php` and emit a "Browse the community" CTA in the empty state. SEO `noindex` on both (personal logged-in views). REST surface unchanged so `--diff-baseline` shows no drift. qa-actions 210/210 PASS; access-matrix 78/78 PASS (identical to baseline). |
| A10 | `jetonomy_user_pending_verification` cron | ✅ DONE | 2026-04-29 | New `jetonomy_verification_reminder` hourly cron + `Verification_Reminder` runner class; rate-limited via new `jt_user_profiles.verification_reminder_sent_at` column (1.4.1 migration); default `verification_reminder` email template seeded on activation + via one-time `jetonomy_verification_reminder_seeded` upgrade flag for in-place upgrades; admin can tune `jetonomy_settings.verification_reminder_hours` (default 24, 0 disables); 210/210 qa-actions PASS, access-matrix 78/78 identical to baseline (REST surface unchanged). |
| A11 | Community visibility mode (public/private) | ✅ DONE | 2026-04-29 (`a00bcf3` → `2e48a41`) | REST enforcement gap closed: `Jetonomy\Visibility` helper centralizes the `guest_read` check, every public-read REST endpoint now wraps `permission_callback` with `Visibility::rest_check`, template-loader refactored to use the helper, runner extended with `--mode=public|private` flag. Verified 72/72 in both modes; qa-actions 210/210 green. |

## Track B — Pro Plugin

| Phase | Package | Status | Sign-off | Notes |
|-------|---------|--------|----------|-------|
| B1 | White Label extension wiring | ✅ DONE | 2026-04-29 (`jetonomy-pro` 6c596ac) | 3 of 5 filters now actively consumed in Pro (`email_logo_url`, `email_accent_color`, `sidebar_auth_card`); the remaining 2 (`header_logo`, `footer_text`) are subscribed in Pro but not yet fired in free — see KG-1 in `jetonomy-pro/plan/1.4.1-baselines/B1-VERIFICATION.md` |
| B2 | Analytics dual-path aggregation | ✅ READY (cutover deferred to 1.5.0) | 2026-04-29 (`jetonomy-pro` B2 commits) | Aggregator + 7 hot-path listeners + `/analytics/diff-report` REST + admin "Verify dual-path" toggle live; `wp_jt_pro_analytics_aggregate` table created via Pro DB-version guard (composite PK + period_start KEY); `Extension::ANALYTICS_PATH = 'query'` (default reader unchanged in 1.4.1, per "Forbidden" rule). 100-event burst measured at 0.36 ms avg per event (budget < 1 ms — PASS). qa-actions 210/210 PASS, PHPStan level 5 clean, WPCS clean. Day-1 drift baseline reflects pre-listener historical source-table rows (legitimate dual-path artifact, see `jetonomy-pro/plan/1.4.1-baselines/B2/diff-day-1.log`). 7-day observation window starts 2026-04-29; cutover decision = 1.5.0 deliverable when day-7 log shows < 1% drift on all metrics. |
| B3 | Email Digest extension wiring | ✅ DONE | 2026-04-29 (`jetonomy-pro` 0732ec7) | Pro `jetonomy_pro_badge_earned` + `jetonomy_pro_poll_voted` consumed in `jetonomy-pro/email-digest`; per-user buffer (user meta `jetonomy_pro_digest_event_buffer`, capped 100 entries / 30-day TTL); two new render blocks (🏆 + 🗳️); buffer cleared after successful send only; commits B3.1=`8895538`, B3.2=`3c0d5ab`, B3.3=`0732ec7`; qa-actions 210/210 PASS; preview duration 3ms vs 7ms PRE (well under +2s budget); empty-buffer digest body identical to PRE baseline (whitespace-only diff); opted-out users (`frequency=none`) get no buffer growth |

---

## Critical Path & Dependencies

```
✅ A1 → ✅ A2 → ✅ A3 ── security track (gated)
                      
A4, A5 ─── REST additions (parallel, independent)
A6, A7 ─── admin pages (parallel, independent)
A8       ─── email templates (parallel, independent)
A9, A10  ─── frontend + cron (parallel, independent)
A11      ─── community visibility mode (after A3 so runner can verify both modes)

B1 ─── White Label (parallel with everything)
B2 ─── Analytics dual-path (parallel, needs ~7 days for data parity cutover decision)
B3 ─── Email Digest (parallel)
```

---

## Sign-off Authorization

| Role | Names | Authority |
|------|-------|-----------|
| Release Driver | Opus Session | Final release gate approval |
| QA Verifier | wp-qa-auditor | Smoke test & regression check |
| Security Lead | wp-verifier | Security-critical phase sign-off |

Each phase's PRE/POST baselines are captured in `1.4.1-baselines/<phase>/` and compared by the agent before marking complete.

