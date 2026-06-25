The Users admin screen gives moderators and administrators a full view of every community member - with one-click access to ban, silence, or change trust level without leaving wp-admin.

## What You Will Learn

- How to search and filter members in the admin
- How to ban, silence, or space-ban a member
- How to override a member's trust level manually
- Which capability gates access to this screen

Go to **Jetonomy → Users** to access this screen.

## Required Capability

The Users screen and its actions are gated by:

| Action | Required Capability |
|---|---|
| View the Users page | `jetonomy_manage_settings` |
| Ban or unban a member | `jetonomy_moderate` |
| Silence a member | `jetonomy_moderate` |
| Change trust level | `jetonomy_manage_settings` |
| Search users (AJAX picker in other admin screens) | `jetonomy_manage_spaces` |

The Users page menu is administrator-only (`jetonomy_manage_settings`). Editors receive `jetonomy_moderate` automatically, which powers the inline ban and silence actions, but it does not open the Users page menu. See **Settings → Permissions → WordPress Role Mapping** for the full capability table.

## Searching and Filtering

The toolbar at the top of the Users screen has two controls:

1. **Search box** - searches by username or display name. Type at least two characters; results update on submit.
2. **Trust Level filter** - dropdown to show only members at a specific trust level (0 = Newcomer through 5 = Moderator). Select "All Trust Levels" to show everyone.

The paginator at the bottom shows total user count and lets you move between pages.

## User Table Columns

| Column | Description |
|---|---|
| Username | Avatar, login name, and row action links |
| Display Name | Public display name |
| Trust Level | Badge showing current level (0-5) |
| Reputation | Total reputation score |
| Posts | Post count |
| Replies | Reply count |
| Joined | Registration date (relative) |
| Last Seen | Most recent login (relative) |

## Row Actions

Hover over a row to reveal the action links under the username.

### View Profile

Opens the standard WordPress user edit screen for that member. This is the right place to change their email, reset their password, or modify their WordPress role.

### Change Trust Level

Opens an inline dropdown directly in the table row. Choose any level from 0 (Newcomer) to 5 (Moderator) and click **Save**. The change takes effect immediately and is logged in the activity log.

Trust levels 0 through 3 are normally earned automatically by the trust cron job. Use this override to:
- Promote a long-standing member to Level 4 or 5 (manual tiers)
- Demote a member who behaved poorly but has not yet triggered enough flags for automatic demotion
- Grant Level 2 to a known member immediately after import

### Ban

Opens the Ban User modal. Three options:

| Type | Effect |
|---|---|
| Global Ban | Member cannot log in to Jetonomy at all |
| Silence | Member can log in and read but cannot post, reply, or vote |
| Space Ban | Member is banned from a single space (enter the space ID in the form) |

**Duration options:** Permanent, 1 Day, 7 Days, 30 Days.

A **Reason** field is optional but recommended - the reason is stored in the `wp_jt_restrictions` table and visible if you query restrictions via WP-CLI or the REST API.

### Silence (quick action)

The row also exposes a **Silence** shortcut link that applies a permanent global silence immediately without opening the modal. Use the Ban modal if you want a temporary silence or a reason.

## Removing a Ban

Bans are not managed through the Users screen directly after they are created. To remove a ban:

**WP-CLI:**

```bash
wp --path="/path/to/wordpress" jetonomy user unban <user_id>
```

**REST API (site admin):**

```
DELETE /jetonomy/v1/users/<user_id>/restrictions/<restriction_id>
```

Temporary bans (1d, 7d, 30d) expire automatically - you do not need to remove them manually.

## Trust Level Reference

| Level | Name | How Earned |
|---|---|---|
| 0 | Newcomer | Default on registration |
| 1 | Member | Auto - light participation |
| 2 | Regular | Auto - consistent participation |
| 3 | Trusted | Auto - high engagement |
| 4 | Leader | Manual only (moderator or admin) |
| 5 | Moderator | Manual only (admin only) |

Thresholds for levels 1-3 are configured on **Settings → Permissions → Trust Level Thresholds**.

## What's Next?

Manage posts and replies from the content list view.

[Admin Content →](11-admin-content.md)
