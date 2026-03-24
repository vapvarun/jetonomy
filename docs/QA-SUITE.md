# Jetonomy — Master QA Suite

> Reusable WP-CLI + Playwright MCP test suite for pre-release and regression testing.
> 50+ numbered test cases organized by feature area.
> Run the Quick Smoke Test first; run the full suite before any release.

**Environment:**
- Site URL: `http://forums.local`
- WP-CLI prefix: `wp --path="/Users/varundubey/Local Sites/forums/app/public"` (required on every command)
- Auto-login: always append `?autologin=1` — never fill login forms manually
- Playwright MCP: `mcp__plugin_playwright_playwright__browser_navigate`, `browser_click`, `browser_take_screenshot`, `browser_snapshot`, `browser_fill_form`, `browser_type`, `browser_wait_for`, `browser_resize`, `browser_press_key`
- Debug log: `wp-content/debug.log`

---

## Quick Smoke Test (10 Critical Cases)

Run these 10 tests first. If any fail, stop and fix before continuing.

### S1: Plugin tables created
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" db tables --all-tables | grep wp_jt_`
**Expected:** 21 or more `wp_jt_*` tables listed (categories, spaces, posts, replies, votes, user_profiles, notifications, subscriptions, read_status, space_members, tags, post_tags, space_tags, space_tag_map, user_interests, activity_log, restrictions, access_rules, flags, revisions, join_requests)

### S2: Community home loads
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** `/community/` renders with category list; no 404; no PHP errors in page source

### S3: Admin menu present
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** "Jetonomy" menu item visible in WP admin sidebar with sub-pages

### S4: Create a post as subscriber
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" user list --role=subscriber --fields=ID,user_login --format=table`
**Expected:** At least one subscriber-role user exists for UI test
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=subscriber_username" })
```
**Expected:** Subscriber can view community home; post-creation button visible in at least one space

### S5: REST API categories endpoint
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo json_encode(rest_do_request(new WP_REST_Request("GET", "/jetonomy/v1/categories")));'`
**Expected:** JSON response with `data` array and `pagination` envelope; no WP_Error

### S6: Upvote a post
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
// Navigate to any space, open any post, click the upvote button
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='vote'][data-direction='up']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Vote score increments by 1; button state changes to active/selected

### S7: No PHP errors in debug log during browse
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo file_exists(WP_CONTENT_DIR . "/debug.log") ? "exists" : "absent";'`
After navigating community pages:
**WP-CLI:** `tail -20 "/Users/varundubey/Local Sites/forums/app/public/wp-content/debug.log"`
**Expected:** No `PHP Fatal`, `PHP Warning`, or `PHP Notice` lines from jetonomy source files

### S8: Single post page loads correctly
**Playwright:**
```
// Navigate to any space then click any post title
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
// Follow link to single post: /community/s/:slug/t/:slug/
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Single post view renders with title, content, reply form, vote controls, author info

### S9: Private space hidden from guest
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/" })
// Do NOT use autologin
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Spaces marked private/hidden are not listed; public spaces are visible

### S10: Deactivation is clean
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin deactivate jetonomy
wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin activate jetonomy
```
**Expected:** Both commands complete with `Success:` message; no fatal errors; no entries in debug.log

---

## Section 1: Installation & Activation

### T1: Plugin tables created
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" db tables --all-tables | grep wp_jt_`
**Expected:** Exactly 21 `wp_jt_*` tables listed (or more if Pro is also active)

### T2: Default capabilities added to roles
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo json_encode(get_role("administrator")->capabilities);' | grep jetonomy
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo json_encode(get_role("subscriber")->capabilities);' | grep jetonomy
```
**Expected:** Administrator has `manage_jetonomy_community` and full caps; Subscriber has `read_jetonomy_community`

### T3: Permalinks flag set on first load
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_permalinks_flushed`
**Expected:** Returns `1` or `true`

### T4: /community/ page is not a 404
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** HTTP 200; no "Page not found" heading; Jetonomy community template renders

### T5: Admin menu and sub-pages render
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy&autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Jetonomy Dashboard page loads; sub-menu items visible (Spaces, Content, Users, Settings, etc.)

