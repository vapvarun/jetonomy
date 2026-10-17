---
title: "What's New in 2.0.3"
category: "whats-new"
order: 1
---

# What's New in 2.0.3

Members can block someone from their profile and see who reacted to a post. Deleting the plugin now keeps your community unless you say otherwise, and a broad set of fixes tightens privacy, moderation and notifications.

> **Note:** Jetonomy and Jetonomy Pro are released together. Update both at the same time.

## New for members

- **Block from a profile.** Use the **⋯** menu beside a member's name to report or block them. Their topics and replies are hidden from you, they cannot message you, and they are not told. See [Blocking a Member](../moderation-and-trust/02-flagging-reporting.md#blocking-a-member-180).
- **Blocked members list.** Edit Profile lists everyone you have blocked, with an **Unblock** button for each.
- **See who reacted (Pro).** Hover over a reaction, focus it with the keyboard, or press and hold it on a phone. See [Reactions](../pro-features/01-reactions.md).
- **Reschedule a topic.** The author of a scheduled topic can change or cancel the time from the topic page.

## Better for members

- **Unread badges.** Reading a notification updates every unread count on the page straight away.
- **Unfollow.** Unfollowing a space or topic removes that follow, not your most recent one.
- **Clear refusals.** A silenced or banned member who tries to post, vote or report is told why.
- **Fewer duplicate notices.** A reply that @mentions someone who is also a follower sends one notice and one email.
- **Digests (Pro).** Choosing a daily or weekly digest replaces the instant emails it covers, and never lists someone else's private topic.
- **Search.** An exact title match ranks first and matched words are highlighted.
- **Phones.** Post Topic and Post Reply stay above the tab bar.

## New for site owners

- **Keep data on delete.** To remove everything when you delete Jetonomy, tick **Remove all community data when Jetonomy is deleted** under **Jetonomy > Settings > General > Data**. Jetonomy Pro follows the same choice.
- **Recount counters.** **Jetonomy > Dashboard > Quick Actions** rebuilds topic, reply, member and vote counts. See [Admin Dashboard](../getting-started/05-admin-dashboard.md#quick-actions-panel).
- **Install with Composer.** Use `wbcomdesigns/jetonomy`.
- **Moderation.** Reports on the same item merge into one card, moderators only see actions they may use, and a second moderator's Close or Pin no longer undoes the first.
- **Spaces and content.** Spaces and Content in wp-admin can be searched and sorted, and deleting a space removes its topics and replies.
- **Email settings.** Settings > Email lists every placeholder and previews with realistic values.

## Privacy and security

- Topics, replies and spaces a viewer cannot see answer like missing ones, in titles, headers, bookmarks, follows, votes and reactions.
- Notices and emails reach only people who can still read the topic.
- Space access ends when its access rule is deleted or narrowed, and only site administrators manage access rules.
- Lifting a restriction needs the authority that issued it, and IP bans refuse loopback and reserved addresses.
- Web Push re-checks its destination address before each send (Pro).

## Fixes

- Clearing the Community Base URL keeps your current address.
- Spaces on boxed themes such as Reign no longer squeeze the description.
- Archived and locked spaces no longer offer reply controls.
- Banned members no longer appear on the leaderboard.
- Personal data export includes custom fields, badges and push subscriptions (Pro).
- Switching the Analytics date range shows the range you picked (Pro).
