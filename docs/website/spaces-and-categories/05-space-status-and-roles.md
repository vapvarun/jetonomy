---
title: Space Status and Member Roles
description: What Active, Archived and Locked do to a space, and how to promote a member to moderator or admin of that space.
order: 5
---

# Space Status and Member Roles

Two controls decide who can do what inside a space: its **status**, which governs whether the space accepts new content at all, and each member's **role**, which governs what that individual can do there.

Both live on the space's edit screen at **Jetonomy -> Spaces -> (space) -> Edit**.

## Space status

The Status dropdown offers three values.

| Status | Members can read | Members can post |
|---|---|---|
| **Active** | Yes | Yes |
| **Archived** | Yes | No |
| **Locked** | Yes | No |

**Active** is the normal state.

**Archived** and **Locked** both stop new topics and replies. Existing content stays readable - nothing is deleted or hidden, and links keep working. A member who tries to post gets *"This space is archived or locked and no longer accepts new posts."*

Use Archived when a space has finished its life: a completed project, a closed beta, a season that has ended. Use Locked when you are pausing a space you intend to reopen - a temporary freeze during a moderation incident, or a category being reorganised.

> **In this version the two behave identically.** The distinction is one of intent, not enforcement: both block posting and both leave content readable. Pick whichever label communicates the right thing to your team, and do not rely on Locked and Archived differing in any functional way.

Changing status back to Active restores posting immediately. Nothing is lost in either direction.

### Status is not visibility

Status and visibility are separate settings and it is easy to confuse them.

- **Status** answers *is this space still accepting content?*
- **Visibility** (Public, Private, Hidden) answers *who can see it at all?*

Archiving a public space leaves it public and readable. If you want it gone from view, change its visibility as well.

## Member roles

Every member of a space holds one of three roles.

| Role | Can post | Can moderate content | Can manage the space |
|---|---|---|---|
| **Member** | Yes | No | No |
| **Moderator** | Yes | Yes | No |
| **Admin** | Yes | Yes | Yes |

**Member** is the default for anyone who joins.

**Moderator** adds moderation of content in that space: approving, marking spam, trashing, and replying to closed topics. A moderator's reply to a closed topic is accepted and the topic stays closed, so they can post a ruling without reopening the discussion.

**Admin** adds management of the space itself: settings, membership, and access rules.

These roles are scoped to one space. Someone can be an admin of one space and an ordinary member of another. They are separate from WordPress roles - a WordPress administrator has site-wide capability regardless.

## Promoting a member

1. Go to **Jetonomy -> Spaces** and click **Edit** on the space.
2. Scroll to the **Members** list.
3. Find the member and change the dropdown beside their name to **Moderator** or **Admin**.

The change saves immediately - there is no separate Save button for the members list - and takes effect on their next page load.

To add someone who is not yet a member, use the **Add member** control above the list: choose the user, pick the role, and add them. This bypasses any join request or approval the space would normally require.

### Two guards

Jetonomy refuses two changes that would leave a space unmanageable:

- **You cannot remove the last admin.** Promote a replacement first. Attempting it returns *"A space must keep at least one admin."*
- **You cannot demote yourself.** Someone else with admin on that space has to do it. This stops an admin from accidentally locking themselves out of a space they own.

Site administrators can always reach the space through wp-admin regardless of their space role, so a space cannot become permanently orphaned.

## Doing this from the API

Both operations are available over REST for anyone building an integration or automation:

```
PATCH /wp-json/jetonomy/v1/spaces/{id}/members/{user_id}
{ "role": "moderator" }
```

Valid roles are `member`, `moderator` and `admin`. The caller needs space-admin rights, and the same two guards apply.

See the [REST API reference](../developer-guide/01-rest-api.md) for the full endpoint list.
