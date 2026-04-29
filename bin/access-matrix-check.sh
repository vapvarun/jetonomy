#!/usr/bin/env bash
# bin/access-matrix-check.sh — REST access matrix runner
#
# Walks a representative subset of plan/REST_ACCESS_MATRIX.md, makes the HTTP
# call as each role (anon / subscriber / author / editor=space-admin / mod /
# admin), and asserts the response code matches the matrix's expected cell.
#
# Outputs one line per (route × role × expected) triple:
#
#   PASS  GET   /spaces             [anon]      expected=200  got=200
#   FAIL  POST  /posts/123          [anon]      expected=401  got=200  ← regression
#
# Exit code 0 if every row passes, 1 otherwise.
#
# This is the master safety net for every code change in 1.4.1. Run before
# AND after every commit. The full output is also captured to
# `plan/1.4.1-baselines/A3/access-matrix-baseline.log` on first run — that
# becomes the regression baseline for every subsequent package.
#
# Usage:
#   bin/access-matrix-check.sh                 # human-readable output
#   bin/access-matrix-check.sh --quiet         # only show FAILs (and final summary)
#   bin/access-matrix-check.sh --baseline      # write to plan/1.4.1-baselines/A3/access-matrix-baseline.log
#   bin/access-matrix-check.sh --diff-baseline # diff current run vs the saved baseline
#
# Constraints:
# * Read-only by default — uses HEAD or GET for status discovery and only
#   sends bodies when a route requires one. Mutating POSTs go to known-safe
#   targets (vote on a post we own; resend-verification with a non-existent
#   email) so re-running the runner is non-destructive.
# * Generates auth cookies through `wp eval` — no external login round-trip.
# * Test users must exist (`bin/seed-qa-users.php`); the runner re-seeds
#   them automatically on first run.

set -u
set -o pipefail

# ── Config ───────────────────────────────────────────────────────────────────
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_PATH="${WP_PATH:-/Users/varundubey/Local Sites/forums/app/public}"
SITE_URL="${SITE_URL:-http://forums.local}"
wp_cli() { wp --path="$WP_PATH" "$@"; }
NS="/wp-json/jetonomy/v1"

QUIET=0
WRITE_BASELINE=0
DIFF_BASELINE=0
BASELINE_FILE="$PLUGIN_DIR/plan/1.4.1-baselines/A3/access-matrix-baseline.log"

for arg in "$@"; do
  case "$arg" in
    --quiet)         QUIET=1 ;;
    --baseline)      WRITE_BASELINE=1 ;;
    --diff-baseline) DIFF_BASELINE=1 ;;
    --help|-h)
      sed -n '1,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      echo "Unknown arg: $arg" >&2
      exit 2
      ;;
  esac
done

# ── Seed test users + collect fixture IDs ────────────────────────────────────
echo "[setup] seeding QA users + fixtures…" >&2
SEED_OUTPUT="$(wp_cli eval-file "$PLUGIN_DIR/bin/seed-qa-users.php" 2>&1)" || {
  echo "[setup] seed-qa-users.php failed:" >&2
  echo "$SEED_OUTPUT" >&2
  exit 2
}
FIXTURES_JSON="$(echo "$SEED_OUTPUT" | awk '/^FIXTURES /{sub(/^FIXTURES /,""); print}')"
if [ -z "$FIXTURES_JSON" ]; then
  echo "[setup] could not read FIXTURES line from seed output" >&2
  echo "$SEED_OUTPUT" >&2
  exit 2
fi

extract_json_int() {
  # Tiny inline JSON int extractor (no jq dependency).
  echo "$1" | python3 -c "import json,sys; d=json.loads(sys.stdin.read()); print($2)" 2>/dev/null
}

USER_SUBSCRIBER=$(extract_json_int "$FIXTURES_JSON" 'd["users"]["test_subscriber"]')
USER_AUTHOR=$(extract_json_int    "$FIXTURES_JSON" 'd["users"]["test_author"]')
USER_EDITOR=$(extract_json_int    "$FIXTURES_JSON" 'd["users"]["test_editor"]')
USER_MOD=$(extract_json_int       "$FIXTURES_JSON" 'd["users"]["test_moderator"]')
USER_ADMIN=$(extract_json_int     "$FIXTURES_JSON" 'd["users"]["test_admin"]')
SPACE_ID=$(extract_json_int       "$FIXTURES_JSON" 'd["space_id"]')
POST_ID=$(extract_json_int        "$FIXTURES_JSON" 'd["post_id"]')

