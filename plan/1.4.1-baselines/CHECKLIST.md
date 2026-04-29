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

## Track B — Pro Plugin

| Phase | Package | Status | Sign-off | Notes |
|-------|---------|--------|----------|-------|
| B1 | White Label extension wiring | ⏳ PENDING | — | 5 branding filters |
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

