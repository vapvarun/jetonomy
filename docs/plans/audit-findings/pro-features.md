# Audit fixes — pro-features (13 findings)

## 1. [CRITICAL] `pro-features/13-ai.md` — wrong-route
- **Issue:** AI doc lists four endpoints including /ai/test-provider and /ai/summarize/{type}/{id}; extension registers only three (usage, usage/summary, suggest-reply) and post_id is a body arg not a path segment.
- **Fix:** Replace the 4-row table with the 3 actually-registered routes: GET /ai/usage, GET /ai/usage/summary, POST /ai/suggest-reply (post_id is a JSON body arg, not a path segment). Drop /ai/test-provider and /ai/summarize/{type}/{id}.
- **Evidence:** Doc 13-ai.md:125-132 (table of 4 routes) and :131-132. Code includes/extensions/ai/class-extension.php register_rest_routes() registers exactly three routes under jetonomy/v1: /ai/usage (:756-757 READABLE), /ai/usage/summary (:782-783 READABLE), /ai/suggest-reply (:810-811 CREATABLE, post_id is a body arg at :816-821). grep for 'summarize' in the extension returns only Summarizer class refs (lines 272-276), no route. grep for test-provider/test_provider returns nothing.

## 2. [MAJOR] `pro-features/13-ai.md` — wrong-key
- **Issue:** Doc says all AI endpoints require the manage_jetonomy capability; that capability does not exist and the routes use different permission callbacks.
- **Fix:** State the real auth: /ai/usage and /ai/usage/summary require admin (manage_options via rest_admin_check); /ai/suggest-reply requires any logged-in member (rest_auth_mutation('read'), rate-limited). Remove the fictional manage_jetonomy capability.
- **Evidence:** Doc 13-ai.md:134. Code: /ai/usage permission_callback rest_admin_check (:761), /ai/usage/summary rest_admin_check (:787), /ai/suggest-reply rest_auth_mutation('read') (:815). grep 'manage_jetonomy' across jetonomy-pro/includes and jetonomy/includes returns zero hits.

## 3. [MAJOR] `pro-features/13-ai.md` — missing-feature
- **Issue:** Doc step 4 describes a Test Connection button returning round-trip latency; no such mechanism exists (no route, no AJAX handler).
- **Fix:** Remove the Test Connection step (step 4) or replace with the actual verification path. There is no test-connection control or endpoint shipped.
- **Evidence:** Doc 13-ai.md:95. Code: grep for test-provider/test_provider/test-connection/test_connection in includes/extensions/ai/ returns nothing; grep for wp_ajax in ai/class-extension.php returns nothing; no /ai/test-provider route is registered (register_rest_routes :754-825).

## 4. [MAJOR] `pro-features/03-polls.md` — wrong-route
- **Issue:** Doc lists POST /polls and GET /polls/{id} for create/read; actual routes are POST and GET /posts/{post_id}/poll. The vote/delete/patch routes match.
- **Fix:** Change the table: POST /posts/{post_id}/poll (create), GET /posts/{post_id}/poll (read). Keep POST/DELETE /polls/{id}/vote and PATCH /polls/{id} as-is.
- **Evidence:** Doc 03-polls.md:88-89. Code includes/extensions/polls/class-extension.php register_routes(): '/posts/(?P<post_id>\d+)/poll' carries both POST create_poll (:334-367) and GET get_poll (:368-379); '/polls/(?P<id>\d+)/vote' POST+DELETE (:384-421); '/polls/(?P<id>\d+)' PATCH (:424-443). No top-level /polls or /polls/{id} read/create route exists.

## 5. [MAJOR] `pro-features/03-polls.md` — inaccurate
- **Issue:** Doc says up to 10 poll options; validation actually allows up to 20.
- **Fix:** Change '10 options' to '20 options' (minimum 2).
- **Evidence:** Doc 03-polls.md:30 ('Write up to 10 options'). Code class-extension.php:487 rejects count < 2; :495-498 rejects count > 20 with message 'A poll can have a maximum of 20 options.'

## 6. [MAJOR] `pro-features/01-reactions.md` — inaccurate
- **Issue:** Doc claims six default reactions including a non-existent 'Sad'; extension ships eight (Like, Love, Haha, Celebrate, Thinking, Watching, Rocket, Dislike).
- **Fix:** List all eight defaults and remove 'Sad'. Add Haha (laugh), Watching (eyes), and Dislike (thumbsdown). Update the '6' to '8'.
- **Evidence:** Doc 01-reactions.md:34 ('six Fluent 3D emojis') and :38-43 (table lists Like, Love, Thinking, Celebrate, Rocket, Sad). Code includes/extensions/reactions/class-extension.php REACTIONS const :26-35 has 8 entries: thumbsup=Like, heart=Love, laugh=Haha, hooray=Celebrate, thinking=Thinking, eyes=Watching, rocket=Rocket, thumbsdown=Dislike. EMOJI_CHARS :40-49 matches. No 'sad' slug.

## 7. [MAJOR] `pro-features/04-custom-fields.md` — missing-feature
- **Issue:** Doc lists five field types (Text, Textarea, Select, Checkbox, URL); extension supports nine.
- **Fix:** Document all nine field types. Add Number, Email, Radio, and Date to the table.
- **Evidence:** Doc 04-custom-fields.md:46-52 (table = Text, Textarea, Select, Checkbox, URL). Code includes/extensions/custom-fields/class-extension.php FIELD_TYPES const :24-34 lists nine: text, textarea, number, email, url, select, checkbox, radio, date.

