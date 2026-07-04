# Audit fixes — user-profiles (14 findings)

## 1. [MAJOR] `docs/website/user-profiles/02-leaderboard.md` — wrong-key
- **Issue:** Doc calls the sidebar widget 'Jetonomy: Top Members' but it is registered as 'Jetonomy: Leaderboard'.
- **Fix:** Change widget name in the doc to 'Jetonomy: Leaderboard' (note the default heading text it inserts is 'Top Members').
- **Evidence:** doc 02-leaderboard.md:48 ('Add the **Jetonomy: Top Members** widget'); code includes/widgets/class-leaderboard-widget.php:17 registers parent::__construct('jetonomy_leaderboard', __('Jetonomy: Leaderboard','jetonomy'),...). 'Top Members' is only the form's default Title value (line 33), not the widget name shown in Appearance -> Widgets.

## 2. [MAJOR] `docs/website/user-profiles/02-leaderboard.md` — inaccurate
- **Issue:** Doc claims widget data is cached for 5 minutes; the widget/shortcode runs a direct uncached wpdb query.
- **Fix:** Remove the '5 minutes cache' sentence (or replace with an accurate note that the widget runs a direct LIMIT query).
- **Evidence:** doc 02-leaderboard.md:57 ('cached for 5 minutes'); code includes/widgets/class-leaderboard-widget.php:28 outputs do_shortcode('[jetonomy_leaderboard ...]'); includes/class-shortcodes.php:304-312 wraps the get_results in phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching with no transient/wp_cache. No 5-minute cache exists.

## 3. [MAJOR] `docs/website/user-profiles/02-leaderboard.md` — inaccurate
- **Issue:** Doc says the widget shows top 5 members with their avatars and scores; the shortcode renders only name links + 'N rep', no avatars.
- **Fix:** Remove 'with their avatars' - the widget lists names and reputation scores only.
- **Evidence:** doc 02-leaderboard.md:48 ('top 5 members by reputation with their avatars and scores'); code includes/class-shortcodes.php:318-328 builds an <ol> of <a> display-name links plus a 'jt-shortcode-rep' span ('N rep') only - no avatar element rendered.

## 4. [MAJOR] `docs/website/user-profiles/02-leaderboard.md` — inaccurate
- **Issue:** Leaderboard 'What Each Row Shows' table lists a 'Trust badge' column and an avatar 'with online status dot' that do not exist on the page.
- **Fix:** Drop the 'Trust badge' row; reword Avatar to 'initials avatar (no online status dot on the leaderboard)'.
- **Evidence:** doc 02-leaderboard.md:23-30 (table rows: Avatar 'with online status dot', 'Trust badge | Colored trust level badge'); code templates/views/leaderboard.php:167 renders <span class="jt-avatar jt-avatar-md"> initials (no is-online dot), 173-176 comment '1.4.1 byline cleanup: trust-level number removed', and 178-187 render only reputation + post_count. No trust badge column, no online dot.

## 5. [MAJOR] `docs/website/user-profiles/02-leaderboard.md` — missing-feature
- **Issue:** The leaderboard page has an All time / This month / This week period filter (scopes by last_seen_at) that the doc never mentions; the doc instead claims ranking is purely by total reputation.
- **Fix:** Add a section documenting the All time / This month / This week period pills; clarify month/week limit the board to members active within 30/7 days, still ranked by reputation.
- **Evidence:** doc 02-leaderboard.md:17 ('Members are ranked by total reputation score, highest first' with no period filter mentioned); code templates/views/leaderboard.php:14-34 ($period all/month/week, period_where 'last_seen_at > DATE_SUB(NOW(), INTERVAL 7/30 DAY)') and 85-105 (pill UI rendering All time/This month/This week).

## 6. [MAJOR] `docs/website/user-profiles/03-online-status.md` — inaccurate
- **Issue:** Doc lists the sidebar 'Top Members' widget as a place the green online dot shows; the widget shortcode renders no avatar and never calls is_online.
- **Fix:** Change the 'Sidebar Top Members widget' row to 'No' (the widget shows no avatars).
- **Evidence:** doc 03-online-status.md:29 ('Sidebar Top Members widget | Yes'); code includes/class-shortcodes.php:318-328 renders name links + 'N rep' only - no avatar, no is_online() call. (Widget delegates to this shortcode per class-leaderboard-widget.php:28.)

## 7. [MAJOR] `docs/website/user-profiles/04-my-spaces.md` — inaccurate
- **Issue:** Doc lists a 'My Spaces' tab on the profile page as a way to reach the page; no such tab exists on the profile view.
- **Fix:** Remove the 'My Spaces tab on the profile page' bullet from the three ways to reach the page.
- **Evidence:** doc 04-my-spaces.md:25 ('The **My Spaces** tab on /community/u/<your-login>/ (your own profile page)'); code templates/views/user-profile.php:228-246 renders only Posts, Replies, Votes, and (own-profile) Bookmarks, Drafts tabs - no My Spaces tab.

