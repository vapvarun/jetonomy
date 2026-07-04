# Audit fixes — admin-settings (24 findings)

## 1. [MAJOR] `docs/website/admin-settings/01-general.md` — inaccurate
- **Issue:** 'Require Login to Participate' setting (key require_login) documented but no such setting exists in code.
- **Fix:** Delete the entire 'Require Login to Participate' section (lines 69-79). Writes already require login implicitly via guest_read=Public ('Visitors must log in to post, reply, or vote'); there is no separate require_login toggle.
- **Evidence:** Doc 01-general.md:69-79 documents Setting require_login, Default true, Location General tab Access section. Code: sanitize_settings General block (class-admin.php:339-380) handles base_slug, community_title, posts_per_page, replies_per_page, default_space_type, guest_read, front_page, frontend_space_creation_roles, require_email_verification only. No require_login key anywhere in the General tab view (settings.php:125-308) or sanitizer. The Access Control card (settings.php:276-307) has only the guest_read radio.

## 2. [MAJOR] `docs/website/admin-settings/01-general.md` — missing-feature
- **Issue:** General tab ships 'Community as Homepage' (front_page) and 'Front-end space creation' role allowlist (frontend_space_creation_roles); neither is documented.
- **Fix:** Add a 'Community as Homepage' section (key front_page, default off, overrides WP front page) and a 'Front-end space creation' section (key frontend_space_creation_roles, role allowlist, admins always qualify, empty = admin-only).
- **Evidence:** Code settings.php:148-157 renders the 'Community as Homepage' checkbox name=jetonomy_settings[front_page]; settings.php:170-243 renders the grouped role-allowlist fieldset name=jetonomy_settings[frontend_space_creation_roles][]. Sanitized at class-admin.php:359-371 (front_page line 361, frontend_space_creation_roles line 367-371). Doc 01-general.md has no section for either feature.

## 3. [MAJOR] `docs/website/admin-settings/02-permissions.md` — wrong-key
- **Issue:** Trust threshold keys documented as min_posts/min_days/min_visits/min_reputation/max_flags; actual keys are posts/days_active/reputation/replies_received.
- **Fix:** Replace the threshold key table: posts (Minimum posts), days_active (Days since registration), reputation (Minimum reputation), replies_received (Replies received). Remove min_visits and max_flags entirely — there is no session-visit or flag threshold.
- **Evidence:** Doc 02-permissions.md:38-42 table lists min_posts, min_days, min_visits, min_reputation, max_flags. Code Trust_Levels::defaults() (class-trust-levels.php:109-130) returns posts, days_active, reputation, replies_received for levels 1-3. UI inputs settings.php:348-351 use [posts], [days_active], [reputation], [replies_received]. Sanitizer class-admin.php:390-395 persists exactly those four keys. No min_ prefix, no min_visits, no max_flags exists.

## 4. [MAJOR] `docs/website/admin-settings/02-permissions.md` — wrong-default
- **Issue:** Level 1 default thresholds documented as min_posts=1, min_days=1, min_visits=3, min_reputation=0; actual Level 1 defaults are posts=5, days_active=3, reputation=0, replies_received=10.
- **Fix:** Update the Level 1 defaults table: posts 5, days_active 3, reputation 0, replies_received 10.
- **Evidence:** Doc 02-permissions.md:47-51. Code class-trust-levels.php:111-117 level 1 => posts=>5, days_active=>3, reputation=>0, replies_received=>10 (matches the LEVELS requirements at :44-48).

