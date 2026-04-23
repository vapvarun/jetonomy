# Agent Smoke Runbook — Jetonomy Pre-Release

**Audience: a browser-capable agent (Claude Sonnet / equivalent) with Playwright MCP + WP-CLI Bash access.**

This runbook is deterministic: each step has an exact action and exact assertion. A successful walk ends with a JSON summary: `{ "passed": N, "failed": 0, ... }`. Any failure halts and emits a Basecamp-ready bug report.

## Global preconditions

- Working directory: `/Users/varundubey/Local Sites/forums/app/public`
- WP-CLI command template: `wp --path="$WP_PATH" <cmd>` where `$WP_PATH` is the working directory
- Site URL: `http://forums.local`
- Admin auto-login: append `?autologin=1` to any front-end URL to log in as admin (user ID 1)
- Per-user auto-login: `?autologin=<user_login>` logs in as that user
- Playwright browser: reuse one Chromium session throughout. Reopen with `browser_navigate` after any `browser_close`.
- Debug log: `wp-content/debug.log`

## Agent output contract

At the end of the walk, emit exactly one JSON object of shape:

```json
{
  "release_version": "<read from JETONOMY_VERSION constant>",
  "ran_at": "<ISO timestamp>",
  "sections": {
    "A_fresh_install": { "pass": N, "fail": N, "skipped": N },
    "B_upgrade": { ... },
    "C_core_flows": { ... },
    "D_regression_guards": { ... },
    "E_pro_smoke": { ... },
    "F_cross_browser": { ... }
  },
  "failures": [
    { "id": "D1.profile-av", "expected": "...", "actual": "...", "url": "...", "screenshot": "<path>" }
  ]
}
```

If `failed > 0`, also emit a Basecamp card draft:

```
### Bug: <failed step id>
**Environment:** Jetonomy <version>, Chromium, <viewport>px
**Expected:** <from runbook>
**Actual:** <measured value / error>
**URL:** <tested URL>
**Screenshot:** <attached file path>
**Steps:** <runbook step reference>
```

---

## Setup fixtures (run before Section A)

```bash
# Ensure a known starting state — reset any prior test fixtures
wp --path="$WP_PATH" eval '
global $wpdb;
// Delete any E2E test posts from prior runs
$wpdb->query("DELETE FROM wp_jt_posts WHERE title LIKE \"E2E %\" OR title LIKE \"Smoke %\"");
$wpdb->query("DELETE FROM wp_jt_replies WHERE content_plain LIKE \"smoke-%\" OR content_plain LIKE \"e2e-%\"");
wp_cache_flush();
echo "fixtures cleaned\n";
'
```

## Debug log monitoring (enable BEFORE Section A, check AFTER every section)

WP_DEBUG + WP_DEBUG_LOG must be ON for the entire walk. Any new warning,
notice, or fatal written to `wp-content/debug.log` during a section counts
as a FAILURE — even if the UI looks fine. Silent errors are the ones that
ship and break customer sites.

### Pre-walk — enable debug, baseline the log

