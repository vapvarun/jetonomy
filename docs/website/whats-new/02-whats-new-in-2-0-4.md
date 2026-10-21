---
title: "What's New in 2.0.4"
category: "whats-new"
order: 2
---

# What's New in 2.0.4

Blocking now stops direct contact, the bbPress and wpForo importers resume cleanly, and a round of access, moderation and accessibility fixes lands in both plugins. Pro adds timezone-correct reporting and messaging fixes.

> **Note:** Jetonomy and Jetonomy Pro are released together. Jetonomy Pro 2.0.4 requires Jetonomy 2.0.4, so update both at the same time.

## New for site owners

- **Community Sidebar widget area.** Go to **Appearance → Widgets** and add widgets or blocks to **Community Sidebar**. They appear in the sidebar on every community page. See [Appearance](../admin-settings/04-appearance.md#community-sidebar-widgets).
- **Add a member over REST.** Space staff can add a member through the API with the same rules as the **Add member** control. See [Space Status and Roles](../spaces-and-categories/05-space-status-and-roles.md).
- **Support URL for the mobile app.** Set it next to the Terms and Privacy URLs; the app shows it in settings and hides it when empty.

## Better for members

- **Blocking stops contact.** A member you blocked can no longer reply to you or vote on your posts. Moderators are exempt. See [Blocking a Member](../moderation-and-trust/02-flagging-reporting.md#blocking-a-member-180).
- **Private spaces.** A guest who opens a private space sees a sign-in or join prompt.
- **Subscriptions and bookmarks.** My Subscriptions hides trashed and spam topics, and bookmarks and follows hide anything you can no longer read. See [Bookmarks and Following](../discussions/04-bookmarks-following.md).
- **Members tab.** A space's Members tab pages through long lists and has a search box.
- **Quote.** Quote is hidden on private replies.
- **Accessibility.** Toast notices are announced by screen readers, Escape closes the share menu, and the Report dialog keeps focus inside.

## Better for moderators

- **Moderation tabs.** Tabs stay in place after you resolve a flag, and the Pending queue can be filtered by Pending or Spam. See [The Moderation Queue](../moderation-and-trust/03-moderation-queue.md).
- **Community pulse.** The pulse numbers refresh when topics and replies change.
- **Restrictions.** A second active ban or silence of the same type and scope is refused, and a silenced member can no longer moderate.

## Importers

- **wpForo.** Stats and total progress count every board. See [wpForo Import](../migration/02-wpforo-import.md).
- **bbPress.** Batches stop at the time limit and resume at the right row, and a re-import gives members of already imported private and hidden forums their access. See [bbPress Import](../migration/01-bbpress-import.md).

## Spaces

- **Deleting a space.** Child spaces move up instead of being left orphaned. See [Creating Spaces](../spaces-and-categories/01-creating-spaces.md).
- **Space header.** Actions, stats and Join or Follow share one row and wrap cleanly.

## Privacy and security

- Private communities refuse guests on every read ability, the same as the website and REST API.
- Link previews and web push connect only to the address that was checked, and link previews are rate limited.
- A crafted compose-topic shortcode can no longer inject script, and the activity log CSV neutralises formula cells.

## What's new in Pro

- **Site timezone.** Analytics and AI usage days, range ends, daily totals and exports follow your site timezone. See [Analytics](../pro-features/06-analytics.md).
- **Messaging.** Messages are plain text, the send button is checked before a message goes out, deleted members show as Deleted User, and long words stay inside the bubble. See [Private Messaging](../pro-features/02-private-messaging.md).
- **Right-to-left text.** Member-written text follows its own direction.
- **Badges.** Badges set to manual are never awarded automatically. See [Custom Badges](../pro-features/05-custom-badges.md).
- **Polls.** Votes are counted correctly after a change or removal, and blocking applies. See [Polls](../pro-features/03-polls.md).
- **Advanced Moderation.** Staff are exempt from hold rules. See [Advanced Moderation](../pro-features/07-advanced-moderation.md).
- **Digests.** The unsubscribe link works for the right member and plain-text digests are honoured. See [Email Digest](../pro-features/08-email-digest.md).
- **Announcements.** Pins whose topic is trashed are ignored. See [Site Announcements](../pro-features/15-site-announcements.md).
- **Topic label.** Poll, Analytics and Branding text follows your Topic label.
