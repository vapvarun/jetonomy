# Audit fixes — spaces-and-categories (13 findings)

## 1. [CRITICAL] `02-space-types.md` — inaccurate
- **Issue:** Doc presents FIVE space types (Forum, Q&A, Ideas, Show & Tell, Social Feed); code has exactly FOUR (forum, qa, ideas, feed).
- **Fix:** Rewrite as 'The Four Space Types' (Forum, Q&A, Ideas, Feed). Delete the Show & Tell section. Rename 'Social Feed' to 'Feed' and describe the short-form/feed-card behavior of the 'feed' type.
- **Evidence:** doc 02-space-types.md:12 'The Five Space Types', :69 Show & Tell section, :86 Social Feed section. Code includes/db/class-schema.php:126 type ENUM('forum','qa','ideas','feed'); includes/api/class-spaces-controller.php:378,381 validates against array('forum','qa','ideas','feed'); admin includes/admin/views/space-edit.php and templates/views/space-edit.php:146-149 dropdown lists only Forum/Q&A/Ideas/Feed; templates/views/new-space.php:83-86 same four. The 'Feed' UI label is plain 'Feed: short-form posts', not 'Social Feed'.

## 2. [CRITICAL] `05-show-and-tell.md` — inaccurate
- **Issue:** Entire page documents a 'Show & Tell' space type that does not exist in code.
- **Fix:** Delete this page (or repurpose it as a 'Feed spaces' guide describing the 'feed' type). Remove the cross-links to it from 02-space-types.md.
- **Evidence:** doc 05-show-and-tell.md:1-40 (and full page) describes Show & Tell as a selectable space type with its own card layout. Code: type ENUM only forum/qa/ideas/feed (includes/db/class-schema.php:126); controller validation array('forum','qa','ideas','feed') (class-spaces-controller.php:378); card-grid is the 'feed' type behavior — templates/views/space.php:431 sets feed-card only when 'feed' === space->type. Demo seeder creates a 'Show & Tell' titled space with type='feed' (includes/admin/ajax/class-demo-seeder.php:171-173).

## 3. [MAJOR] `07-front-end-create-space.md` — inaccurate
- **Issue:** Type field options listed as 'Forum, Q&A, Ideas, Show & Tell, or Social Feed'; code offers only forum/qa/ideas/feed.
- **Fix:** Change line 51 to 'Forum, Q&A, Ideas, or Feed.'
- **Evidence:** doc 07-front-end-create-space.md:51. Code templates/views/new-space.php:83-86 selects only forum / qa / ideas / feed with labels 'Forum: discussions and replies', 'Q&A: questions with accepted answers', 'Ideas: feedback voted by members', 'Feed: short-form posts'.

## 4. [MAJOR] `08-front-end-edit-space.md` — inaccurate
- **Issue:** Type field options listed as 'Forum, Q&A, Ideas, Show & Tell, or Social Feed'; code offers only forum/qa/ideas/feed.
- **Fix:** Change line 44 to 'Forum, Q&A, Ideas, or Feed.'
- **Evidence:** doc 08-front-end-edit-space.md:44. Code templates/views/space-edit.php:146-149 lists only forum/qa/ideas/feed (option value='feed' label 'Feed: short-form posts').

## 5. [MAJOR] `03-membership-policies.md` — inaccurate
- **Issue:** Who Can Post / Who Can Reply documented as trust-level-based ('Anyone', 'trust level 1+', 'Moderators only'); code is role-based and omits the existing 'Admins Only' option.
- **Fix:** Replace both tables with the real role-based options. Who Can Post: Use Global Default / Members Only / Moderators & Admins / Admins Only. Who Can Reply: Use Global Default / Members Only / Moderators & Admins. Drop the 'trust level 1+' rows.
- **Evidence:** doc 03-membership-policies.md:79-91 lists 'Anyone (members)', 'Members with trust level 1+', 'Moderators only'. Code includes/admin/views/space-edit.php:376-380 (Who Can Post): '' (Use Global Default), 'members' (Members Only), 'moderators' (Moderators & Admins), 'admins' (Admins Only); lines 387-390 (Who Can Reply): '', members, moderators (no admins option on reply).

## 6. [MAJOR] `07-front-end-create-space.md` — inaccurate
- **Issue:** Create form claimed to 'mirror wp-admin field-for-field' and to include a 'Slug' field and a 'Posts per page' field; neither is on the create form.
- **Fix:** Remove the Slug and Posts per page rows from the create-form field table. Soften 'mirrors the wp-admin space editor field-for-field' — note slug is auto-generated and posts-per-page is set on the edit form, not create. Validation Hints about editing the slug (lines 83) should also be corrected.
- **Evidence:** doc 07-front-end-create-space.md:42,47,54. Code templates/views/new-space.php:68-151 — fields are title, description, type, visibility, join_policy, category_id, cover_image, icon picker. No slug input and no posts_per_page input exist on the create form.

## 7. [MAJOR] `08-front-end-edit-space.md` — inaccurate
- **Issue:** Lists a 'Welcome message' editable field; no such field or welcome_message key exists in the editor, Space model, or settings.
- **Fix:** Remove the 'Welcome message' row from the field table (line 49) and the mention in the intro/What You Can Edit list (lines 7, 12).
- **Evidence:** doc 08-front-end-edit-space.md:49 (and listed at :12). Code: grep 'welcome_message' across includes/ and templates/ returns nothing; grep 'welcome' in templates/views/space-edit.php, includes/admin/views/space-edit.php, and includes/models/class-space.php all return nothing. Unrelated 'welcome' hits exist only in email/verification-reminder, icon-picker label, and home.php copy.