```bash
# 1. Turn WP_DEBUG on if not already. Snapshot current state first so we can restore.
wp --path="$WP_PATH" eval '
$wp_config = file_get_contents(ABSPATH . "wp-config.php");
$had_debug_true = strpos($wp_config, "define( \"WP_DEBUG\", true );") !== false;
echo "wp_debug_was_on:" . ($had_debug_true ? "yes" : "no") . "\n";
'

# 2. Ensure WP_DEBUG + WP_DEBUG_LOG + WP_DEBUG_DISPLAY=false are set.
# If the site-specific Local mu-plugin already does this, skip.
# Otherwise use the following search-replace pattern:
wp --path="$WP_PATH" eval '
$file = ABSPATH . "wp-config.php";
$contents = file_get_contents($file);
$needs_write = false;
foreach (["WP_DEBUG" => "true", "WP_DEBUG_LOG" => "true", "WP_DEBUG_DISPLAY" => "false"] as $k => $v) {
  if (!preg_match("/define\\(\\s*[\"\']" . $k . "[\"\'].*?\\);/s", $contents)) {
    $contents = preg_replace("/\\/\\* That\\'s all, stop editing!/", "define( \"$k\", $v );\n/* That\\\"s all, stop editing!", $contents);
    $needs_write = true;
  } else if (!preg_match("/define\\(\\s*[\"\']" . $k . "[\"\']\\s*,\\s*" . preg_quote($v, "/") . "\\s*\\);/", $contents)) {
    $contents = preg_replace("/define\\(\\s*[\"\']" . $k . "[\"\'].*?\\);/s", "define( \"$k\", $v );", $contents);
    $needs_write = true;
  }
}
if ($needs_write) { file_put_contents($file, $contents); echo "wp-config updated\n"; }
else { echo "wp-config already ok\n"; }
'

# 3. Baseline the debug log — size before the walk starts.
BASELINE_SIZE=$(wc -c < "$WP_PATH/wp-content/debug.log" 2>/dev/null || echo 0)
echo "debug_log_baseline_bytes:$BASELINE_SIZE"
```

### After each section — diff new entries

```bash
# Run after every numbered section. Any new warning/error/fatal = FAILURE.
tail -c +$((BASELINE_SIZE + 1)) "$WP_PATH/wp-content/debug.log" 2>/dev/null \
  | grep -vE "^\s*$|^\[cli\]" \
  | tee "/tmp/smoke-new-log-section-<SECTION>.txt"

# Classify any non-empty diff into failures:
# - "Fatal error:" → critical, blocks release
# - "Warning:" / "Notice:" / "Deprecated:" → failure unless whitelisted
# - Anything else (info, cron debug prints) → warn only, don't block
```

### Post-walk — restore debug state (only if it wasn't already on)

```bash
# Restore debug if we turned it on ourselves (don't touch if the dev had it on)
# and archive the section of the log that belongs to this walk.
ARCHIVE="$WP_PATH/wp-content/plugins/jetonomy/docs/qa/.debug-log-<release_version>-<ran_at>.txt"
tail -c +$((BASELINE_SIZE + 1)) "$WP_PATH/wp-content/debug.log" > "$ARCHIVE"
echo "archived walk-window log to $ARCHIVE"
```

### Report shape addition

Add `debug_log_issues` to the output JSON:

```json
{
  "debug_log_issues": [
    { "section": "C5", "level": "fatal", "line": "PHP Fatal error:  Uncaught TypeError ...", "file": "class-foo.php:123" },
    { "section": "G4", "level": "warning", "line": "PHP Warning: Undefined array key 'x'", "file": "class-bar.php:45" }
  ]
}
```

If `debug_log_issues` has any entry of level `fatal`, block the release.
If only `warning` / `notice` / `deprecated`, block unless explicitly whitelisted in this repo.

---

## A — Fresh install (skip if tests on existing install)

**Skip this section if the site is a live dev environment. Run only on a dedicated fresh-install test site.**

### A1 — Activation rewrite flush
1. Via WP-CLI: `wp plugin deactivate jetonomy && wp option delete rewrite_rules && wp option delete jetonomy_permalinks_flushed_<VERSION> && wp plugin activate jetonomy`
2. Via Playwright: `browser_navigate("http://forums.local/community/s/welcome/?_cb=freshA1")`
3. Read response status via `browser_network_requests` — the main document request must be **200**.
4. Read `wp option get rewrite_rules` — must contain a pattern with `jetonomy_route=`.

**Pass:** HTTP 200 + rewrite_rules contains jetonomy routes. **Fail:** 404 on the first request.

### A2 — DB version bumped on activation
1. Run `wp option get jetonomy_db_version`.
2. Run `wp eval 'echo JETONOMY_DB_VERSION;'`.

**Pass:** both values equal.

---

## B — Upgrade from previous version

