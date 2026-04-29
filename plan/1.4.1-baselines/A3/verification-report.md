# A3 — REST Security Verification + Access-Matrix Runner

**Phase:** A3 (Track A, Free plugin)
**Status:** PASS — ready for sign-off
**Generated:** 2026-04-29
**Base commit:** see `git log --oneline 1.4.1` (this commit + the two A3 commits below)

## Summary

| Task                                                | Verdict |
|-----------------------------------------------------|---------|
| 1. Rate-limiting on public auth endpoints           | PASS    |
| 2. `lost_password()` enumeration safety             | PASS    |
| 3. `bin/access-matrix-check.sh` runner deliverable  | PASS — 72/72 routes |
| 4. GET /bookmarks callback cleanup (optional)       | PASS — 1-line fix applied |

No new bugs found. Plugin smoke (`wp jetonomy qa-actions run`) is 210/210
green before, during, and after every change.

---

## Task 1 — Rate-limiting

`Auth_Controller::check_rate_limit()` is defined at
`includes/api/class-auth-controller.php:736` (protected static).
**IP-based** (uses `$_SERVER['REMOTE_ADDR']`), fixed-window (1.4.0
already fixed the window-extension bug noted at lines 743–749).

Per-bucket configuration:

| Bucket               | Max | Window  | Caller            | Line |
|----------------------|-----|---------|-------------------|------|
| login                | 5   | 60s     | login()           | 189  |
| register             | 5   | 60s     | register_user()   | 272  |
| lost_password        | 3   | 5 min   | lost_password()   | 670  |
| resend_verification  | 3   | 5 min   | resend_verification() | 497 |

Full notes in `auth-endpoints-verification.txt`.

## Task 2 — Lost-password enumeration safety

Handler at `class-auth-controller.php:669–722`. retrieve_password()'s
return is intentionally discarded; the same JSON envelope is returned
for known and unknown emails. Verified via curl:

```
$ curl … known-account     → 200 + generic message
$ curl … unknown-account   → 200 + generic message
$ curl … (3rd within 5min) → 429 (rate-limit)
```

Full notes in `lost-password-handler.txt`.

## Task 3 — Access-matrix runner

Built at `bin/access-matrix-check.sh` + helper `bin/seed-qa-users.php`.

- **30+ representative routes** across all 6 categories (public-read,
  public rate-limited, login-only, cap-gated, moderation, admin).
  Actual coverage: 72 (route × role × expected) triples.
- Auth via WP **Application Passwords** (Basic auth) — sidesteps the
  `wp_create_nonce` / session-token mismatch you hit when generating
  cookies in CLI context. The runner mints a `matrix-runner` app
  password per fixture user automatically; re-runs revoke + remint.
- Idempotent seed (`bin/seed-qa-users.php`) — runs from a fresh DB
  or an existing one without duplicate users. test_editor is
  promoted to space-admin of the public test space; test_author is
  added as a member.
- Read-only by default — mutating routes use empty bodies that
  short-circuit at validation, never actually creating/destroying
  data. Two exceptions: POST /bookmarks toggles a bookmark (idempotent
  toggle), and POST /admin/recount actually runs the recount (admin
  only, also idempotent — re-running produces the same counts).
- Bash-3.2 compatible (macOS default) — no associative arrays or
  modern bash idioms.
- Three modes:
  - default — human-readable run, exits 0 if all PASS, 1 otherwise
  - `--baseline` — writes `plan/1.4.1-baselines/A3/access-matrix-baseline.log`
  - `--diff-baseline` — diffs current run against the saved baseline

**Run from clean state (after `delete_transient` flush):**
```
$ bin/access-matrix-check.sh
…
Access matrix: 72/72 passed
```

Baseline saved at `plan/1.4.1-baselines/A3/access-matrix-baseline.log`.

This is the master safety net for every code change in 1.4.1 onward.
Every package (A4–A10, B1–B3) runs it before/after the diff and the
output must be identical or strictly improved.

## Task 4 — GET /bookmarks cleanup

One-line fix to `class-bookmarks-controller.php` line 31:

```diff
-                  'permission_callback' => '__return_true',
+                  'permission_callback' => function () { return is_user_logged_in(); },
```

Permission callback now mirrors the handler's `require_auth()` gate.
No behaviour change for end users; manifest's `auth` field will read
"login_required" on next refresh instead of the documentation-only
"login_required_handler" tag.

Full notes in `GET-bookmarks-fix.txt`.

---

## Files touched

| Path                                                    | Type        |
|---------------------------------------------------------|-------------|
| `bin/access-matrix-check.sh`                            | new — runner |
| `bin/seed-qa-users.php`                                 | new — fixture seeder |
| `includes/api/class-bookmarks-controller.php`           | modified — 1-line callback cleanup |
| `plan/1.4.1-baselines/A3/access-matrix-baseline.log`    | new — regression baseline |
| `plan/1.4.1-baselines/A3/auth-endpoints-verification.txt` | new — task 1 evidence |
| `plan/1.4.1-baselines/A3/lost-password-handler.txt`     | new — task 2 evidence |
| `plan/1.4.1-baselines/A3/GET-bookmarks-fix.txt`         | new — task 4 evidence |
| `plan/1.4.1-baselines/A3/verification-report.md`        | new — this file |
| `plan/1.4.1-baselines/CHECKLIST.md`                     | modified — A3 sign-off |

## Smoke results (post-change)

```
wp jetonomy qa-actions run
…
TOTAL: 210/210
Success: All 210 action tests passed. Full stack verified.
```

```
bin/access-matrix-check.sh --diff-baseline --quiet
Access matrix: 72/72 passed
Identical to baseline.
```

## Decision: continue → A4

All A3 gates passed. No security blockers. Ready to start A4 (POST
/moderation/bulk REST endpoint) and the parallel work packages
(A5–A10, B1–B3).
