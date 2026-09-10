---
title: Role Capability Mapping
description: Decide which Jetonomy capability each WordPress role holds - who can post, moderate, manage spaces or change settings.
order: 18
---

# Role Capability Mapping

**Settings -> Permissions -> Role Capability Mapping**

This grid decides which Jetonomy capabilities each WordPress role carries. It is the first of the three layers that answer "is this member allowed to do that", and the only one that applies site-wide rather than per space.

Rows are capabilities, columns are the WordPress roles on your site. Tick a box to grant, untick to revoke. Changes apply on save, to every member holding that role.

> **Only administrators can change this grid.** Other roles see it read-only with a padlock, even if they can otherwise reach the Settings screen. Granting the ability to moderate is not something a moderator should be able to grant themselves.

## Administrators are not listed

The administrator column is deliberately absent. Site administrators always hold every Jetonomy capability and it cannot be revoked here - if it could, an administrator could lock themselves and everyone else out of the plugin's own settings screen.

## The capabilities

Grouped by what they let someone do. The identifier in `code` is what a developer would check with `current_user_can()`.

### Taking part

| Capability | Lets the member |
|---|---|
| `jetonomy_read` | Read the community at all |
| `jetonomy_create_posts` | Start topics |
| `jetonomy_create_replies` | Reply to topics |
| `jetonomy_edit_own_posts` | Edit their own posts |
| `jetonomy_delete_own_posts` | Delete their own posts |
| `jetonomy_vote` | Vote on posts and replies |
| `jetonomy_flag` | Report content to moderators |
| `jetonomy_join_spaces` | Join spaces that allow it |
| `jetonomy_upload_media` | Attach files and images |
| `jetonomy_create_spaces` | Create spaces programmatically |

### Moderating

| Capability | Lets the member |
|---|---|
| `jetonomy_edit_others_posts` | Edit anyone's post |
| `jetonomy_delete_others_posts` | Delete anyone's post |
| `jetonomy_moderate` | Work the moderation queue, resolve reports, ban members |
| `jetonomy_manage_users` | Manage community members |
| `jetonomy_move_posts` | Move topics between spaces |
| `jetonomy_close_posts` | Close topics |
| `jetonomy_pin_posts` | Pin topics |

### Running the community

| Capability | Lets the member |
|---|---|
| `jetonomy_manage_settings` | Change Jetonomy settings |
| `jetonomy_manage_categories` | Create and edit categories |
| `jetonomy_manage_spaces` | Manage every space |
| `jetonomy_manage_badges` | Manage badges |
| `jetonomy_view_analytics` | View community analytics |
| `jetonomy_manage_extensions` | Enable and disable Pro extensions |

## Defaults

Out of the box, capabilities accumulate up the role hierarchy - each role holds everything the roles below it hold, plus its own additions.

| Role | Adds |
|---|---|
| **Subscriber** | Read, create topics and replies, edit and delete own posts, vote, report, join spaces |
| **Contributor** | Upload media |
| **Author** | Create spaces |
| **Editor** | Edit and delete others' posts, moderate, manage users, move, close and pin topics |
| **Administrator** | Manage settings, categories, all spaces, badges, analytics and extensions |

So an Editor holds everything a Subscriber, Contributor and Author hold, plus the moderation set. This is why Editor is the natural role for a community moderator with no other change needed.

## Roles from other plugins

Roles registered by other plugins - an LMS student role, a membership tier, a marketplace vendor - appear as columns here, but they start with **no Jetonomy capabilities at all**.

They are not left stranded, though. A member whose role carries nothing can still take part where a space roster row or an access rule puts them, because the permission system falls through to those. What that fallback grants is deliberately **member-grade only**: posting, replying, voting. It never grants moderation, no matter what role a roster row or access rule names. Moderation has to come from this grid or from an explicit space role.

If a custom role should behave like a full community member everywhere rather than only in spaces it has been granted, tick the taking-part capabilities for it here.

## Common changes

**Read-only community.** Untick `jetonomy_create_posts` and `jetonomy_create_replies` for Subscriber. Members can read and vote but not post. Useful for an announcement-style community.

**Moderators who cannot delete.** Give a role `jetonomy_moderate` but leave `jetonomy_delete_others_posts` unticked. They can work the queue, resolve reports and hide content without permanently removing anyone's writing.

**No file uploads.** Untick `jetonomy_upload_media` everywhere below Administrator. Useful where storage or moderation load is a concern.

**Analytics for a manager.** Tick `jetonomy_view_analytics` for a role that holds nothing else. They see the numbers without gaining any content control.

## How this fits with space roles

This grid is site-wide. A member's role in an individual space - member, moderator or admin - is set per space and layered on top. See [Space Status and Member Roles](../spaces-and-categories/05-space-status-and-roles.md).

The two combine rather than compete: a site-wide capability applies everywhere, and a space role applies only in its own space. Someone can be an ordinary member site-wide and a moderator of one space, or hold `jetonomy_moderate` site-wide and moderate everywhere.

If you are troubleshooting "why can this member not do X", check this grid first, then their space role, then their trust level.