### T6: Deactivation runs cleanly
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin deactivate jetonomy 2>&1`
**Expected:** `Success: Deactivated 1 of 1 plugins.` — no PHP errors or warnings; tables remain (data preserved on deactivate)

### T7: Uninstall removes all data
**Note:** Run this on a scratch/demo install only — data is permanently deleted.
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" plugin uninstall jetonomy --deactivate
wp --path="/Users/varundubey/Local Sites/forums/app/public" db tables --all-tables | grep wp_jt_
wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_version
```
**Expected:** No `wp_jt_*` tables found; `jetonomy_version` option returns empty; Jetonomy capabilities removed from all roles

---

## Section 2: Setup Wizard & Demo Data

### T8: Setup wizard launches on first activation
**Playwright:**
```
// After fresh activation (jetonomy_setup_complete option absent), navigate to admin
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Setup wizard screen appears automatically or admin notice with "Run Setup Wizard" link

### T9: Demo data installs sample content
**Playwright:**
```
// Click "Install Demo Data" from setup wizard or admin > Jetonomy > Settings
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy&autologin=1" })
// Trigger demo install action
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**WP-CLI (verify after):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_demo_data`
**Expected:** Option contains a JSON array/object with demo content IDs; at least 2 categories, 4 spaces, 10 posts visible on `/community/`

### T10: Demo data tracking flag set
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_demo_data`
**Expected:** Non-empty value (JSON array of demo entity IDs)

### T11: One-click demo cleanup
**WP-CLI (verify before):**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_posts");'
```
**Trigger cleanup via admin UI, then verify:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_demo_data
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_posts");'
```
**Expected:** `jetonomy_demo_data` option is empty/deleted; post count returns to pre-demo value

### T12: Activity backfill runs once
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" option get jetonomy_activity_backfilled`
**Expected:** Returns `1` after first load post-activation

---

## Section 3: Categories

