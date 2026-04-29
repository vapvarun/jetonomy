# A3 Work Brief — REST Security Fixes Verification

**Phase:** A3 (Track A, Free plugin)  
**Type:** Security verification and rate-limit confirmation  
**Risk:** MEDIUM (verifying auth endpoints; if rate-limits fail, those routes need hardening)  
**Assigned to:** wp-verifier (security lead)  
**Dependencies:** A1 (✅ done), A2 (✅ done)  
**Related Files:**
- `plan/REST_AUDIT.md` — audit verdicts for all 18 routes (read for context)
- `plan/REST_ACCESS_MATRIX.md` — **per-role × per-route expected response codes (the safety contract)**
- `plan/1.4.1-safety-checks.md` lines 66–101 — A3 safety checks
- `audit/manifest.json` — updated with schema v2 (reference for routes)

**A3 NEW DELIVERABLE (added 2026-04-29):** Build `bin/access-matrix-check.sh` — automated runner that walks every row of `plan/REST_ACCESS_MATRIX.md`, makes the HTTP call as each role (anon, subscriber, author, editor, mod, admin), and asserts the response code. Output is a pass/fail report. This is the **master safety net for every code change in 1.4.1** — must run before/after each commit.

Why this matters: A1's verdict was "code is safe" but it was code-reading. The runner verifies actual HTTP behavior against the documented matrix — catches regressions a code review can miss. Every other package (A4, A6, B1, etc.) will run this script before pushing.

---

## Context

Phase A1 audited 18 REST routes and classified them as:
- **✅ SAFE (14)**: Callback + handler properly gated
- **⚠️ LOGIN-ONLY (1)**: GET /bookmarks has `__return_true` callback but handler enforces login (documentation gap only)
- **🚨 OPEN (0)**: No routes found vulnerable to IDOR or capability bypass
- **ℹ️ EXPECTED (3)**: Auth endpoints (login, register, resend-verification) intentionally public with rate-limiting

Phase A2 updated the manifest schema to document auth, capability, and ownership checks per route.

**A3's job:** Verify that rate-limiting on public auth endpoints is actually working and that no new vulnerabilities were introduced during A1/A2.

---

## Tasks

### Task 1: Verify Auth Endpoint Rate-Limiting

**Affected routes:**
- `POST /auth/login`
- `POST /auth/register`
- `POST /auth/lost-password`
- `POST /auth/resend-verification`

**Check in code:**
1. [ ] Verify `check_rate_limit()` method exists in `includes/api/class-auth-controller.php` (or parent class)
2. [ ] Verify it's **IP-based** (not user-based — these are pre-login flows)
3. [ ] Verify it's called with correct action names:
   - `login` (likely 5–10 attempts per minute)
   - `register` (likely 2–5 per minute)
   - `lost-password` (likely 3–5 per minute)
   - `resend-verification` (likely 3 per 5 minutes)

**Reference:** `plan/REST_AUDIT.md` lines 449–544 document which routes call `check_rate_limit()` and which don't.

### Task 2: Verify Lost-Password Handler Exists & Works

The `lost_password()` handler implementation was not visible in the audit excerpt. Verify:
1. [ ] Method exists in `includes/api/class-auth-controller.php` (lines 600+)
2. [ ] It enforces rate-limiting via `check_rate_limit('lost-password')`
3. [ ] It returns a generic response to prevent account enumeration (does not leak whether email is registered)

**Reference:** `plan/REST_AUDIT.md` lines 479–491.

### Task 3: Verify GET /Bookmarks Callback Mismatch

`GET /bookmarks` has a documentation gap: the callback is `__return_true` but the handler calls `require_auth()`. Decide:

**Option A:** Fix callback to reflect reality (update permission_callback to `login_permission_check()`)  
**Option B:** Update manifest to document the gap without code change

**Recommendation:** Option A (code fix is trivial). Update callback to:
```php
'permission_callback' => function () { return is_user_logged_in() ? true : new WP_Error(...); }
```

Then update `audit/manifest.json` endpoint to set `auth: "login_required"` instead of `login_required_handler`.

**Reference:** `plan/REST_AUDIT.md` lines 374–387.

### Task 4: Smoke Test All Routes

1. [ ] Boot the site: `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo "WordPress loaded"'`
2. [ ] Run `wp jetonomy qa-actions` — all 210 smoke tests should pass
3. [ ] Run the global `/jetonomy-smoke` runner (if it exists) — should be green
4. [ ] Test browser: log in, create post, reply, vote, check bookmarks tab — no 500 errors

### Task 5: Document Findings

Capture per-route verification in `plan/1.4.1-baselines/A3/`:
1. [ ] Create `auth-endpoints-verification.txt` with:
   - `check_rate_limit()` method location (file + line)
   - IP-based or user-based (which)
   - Rate limit values per action
   - Any missing implementations
2. [ ] Create `lost-password-handler.txt` with handler code snippet and verification notes
3. [ ] If GET /bookmarks code was fixed, create `GET-bookmarks-fix.txt` with before/after

---

## Safety Check Procedure

**Before commit:**
```bash
# Capture baseline for auth endpoint rate-limiting
curl -s -X POST http://forums.local/wp-json/jetonomy/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin"}' \
  -o /dev/null -w "%{http_code}\n"
# Should return 200 (success) or 403 (invalid creds), NOT 429 (rate-limited) on first try
```

**After verification:**
```bash
# Verify rate-limit kicks in on repeated attempts
for i in {1..10}; do
  curl -s -X POST http://forums.local/wp-json/jetonomy/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"username":"test","password":"test"}' \
    -o /dev/null -w "Attempt $i: %{http_code}\n"
done
# Last attempts should show 429 (rate-limited)
```

Store results in `plan/1.4.1-baselines/A3/rate-limit-test.log`.

---

## Definition of Done

A3 is complete when:
1. ✅ `check_rate_limit()` implementation verified and documented
2. ✅ `lost_password()` handler verified
3. ✅ GET /bookmarks callback fixed (if needed) or documented
4. ✅ All 210 `wp jetonomy qa-actions` smoke tests pass
5. ✅ Browser smoke: POST, GET, PATCH, DELETE flows work for typical user + mod + admin
6. ✅ Rate-limiting test results logged
7. ✅ CHECKLIST.md updated with A3 sign-off

---

## Decision: Continue vs. Fix vs. Defer

| Finding | Action |
|---------|--------|
| Rate-limit is working, all tests pass | ✅ **CONTINUE** — move to A4 |
| Rate-limit call is missing or broken | 🚨 **FIX IN A3** — implement + re-test |
| Rate-limit is user-based instead of IP-based | 🚨 **FIX IN A3** — switch to IP-based |
| GET /bookmarks callback is inconsistent | ✅ **FIX IN A3** (trivial, low risk) or document if intentional |
| Unrelated bug found (not in audit scope) | 📌 **DEFER** — create Basecamp card, flag as out-of-scope |

**No route was flagged 🚨 OPEN in the audit, so no security fixes are expected. This phase is primarily verification and documentation cleanup.**

---

## Files to Update

After A3 is complete:
- `plan/1.4.1-baselines/A3/` — store verification logs and baselines
- `plan/1.4.1-baselines/CHECKLIST.md` — mark A3 as ✅ DONE
- `audit/manifest.json` — if GET /bookmarks callback was fixed, update `auth` field to `login_required`

---

## Time Estimate

1–2 days (mostly verification, minimal code changes expected).

---

**Start A3 after A2 sign-off. A4–A10 can run in parallel once A3 is verified.**

