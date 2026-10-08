---
title: Error Messages
description: Every error Jetonomy can show a member, what causes it, and what to do. Search this page for the exact wording the member saw.
order: 1
---

# Error Messages

When a member reports an error, search this page for the wording they saw.

The **Code** column is the identifier the REST API returns. You will see it in a debug log or an API response rather than on screen, and it is the more reliable thing to search for if the wording has been translated.

> Generated from the plugin source, so the wording matches what members actually see. A message that is not listed here comes from WordPress or another plugin, not from Jetonomy.


## Signing in and registering

| Message | Code | What it means |
|---|---|---|
| Incorrect username or password. | `jetonomy_invalid_credentials` |  |
| Enter your username and password. | `jetonomy_missing_credentials` |  |
| Enter your username or email. | `jetonomy_missing_user_login` |  |
| Password must be at least 8 characters. | `jetonomy_password_too_short` |  |
| That username is unavailable. | `jetonomy_username_unavailable` | That username is taken. |
| That email is unavailable. | `jetonomy_email_unavailable` | An account already exists with that email. Point them at password reset rather than a second account. |
| Please use a permanent email address. Disposable mailboxes are not accepted. | `jetonomy_disposable_email` | The address is on the disposable-domain blocklist. Turn the check off at **Settings -> Anti-Spam** if you would rather accept them. |
| Registration is disabled on this site. | `jetonomy_registration_disabled` | Registration is off. Either WordPress's own *Anyone can register* is unchecked, or Jetonomy's registration setting is disabled. |
| Could not create the account. Please try again. | `jetonomy_invalid_signup` |  |
| Confirm your email to finish signing up before posting. | `jetonomy_pending_verification` | Email verification is on and this member has not clicked their link. They can request another; the reminder interval is set at **Settings -> Email**. |
| Cookie nonce is invalid. | `rest_cookie_invalid_nonce` | The page sat open long enough for its security token to expire. Reloading fixes it. Jetonomy retries automatically in most places, so a member should rarely see this. |
| You must be logged in to perform this action. | `rest_not_logged_in` | The action needs a signed-in member. |
| You must be logged in to reply. | `jetonomy_not_logged_in` | The action needs a signed-in member. |
| You must be logged in. | `jetonomy_unauthorized` | The action needs a signed-in member. |
| CAPTCHA verification failed. Please try again. | `jetonomy_captcha_failed` | The CAPTCHA response was rejected. Usually a mistyped challenge or an expired form left open too long. Persistent failures across many members point at wrong keys - recheck them at **Settings -> Anti-Spam**. |
| Application passwords are not available on this site. | `jetonomy_app_passwords_unavailable` | The mobile app sign-in needs WordPress Application Passwords, which are disabled on this site. They require HTTPS. |
| This connection request came from an app this site does not recognise. | `jetonomy_app_bad_scheme` | The app callback address was rejected. Usually a mismatched custom scheme in a white-labelled build. |
| This connection screen has expired. Go back to the app and try connecting again. | `jetonomy_app_bridge_expired` | The app connect link timed out. Start the sign-in again from the app. |

## Blocked from posting

| Message | Code | What it means |
|---|---|---|
| Too many attempts. Please wait a while and try again. | `jetonomy_rate_limited` | The member is **Trust Level 0** and hit a daily cap. Levels 1 and above have no limits at all, so this only ever affects brand-new accounts. Caps live at **Settings -> Permissions** (defaults: 3 topics, 10 replies, 5 votes per day). The 24-hour window restarts from their *last* attempt, so a member who keeps retrying keeps resetting their own clock - tell them to stop and wait, or raise their trust level. |
| Your account has been banned from this community. | `jetonomy_user_banned` | The account was banned at **Jetonomy -> Users**. Bans block reading and posting everywhere. Unban from the same screen. |
| Your account is currently silenced and cannot post. | `jetonomy_user_silenced` | The account is silenced: it can still read, but not post. Also managed at **Jetonomy -> Users**. |
| This post is closed and cannot receive new replies. | `jetonomy_post_closed` | The topic is closed. Members cannot reply. **Moderators can** - they see the composer and their reply is accepted, and the topic stays closed afterwards. If a moderator sees this error, they lack the `moderate` permission in that space. |
| This space is archived or locked and no longer accepts new posts. | `jetonomy_space_restricted` | The space status is Archived or Locked, so it accepts no new posts. Change the status on the space's edit screen. Archived also hides the space from listings; Locked leaves it visible and readable. |
| User is not a member of this space. | `jetonomy_not_member` | The space requires membership and the member has not joined. Either they join, or an admin adds them on the space's Members tab. |
| You do not have permission to perform this action. | `jetonomy_forbidden` | The member lacks the permission this action needs. Check their space role and trust level at **Settings -> Permissions**. |
| You do not have permission to perform this action. | `rest_forbidden` | Same as above, raised by WordPress itself rather than Jetonomy. |
| Accepted answers only apply to Q&A spaces. | `jetonomy_not_qa_space` | Accepting an answer only works in a Q&A space. |
| Roadmap status only applies to Ideas spaces. | `jetonomy_not_ideas_space` | Roadmap status only applies in an Ideas space. |
| This reply is not the accepted answer. | `jetonomy_not_accepted` |  |
| Invalid published_at: expected Y-m-d H:i:s or ISO 8601. | `jetonomy_invalid_published_at` |  |

