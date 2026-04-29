# 1.4.1 Phase Completion Checklist

**Gate:** Each phase's safety checks must be signed off before proceeding to the next.

## Track A — Free Plugin

| Phase | Package | Status | Sign-off | Notes |
|-------|---------|--------|----------|-------|
| A1 | REST audit verdict pass | ✅ DONE | 2026-04-29 | `audit/REST_AUDIT.md` with 18 routes, all verdicts classified |
| A2 | Manifest schema v2 (auth/cap/ownership) | ✅ DONE | 2026-04-29 | All 64 endpoints updated; discrepancies documented in A2-COMPLETION.md |
| A3 | REST security fixes | ⏳ IN PROGRESS | — | Fixing routes flagged 🚨 OPEN (none found); verifying rate-limits on auth endpoints |
| A4 | POST /moderation/bulk REST endpoint | ⏳ PENDING | — | Additive feature; low risk |
| A5 | GET /posts/{id}/flags | ⏳ PENDING | — | Mod-only endpoint |
| A6 | Activity Log admin page | ⏳ PENDING | — | New admin page |
| A7 | Revisions admin page | ⏳ PENDING | — | Per-post diff browser |
| A8 | Email Templates admin editor | ⏳ PENDING | — | UI for `jetonomy_email_templates` option |
| A9 | Frontend `?tab=drafts` and `?tab=bookmarks` | ⏳ PENDING | — | User content views |
| A10 | `jetonomy_user_pending_verification` cron | ⏳ PENDING | — | Reminder emails for unverified users |
| A11 | Community visibility mode (public/private) | ⏳ PENDING | — | Foundational toggle: helper + setting + per-endpoint enforcement; runner passes in BOTH modes |

## Track B — Pro Plugin

| Phase | Package | Status | Sign-off | Notes |
|-------|---------|--------|----------|-------|
| B1 | White Label extension wiring | ✅ DONE | 2026-04-29 (`jetonomy-pro` 6c596ac) | 3 of 5 filters now actively consumed in Pro (`email_logo_url`, `email_accent_color`, `sidebar_auth_card`); the remaining 2 (`header_logo`, `footer_text`) are subscribed in Pro but not yet fired in free — see KG-1 in `jetonomy-pro/plan/1.4.1-baselines/B1-VERIFICATION.md` |
| B2 | Analytics dual-path aggregation | ⏳ PENDING | — | Validation alongside direct-query |
| B3 | Email Digest extension wiring | ⏳ PENDING | — | Event subscriptions |

---

## Critical Path & Dependencies

```
✅ A1 → ✅ A2 → ⏳ A3 ── security track (gated)
                      
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