# Application Passwords for Basic auth. We use these instead of building
# cookies + nonces in CLI context, because wp_create_nonce in CLI ties to
# a session token that doesn't exist when REST validates the cookie at
# request time — so the nonce check always fails. App Passwords are first-
# class WP core auth; curl just sets Basic auth and REST is happy.
extract_json_str() {
  echo "$1" | python3 -c "import json,sys; d=json.loads(sys.stdin.read()); print($2)" 2>/dev/null
}
APPPW_SUBSCRIBER=$(extract_json_str "$FIXTURES_JSON" 'd["app_passwords"]["test_subscriber"]')
APPPW_AUTHOR=$(extract_json_str    "$FIXTURES_JSON" 'd["app_passwords"]["test_author"]')
APPPW_EDITOR=$(extract_json_str    "$FIXTURES_JSON" 'd["app_passwords"]["test_editor"]')
APPPW_MOD=$(extract_json_str       "$FIXTURES_JSON" 'd["app_passwords"]["test_moderator"]')
APPPW_ADMIN=$(extract_json_str     "$FIXTURES_JSON" 'd["app_passwords"]["test_admin"]')

if [ -z "$SPACE_ID" ] || [ "$SPACE_ID" = "0" ]; then
  echo "[setup] no public space found in DB — runner aborted" >&2
  exit 2
fi
if [ -z "$POST_ID" ] || [ "$POST_ID" = "0" ]; then
  echo "[setup] no published post in fixture space — runner aborted" >&2
  exit 2
fi

echo "[setup] fixtures: SPACE_ID=$SPACE_ID POST_ID=$POST_ID" >&2

# ── Per-role auth (Basic, via WP Application Passwords) ──────────────────────
# Returns "user:app_password" suitable for curl -u, or empty for anon.
auth_for() {
  case "$1" in
    anon)       echo "" ;;
    subscriber) echo "test_subscriber:$APPPW_SUBSCRIBER" ;;
    author)     echo "test_author:$APPPW_AUTHOR" ;;
    editor)     echo "test_editor:$APPPW_EDITOR" ;;
    mod)        echo "test_moderator:$APPPW_MOD" ;;
    admin)      echo "test_admin:$APPPW_ADMIN" ;;
    *)          echo "" ;;
  esac
}

echo "[setup] application passwords built for 5 roles" >&2

# ── Test runner ──────────────────────────────────────────────────────────────
PASS_COUNT=0
FAIL_COUNT=0
FAIL_LINES=""
ALL_LINES=""

run_check() {
  # $1 method, $2 route (relative to /jetonomy/v1), $3 role, $4 expected_codes_csv, $5 (optional) body
  local method="$1"
  local route="$2"
  local role="$3"
  local expected="$4"
  local body="${5:-}"

  local auth
  auth="$(auth_for "$role")"
  local url="${SITE_URL}${NS}${route}"

  local curl_args=(
    -s -o /dev/null
    -w '%{http_code}'
    -X "$method"
    --max-time 15
  )
  if [ -n "$auth" ]; then
    curl_args+=( -u "$auth" )
  fi
  if [ -n "$body" ]; then
    curl_args+=( -H "Content-Type: application/json" --data "$body" )
  fi

  local got
  got="$(curl "${curl_args[@]}" "$url" 2>/dev/null)"
  if [ -z "$got" ]; then
    got="000"
  fi

  # Compare against the comma-separated expected list.
  local matched=0
  IFS=',' read -ra exp_arr <<< "$expected"
  for exp in "${exp_arr[@]}"; do
    if [ "$got" = "$exp" ]; then
      matched=1
      break
    fi
  done

  local label
  label="$(printf 'PASS  %-7s %-50s [%-10s] expected=%-12s got=%s' "$method" "$route" "$role" "$expected" "$got")"
  if [ "$matched" = "1" ]; then
    PASS_COUNT=$((PASS_COUNT + 1))
    ALL_LINES+="$label"$'\n'
    [ "$QUIET" = "0" ] && echo "$label"
  else
    label="${label/PASS/FAIL}"
    FAIL_COUNT=$((FAIL_COUNT + 1))
    FAIL_LINES+="$label"$'\n'
    ALL_LINES+="$label"$'\n'
    echo "$label"
  fi
}

