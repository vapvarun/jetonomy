---
title: "What's New in 2.0.3"
category: "whats-new"
order: 1
---

# What's New in 2.0.3

Members can block someone from their profile and see who reacted to a post. Deleting the plugin now keeps your community unless you say otherwise, and a set of fixes tightens privacy and moderation.

> **Note:** Jetonomy and Jetonomy Pro are released together. Update both at the same time.

## New for members

- **Block from a profile.** Open a member's profile and use the **⋯** menu beside their name to report or block them. Their topics and replies are hidden from you, they cannot message you, and they are not told. See [Blocking a Member](../moderation-and-trust/02-flagging-reporting.md#blocking-a-member-180).
- **Blocked members list.** Edit Profile lists everyone you have blocked, with an **Unblock** button for each.
- **See who reacted (Pro).** Hover over a reaction, move to it with the keyboard, or press and hold it on a phone to see who chose it, for example "Like: Maya, Bob and 3 others". See [Reactions](../pro-features/01-reactions.md).

## Better for members

- **Unread badges.** Reading a notification updates every unread count on the page straight away, with no reload.
- **Unfollow.** Unfollowing a space or topic removes that follow, not your most recent one.
- **Refused actions.** A silenced or banned member who tries to start a topic, vote or report something is told why, not just that they lack permission.
- **Fewer duplicate notices.** A reply that @mentions someone who would also be notified as the topic author or a follower sends them one notice and one email, not two.

## New for site owners

- **Keep data on delete.** Deleting Jetonomy no longer erases your community. To remove everything when you delete it, first tick **Remove all community data when Jetonomy is deleted** under **Jetonomy > Settings > General > Data**. Jetonomy Pro follows the same choice.
- **Recount counters.** **Jetonomy > Dashboard > Quick Actions** has a button that rebuilds topic, reply, member and vote counts from your content. See [Admin Dashboard](../getting-started/05-admin-dashboard.md#quick-actions-panel).
- **Install with Composer.** Jetonomy can be installed with Composer as `wbcomdesigns/jetonomy`.

## Fixes

- A topic in a hidden space no longer shows its title in the browser tab to someone who cannot read it, and its reactions answer as if the topic did not exist.
- Bookmarking, following or voting on a topic you cannot read no longer reveals whether it is held for review.
- Followers are only notified about topics they can read, so following a hidden space or keeping a follow after leaving a private space no longer delivers its topic titles.
- IP bans refuse loopback and reserved addresses, which could never match a real visitor.
- Clearing the Community Base URL field and saving keeps the community at its current address instead of breaking every community link.
- Personal data export includes custom fields, badges and browser push subscriptions (Pro).
- Switching the Analytics date range always shows the range you picked (Pro).
- Messaging abilities are not offered to AI agents while WPMediaVerse handles messaging (Pro).
- When you choose to remove all data, deleting the plugin also clears its scheduled jobs, licence check and widget settings.