**Skip if no previous version is installed.** This requires a site that was already on `<previous stable>` before the new zip was activated.

### B1 — Migration runs silently
1. `wp option get jetonomy_db_version` should equal new `JETONOMY_DB_VERSION`.
2. `wp db query "SELECT COUNT(*) FROM wp_jt_space_members"` must be ≥ 1.
3. `wp db query "SELECT id, member_count FROM wp_jt_spaces WHERE member_count > 0 LIMIT 5"` — each space with posts should show member_count ≥ 1, not stuck at 1 across all.

**Pass:** migration completed, space_members populated, member_count realistic.

---

## C — Core user flows (as admin via `?autologin=1`)

### C1 — Anonymous visitor smoke
1. `browser_evaluate("() => document.cookie.split(';').forEach(c => document.cookie = c.split('=')[0].trim() + '=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/;'); location.reload();")` to clear cookies
2. `browser_navigate("http://forums.local/community/")` (no autologin)
3. `browser_evaluate("() => ({ postLinks: document.querySelectorAll('a[href*=\"/community/s/\"]').length, errors: (window.console?.errors || []).length }))")`

**Pass:** postLinks > 0, no console errors.

### C2 — Login block OS-dark respect
1. `browser_run_code("async (page) => { await page.emulateMedia({ colorScheme: 'dark' }); }")` to force OS-dark
2. Create a test page with login block: `wp post create --post_title='Smoke login' --post_content='<!-- wp:jetonomy/login /-->' --post_status=publish --porcelain` → capture `<pageId>`
3. `browser_navigate("http://forums.local/?p=<pageId>")` (no autologin so we stay logged-out)
4. `browser_evaluate("() => getComputedStyle(document.querySelector('.jt-login-block')).backgroundColor")`

**Pass:** returns `rgb(255, 255, 255)` (light). **Fail:** returns a dark color.

**Teardown:** `wp post delete <pageId> --force`. Restore color scheme: `browser_run_code("async (page) => { await page.emulateMedia({ colorScheme: 'light' }); }")`.

### C3 — Share dropdown lifecycle
1. `browser_navigate("http://forums.local/community/s/welcome/t/welcome-to-our-community-heres-how-it-works/?autologin=1")`
2. Click `.jt-share-btn`, wait 300ms.
3. Assert `.jt-share-dropdown` exists and its top is within 10px of `(.jt-share-btn.getBoundingClientRect().bottom + 4)`.
4. `window.scrollBy(0, 200)` + wait 500ms.
5. Assert `.jt-share-dropdown` is no longer in DOM.

**Pass:** dropdown opens anchored, closes on scroll.

### C4 — Scheduled post with HH:MM select picker
1. Navigate to `/community/s/welcome/new/?autologin=1`, wait for composer
2. Click `.jt-publish-mode__toggle`, then click the option whose text matches `/schedule/i`
3. Verify `select[name="published_hour"]` has 25 options (including placeholder)
4. Verify `select[name="published_minute"]` has 13 options (placeholder + 00,05,10,...,55)
5. Via REST (not UI, to avoid IAPI click complexities):
   ```
   wp eval 'wp_set_current_user(1); $r = new WP_REST_Request("POST","/jetonomy/v1/spaces/1/posts"); $r->set_param("title","Smoke scheduled " . time()); $r->set_param("content","<p>body</p>"); $r->set_param("type","topic"); $r->set_param("status","draft"); $r->set_param("published_at","2026-04-25T09:45:00"); $resp = rest_do_request($r); echo $resp->get_status() . " | " . json_encode($resp->get_data()["published_at"] ?? null);'
   ```
6. Parse output, expect status `201` and published_at stored as `2026-04-25 09:45:00` (with space, not T).
7. Cleanup: `wp db query "DELETE FROM wp_jt_posts WHERE title LIKE 'Smoke scheduled%'"`

**Pass:** selects have correct options + REST accepts ISO + stores in MySQL datetime format.