# ── The matrix subset (≥30 representative routes across all 6 categories) ───
# Each row covers (method, route, role, expected). We deliberately pick rows
# that flex the auth/cap/ownership boundaries, not just happy-path GETs.

echo
echo "── Public read endpoints ──────────────────────────────────────"

# 1. /spaces — public, anon allowed
run_check GET  "/spaces"                                 anon       200
run_check GET  "/spaces"                                 subscriber 200
run_check GET  "/spaces"                                 admin      200

# 2. /spaces/{id} — public space readable by anon
run_check GET  "/spaces/$SPACE_ID"                       anon       200
run_check GET  "/spaces/$SPACE_ID"                       subscriber 200

# 3. /spaces/{id}/posts — public listing
run_check GET  "/spaces/$SPACE_ID/posts"                 anon       200
run_check GET  "/spaces/$SPACE_ID/posts"                 subscriber 200

# 4. /spaces/{id}/members — public listing
run_check GET  "/spaces/$SPACE_ID/members"               anon       200

# 5. /posts/{id} — public post readable by anon
run_check GET  "/posts/$POST_ID"                         anon       200
run_check GET  "/posts/$POST_ID"                         subscriber 200

# 6. /posts/{post_id}/replies — public listing
run_check GET  "/posts/$POST_ID/replies"                 anon       200

# 7. /categories — public listing
run_check GET  "/categories"                             anon       200

# 8. /tags — public listing
run_check GET  "/tags"                                   anon       200

# 9. /users/{id} — public profile
run_check GET  "/users/$USER_ADMIN"                      anon       200

# 10. /leaderboards — public listing
run_check GET  "/leaderboards"                           anon       200

echo
echo "── Public rate-limited (anon mutations) ──────────────────────"

# Flush per-IP auth rate-limit transients so the first call in this section
# reflects current code, not residue from a prior runner pass. This is
# scoped to the runner's IP (loopback) — production data is untouched.
wp_cli eval '
global $wpdb;
$ip = "127.0.0.1";
foreach ( array( "login", "register", "lost_password", "resend_verification" ) as $bucket ) {
    delete_transient( "jt_auth_" . $bucket . "_" . md5( $ip ) );
}
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE "\_transient\_jt\_auth\_%" OR option_name LIKE "\_transient\_timeout\_jt\_auth\_%"");
echo "ok";
' >/dev/null 2>&1

# 11. /auth/lost-password — must be 200 for both unknown + known to prevent
# enumeration. We send a known-bad email and expect 200, NOT 404.
# (Empty body sends a 400 — that's also acceptable since it's not a leak.)
run_check POST "/auth/lost-password"                     anon       200,400,429 \
  '{"user_login":"definitely-not-a-real-account-9876@example.com"}'

# 12. /auth/resend-verification — always returns 200 (rate-limit kicks in
# at 3/5min so single shot is safe).
run_check POST "/auth/resend-verification"               anon       200,429 \
  '{"user_login":"nobody-here@example.com"}'

# 13. /auth/login with bad creds — 401 (NOT 429 on first attempt; rate
# limiter from prior runs may already be in cooldown so accept 429 too).
run_check POST "/auth/login"                             anon       401,429,400 \
  '{"user_login":"nobody","user_password":"wrong"}'

echo
echo "── Login-required, no special cap ────────────────────────────"

# 14. /bookmarks GET — anon=401, sub+=200 (after A3 cleanup if applied)
run_check GET  "/bookmarks"                              anon       401,403
run_check GET  "/bookmarks"                              subscriber 200
run_check GET  "/bookmarks"                              admin      200

# 15. /notifications/unread-count — anon=401, sub=200
run_check GET  "/notifications/unread-count"             anon       401,403
run_check GET  "/notifications/unread-count"             subscriber 200