### T13: Create category from WP Admin
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-categories&autologin=1" })
// Fill category name, submit form
mcp__plugin_playwright_playwright__browser_fill_form({ fields: { name: "Test Category QA", slug: "test-category-qa" } })
mcp__plugin_playwright_playwright__browser_click({ selector: "[type='submit']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Category appears in table on the right; no error message

### T14: Edit category (name, slug, description)
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-categories&autologin=1" })
// Click edit on an existing category, update fields, save
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Updated values saved; reflected in table and on frontend `/community/` page

### T15: Delete category
**WP-CLI (before):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_categories");'`
**Playwright:** Click delete on a category with no child spaces
**WP-CLI (after):** Same query — count decremented by 1
**Expected:** Category removed; spaces previously under it either removed or reassigned per cascade rules

### T16: Category tree renders on community home
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Each category heading visible with its child space cards listed below it

### T17: REST API categories returns nested tree
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'echo wp_remote_retrieve_body(wp_remote_get("http://forums.local/wp-json/jetonomy/v1/categories"));'`
**Expected:** JSON array; each item has `id`, `name`, `slug`, `children` array; children contain space stubs

### T18: Category page renders at its URL
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/category/general/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Category template renders with its spaces; heading matches category name; HTTP 200

---

## Section 4: Spaces

### T19: Create Forum-type space
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-spaces&autologin=1" })
// Fill name, select type=Forum, select a category, submit
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_spaces WHERE type='"'"'forum'"'"'");'`
**Expected:** New forum space appears in admin list; count increments; space page at `/community/s/:slug/` returns 200

### T20: Create Q&A and Ideas spaces
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_spaces WHERE type='"'"'qna'"'"'");'
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_spaces WHERE type='"'"'ideas'"'"'");'
```
**Expected:** At least one Q&A space and one Ideas space exist; each renders its specific UI elements (Accept Answer button for Q&A; status badges for Ideas)

### T21: Space visibility — private space hidden from non-members
**Playwright:**
```
// Browse /community/ without autologin (guest)
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Spaces with `visibility=private` do not appear in the listing for unauthenticated users or non-members

### T22: Hidden space not visible in any listing
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT id, name, visibility FROM wp_jt_spaces WHERE visibility='"'"'hidden'"'"'"));'`
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Hidden spaces returned by WP-CLI are absent from every frontend listing including category pages and search

### T23: Approval-based join request flow
**Playwright:**
```
// Login as a subscriber (non-member), navigate to an approval-gated space
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/approval-space/?autologin=subscriber_username" })
// Click "Request to Join"
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='request-join']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify join request recorded):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT * FROM wp_jt_join_requests ORDER BY id DESC LIMIT 1"));'`
**Expected:** Join request row created with `status=pending`; admin can approve/decline via admin UI

### T24: Space card shows correct counts
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Each space card displays: post count, member count, last activity time; values match DB counts from `wp_jt_spaces.post_count` and `wp_jt_space_members` table

---

## Section 5: Posts

### T25: Create post (title + content + tags)
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/new/?autologin=1" })
mcp__plugin_playwright_playwright__browser_fill_form({ fields: { title: "QA Test Post", content: "This is a test post body." } })
// Add a tag
mcp__plugin_playwright_playwright__browser_type({ selector: "[data-jt-input='tags']", text: "qa-test" })
mcp__plugin_playwright_playwright__browser_press_key({ key: "Enter" })
mcp__plugin_playwright_playwright__browser_click({ selector: "[type='submit']" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Redirect to new post URL at `/community/s/general-discussion/t/qa-test-post/`; post visible in space listing

### T26: Post listing shows correct metadata
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Each post row shows: title, vote score, reply count, author avatar + name, tags, relative timestamp

### T27: Single post view renders at correct URL
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Full post content renders; reply form visible; vote controls present; no 404

### T28: Edit post creates a revision
**WP-CLI (before):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_revisions");'`
**Playwright:** Edit an existing post, change body, save
**WP-CLI (after):** Same query — count increments
**Expected:** Revision row created with `entity_type=post`, previous content stored

### T29: Sort posts — Latest, Popular, Unanswered
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?sort=popular&autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?sort=unanswered&autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Sorting changes post order; "Popular" sorts by `vote_score DESC`; "Unanswered" shows only `reply_count=0`

### T30: Close and pin post (moderator actions)
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
// Click "Close Post" (moderator/admin only)
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='close-post']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Post shows "Closed" badge; reply form hidden; pinned post appears at top of listing after pin action

### T31: Post pagination (cursor-based)
**WP-CLI (seed posts):** Ensure at least 30 posts exist in a space
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=1" })
// Click "Load more" or pagination control
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='load-more']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Additional posts load using cursor parameter in URL/request; no duplicate posts; no infinite loop

### T32: Q&A — Accept Answer button appears for post author
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/qna-space/?autologin=1" })
// Open a Q&A post created by the logged-in user
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** "Accept Answer" button visible on each reply for the post's original author; not visible for other users

### T33: Ideas — status tracking badges
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/ideas-space/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Ideas posts show status badges (Submitted / Planned / In Progress / Completed / Declined); status badge is color-coded

---

## Section 6: Replies

### T34: Reply to a post
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_fill_form({ fields: { reply: "This is a QA test reply." } })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='submit-reply']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT reply_count FROM wp_jt_posts ORDER BY id DESC LIMIT 1");'`
**Expected:** Reply appears in thread; `reply_count` on the post incremented by 1

### T35: Threaded reply (reply-to-reply, up to 3 levels)
**Playwright:**
```
// On a post with an existing reply, click "Reply" on that reply to thread
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='reply-to'][data-reply-id]" })
mcp__plugin_playwright_playwright__browser_fill_form({ fields: { reply: "Nested reply level 2." } })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='submit-reply']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Reply renders indented/nested under the parent reply; `parent_id` set correctly in `wp_jt_replies`; at level 3, no further nesting allowed

### T36: Sort replies — Oldest, Newest, Best
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?sort=best&autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** "Best" sort puts highest `vote_score` reply first; "Newest" puts most recent first; "Oldest" reverses

### T37: Q&A — accept answer highlights and pins to top
**Playwright:**
```
// On a Q&A post with at least 2 replies, click "Accept Answer" on one reply
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='accept-answer']" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT id, is_accepted FROM wp_jt_replies WHERE is_accepted=1 LIMIT 3"));'`
**Expected:** Accepted reply has green "Accepted" badge; appears first in the reply list; `is_accepted=1` in DB

