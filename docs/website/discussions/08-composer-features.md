---
title: "Composer Features"
category: "discussions"
order: 8
---

The composer is the box you type in when you create a new post or reply. Jetonomy 1.4.0 added three features that make the composer feel more like a modern community tool and less like a comment form: @mention autocomplete, a "New" pill on unread threads, and a "Managed by" sidebar card that surfaces space staff at a glance. Admin and moderator role pills also appear next to staff names so members always know who they are talking to.

![Composer with @mention dropdown open, showing matching member suggestions](../images/composer-mention-autocomplete.png)

## What You Will Learn

- How @mention autocomplete works in posts and replies
- What the "New" pill on the topic card means and when it clears
- How the "Managed by" sidebar surfaces space admins and moderators
- Where role pills appear and what they tell members
- How the composer behaves with markdown vs the WYSIWYG editor

## @mention Autocomplete

Type `@` anywhere in the composer and Jetonomy opens a dropdown of matching people. Keep typing to narrow the list, then click a result or press Enter to insert the mention.

The inserted mention is a clickable link to the user's profile, and the moment your post or reply is published, Jetonomy fires a notification to the mentioned member. They see it in the bell menu and, if they have email notifications on, in their inbox.

### Where It Works

| Surface | Mentions supported |
|---|---|
| New post composer | Yes |
| Reply composer | Yes |
| Edit post / edit reply | Yes |
| Private message composer (Pro) | Yes |
| Quick reply on a topic card | Yes |

### Who Shows Up in the Dropdown

The dropdown is scoped to people the current user shares at least one space with. This keeps the list relevant on large communities and avoids leaking member names across private spaces. Within that scope, results are ordered by:

1. Recent contributors in the current space (they are most likely to be relevant)
2. Other members of the current space
3. People you share other spaces with

If you start typing a name that does not match anyone in your shared spaces, the dropdown shows "No matches" rather than searching the whole site.

### Walkthrough: Mentioning a Member in a Reply

1. Open any topic and start a reply.
2. Type `@` followed by the first few letters of the person's name or username.
3. The dropdown appears under your cursor with up to 8 matching members.
4. Use the arrow keys to highlight a name, or click directly.
5. Press Enter (or click) to insert the mention.
6. Publish the reply. The mentioned member receives a notification within seconds.

### Notes on Privacy

- Mentions in a private space still notify the mentioned member, but the linked post is only visible to people who can read that space.
- Mentioning a member who has muted you still inserts the link, but no notification is sent.
- Site admins can disable mention notifications globally in **Jetonomy → Settings → Notifications**.

## "New" Pill on Unread Threads

The space listing shows a card for each topic. When a topic has replies you have not read yet, a small "New" pill appears on the card.

![Space listing with a New pill on two of the topic cards](../images/composer-new-pill.png)

The pill is intentionally subtle. It uses the `--jt-accent` design token, so it picks up your theme's brand color and matches dark mode automatically.

### When the Pill Appears and Clears

| Event | Pill state |
|---|---|
| Someone replies to a thread you previously read | Pill appears |
| You open the thread | Pill clears immediately |
| You scroll past the thread without opening it | Pill stays |
| The original poster edits their post (no new replies) | Pill does not appear |
| You are not signed in | Pill never appears (read state is per-user) |

The pill is driven by the same read-status table that powers the unread indicator in the bell menu. There is no separate setting to toggle it. If you do not want unread tracking at all, disable read tracking in **Jetonomy → Settings → Performance**.

### Mobile Behavior

On mobile the pill sits at the top-right of the card, sized for thumb readability without crowding the title. It uses the same touch target as the rest of the card, so tapping anywhere on the card opens the thread and clears the pill.

## "Managed by" Sidebar Card

Every space page now shows a "Managed by" card in the sidebar. The card lists the space admin(s) and moderator(s) with their avatars and a small role badge next to each name.

![Space sidebar showing the Managed by card with one admin and two moderators](../images/composer-managed-by-card.png)

### What the Card Shows

- Up to 5 staff members per space, sorted with admins first, then moderators
- Each entry shows: avatar, display name, role badge
- Hovering an entry reveals a "View profile" link
- If the space has private messaging enabled (Pro), a "Message" link appears as well

If a space has more than 5 staff, the card shows the first 5 and a "See all staff" link that opens the full member list filtered to staff only.

### Why This Helps Members

Members often want to ask a question, report a problem, or appeal a moderation action but don't know who to talk to. The "Managed by" card answers that question on every space page, without forcing members to dig through settings or member lists.

For owners, this surfaces your community staff and gives them visibility. Members are more likely to recognise and trust moderators they see consistently.

### Customizing the Card

The card is rendered from `templates/parts/space-sidebar-managed-by.php`. You can override it in your theme at `theme/jetonomy/parts/space-sidebar-managed-by.php` to change the layout, hide certain roles, or add extra links.

## Role Pills on Posts and Replies

In addition to the sidebar card, admins and moderators now have role pills next to their name on every post and reply they make. The pill is small, accessible, and uses theme tokens for color.

| Role | Pill label | Pill style |
|---|---|---|
| Community Admin | "Admin" | Filled, accent color |
| Space Moderator | "Mod" | Outline, accent color |
| Member | (no pill) | n/a |

The pills are visible to everyone, including signed-out visitors. They make it obvious when an answer comes from staff vs from another member, which matters for support spaces and Q&A spaces where members weigh staff replies more heavily.

## Markdown and WYSIWYG

The composer supports two input modes, controlled per-space in **Space Settings → Editor**:

- **Plain text with markdown** - members type using common markdown shortcuts (`**bold**`, `*italic*`, `[link](url)`). Lightweight, fast, ideal for technical communities.
- **WYSIWYG** - a TinyMCE-style toolbar with buttons for formatting, lists, links, and image uploads. Friendlier for non-technical members.

Both modes support the same paste handling, the same image uploads, and the same @mention autocomplete. Switching modes does not lose existing content; markdown is converted to HTML and vice versa on save.

For a deeper walkthrough of editor settings see [Space Settings](../spaces-and-categories/04-space-settings.md).

## What's Next?

Learn how Jetonomy's in-product modal toolkit replaces native browser dialogs with accessible, themeable confirmations.

[Modals and Confirmations](09-modals-confirmations.md)