### C5 — posts_per_page = 1
1. `wp eval '$r=$GLOBALS["wpdb"]->get_var("SELECT settings FROM wp_jt_spaces WHERE id=1"); echo base64_encode($r);'` → capture `$ORIG_B64`
2. `wp eval '$m = array_merge((array) json_decode($GLOBALS["wpdb"]->get_var("SELECT settings FROM wp_jt_spaces WHERE id=1"),true), ["posts_per_page"=>1]); \Jetonomy\Models\Space::update(1,["settings"=>wp_json_encode($m)]); wp_cache_delete("jetonomy_space_settings_1","jetonomy");'`
3. `browser_navigate("http://forums.local/community/s/welcome/?autologin=1&_cb=ppg1")`, wait 1500ms
4. `browser_evaluate("() => document.querySelectorAll('.jt-topics .jt-row').length")` — **expect 1**
5. `browser_evaluate("() => !!document.querySelector('.jt-load-more-trigger')")` — expect `true`
6. `browser_evaluate("async () => { window.scrollBy(0, 200); await new Promise(r => setTimeout(r, 1500)); return document.querySelectorAll('.jt-topics .jt-row').length; }")` — **expect 2**
7. Teardown: `wp eval '\Jetonomy\Models\Space::update(1,["settings"=>base64_decode("$ORIG_B64")]); wp_cache_delete("jetonomy_space_settings_1","jetonomy");'`

**Pass:** 1 on load, 2 after scroll.

### C6 — Messaging reply as low-trust participant
1. Seed: admin creates conversation with user 3 (or any low-trust user)
2. As user 3 with trust_level=0, POST reply via REST
3. Expect HTTP 201

Full fixture script:
```bash
wp eval '
global $wpdb;
$wpdb->update("wp_jt_user_profiles", ["trust_level" => 0], ["user_id" => 3]);
$wpdb->insert("wp_jt_pro_conversations", ["title"=>"Smoke","type"=>"direct","created_by"=>1,"last_message_at"=>current_time("mysql",true),"message_count"=>1]);
$conv = $wpdb->insert_id;
$wpdb->insert("wp_jt_pro_conversation_participants",["conversation_id"=>$conv,"user_id"=>1,"joined_at"=>current_time("mysql",true)]);
$wpdb->insert("wp_jt_pro_conversation_participants",["conversation_id"=>$conv,"user_id"=>3,"joined_at"=>current_time("mysql",true)]);
$wpdb->insert("wp_jt_pro_messages",["conversation_id"=>$conv,"sender_id"=>1,"content"=>"hi","content_plain"=>"hi","created_at"=>current_time("mysql",true)]);

wp_set_current_user(3);
$r = new WP_REST_Request("POST","/jetonomy/v1/conversations/$conv/messages");
$r->set_param("id",$conv); $r->set_param("content","reply from author-role");
$resp = rest_do_request($r);
echo "status:" . $resp->get_status() . "\n";

$wpdb->delete("wp_jt_pro_messages",["conversation_id"=>$conv]);
$wpdb->delete("wp_jt_pro_conversation_participants",["conversation_id"=>$conv]);
$wpdb->delete("wp_jt_pro_conversations",["id"=>$conv]);
'
```

**Pass:** output contains `status:201`.

### C7 — Profile tabs mobile scroll
1. `browser_resize(390, 844)`
2. `browser_navigate("http://forums.local/community/u/admin/drafts/?autologin=1")`
3. Wait 500ms.
4. `browser_evaluate("() => { const c = document.querySelector('.jt-profile-tabs'); return { scrollLeft: c.scrollLeft, canScroll: c.scrollWidth > c.clientWidth }; }")`

**Pass:** `canScroll === true` and `scrollLeft > 0` (active Drafts tab was auto-scrolled into view).

### C8 — is-online dot across all avatar contexts
Seed: `wp eval '$wpdb = $GLOBALS["wpdb"]; $wpdb->query("UPDATE wp_jt_user_profiles SET last_seen_at = NOW() WHERE user_id IN (1, 2, 3, 16)"); wp_cache_flush(); echo "seeded\n";'`