## 8. [MAJOR] `08-front-end-edit-space.md` — inaccurate
- **Issue:** Cover upload documented with a 5MB JPEG/PNG/WebP limit, a custom /uploads/jetonomy/covers/<space-id>/ path, and old-file deletion on replace; code uses standard WP media_handle_upload (default uploads path, no custom limits, URL-only storage).
- **Fix:** Rewrite the Cover Image section to say uploads go to the standard WordPress media library, that recommended dimensions are guidance not enforced limits, and that removing a cover clears the reference (it does not delete the underlying attachment). Verify and qualify the 'works without upload_files capability' claim against the media controller's permission_callback before keeping it.
- **Evidence:** doc 08-front-end-edit-space.md:61-65. Code includes/api/class-media-controller.php:69 media_handle_upload('file',0) — standard WP attachment into /wp-content/uploads/YYYY/MM/, no explicit size/MIME restriction; includes/api/class-spaces-controller.php:401,501-502 cover_image stored via esc_url_raw as a URL string.

## 9. [MAJOR] `07-front-end-create-space.md` — wrong-hook
- **Issue:** References non-existent filters 'jetonomy_can_create_space' and 'jetonomy_can_create_space_with_visibility'.
- **Fix:** Remove both filter references. Replace the 'Developer Hooks' bullet (line 117) with the real gate: jetonomy_create_spaces capability + the front-end-creation roles option, plus jetonomy_use_frontend_space_edit. Reword line 78 to not promise an existing filter.
- **Evidence:** doc 07-front-end-create-space.md:78,93,117. Code: grep 'jetonomy_can_create_space' across includes/ and templates/ returns nothing; audit/manifest.json has no such hook. Actual gating is capability 'jetonomy_create_spaces' (includes/blocks/class-blocks.php) and the front-end role option; the real surfaced filter in this area is jetonomy_use_frontend_space_edit (includes/functions.php).

## 10. [MINOR] `07-front-end-create-space.md` — wrong-default
- **Issue:** Posts per page documented as a 10/25/50 dropdown; where it exists (edit form) it is a numeric input min=1 max=100.
- **Fix:** On 08-front-end-edit-space.md:47 change 'Posts per page | 10, 25, or 50' to 'a number from 1 to 100; leave blank to use the site default.' On the create doc, remove the row entirely (field is not on the create form).
- **Evidence:** doc 07-front-end-create-space.md:54 and 08-front-end-edit-space.md:47. Code templates/views/space-edit.php:184-194 — type='number' min='1' max='100', placeholder 'Default', help 'Leave blank to use the site default.'

## 11. [MINOR] `04-space-settings.md` — inaccurate
- **Issue:** Access Rules 'Rule Type' table lists MemberPress and PMPro as separate rule types and omits the 'everyone' type; valid types are membership/role/capability/trust_level/logged_in/everyone.
- **Fix:** Replace the MemberPress and Paid Memberships Pro rows with a single 'Membership' row (note the specific provider — MemberPress, PMPro, and in Pro WooCommerce/RCP/LearnDash — is chosen within the membership rule via adapter). Add an 'Everyone' row. Keep the free/Pro adapter note at line 102.
- **Evidence:** doc 04-space-settings.md:80-88 lists six types: Logged In, WordPress Role, Capability, Trust Level, MemberPress, Paid Memberships Pro. Code includes/admin/ajax/class-spaces-handler.php:392 valid_types = membership, role, capability, trust_level, logged_in, everyone. MemberPress/PMPro adapters live in includes/adapters/ (free); Pro adds WooCommerce/RCP/LearnDash.

## 12. [MINOR] `02-space-types.md` — outdated
- **Issue:** Says 'Social Feed is available in Jetonomy 1.0' with 1.1-planned features; plugin is 1.5.0 and the type is labeled 'Feed'.
- **Fix:** Drop the 1.0/1.1 version note (or update to reflect what actually shipped) and rename 'Social Feed' to 'Feed' throughout the section.
- **Evidence:** doc 02-space-types.md:99. Code jetonomy.php:6 Version: 1.5.0; templates/views/new-space.php:86 label 'Feed: short-form posts'.

## 13. [MINOR] `07-front-end-create-space.md` — inaccurate
- **Issue:** Default icon-picker icon list is wrong; doc names sparkles/code/life-buoy/palette/briefcase/gamepad/heart/globe/target/trophy which are not in the default set.
- **Fix:** Replace the icon list at line 62 with the actual default set: users, hand, megaphone, message-circle, help-circle, lightbulb, star, rocket, book-open, award, shield, pin, bookmark, home, hash, folder. Optionally list the 8 extended icons (user, settings, bell, flag, image, eye, lock, smile-plus).
- **Evidence:** doc 07-front-end-create-space.md:62. Code templates/partials/icon-picker.php:47-174 — default (non-extended) icons: users, hand, megaphone, message-circle, help-circle, lightbulb, star, rocket, book-open, award, shield, pin, bookmark, home, hash, folder (16); extended (extended=>true): user, settings, bell, flag, image, eye, lock, smile-plus (8).
