# Audit fixes — moderation-and-trust (15 findings)

## 1. [CRITICAL] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Trust levels presented as single-reputation thresholds (TL1=50, TL2=200, TL3=500, TL4=1000, TL5=2500) auto-earned across all 6 levels.
- **Fix:** Replace the threshold table with the actual multi-stat requirements per level (posts + days_active + replies_received + reputation for L1-L3), state that L4/L5 are manually granted only, and remove the 50/200/500/1000/2500 reputation numbers entirely.
- **Evidence:** Doc 01-trust-levels.md:15-24 table lists reputation-only thresholds 50/200/500/1000/2500. Code Trust_Levels::defaults() (class-trust-levels.php:109-130) defines multi-stat requirements: L1 = posts 5 + days_active 3 + replies_received 10 + reputation 0; L2 = posts 30 + days_active 20 + reputation 50; L3 = posts 100 + days_active 60 + reputation 200. LEVELS (:75-88) marks levels 4-5 requirements as [] '// Manually granted.'. Trust_Evaluator::evaluate_level (class-trust-evaluator.php:36-79) only evaluates levels 1-3 and never returns 4 or 5. None of the doc's numbers (50/200/500/1000/2500) appear anywhere in code.

## 2. [MAJOR] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Trust level names (TL0 New Member, TL1 Basic Member, TL2 Member, TL3 Regular, TL4 Leader, TL5 Trusted) match neither code name set.
- **Fix:** Align doc names to the canonical Trust_Levels class (0 Newcomer, 1 Member, 2 Regular, 3 Trusted, 4 Leader, 5 Moderator). Separately, the admin Users page labels diverge from the class - that is a code inconsistency worth flagging to engineering, but the doc should follow the canonical class.
- **Evidence:** Doc 01-trust-levels.md:19-24. Trust_Levels::LEVELS (class-trust-levels.php:32,43,54,65,76,83) = 0 Newcomer, 1 Member, 2 Regular, 3 Trusted, 4 Leader, 5 Moderator. Admin users.php:18-25 $trust_labels = 0 New, 1 Basic, 2 Member, 3 Regular, 4 Leader, 5 Elder. Doc's TL5 'Trusted' matches neither (canonical 'Moderator', admin 'Elder'); doc's TL1 'Basic Member', TL2 'Member', TL3 'Regular' do not match the canonical class names (Member/Regular/Trusted).

## 3. [MAJOR] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Configuring Thresholds describes one reputation number per level via Settings -> Permissions.
- **Fix:** Rewrite the Configuring Thresholds section to describe the four-input grid (posts, days active, reputation, replies received) per level for levels 1-3, and clarify levels 4-5 are not configurable (manual grant).
- **Evidence:** Doc 01-trust-levels.md:67-69. Settings Permissions tab (settings.php:310-378, gated by 'permissions' === $active_tab at :310) renders FOUR number inputs per level under jetonomy_settings[trust_thresholds][level][posts|days_active|reputation|replies_received] for levels 1-3 only (settings.php:348-351). No single reputation threshold input exists.

## 4. [MAJOR] `moderation-and-trust/02-flagging-reporting.md` — inaccurate
- **Issue:** Flagging described as a 'small modal' with free-text reason and 'Submit Report' button; never mentions structured reason categories.
- **Fix:** Soften 'small modal' to 'a prompt dialog'; drop the specific 'Submit Report' button name; add one line that moderators see a reason category (the member-side prompt currently files everything as 'Other', with the typed text saved as the description).
- **Evidence:** Doc 02-flagging-reporting.md:15. REST /flags requires reason enum spam|offensive|off_topic|harassment|other with description optional (class-moderation-controller.php:104-112). Frontend view.js:1364-1372 uses jetonomyPrompt (a single text prompt) and hardcodes reason:'other' with the typed text as description. Admin flag rows render reason labels (moderation.php:263 via ucfirst/str_replace) and flag-card.php:24-33 maps Spam/Offensive/Abuse-Harassment/Off-topic/Misinformation/Other.

