# Audit fixes — notifications (7 findings)

## 1. [MAJOR] `notifications/01-notifications.md` — inaccurate
- **Issue:** Full notifications page paginated 25 per page
- **Fix:** Change '25 per page' to '20 per page' and describe it as a 'Load More' pattern rather than numbered pagination.
- **Evidence:** doc 01-notifications.md:58 says 'paginated 25 per page'. Code templates/views/notifications.php:28 sets $page_size = 20, :33 $has_more = count(...) === $page_size, and :252-261 renders the 'pagination' partial with has_more (Load More pattern), not numbered pages. The docblock at :7-8 explicitly states 'Load More pagination at 20 rows / page'.

## 2. [MAJOR] `notifications/01-notifications.md` — inaccurate
- **Issue:** 90-day auto-delete cron for notifications
- **Fix:** Replace with: 'A weekly background job marks unread notifications older than 30 days as read. Notifications are not auto-deleted from the database.' Or drop the sentence entirely if too detailed for customer docs.
- **Evidence:** doc 01-notifications.md:58 says 'Notifications older than 90 days are automatically cleaned up from the database by a background cron job'. Code class-cron.php:31 schedules jetonomy_cleanup_notifications weekly; :247 docblock 'Mark old unread notifications as read (runs weekly, 30 days)'; :255 $cutoff = time() - (30 * DAY_IN_SECONDS); :257-263 runs UPDATE ... SET is_read = 1 WHERE is_read = 0 AND created_at < cutoff. No DELETE, no 90-day window.

## 3. [MAJOR] `notifications/02-email-settings.md` — wrong-default
- **Issue:** Reply to your reply email default shown as Yes
- **Fix:** Change the 'Reply to your reply' email-by-default value from 'Yes' to 'No'.
- **Evidence:** doc 02-email-settings.md:21 'Reply to your reply | Yes'. Code class-jetonomy.php:153-156 seeds reply_to_reply => ['web' => true, 'email' => false]. By contrast reply_to_post:149-152 is email=true. So reply_to_reply ships with email OFF.

## 4. [MAJOR] `notifications/02-email-settings.md` — inaccurate
- **Issue:** Non-existent 'Email notifications enabled' master kill-switch documented
- **Fix:** Remove the 'Email notifications enabled' row from the Configuring Default Settings table. If documenting a true global pause, point to the per-member 'Pause all email' toggle instead.
- **Evidence:** doc 02-email-settings.md:49 lists 'Email notifications enabled | Yes | Master switch - turns off all notification email for the site'. Code settings.php Email screen (read :500-628) exposes only Email Sender card (email_from_name :509, email_from_email :515, email_logo_url :521, adapter display, test email), Notification Defaults card (per-type web/email checkboxes :582-605), and Email Templates card (email_footer_text :623, per-type templates). There is no site-wide master email-enabled toggle key. The only opt-out is per-user (settings['notifications'][type]['email']).

## 5. [MINOR] `notifications/02-email-settings.md` — inaccurate
- **Issue:** Unsubscribe confirmation page with per-type / all options
- **Fix:** Rewrite to: clicking the link immediately unsubscribes the member from that one notification type and shows a confirmation message. Remove the 'confirmation page' and 'unsubscribe from all in one click' claims.
- **Evidence:** doc 02-email-settings.md:67 says clicking unsubscribe 'takes the member to a confirmation page where they can unsubscribe from that specific notification type or from all notification emails in one click'. Code class-jetonomy.php:573-605 handle_email_unsubscribe verifies token, then immediately sets settings['notifications'][$type]['email'] = false (:596) for the single type from the link, and renders wp_die confirmation (:600-604). No interstitial choice page, no 'unsubscribe from all' option.

## 6. [MINOR] `notifications/01-notifications.md` — inaccurate
- **Issue:** Idea status change recipient and channels mis-stated
- **Fix:** Set recipient to 'Idea author' only and channels to 'In-app, email'. Drop 'all followers of that space', 'Activity log', and 'email digest'.
- **Evidence:** doc 01-notifications.md:42 'An idea's status changes (Ideas spaces) | Idea author + all followers of that space | Activity log, email digest, in-app inbox'. Code class-notifier.php:592-618 on_idea_status_changed resolves $author_id = post->author_id (:597) and notifies only that author via create_and_maybe_email(:605) (in-app + immediate per-event email); no follower fan-out. class-jetonomy.php:165-168 sets idea_status_changed default web=true/email=true (immediate email, not a digest).

## 7. [MINOR] `notifications/02-email-settings.md` — missing-feature
- **Issue:** Enriched email template placeholders undocumented
- **Fix:** Add a placeholder reference list to the Editable Email Templates section including {site} {user} {message} {type} {url} plus the enriched {post_title} {actor_display_name} {reply_excerpt} {space_title}. (Admin UI help text at settings.php:616 should also be updated, but that is a code change outside docs scope.)
- **Evidence:** doc 02-email-settings.md:32-41 (Editable Email Templates section) does not mention any placeholders. Admin help text settings.php:616 advertises only {site} {user} {message} {type} {url}. Code class-notifier.php:802-815 additionally supports {post_title}, {actor_display_name}, {reply_excerpt}, {space_title} (added 1.3.6 per :797-799 comment).