Then walk:

1. `browser_resize(1280, 900)` then `browser_navigate("http://forums.local/community/s/welcome/t/welcome-to-our-community-heres-how-it-works/?autologin=1&_cb=is8a")`
2. `browser_evaluate` — for every `.jt-avatar-wrap.is-online`, verify dot center is within 12px of avatar's top-right corner. Script:

```js
() => {
  const onlines = Array.from(document.querySelectorAll('.jt-avatar-wrap.is-online'));
  return onlines.map(w => {
    const a = w.querySelector('.jt-avatar, img');
    if (!a) return { aligned: false, reason: 'no avatar' };
    const aRect = a.getBoundingClientRect();
    const wrap = w.getBoundingClientRect();
    const st = getComputedStyle(w, '::after');
    const dw = parseFloat(st.width) + 2 * parseFloat(st.borderWidth || '0');
    const dcx = wrap.x + parseFloat(st.left === 'auto' ? '0' : st.left) + dw / 2;
    const dcy = wrap.y + parseFloat(st.top === 'auto' ? '0' : st.top) + dw / 2;
    return { avatarClass: a.className, aligned: Math.abs(dcx - aRect.right) < 12 && Math.abs(dcy - aRect.top) < 12 };
  });
}
```

3. Repeat on `/community/u/admin/?autologin=1` and `/community/?autologin=1`.

**Pass:** every entry `.aligned === true`.

### C9 — Akismet staff bypass
1. `wp eval 'wp_set_current_user(1); $ref = new ReflectionMethod(\Jetonomy\API\Base_Controller::class, "author_bypasses_spam_check"); $ref->setAccessible(true); $ctrl = new \Jetonomy\API\Replies_Controller(); echo "admin:" . ($ref->invoke($ctrl, 1, 1) ? "bypass" : "check") . "\n";'`

**Pass:** output contains `admin:bypass`.

### C10 — Moderation queue spam visibility
1. Fixture: seed one spam reply (see existing C10 pattern in runbook section below)
2. REST: `GET /jetonomy/v1/moderation/queue?type=reply&status=spam` as admin
3. Expect returned data array to include the seeded spam reply's id.

---

## D — Known-regression guards (covered inline in C via seed/assert cycles)

All D-class guards are expressed as C steps above. Re-run any that failed after fixing.

---

## E — Pro extension smoke (if Pro active)

Navigate to `/community/messages/?autologin=1`, verify:
- `document.querySelector('.jt-messages-list')` exists
- No `.jt-error` or PHP fatal toasts
- At least the conversation list OR the empty state renders

Walk `jetonomy-pro/plans/PRO-EXTENSION-QA-CHECKLIST.md` at a minimum for: Private Messaging, Reactions, Polls, Custom Badges, Analytics (dashboard loads), White Label (settings save).

---

## F — Cross-browser spot pass

**Chromium:** already covered by sections A-E.

**Firefox Desktop:** (Playwright MCP default here is Chromium-only; log a WARN row but don't fail the run.) Produce a manual-check reminder in the output JSON:

```json
"manual_required": [
  "Firefox Desktop: verify HH:MM selects in /community/s/<space>/new/ open on click",
  "Safari iOS 390px: verify share dropdown + profile tabs scroll"
]
```

---

## Failure protocol

On ANY failure:
1. `browser_take_screenshot({ filename: "fail-<section>-<step>.png", fullPage: false })`
2. Add a `failures[]` entry to the output JSON with: id, expected, actual, url, screenshot path
3. Continue running subsequent steps (collect ALL failures in one pass) — do NOT halt mid-run
4. At the end, if `failed > 0`, emit the Basecamp card template for each failure
5. Exit non-zero

## Step ID conventions

Every step above is identified as `<Section><Number>` e.g. `C5`, `D1.profile-av`. Use these as the `id` in the output JSON.