---

## Section 7: Voting

### T38: Upvote a post — score increments
**WP-CLI (before):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT vote_score FROM wp_jt_posts WHERE id=1");'`
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='vote'][data-direction='up']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (after):** Same query — score incremented by 1
**Expected:** Vote score +1; upvote button active state; vote recorded in `wp_jt_votes`

### T39: Toggle vote — clicking same button removes vote
**Playwright:**
```
// Click upvote once (voted), then click again (unvote)
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='vote'][data-direction='up']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT COUNT(*) FROM wp_jt_votes WHERE entity_id=1 AND user_id=1");'`
**Expected:** Vote row deleted from `wp_jt_votes`; score returns to pre-vote value

### T40: Change vote direction (up to down) adjusts score by 2
**Playwright:**
```
// First upvote, then immediately downvote the same post
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='vote'][data-direction='up']" })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='vote'][data-direction='down']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Score drops by 2 (remove +1, add -1); vote row in DB updated with `direction=-1`

### T41: Vote on reply works identically
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_click({ selector: ".jt-reply [data-jt-action='vote'][data-direction='up']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT * FROM wp_jt_votes WHERE entity_type='"'"'reply'"'"' ORDER BY id DESC LIMIT 1"));'`
**Expected:** Vote row with `entity_type=reply` created; reply `vote_score` updated

---

## Section 8: Trust Levels & Reputation

### T42: New user starts at Trust Level 0
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" user create qa_trust_test qa_trust@test.local --role=subscriber --porcelain
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT trust_level FROM wp_jt_user_profiles WHERE user_id = (SELECT ID FROM wp_users WHERE user_login='"'"'qa_trust_test'"'"')");'
```
**Expected:** `trust_level=0` (or NULL before first activity, defaulting to 0)

### T43: Trust badge displays on user avatar
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** User avatars on posts/replies have a badge element with `data-jt-tl` attribute matching the user's trust level; badge has correct color via CSS attribute selector

### T44: Reputation points awarded correctly
**WP-CLI (before):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; echo $wpdb->get_var("SELECT reputation FROM wp_jt_user_profiles WHERE user_id=2");'`
**Action:** Upvote a post by user ID 2
**WP-CLI (after):** Same query — value increased by 10
**Expected:** Post upvoted: +10 rep; reply upvoted: +5 rep; accepted answer: +15 rep

### T45: Trust evaluation cron registered
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" cron event list | grep jetonomy`
**Expected:** `jetonomy_evaluate_trust_levels` event listed; schedule is `twicedaily` or every 12 hours

---

## Section 9: Permissions (3-Layer)

### T46: WP Capabilities — Subscriber cannot moderate
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-moderation&autologin=subscriber_username" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** 403 / "Sorry, you are not allowed to access this page" — Subscriber cannot access moderation admin page

### T47: Banned user denied access everywhere
**WP-CLI (ban user):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; $wpdb->insert("wp_jt_restrictions", ["user_id" => 3, "restriction_type" => "ban", "created_at" => current_time("mysql")]);'`
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=user_id_3" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Banned user sees a "You have been banned" message; cannot post, reply, or vote; all write actions return 403

### T48: Silenced user can read but not write
**WP-CLI (silence user):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; $wpdb->insert("wp_jt_restrictions", ["user_id" => 4, "restriction_type" => "silence", "created_at" => current_time("mysql")]);'`
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=user_id_4" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Silenced user can browse and read all public content; reply form and post form are hidden or disabled; vote buttons disabled

---

## Section 10: Search

### T49: Search page renders and filters work
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/search/?q=test&autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
// Click "Spaces" tab
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-filter='spaces']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Results list renders; filter tabs (All / Posts / Spaces / Tags) switch result types; FULLTEXT search returns relevant matches