## 5. [MAJOR] `moderation-and-trust/03-moderation-queue.md` — inaccurate
- **Issue:** Doc describes admin Bulk Actions (checkboxes + Bulk Action dropdown + Apply) on the Moderation page; no such UI exists there.
- **Fix:** Remove the Bulk Actions section from the Moderation Queue doc (or relocate/reframe it to the Content management page if that page actually surfaces bulk controls - verify separately). The Moderation screen offers per-row actions only.
- **Evidence:** Doc 03-moderation-queue.md:50-53. Admin moderation.php renders only per-row buttons (Approve/Spam/Trash at :101-104 and :183-186); there are no checkboxes, no bulk-action select, and no Apply button anywhere in the file. Bulk action exists only as REST POST /moderation/bulk (class-moderation-controller.php:161-181) and the wp_ajax_jetonomy_bulk_content_action handler (referenced in controller comment :157-160, and CLAUDE.md Content_Handler) which lives on the separate Content admin page, not the Moderation page.

## 6. [MAJOR] `moderation-and-trust/03-moderation-queue.md` — inaccurate
- **Issue:** Akismet section claims a 'Spam' filter tab and a 'Not Spam' restore button in the admin Moderation view.
- **Fix:** Rewrite the Akismet section: spam-flagged content is set to 'spam' status (not surfaced as a dedicated admin tab); there is no 'Not Spam' restore button in the Moderation UI. If a restore path exists it is via REST status filter only - verify before documenting any UI affordance.
- **Evidence:** Doc 03-moderation-queue.md:63-71. Admin moderation.php tabs are Pending Posts (:33), Pending Replies (:36), Flags (:39), Banned Users (:42) - no 'Spam' tab. No 'Not Spam' string exists in the file; flag controls are 'Valid (Trash)' and 'Dismiss' (:269-270). A status=pending|spam|all filter exists only on REST GET /moderation/queue (class-moderation-controller.php:43-49), not in any admin UI.

## 7. [MAJOR] `moderation-and-trust/03-moderation-queue.md` — missing-feature
- **Issue:** Doc lists three moderation actions (Approve, Spam, Trash) and omits the Hold action.
- **Fix:** Do NOT add Hold as a fourth moderator button (it is not one). Optionally add a sentence that automated moderation (Pro AI spam detection / mod rules) can place content into a held 'pending' state that then appears in Pending Posts/Replies for review.
- **Evidence:** Doc 03-moderation-queue.md:37-43. Moderation_Service::apply_object_status map includes 'hold' => 'pending' (class-moderation-service.php:344-349); system_set_object_status is a system-actor entry point (:322-324). Free class-ai-spam-detector.php:94 returns 'hold'. Pro spam-detector (jetonomy-pro/.../ai/class-spam-detector.php:124) and advanced-moderation extension also use 'hold'.