## Moderation actions

| Message | Code | What it means |
|---|---|---|
| You cannot restrict your own account. | `jetonomy_cannot_ban_self` | You cannot ban your own account. |
| Administrators cannot be restricted. | `jetonomy_cannot_ban_admin` | Site administrators cannot be banned from inside Jetonomy. Change their WordPress role first. |
| Only an administrator can restrict a moderator. | `jetonomy_cannot_ban_moderator` | Moderators cannot be banned by another moderator. Demote them first. |
| Failed to issue restriction. | `jetonomy_ban_failed` |  |
| Failed to remove restriction. | `jetonomy_unban_failed` |  |
| You cannot report your own content. | `jetonomy_flag_self` | Members cannot report their own content. |
| The reported content no longer exists. | `jetonomy_flag_target_missing` |  |
| You have already reported this content. | `jetonomy_already_flagged` | This member has already reported that item; a second report adds nothing to the queue. |
| Failed to create flag. | `jetonomy_flag_failed` |  |
| Failed to merge topics. | `jetonomy_merge_failed` |  |
| Failed to split reply into new topic. | `jetonomy_split_failed` |  |
| Permanently deleting a space is restricted to site administrators on this community. | `jetonomy_purge_not_allowed` | Permanent purge is refused for this account. Check the purge permission on the space. |

## Spaces and membership

| Message | Code | What it means |
|---|---|---|
| Space not found. | `jetonomy_space_not_found` | No space with that id or slug. After changing a space slug, old links 404 until they are updated. |
| A space must keep at least one admin. Promote someone else first. | `jetonomy_last_admin_required` | Every space keeps at least one admin. Promote someone else before removing or demoting the last one. |
| You cannot remove your own admin role. Ask another admin to do it. | `jetonomy_cannot_self_demote` | You cannot remove your own admin role from a space - someone else has to. |
| No one else can take over this space, so it cannot be transferred. An administrator must delete it permanently instead. | `jetonomy_no_successor` |  |
| Join request not found or already processed. | `jetonomy_join_request_not_found` |  |
| Access rule not found. | `jetonomy_rule_not_found` |  |
| Failed to create access rule. | `jetonomy_rule_create_failed` |  |
| Failed to delete access rule. | `jetonomy_rule_delete_failed` |  |
| At least one user_id is required. | `jetonomy_empty_user_ids` |  |

## Uploads

| Message | Code | What it means |
|---|---|---|
| No file provided. | `jetonomy_no_file` | The request carried no file. Usually the upload was silently dropped for exceeding the server limit - the cap is the smaller of WordPress's own limit and 10 MB. |
| This file type is not allowed. | `jetonomy_upload_type` | The file extension is not on the allowed list. Developers can widen it with the `jetonomy_upload_allowed_types` filter. |
| You are not allowed to upload media. | `jetonomy_upload_forbidden` | The member lacks upload permission in this space. |

## Tags and categories

| Message | Code | What it means |
|---|---|---|
| Tag not found. | `jetonomy_tag_not_found` |  |
| A tag with that slug already exists. | `jetonomy_tag_slug_taken` | Another tag already uses that slug. |
| Name cannot be empty. | `jetonomy_tag_name_empty` |  |
| Name is required. | `jetonomy_tag_name_required` |  |
| Slug cannot be empty. | `jetonomy_tag_slug_empty` |  |
| No data to update. | `jetonomy_tag_no_changes` |  |
| Failed to create tag. | `jetonomy_tag_create_failed` |  |
| Failed to update tag. | `jetonomy_tag_update_failed` |  |
| Failed to delete tag. | `jetonomy_tag_delete_failed` |  |
| Failed to create category. | `jetonomy_create_failed` |  |
| Failed to delete category. | `jetonomy_delete_failed` |  |

## Deleting an account

| Message | Code | What it means |
|---|---|---|
| Type DELETE to confirm you want to permanently delete your account. | `jetonomy_confirm_required` | Account deletion needs the literal word DELETE as confirmation. |
| That password is incorrect. | `jetonomy_bad_password` | The password given to confirm account deletion was wrong. |
| Site administrators can\ | `jetonomy_admin_must_use_wp_admin` | Accounts holding `manage_options` cannot be self-deleted through the community. Remove them in WordPress under Users. |
| We couldn | `jetonomy_delete_account_failed` |  |
| Choose a display name from your own name fields. | `jetonomy_invalid_display_name` |  |

## Other

| Message | Code | What it means |
|---|---|---|
| Not a Jetonomy thread URL. | `jetonomy_oembed_not_found` | The URL is not a Jetonomy topic, so there is nothing to embed. |
| No notifications selected. | `jetonomy_invalid_ids` |  |
| All fields are required. | `jetonomy_missing_fields` |  |

## Still stuck

If the message is not here, or the fix above did not work, turn on `WP_DEBUG_LOG` and reproduce the problem. Jetonomy writes the error code to the log alongside the request, which usually identifies the surface involved even when the on-screen wording is generic.