### T50: Private space content excluded from search for non-members
**Playwright:**
```
// Search as a non-member for content known to be in a private space
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/search/?q=private-space-keyword&autologin=subscriber_username" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** No results from the private space are shown for a user who is not a member of that space

---

## Section 11: Moderation

### T51: Flag content — appears in mod queue
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='flag']" })
// Select a reason and confirm
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT * FROM wp_jt_flags ORDER BY id DESC LIMIT 1"));'`
**Expected:** Flag row created with `status=pending`; appears in admin moderation queue

### T52: Approve, Spam, and Trash actions work
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-moderation&autologin=1" })
// Click Approve on a flagged item
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-mod-action='approve']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Approve: flag resolved, content remains public; Spam: content hidden, author reputation -20; Trash: content deleted from DB

### T53: Ban user from moderation panel
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-users&autologin=1" })
// Search for a user, click "Ban"
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='ban-user']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT * FROM wp_jt_restrictions WHERE restriction_type='"'"'ban'"'"' ORDER BY id DESC LIMIT 1"));'`
**Expected:** Ban row inserted; user cannot access write actions on next page load

---

## Section 12: Notifications

### T54: Bell icon shows unread badge count
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Notification bell in nav shows a numeric badge when there are unread notifications; badge is absent or "0" when all notifications read