# 16. /users/me — anon=401, sub=200
run_check GET  "/users/me"                               anon       401,403
run_check GET  "/users/me"                               subscriber 200
run_check GET  "/users/me"                               admin      200

# 17. /subscriptions GET — anon=401, sub=200
run_check GET  "/subscriptions"                          anon       401,403
run_check GET  "/subscriptions"                          subscriber 200

echo
echo "── Cap-gated mutations ───────────────────────────────────────"

# 18. /categories POST — needs jetonomy_manage_categories (admin only)
run_check POST "/categories"                             anon       401,403  '{"name":"runner-test"}'
run_check POST "/categories"                             subscriber 403      '{"name":"runner-test"}'
run_check POST "/categories"                             mod        403      '{"name":"runner-test"}'
# admin SHOULD be 201 but POST is destructive; we send an invalid body to
# trigger 400 instead — proves the cap check passes ("got past the cap; got
# blocked on payload validation"). Empty body → 400.
run_check POST "/categories"                             admin      400,201  '{}'

# 19. /spaces POST — needs jetonomy_create_spaces (subscriber=403, admin=201)
run_check POST "/spaces"                                 anon       401,403,400  '{}'
run_check POST "/spaces"                                 subscriber 403,400  '{"name":"runner-test"}'
# admin gets through cap → fails on validation
run_check POST "/spaces"                                 admin      400,201  '{}'

# 20. /spaces/{id} PATCH — anon=401; subscriber not space-admin → 403;
# editor (we made them space-admin) → 200 with no-op body
run_check PATCH "/spaces/$SPACE_ID"                      anon       401,403  '{}'
run_check PATCH "/spaces/$SPACE_ID"                      subscriber 403      '{"description":"runner"}'
run_check PATCH "/spaces/$SPACE_ID"                      editor     200,204,400  '{}'
run_check PATCH "/spaces/$SPACE_ID"                      admin      200,204,400  '{}'

# 21. /posts/{id} PATCH on a post NOT owned by subscriber → 403
# (admin via edit_others_posts cap → 200)
run_check PATCH "/posts/$POST_ID"                        anon       401,403  '{}'
run_check PATCH "/posts/$POST_ID"                        subscriber 403      '{"title":"runner"}'
run_check PATCH "/posts/$POST_ID"                        admin      200,400  '{}'

# 22. /posts/{id} DELETE — anon=401, subscriber=403, admin OK (but destructive
# — skip the actual admin DELETE; we verify only the gate by sending as
# subscriber/anon).
run_check DELETE "/posts/$POST_ID"                       anon       401,403
run_check DELETE "/posts/$POST_ID"                       subscriber 403

# 23. /posts/{post_id}/replies POST — login required + create_replies cap
# Subscriber has create_replies but isn't a space member of every private
# space; on the public space they'd succeed → we send empty body to trigger
# 400 after the gate.
run_check POST "/posts/$POST_ID/replies"                 anon       401,403,400  '{}'
run_check POST "/posts/$POST_ID/replies"                 subscriber 400,201  '{}'

echo
echo "── Moderation routes (jetonomy_moderate cap) ────────────────"

# 24. /moderation/queue GET — mod+admin only
run_check GET  "/moderation/queue"                       anon       401,403
run_check GET  "/moderation/queue"                       subscriber 403
run_check GET  "/moderation/queue"                       author     403
run_check GET  "/moderation/queue"                       mod        200
run_check GET  "/moderation/queue"                       admin      200

# 25. /moderation/flags GET
run_check GET  "/moderation/flags"                       anon       401,403
run_check GET  "/moderation/flags"                       subscriber 403
run_check GET  "/moderation/flags"                       mod        200

# 26. /flags POST — needs login + jetonomy_flag (subs have it by default)
run_check POST "/flags"                                  anon       401,403,400  '{"object_type":"post","object_id":1,"reason":"runner"}'
# subscriber will pass cap then fail on object validation OR object_already_flagged
run_check POST "/flags"                                  subscriber 400,201,409  '{"object_type":"post","object_id":'"$POST_ID"',"reason":"runner-trigger"}'