## 5. [MAJOR] `docs/website/admin-settings/02-permissions.md` — inaccurate
- **Issue:** Trust level names mis-mapped at every level (doc: 0 New Member,1 Basic,2 Member,3 Regular,4 Trusted,5 Leader); code names are 0 Newcomer,1 Member,2 Regular,3 Trusted,4 Leader,5 Moderator.
- **Fix:** Rewrite the table to: 0 Newcomer, 1 Member, 2 Regular, 3 Trusted, 4 Leader, 5 Moderator. Note the auto-earned range is 0-3 and manual 4-5 (matches doc's earned-by column).
- **Evidence:** Doc 02-permissions.md:19-25 table. Code class-trust-levels.php:30-89 LEVELS: 0 'Newcomer', 1 'Member', 2 'Regular', 3 'Trusted', 4 'Leader', 5 'Moderator'. The admin UI level_names (settings.php:317-321) also confirms 1 Member, 2 Regular, 3 Trusted. Every doc row is off: doc L1 'Basic' vs code 'Member', doc L2 'Member' vs code 'Regular', doc L4 'Trusted' vs code 'Leader', doc L5 'Leader' vs code 'Moderator'.

## 6. [MAJOR] `docs/website/admin-settings/04-appearance.md` — missing-feature
- **Issue:** Appearance Color Palette card ships 4 extra color overrides (text_color, bg_color, bg_subtle_color, border_color) beyond Accent; undocumented.
- **Fix:** Add a Color Palette section documenting text_color, bg_color, bg_subtle_color, border_color (each sanitize_hex_color, empty = keep default, applied when 'Inherit theme colors' is off).
- **Evidence:** Code settings.php:757-799 'Color Palette' card renders accent_color (765), text_color (774), bg_color (781), bg_subtle_color (788), border_color (795). Sanitizer class-admin.php:462-463 loops sanitize_hex_color over text_color/bg_color/bg_subtle_color/border_color. Doc 04-appearance.md:20-50 documents only accent_color.

## 7. [MAJOR] `docs/website/admin-settings/05-seo.md` — wrong-key
- **Issue:** Default share image key documented as seo_default_share_image with a 'media library picker'; actual key is seo_default_og_image and the field is a plain URL text input.
- **Fix:** Change key to seo_default_og_image and reword instructions: paste an absolute image URL (it is a URL field, not a media-library picker).
- **Evidence:** Doc 05-seo.md:133 ('Setting: seo_default_share_image') and :137 ('Pick an image from the WordPress media library'). Code settings.php:990-992 name=jetonomy_settings[seo_default_og_image], <input type="url"> with a URL placeholder. Sanitizer class-admin.php:509 esc_url_raw($input['seo_default_og_image']). No media-library picker, no seo_default_share_image key.

## 8. [MAJOR] `docs/website/admin-settings/06-anti-spam.md` — wrong-key
- **Issue:** Provider key documented as antispam_provider and threshold as recaptcha_score_threshold; actual keys are captcha_provider and captcha_score_threshold.
- **Fix:** Change antispam_provider -> captcha_provider (values none|recaptcha_v3|turnstile) and recaptcha_score_threshold -> captcha_score_threshold. Optionally document captcha_site_key/captcha_secret_key.
- **Evidence:** Doc 06-anti-spam.md:22 ('Setting: antispam_provider') and :50 ('Setting: recaptcha_score_threshold'). Code settings.php:1120 name=jetonomy_settings[captcha_provider], :1149 name=jetonomy_settings[captcha_score_threshold]; also captcha_site_key (1130) and captcha_secret_key (1142). Sanitizer class-admin.php:481-488 uses captcha_provider, captcha_site_key, captcha_secret_key, captcha_score_threshold. No antispam_provider or recaptcha_score_threshold key exists.

## 9. [MAJOR] `docs/website/admin-settings/09-revisions.md` — wrong-key
- **Issue:** Revisions page documented as gated by jetonomy_manage_revisions (admin + moderator); the page is registered with jetonomy_manage_settings (administrator only) and jetonomy_manage_revisions does not exist.
- **Fix:** Change to: requires jetonomy_manage_settings (administrators only by default). Remove the 'moderator roles' claim — moderators/editors cannot open this page.
- **Evidence:** Doc 09-revisions.md:50 'Only users with the jetonomy_manage_revisions capability (admin and moderator roles by default)'. Code class-admin.php:149-156 add_submenu_page Revisions cap 'jetonomy_manage_settings'. Capabilities ROLE_MAP (class-capabilities.php:36-43) grants manage_settings only in the administrator entry, and the map is cumulative top-down (subscriber->...->administrator), so editor does NOT inherit it. No 'jetonomy_manage_revisions' string exists in the capability map.

## 10. [MAJOR] `docs/website/admin-settings/10-admin-users.md` — inaccurate
- **Issue:** Users page documented as viewable with jetonomy_moderate (and 'Editors have it automatically'); the menu is registered under jetonomy_manage_settings (administrator only).
- **Fix:** Change 'View the Users page' required cap to jetonomy_manage_settings and note it is administrator-only (Editors get jetonomy_moderate for inline ban/silence actions but cannot open the page menu).
- **Evidence:** Doc 10-admin-users.md:18 'View the Users page | jetonomy_moderate' and :24 'WordPress Administrators and Editors have jetonomy_moderate automatically.' Code class-admin.php:158-165 Users submenu cap 'jetonomy_manage_settings'. ROLE_MAP (class-capabilities.php:36-39) grants manage_settings only to administrator; editor entry (:27-35) has jetonomy_moderate but NOT manage_settings, and the cumulative merge stops adding admin caps below administrator. So an Editor with jetonomy_moderate still cannot open the Users page.

## 11. [MAJOR] `docs/website/admin-settings/11-admin-content.md` — inaccurate
- **Issue:** Content (Posts and Replies) page documented as requiring jetonomy_moderate auto-granted to Editors; the page menu is registered under jetonomy_manage_settings (administrator only).
- **Fix:** State that the 'Posts and Replies' admin page itself requires jetonomy_manage_settings (administrator only); jetonomy_moderate (which Editors have) governs the separate Moderation screen, not this page. Severity adjusted but still major — the cap named for page access is wrong.
- **Evidence:** Doc 11-admin-content.md:13-14 'Viewing and moderating content requires jetonomy_moderate. WordPress Administrators and Editors receive this capability automatically.' Code class-admin.php:107-114 Content submenu cap 'jetonomy_manage_settings' (administrator-only per ROLE_MAP). Editors do have jetonomy_moderate (class-capabilities.php:30) but that does not open this admin page. NOTE: a separate /community/mod/ Moderation menu IS registered under jetonomy_moderate (class-admin.php:116-123), so moderating content is genuinely editor-reachable there — just not THIS 'Posts and Replies' page.

## 12. [MAJOR] `docs/website/admin-settings/12-admin-categories.md` — inaccurate
- **Issue:** Categories page documented as requiring jetonomy_manage_spaces auto-granted to Editors; the page menu is registered under jetonomy_manage_settings, and both manage_spaces and manage_settings are administrator-only (Editors get neither).
- **Fix:** State the Categories page requires jetonomy_manage_settings (administrator only). Remove the claim that Editors get jetonomy_manage_spaces — that cap is administrator-only.
- **Evidence:** Doc 12-admin-categories.md:13-14 'Creating, editing, and deleting categories requires jetonomy_manage_spaces. WordPress Administrators and Editors receive this capability automatically.' Code class-admin.php:80-87 Categories submenu cap 'jetonomy_manage_settings'. ROLE_MAP (class-capabilities.php:36-39) lists jetonomy_manage_spaces AND jetonomy_manage_settings only in the administrator entry; editor entry (:27-35) has neither. Cumulative merge does not push admin caps down to editor. So 'Editors receive jetonomy_manage_spaces automatically' is false on both the named cap and the page-access cap.

## 13. [MINOR] `docs/website/admin-settings/01-general.md` — wrong-default
- **Issue:** Replies Per Page default documented as 20; code default is 30.
- **Fix:** Change Replies Per Page Default from 20 to 30 (line 54).
- **Evidence:** Doc 01-general.md:54 'Default: 20'. Code settings.php:270 absint($settings['replies_per_page'] ?? 30); class-admin.php:354 max(1, absint($input['replies_per_page'] ?? 30)). Both default to 30. (Posts Per Page is 20 — settings.php:266 — so the doc likely copied the posts default.)

## 14. [MINOR] `docs/website/admin-settings/01-general.md` — inaccurate
- **Issue:** Default Space Type fourth option documented as 'Show & Tell'; actual option is 'Feed' (value feed).
- **Fix:** Replace 'Show & Tell' with 'Feed' at lines 30 and 39 (value feed; chronological short-form feed).
- **Evidence:** Doc 01-general.md:30 ('Options: Forum, Q&A, Ideas, Show & Tell') and :39 ('Show & Tell - short-form cards...'). Code settings.php:165 <option value="feed">Feed</option>; class-admin.php:356 enum array('forum','qa','ideas','feed'). The space type filter (class-admin.php:1011) also uses 'feed'. No 'show_and_tell' value or 'Show & Tell' label anywhere.

## 15. [MINOR] `docs/website/admin-settings/03-email.md` — missing-feature
- **Issue:** Notification toggle table omits 3 real types (idea_status_changed, moderation, join_request); table lists 7 of 10 shipped types.
- **Fix:** Add three rows to the notification defaults table: Your idea roadmap status changed (idea_status_changed), Moderator action on your content (moderation), Space join request (join_request).
- **Evidence:** Doc 03-email.md:45-53 is a 7-row table (reply_to_post, reply_to_reply, mention, accepted_answer, vote_on_post, badge_earned, new_post_in_sub). Code settings.php:561-572 defines 10 notif_types adding idea_status_changed ('Your idea roadmap status changed'), moderation ('Moderator action on your content'), join_request ('Space join request').

## 16. [MINOR] `docs/website/admin-settings/03-email.md` — inaccurate
- **Issue:** Doc claims a type disabled at site level 'cannot be re-enabled' by members; code treats these as per-member-overridable defaults.
- **Fix:** Remove/rewrite the line 55 claim. These are defaults members can override; they are not a hard global kill-switch. Keep line 43's wording.
- **Evidence:** Doc 03-email.md:54-55 'Turning off a type at the site level disables it globally - individual members cannot re-enable a type you have disabled here.' This contradicts the same screen's own card description: settings.php:557 'Default on/off state ... Members can override these in their profile settings.' and doc's own line 43 ('Individual members can override their own preferences'). The two doc sentences are mutually contradictory; the code intent (overridable default) matches line 43, not line 55.

## 17. [MINOR] `docs/website/admin-settings/04-appearance.md` — wrong-key
- **Issue:** Theme Sidebar key documented as theme_sidebar and Page Padding as page_padding; actual keys are sidebar_visibility and padding_preset.
- **Fix:** Change Theme Sidebar key to sidebar_visibility (values theme|hide) and Page Padding key to padding_preset (values theme|none|comfortable).
- **Evidence:** Doc 04-appearance.md:71 ('Setting: theme_sidebar') and :83 ('Setting: page_padding'). Code settings.php:844 name=jetonomy_settings[sidebar_visibility], :861 name=jetonomy_settings[padding_preset]. Sanitizer class-admin.php:472-476 uses sidebar_visibility and padding_preset. No theme_sidebar or page_padding key exists.

## 18. [MINOR] `docs/website/admin-settings/04-appearance.md` — inaccurate
- **Issue:** Layout Density documented as two options (Comfortable, Compact); code offers three (Compact, Comfortable, Spacious).
- **Fix:** Add the Spacious option to the options list (line 100) and describe it (extra-roomy spacing).
- **Evidence:** Doc 04-appearance.md:100 'Options: Comfortable, Compact'; body only describes Comfortable and Compact (103-105). Code settings.php:881-883 renders compact / comfortable / spacious options.

## 19. [MINOR] `docs/website/admin-settings/05-seo.md` — missing-feature
- **Issue:** SEO tab ships a 'Social Embeds (Instagram & Facebook)' card with fb_app_id and fb_app_secret; undocumented.
- **Fix:** Add a Social Embeds section documenting fb_app_id (numeric Meta App ID) and fb_app_secret (App Secret) for Instagram/Facebook oEmbed; note other providers (YouTube/Vimeo/etc.) embed with no setup.
- **Evidence:** Code settings.php:1008-1031 'Social Embeds (Instagram & Facebook)' card with fb_app_id (1020) and fb_app_secret (1027), plus a Meta-app setup guide. Sanitizer class-admin.php:513-514 persists fb_app_id and fb_app_secret. Doc 05-seo.md has no social-embeds section.

## 20. [MINOR] `docs/website/admin-settings/05-seo.md` — inaccurate
- **Issue:** Noindex default for profiles documented as 'Off'; code defaults seo_noindex_profiles to ON. Also {page_number} title token is not actually offered in the UI title help.
- **Fix:** Change profiles default to On (matches code ?? true). Remove {page_number} from the available-tokens table unless/until it is added to the UI help and the renderer.
- **Evidence:** Doc 05-seo.md:102 'Default: Off for profiles, On for search' and :91 lists {page_number} as an available token. Code settings.php:970 checked($settings['seo_noindex_profiles'] ?? true) => default ON (same default for search at :975). Token help only lists {post_title}, {space_name}, {site_name} (settings.php:927, :934); no {page_number} token is documented in the field help.

## 21. [MINOR] `docs/website/admin-settings/07-access-control.md` — inaccurate
- **Issue:** Doc breadcrumb 'General > Access Control' implies a sub-page; it is one card on the General tab. Behavior (Public/Private guest_read) is accurate.
- **Fix:** Reword the breadcrumb to 'Settings -> General -> Access Control card' so users don't hunt for a separate sub-page. Severity is minor/cosmetic — keep, do not drop.
- **Evidence:** Doc 07-access-control.md:10 'Go to Jetonomy -> Settings -> General -> Access Control to choose the mode.' Code: Access Control is a card title (settings.php:278) inside the General tab, with the guest_read radio Public(1)/Private(0) at settings.php:282-306. There is no separate Access Control sub-nav/page. Behavior described is correct.

## 22. [MINOR] `docs/website/admin-settings/08-activity-log.md` — wrong-default
- **Issue:** Activity Log documented as paginated at 50 entries per page; default is 20.
- **Fix:** Change 50 to 20 (default; admins can adjust via the per-page screen option).
- **Evidence:** Doc 08-activity-log.md:47 'paginated server-side at 50 entries per page'. Code class-activity-list-table.php:161 get_items_per_page('jetonomy_activity_per_page', 20); also on_activity_load() screen option default 20 (class-admin.php:1133) and save clamp falls back to 20 (class-admin.php:1152).

## 23. [MINOR] `docs/website/admin-settings/10-admin-users.md` — inaccurate
- **Issue:** Trust level reference table uses wrong names (0 New,1 Basic,2 Member,3 Regular,4 Leader,5 Elder) and '5 = Elder' in filter text; code names differ at most levels and 'Elder'/'Basic' do not exist.
- **Fix:** Replace with 0 Newcomer, 1 Member, 2 Regular, 3 Trusted, 4 Leader, 5 Moderator at lines 105-110, and fix '5 = Elder' to '5 = Moderator' at line 31. Align with the corrected 02-permissions.md table.
- **Evidence:** Doc 10-admin-users.md:105-110 table and :31 '5 = Elder'. Code class-trust-levels.php:30-89: 0 Newcomer, 1 Member, 2 Regular, 3 Trusted, 4 Leader, 5 Moderator. Doc L1 'Basic' (code 'Member'), L3 'Regular' (code 'Trusted'), L5 'Elder' (code 'Moderator'); 'Basic'/'Elder' do not exist. Also internally inconsistent with 02-permissions.md which gives a different (also wrong) mapping.

## 24. [MINOR] `docs/website/admin-settings/13-extensions.md` — inaccurate
- **Issue:** Extensions menu correctly Pro-gated; toggle-action cap (manage_options vs the menu's jetonomy_manage_settings) cannot be fully verified from free code.
- **Fix:** For accuracy against the visible page, reference jetonomy_manage_settings (the menu cap) rather than manage_options, OR verify the Pro toggle AJAX handler's cap before asserting. Pro-gating claim is correct and needs no change.
- **Evidence:** Doc 13-extensions.md:9 'added by Jetonomy Pro... not visible on free-only installs' — confirmed: the Extensions submenu is registered only inside if(defined('JETONOMY_PRO_VERSION')) (class-admin.php:186-194) under cap 'jetonomy_manage_settings'. Doc :13 says toggling requires manage_options. The menu/page-access cap is jetonomy_manage_settings (administrator-only, so practically equivalent to manage_options for default installs), but the actual AJAX toggle handler lives in jetonomy-pro and was out of scope for this section, so the manage_options claim is unverified, not proven wrong.