## 8. [MINOR] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Doc says admin can click 'Recalculate Trust Level' on a user to re-run the evaluator.
- **Fix:** Change the doc to describe the 'Change Trust Level' control as a manual override that sets the level directly. Drop the claim of an immediate re-evaluation trigger (no such button exists).
- **Evidence:** Doc 01-trust-levels.md:65. Admin users.php:89 row action is 'Change Trust Level' (class jetonomy-change-trust-trigger), which opens an inline select (#trust-level-select, users.php:140-147) that sets the level directly via Save. No 'Recalculate' string exists - grep over includes/admin/ returned no matches for Recalculate/recalculate.

## 9. [MINOR] `moderation-and-trust/01-trust-levels.md` — missing-feature
- **Issue:** Downvote reputation shown as single -2 row; auditor notes code splits post_downvoted/reply_downvoted and the -2 value is correct.
- **Fix:** No change needed for the -2 row itself. The missing scored actions are already captured in finding 6 - address there, not as a separate downvote-key issue.
- **Evidence:** Doc 01-trust-levels.md:52. Code POINTS_MAP (class-reputation.php:34-46): post_downvoted => -2, reply_downvoted => -2 (plus legacy 'downvoted' => -2 alias). Value -2 is correct; keys are split but functionally identical.

## 10. [MINOR] `moderation-and-trust/01-trust-levels.md` — missing-feature
- **Issue:** Reputation events table omits idea_planned (+20), flag_validated (+5), post_reported (-10), and never mentions points are admin-overridable.
- **Fix:** Add idea_planned (+20), flag_validated (+5), post_reported (-10) rows to the reputation table and add a sentence noting every point value is editable at Settings -> Permissions -> Reputation Points (jetonomy_settings[reputation_points]).
- **Evidence:** Doc 01-trust-levels.md:47-53 lists 5 events. Code POINTS_MAP (class-reputation.php:33-47) additionally defines idea_planned => 20, flag_validated => 5, post_reported => -10. Reputation::points_for (:64-93) reads admin overrides from jetonomy_settings[reputation_points], and settings.php:439-494 renders an editable Reputation Points table exposing all 9 actions with per-action number inputs.

## 11. [MINOR] `moderation-and-trust/03-moderation-queue.md` — inaccurate
- **Issue:** Doc says the queue has two sections (Pending Posts, Flagged Content); admin view has separate Pending Posts, Pending Replies, and Flags tabs.
- **Fix:** Update to reflect the four tabbed views (Pending Posts, Pending Replies, Flags, Banned Users). At minimum split Pending Posts from Pending Replies.
- **Evidence:** Doc 03-moderation-queue.md:23-33. Admin moderation.php nav-tab-wrapper (:32-44) has four tabs: Pending Posts (:33-35), Pending Replies (:36-38), Flags (:39-41), Banned Users (:42-44). The doc collapses Pending Posts and Pending Replies into one section and omits Banned Users from the queue-sections framing.

## 12. [MINOR] `moderation-and-trust/03-moderation-queue.md` — inaccurate
- **Issue:** Doc says flagged content 'Approve resolves the flag as dismissed', implying an Approve button on flag rows.
- **Fix:** Rename the flag-row actions in the doc to 'Valid (Trash)' (flag confirmed, content trashed) and 'Dismiss' (flag unfounded, content stays live). Remove the Approve->dismissed mapping.
- **Evidence:** Doc 03-moderation-queue.md:41,45. Flag rows expose only two buttons: 'Valid (Trash)' (data-resolution=valid) and 'Dismiss' (data-resolution=dismissed) (moderation.php:269-270). resolve_flag REST enum is valid|dismissed (class-moderation-controller.php:148-152). There is no 'Approve' control on flag rows.

## 13. [MINOR] `moderation-and-trust/04-anti-spam.md` — inaccurate
- **Issue:** Rate limits table shows Topics/day 3 and Replies/day 10 but omits Votes/day (default 5).
- **Fix:** Add a 'Votes per day | 5' row to the rate limits table in 04-anti-spam.md.
- **Evidence:** Doc 04-anti-spam.md:72-75 (two-row table, no votes). Rate_Limiter::defaults() (class-rate-limiter.php:68-73) = posts 3, replies 10, votes 5. Settings UI exposes a 'Votes per Day' input (settings.php:398-401). Posts/replies numbers are correct.

## 14. [MINOR] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Cross-doc contradiction: 01-trust-levels.md:39 marks 'Rate limit lifted' as No for TL1; 04-anti-spam.md says TL1+ exempt. Code confirms TL1+ exempt.
- **Fix:** In 01-trust-levels.md capability matrix, change 'Rate limit lifted' to Yes starting at TL1 (currently shows No at TL1).
- **Evidence:** Doc 01-trust-levels.md:39 capability row 'Rate limit lifted' = No for TL1, Yes from TL2. Doc 04-anti-spam.md:77 'Members at Trust Level 1 and above are exempt from all rate limits.' Code Rate_Limiter::get_limits (class-rate-limiter.php:85-88) returns [] (no limits) for trust_level >= 1. So 04-anti-spam.md matches code; 01-trust-levels.md is wrong.

## 15. [MINOR] `moderation-and-trust/01-trust-levels.md` — inaccurate
- **Issue:** Capability matrix: Skip CAPTCHA TL2+ and Upload images TL1+ are correct, but 'Daily post limit lifted'/'Rate limit lifted' wrongly marked No at TL1.
- **Fix:** Set both 'Daily post limit lifted' and 'Rate limit lifted' to Yes at TL1 in the capability matrix (rate limits apply only to TL0). Leave Skip CAPTCHA (TL2+) and Upload images (TL1+) unchanged - they are correct.
- **Evidence:** Doc 01-trust-levels.md:36,38-39. Skip CAPTCHA: captcha-manager.php:112-115 skips trust_level >= 2 (matches doc Yes at TL2+). Upload images: Trust_Levels::LEVELS level 1 abilities include 'upload_media' (class-trust-levels.php:50) (matches doc Yes at TL1+). Rate limits: Rate_Limiter::get_limits returns [] for trust_level >= 1 (class-rate-limiter.php:85-88), so 'Rate limit lifted' should be Yes at TL1, but doc shows No at TL1 / Yes at TL2.