## 8. [MAJOR] `docs/website/user-profiles/04-my-spaces.md` — inaccurate
- **Issue:** 'Spaces You Run' row description is wrong: doc claims a 'Moderator' label, unread count, last-activity timestamp, and a Visit button; the code shows 'Admin'/'Mod', post+member counts, and Edit/Mod queue/Members actions (no Visit, no unread, no last-activity).
- **Fix:** Rewrite the row description: badge is 'Admin' or 'Mod'; meta shows post count + member count; actions are Edit (admins only), Mod queue, Members; the card itself opens the space. Remove unread count, last-activity, and Visit.
- **Evidence:** doc 04-my-spaces.md:40-44; code templates/views/my-spaces.php:96 ($label = 'Admin' or 'Mod'), 116-126 (meta line: post count + member count only), 128-143 (actions: Edit [admin only], 'Mod queue', Members - no Visit; the whole card-link is the visit target at line 99). No unread count or last-activity timestamp anywhere.

## 9. [MAJOR] `docs/website/user-profiles/04-my-spaces.md` — inaccurate
- **Issue:** 'Spaces You're In' row description is wrong: doc claims a type label, unread count, last-activity, and Visit/Leave buttons; the member card shows only icon, title, optional description, and post+member counts with no action buttons.
- **Fix:** Rewrite: member rows show icon, title, optional description, and post + member counts; there are no per-row action buttons (no Visit, no Leave). Remove the type label / unread / last-activity claims.
- **Evidence:** doc 04-my-spaces.md:53-58; code templates/views/my-spaces.php:153-179 (member card: head/icon/title, optional description excerpt, post+member count meta) - no type label, no unread, no last-activity, and no actions div (no Visit, no Leave button).

## 10. [MAJOR] `docs/website/user-profiles/04-my-spaces.md` — inaccurate
- **Issue:** Doc describes four empty-state combinations with per-section empty states and a 'Create a space' button; the code renders a single combined empty state only when both sections are empty and hides empty sections otherwise.
- **Fix:** Replace the four-combination table with: a single full-page empty state ('You are not in any spaces yet' + 'Browse spaces') shown only when the member runs and belongs to nothing; otherwise empty sections are hidden. Remove the 'Create a space' button reference.
- **Evidence:** doc 04-my-spaces.md:46,60,90-99 (per-section empty states, 'Create a space' button, four-combination table); code templates/views/my-spaces.php:69-81 (single empty-state partial rendered only when both privileged and member spaces empty, CTA 'Browse spaces') and 90,150 (sections render only when non-empty, so empty sections simply do not appear). No 'Create a space' button.

## 11. [MAJOR] `docs/website/user-profiles/04-my-spaces.md` — inaccurate
- **Issue:** Performance section claims 25-per-section server-side pagination and an unread-count batched query; the view fetches and renders all of the user's spaces with no LIMIT/pagination and computes no unread count.
- **Fix:** Remove the 25-per-section pagination claim and the unread-count batched-query claim. Optionally state that all of a user's spaces are loaded via one query per role bucket; if scale is a concern, flag pagination as a real gap rather than documenting it as shipped.
- **Evidence:** doc 04-my-spaces.md:114-116 ('paginates server-side at 25 spaces per section', 'unread count ... one batched query'); code templates/views/my-spaces.php:20-39 (spaces_for_user -> array_map Space::find over every id, no LIMIT/OFFSET, no pagination partial) and 92-183 (no unread output anywhere). The only per-row warm is role-label cache (43-47).

## 12. [MINOR] `docs/website/user-profiles/02-leaderboard.md` — wrong-default
- **Issue:** Doc says count max is 10; the input enforces max 20.
- **Fix:** Change 'max 10' to 'max 20'.
- **Evidence:** doc 02-leaderboard.md:55 ('Count | 5 | Number of members to show (max 10)'); code includes/widgets/class-leaderboard-widget.php:42 (input number min="1" max="20"). Default 5 is correct (lines 23, 34).

## 13. [MINOR] `docs/website/user-profiles/01-profiles.md` — inaccurate
- **Issue:** Doc says the reputation score links to the leaderboard; the Reputation stat is a plain div with no link.
- **Fix:** Remove the sentence 'The reputation score links to the leaderboard.'
- **Evidence:** doc 01-profiles.md:39 ('The reputation score links to the leaderboard.'); code templates/views/user-profile.php:187-190 renders the Reputation stat as <div class="jt-stat"><div class="jt-stat-n">...</div><div class="jt-stat-l">Reputation</div></div> - no <a> anchor. Whole stats bar (186-203) contains no link.

## 14. [MINOR] `docs/website/user-profiles/03-online-status.md` — inaccurate
- **Issue:** Doc says the online status read is cached for 60s using WordPress transients; it actually uses the object cache (wp_cache_*), transients are only the once-per-minute write rate-limit.
- **Fix:** Replace 'using WordPress transients' with 'using the WordPress object cache' in the is_online sentence.
- **Evidence:** doc 03-online-status.md:45 ('cached for 60 seconds using WordPress transients'); code includes/models/class-user-profile.php:197 wp_cache_get($key,'jetonomy'), 205 & 210 wp_cache_set(...,'jetonomy',60) - object cache, not transients. update_last_seen() at 167-183 uses get_transient/set_transient(MINUTE_IN_SECONDS) for the write rate-limit only.