### T55: Notification types received
**WP-CLI (trigger a reply to admin's post):** Use a different user to reply to a post authored by admin
**Playwright (check notifications):**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/notifications/?autologin=1" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Notification for "reply" type appears; clicking it navigates to the specific reply; notification marked as read

### T56: Each notification navigates to correct content
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/notifications/?autologin=1" })
// Click a notification
mcp__plugin_playwright_playwright__browser_click({ selector: ".jt-notification-item:first-child a" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Browser navigates to the referenced post/reply; notification item marked as read

---

## Section 13: Email Notifications

### T57: Email sent on reply to subscribed post
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval '
add_filter("wp_mail", function($args) { file_put_contents("/tmp/jt_last_email.json", json_encode($args)); return $args; });
// Trigger a reply to a subscribed post programmatically
'
```
Check `/tmp/jt_last_email.json` contents after reply action
**Expected:** `wp_mail` called with: recipient is post subscriber, subject contains post title, body contains reply excerpt, HTML + plain text versions present

### T58: Unsubscribe link works
**Playwright:**
```
// Open an email notification and click the Unsubscribe link
// URL format: /community/?jt_unsubscribe=:token
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?jt_unsubscribe=TEST_TOKEN" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**WP-CLI (verify):** Check `wp_jt_subscriptions` for the user — subscription removed or `status=unsubscribed`
**Expected:** Confirmation message shown; user no longer receives email for that post

---

## Section 14: SEO

### T59: Schema.org JSON-LD on single post page
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/t/qa-test-post/?autologin=1" })
mcp__plugin_playwright_playwright__browser_evaluate({ expression: "JSON.stringify(JSON.parse(document.querySelector('script[type=\"application/ld+json\"]').textContent))" })
```
**Expected:** JSON-LD block present with `@type: "DiscussionForumPosting"`; includes `headline`, `author`, `datePublished`, `url`

### T60: XML Sitemap includes spaces and posts
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-sitemap.xml" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Sitemap lists entries for Jetonomy spaces and posts; private/hidden spaces are excluded

### T61: OG and meta tags on community pages
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=1" })
mcp__plugin_playwright_playwright__browser_evaluate({ expression: "document.querySelector('meta[property=\"og:title\"]').content" })
```
**Expected:** `og:title`, `og:description`, `og:url` tags present on space page and single post page; canonical URL matches the clean URL

---

## Section 15: Admin Dashboard

### T62: Dashboard stats page loads
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy&autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Overview stats render (total posts, replies, spaces, members); no PHP errors

### T63: Spaces admin CRUD
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-spaces&autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Space list renders with correct columns; create/edit/delete actions complete without errors

### T64: Content admin page — post/reply management
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-content&autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Paginated list of posts and replies; search/filter controls work; bulk actions (trash, spam) apply correctly

### T65: Settings page — all tabs render and save
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/wp-admin/admin.php?page=jetonomy-settings&autologin=1" })
// Click each tab and verify it renders
mcp__plugin_playwright_playwright__browser_snapshot({})
// Change a setting, click Save
mcp__plugin_playwright_playwright__browser_click({ selector: "[type='submit']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** All settings tabs render without errors; save action stores values (verify via `wp option get jetonomy_settings` or relevant option key)

### T66: Abilities API — 18 abilities registered
**WP-CLI:** `wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'print_r(apply_filters("jetonomy_abilities", []));'`
**Expected:** Array with 18 ability definitions across 5 categories; each has `id`, `label`, `category`, `callback` keys

---

## Section 16: Membership Integration

### T67: WP Roles adapter — access rules by role work
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval '
$engine = \Jetonomy\Permissions\Permission_Engine::instance();
$can = $engine->can(get_current_user_id(), "create_post", 1);
echo $can ? "allowed" : "denied";
'
```
**Expected:** Returns `allowed` for admin; returns `denied` for an unauthenticated user (ID 0) attempting `create_post`

### T68: Access rule types enforced
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval 'global $wpdb; print_r($wpdb->get_results("SELECT space_id, rule_type, rule_value FROM wp_jt_access_rules LIMIT 10"));'
```
**Expected:** Access rules with types `membership`, `role`, `capability`, `trust_level`, `logged_in`, and `everyone` are all stored correctly; each rule is enforced by the permission engine (test by visiting a restricted space as a user who does/doesn't qualify)

---

## Section 17: Frontend & UI

### T69: Mobile responsive — 375px viewport
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_resize({ width: 375, height: 812 })
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** No horizontal overflow; nav collapses to hamburger or mobile layout; space cards stack vertically; all interactive elements remain tappable

### T70: Tablet responsive — 768px viewport
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_resize({ width: 768, height: 1024 })
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/?autologin=1" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Layout adapts appropriately for tablet breakpoint; sidebar/content split or single column as designed

### T71: Emoji picker functional
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/new/?autologin=1" })
mcp__plugin_playwright_playwright__browser_click({ selector: "[data-jt-action='emoji-picker']" })
mcp__plugin_playwright_playwright__browser_snapshot({})
mcp__plugin_playwright_playwright__browser_click({ selector: ".jt-emoji-item:first-child" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** Emoji picker opens; clicking an emoji inserts it into the editor/textarea

### T72: Keyboard shortcuts work
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=1" })
// Press 'j' to navigate to next post
mcp__plugin_playwright_playwright__browser_press_key({ key: "j" })
mcp__plugin_playwright_playwright__browser_snapshot({})
// Press 'l' to vote
mcp__plugin_playwright_playwright__browser_press_key({ key: "l" })
mcp__plugin_playwright_playwright__browser_snapshot({})
```
**Expected:** `j` moves focus to next post; `k` to previous; `l` triggers upvote on focused item; `r` opens reply form

### T73: Hover cards on user avatars
**Playwright:**
```
mcp__plugin_playwright_playwright__browser_navigate({ url: "http://forums.local/community/s/general-discussion/?autologin=1" })
mcp__plugin_playwright_playwright__browser_hover({ selector: ".jt-post-author .jt-avatar" })
mcp__plugin_playwright_playwright__browser_take_screenshot({})
```
**Expected:** Hover card appears with user's display name, trust level, reputation, post count, and join date

---

## Section 18: REST API General

### T74: Authentication required on write endpoints
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval '
$request = new WP_REST_Request("POST", "/jetonomy/v1/posts");
$request->set_body_params(["space_id" => 1, "title" => "Unauth test", "content" => "test"]);
$response = rest_do_request($request);
echo $response->get_status();
'
```
**Expected:** Returns HTTP `401` (Unauthorized) when no user is authenticated

### T75: Cursor-based pagination works correctly
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval '
$r1 = rest_do_request(new WP_REST_Request("GET", "/jetonomy/v1/spaces/1/posts"));
$data = $r1->get_data();
echo json_encode(["count" => count($data["data"]), "next_cursor" => $data["pagination"]["next_cursor"] ?? null]);
'
```
**Expected:** Response has `data` array with items and `pagination.next_cursor` value; using that cursor in the next request returns the next page without duplicates

### T76: Response format consistent — envelope with data + pagination
**WP-CLI:**
```
wp --path="/Users/varundubey/Local Sites/forums/app/public" eval '
$response = rest_do_request(new WP_REST_Request("GET", "/jetonomy/v1/categories"));
print_r(array_keys($response->get_data()));
'
```
**Expected:** Top-level keys include `data` (array) and `pagination` (object with `total`, `next_cursor`, `has_more`); no raw array responses without envelope

---

## Pre-Release Checklist

Run this checklist before tagging any release. Each item must pass.

### Core Functionality
- [ ] **T1** — All 21 `wp_jt_*` tables created on fresh activation
- [ ] **T2** — Default capabilities added to all roles (Administrator, Editor, Subscriber)
- [ ] **T4** — `/community/` page returns HTTP 200 with correct template
- [ ] **T7** — Uninstall removes all tables, options, capabilities, and cron events (test on scratch install)
- [ ] **T10** — Demo cleanup removes all demo data; `jetonomy_demo_data` option empty
- [ ] **T12** — Activity backfill flag set after first activation

### Content Flows
- [ ] **T25** — Post creation (title + content + tags) works end-to-end
- [ ] **T27** — Single post view renders at `/community/s/:slug/t/:slug/` with no 404
- [ ] **T34** — Reply submission increments `reply_count` on post
- [ ] **T35** — Threaded replies render correctly at depth 2–3; no nesting beyond 3
- [ ] **T37** — Accept answer: reply marked `is_accepted=1`, pinned to top in Q&A

### Voting & Reputation
- [ ] **T38** — Upvote increments score by 1; vote row created in `wp_jt_votes`
- [ ] **T39** — Toggle vote (click again) removes vote and restores score
- [ ] **T40** — Changing vote direction adjusts score by 2
- [ ] **T44** — Reputation points awarded on upvote (+10 post, +5 reply, +15 accepted answer)

### Permissions & Safety
- [ ] **T21** — Private spaces hidden from non-members and guests
- [ ] **T22** — Hidden spaces absent from all frontend listings
- [ ] **T46** — Subscriber cannot access moderation admin page (403)
- [ ] **T47** — Banned user sees ban message; all write actions return 403
- [ ] **T48** — Silenced user can read but reply/post forms are hidden or disabled

### REST API
- [ ] **T17** — `GET /jetonomy/v1/categories` returns nested tree with `data` + `pagination`
- [ ] **T74** — Unauthenticated POST to `/jetonomy/v1/posts` returns 401
- [ ] **T75** — Cursor pagination works; second page uses `next_cursor` with no duplicates
- [ ] **T76** — All endpoints return consistent envelope: `{ data: [...], pagination: {...} }`

### Admin
- [ ] **T62** — Admin dashboard loads with stats; no PHP errors
- [ ] **T63** — Spaces admin list renders; CRUD operations complete
- [ ] **T65** — Settings page: all tabs render; save action persists values
- [ ] **T66** — 18 abilities registered across 5 categories

### SEO & Frontend
- [ ] **T59** — Single post page has `DiscussionForumPosting` JSON-LD schema
- [ ] **T60** — XML sitemap includes public spaces and posts; private/hidden excluded
- [ ] **T69** — Community home renders correctly at 375px (no horizontal overflow)
- [ ] **T72** — Keyboard shortcuts: `j/k` navigate, `l` votes, `r` opens reply

### Stability
- [ ] No `PHP Fatal`, `PHP Warning`, or `PHP Notice` in `debug.log` after a full browse session
- [ ] `wp plugin deactivate jetonomy && wp plugin activate jetonomy` completes with no errors
- [ ] Cron event `jetonomy_evaluate_trust_levels` listed with `twicedaily` or 12-hour schedule
- [ ] All 35+ REST endpoints respond (no `rest_no_route` errors on valid authenticated requests)
