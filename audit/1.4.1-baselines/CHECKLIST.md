# 1.4.1 Release Gate Checklist

One row per work package. Every box must be ticked before tagging 1.4.1.

See [`../1.4.1-plan.md`](../1.4.1-plan.md) for scope and [`../1.4.1-safety-checks.md`](../1.4.1-safety-checks.md) for what each check verifies.

## Track A — Free plugin

- [ ] **A1** — REST audit verdict pass — `audit/REST_AUDIT.md` exists, 18 verdicts recorded
- [ ] **A2** — Manifest schema v2 — refresh shows new `auth`/`capability`/`ownership_check` fields, counts unchanged
- [ ] **A3** — REST security fixes — every 🚨 from A1 is closed, smoke green for owner/non-owner/anon test triple per route
- [ ] **A4** — `POST /moderation/bulk` — REST + AJAX parity verified, mod-only access enforced
- [ ] **A5** — `GET /posts/{id}/flags` — mod-only, returns array shape matching existing `/moderation/flags`
- [ ] **A6** — Activity Log admin page — loads <2s, pagination works, no JS errors, other admin pages unaffected
- [ ] **A7** — Revisions admin page — diff renders, non-mod cannot view others' revisions
- [ ] **A8** — Email Templates editor — save→reflect in option key, test email uses new copy, XSS-safe, default reset works
- [ ] **A9** — Frontend `?tab=drafts` + `?tab=bookmarks` — populated for authed user, login prompt for anon, existing tabs unbroken
- [ ] **A10** — Verification reminder cron — registered, sends once at T+24h, rate-limited, doesn't email verified users

## Track B — Pro plugin

- [ ] **B1** — White Label subscribes to 5 branding filters — visual change on enable, pixel-identical to baseline on disable
- [ ] **B2** — Analytics dual-path — `from_query` and `from_events` agree within ±1% for 7 consecutive days, OR ship with old path active and defer cutover to 1.5.0
- [ ] **B3** — Email Digest event subscriptions — badge/poll lines appear in next digest, no spurious additions otherwise

## Cross-cutting (run after every push to 1.4.1)

- [ ] `/jetonomy-smoke` — both FREE and FREE+PRO modes green
- [ ] PHPStan level 5 — 0 errors
- [ ] WPCS — 0 errors
- [ ] Manifest coverage gate — ≥95% per category
- [ ] No new entries in `wp-content/debug.log` during smoke

## Pre-merge to `main` / pre-tag

- [ ] All 13 package boxes above ticked
- [ ] `bin/build-release.sh --dry-run` accepts the working tree (clean-tree gate, version triangulation, source/min pairing, etc.)
- [ ] `audit/manifest.json` regenerated with schema v2; `audit/REST_AUDIT.md` shows zero 🚨 entries
- [ ] CHANGELOG.md entry written, references each package
- [ ] Smoke test passes against the built zip (not just the working tree)