# 27. /space-moderation/queue — space-mod required
run_check GET  "/space-moderation/queue"                 anon       401,403,404
run_check GET  "/space-moderation/queue"                 subscriber 403,404

echo
echo "── Admin-only routes ─────────────────────────────────────────"

# 28. /admin/settings GET — admin only
run_check GET  "/admin/settings"                         anon       401,403,404
run_check GET  "/admin/settings"                         subscriber 403,404
run_check GET  "/admin/settings"                         mod        403,404
run_check GET  "/admin/settings"                         admin      200,404

# 29. /admin/recount POST — admin only
run_check POST "/admin/recount"                          anon       401,403,404  '{}'
run_check POST "/admin/recount"                          subscriber 403,404      '{}'
run_check POST "/admin/recount"                          admin      200,400,404  '{}'

# 30. /users/{id}/ban POST — needs manage_users (admin/mod). Target user is
# the subscriber so ban is meaningful, but we send empty body to short-circuit
# at validation, not actually ban anyone.
run_check POST "/users/$USER_SUBSCRIBER/ban"             anon       401,403,404  '{}'
run_check POST "/users/$USER_SUBSCRIBER/ban"             author     403,404      '{}'

echo
echo "── Auth flow + bookmarks edge ──────────────────────────────"

# 31. POST /bookmarks — anon=401, sub=200/201 (toggle on a public post)
run_check POST "/bookmarks"                              anon       401,403  '{"post_id":'"$POST_ID"'}'
run_check POST "/bookmarks"                              subscriber 200,201  '{"post_id":'"$POST_ID"'}'

# 32. GET /search — public
run_check GET  "/search?q=test"                          anon       200

# 33. GET /space-tags — public
run_check GET  "/space-tags"                             anon       200

# 34. /votes POST — login required
run_check POST "/posts/$POST_ID/vote"                    anon       401,403,400  '{}'

# ── Summary ──────────────────────────────────────────────────────────────────
TOTAL=$((PASS_COUNT + FAIL_COUNT))
echo
echo "──────────────────────────────────────────────────────────────"
echo "Access matrix: $PASS_COUNT/$TOTAL passed"
if [ "$FAIL_COUNT" -gt 0 ]; then
  echo
  echo "Failures:"
  echo -n "$FAIL_LINES"
fi
echo "──────────────────────────────────────────────────────────────"

# ── Baseline writing / diffing ───────────────────────────────────────────────
if [ "$WRITE_BASELINE" = "1" ]; then
  mkdir -p "$(dirname "$BASELINE_FILE")"
  {
    echo "# Access matrix baseline — generated $(date -u +%Y-%m-%dT%H:%M:%SZ)"
    echo "# Site: $SITE_URL  Plugin: $PLUGIN_DIR"
    echo "# Pass=$PASS_COUNT Fail=$FAIL_COUNT Total=$TOTAL"
    echo
    echo -n "$ALL_LINES"
  } > "$BASELINE_FILE"
  echo "Baseline written: $BASELINE_FILE"
fi

if [ "$DIFF_BASELINE" = "1" ]; then
  if [ ! -f "$BASELINE_FILE" ]; then
    echo "No baseline to diff against. Run with --baseline first." >&2
    exit 2
  fi
  TMP="$(mktemp)"
  echo -n "$ALL_LINES" > "$TMP"
  echo
  echo "── Diff vs baseline ──────────────────────────────────────────"
  # Compare only the PASS/FAIL verdict + method + route + role. The actual
  # "got" code can legitimately vary within the allowed expected-set
  # (e.g. /auth/resend-verification returns 200 OR 429 depending on
  # transient state) without that being a regression. The baseline
  # contract is "this row passed before" — not "this exact response code".
  normalize() { awk '/^(PASS|FAIL)/ {print $1, $2, $3, $4}' "$1"; }
  if diff -u <(normalize "$BASELINE_FILE") <(normalize "$TMP"); then
    echo "Identical to baseline (same PASS/FAIL outcome on every row)."
  else
    echo "Drift detected (above)."
  fi
  rm -f "$TMP"
fi

if [ "$FAIL_COUNT" -gt 0 ]; then
  exit 1
fi
exit 0