## 8. [MAJOR] `pro-features/05-custom-badges.md` — wrong-default
- **Issue:** Doc says badge auto-evaluation runs every 12 hours; the cron is scheduled at 6 hours.
- **Fix:** Change '12 hours' to '6 hours'. Note evaluation also runs via Action Scheduler / WP-Cron fallback, and event-driven async re-evaluation runs on post/reply/vote/reputation/trust changes.
- **Evidence:** Doc 05-custom-badges.md:56 ('runs once every 12 hours via WP-Cron'). Code includes/extensions/custom-badges/class-extension.php:96 Queue::recurring(self::CRON_EVALUATE, 6 * HOUR_IN_SECONDS); docblock :19 'Automated badge evaluation cron (every 6 hours)'; interval const CRON_INTERVAL = 'jetonomy_six_hours' (:44).

## 9. [MAJOR] `pro-features/05-custom-badges.md` — inaccurate
- **Issue:** Doc's badge sentence is self-contradictory and names a non-existent 'Jetonomy -> Badges -> Run Evaluation' control.
- **Fix:** Remove the contradictory sentence. State plainly that evaluation is automatic (cron + event-driven) with no manual trigger button, or add a real trigger control before documenting one.
- **Evidence:** Doc 05-custom-badges.md:56 ('There is no manual "evaluate now" button, but you can trigger evaluation by going to Jetonomy -> Badges -> Run Evaluation'). Code class-extension.php admin buttons: Save/Update Badge (:1884-1885), Cancel (:1888), Edit (:1953), Award (:1954), Deactivate/Activate (:1956,:1958), Add Condition (:1878), Award modal confirm (:1979). grep 'run evaluation'/'evaluate now' in the extension returns nothing. No Run Evaluation button exists.

## 10. [MAJOR] `pro-features/12-white-label.md` — inaccurate
- **Issue:** Doc describes a REST API powered_by key overridable via Settings -> Branding -> REST API Label; neither the key nor the setting exists.
- **Fix:** Remove the entire REST API Branding section, or implement a real powered_by REST root key + setting before documenting it. No REST root emits powered_by today.
- **Evidence:** Doc 12-white-label.md:53-68 (REST API Branding section, powered_by JSON example, 'Settings -> Branding -> REST API Label'). Code: white-label DEFAULTS :24-36 are community_name, logo_url, footer_text, admin_label, admin_icon, accent_color, custom_css, header_logo_url, email_logo_url, sidebar_auth_card_html - no REST label key. grep 'powered_by' in jetonomy/includes returns nothing; in jetonomy-pro/includes only UI footer-text placeholder strings (class-white-label-command.php:64,90; class-extension.php:600,602).

## 11. [MAJOR] `pro-features/12-white-label.md` — inaccurate
- **Issue:** Doc documents an 'HTML data-plugin attribute' branding setting (default jetonomy) on the .jt-app wrapper; no such setting or attribute output exists.
- **Fix:** Remove the data-plugin attribute row from the branding settings table. No such control or attribute is shipped.
- **Evidence:** Doc 12-white-label.md:33 (table row 'HTML data-plugin attribute | jetonomy | Change or remove the attribute on the .jt-app wrapper'). Code: grep 'data-plugin'/'data_plugin' in jetonomy-pro/includes returns nothing. White-label DEFAULTS (:24-36) has no data_plugin key.

## 12. [MINOR] `pro-features/05-custom-badges.md` — missing-feature
- **Issue:** Doc lists five auto-award criteria; extension supports eight metrics (omits reputation, trust_level, spaces_joined).
- **Fix:** Add the three missing metrics (Reputation, Trust level, Spaces joined) to the auto-award criteria table.
- **Evidence:** Doc 05-custom-badges.md:46-52 (Total posts, Accepted answers, Total replies, Upvotes received, Days as member). Code class-extension.php docblock :20-21 lists 8 metrics: post_count, reply_count, reputation, trust_level, vote_received, days_active, accepted_answers, spaces_joined.

## 13. [MINOR] `pro-features/00-getting-started-pro.md` — outdated
- **Issue:** Doc points users to a standalone jetonomy-license admin page; License is now a tab inside free's Settings page, not a standalone page. (The jetonomy-extensions reference is correct.)
- **Fix:** Update line 16 only: License is a tab under Jetonomy -> Settings -> License, not a standalone jetonomy-license page. Leave the jetonomy-extensions reference at line 28 unchanged - it is accurate.
- **Evidence:** Doc 00-getting-started-pro.md:16 ('Go to Jetonomy -> License ... the jetonomy-license page'). Code: no jetonomy-license page slug is registered; class-jetonomy-pro.php:111 hooks 'jetonomy_admin_license_tab_content' and :422-424 render_sdk_license_tab() 'Render the license tab inside Settings -> License'; license dir contains only class-license.php (no class-license-admin.php). Manifest admin_pages notes the License page entry was stale and 'license is a tab inside free's Settings page'. The Extensions slug jetonomy-extensions IS correct: free registers it (jetonomy/includes/admin/class-admin.php:192) and Pro renders into it via jetonomy_admin_render_extensions (:1419).
