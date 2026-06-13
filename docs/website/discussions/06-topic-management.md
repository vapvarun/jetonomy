---
title: "Topic Management"
category: "discussions"
order: 6
---

Space moderators have a full toolkit for organizing, curating, and controlling discussions. Every action in this guide requires moderator permission on the space where the topic lives - or site-wide admin access.

![Admin moderation panel with content review and bulk action controls](../images/admin-moderation.png)

## What You Will Learn

- How to move a topic to a different space
- How to merge duplicate topics
- How to split a reply into a new topic
- How to pin and unpin topics
- How to close and reopen topics
- How to delete topics

## Who Can Use These Tools

All moderation actions described here require one of the following:

- **Space Moderator** role in the space where the topic lives
- **Space Admin** role in that space
- **WordPress Administrator** (can moderate any space)

Regular members do not see moderation options in the menus, even if they are the topic author. Topic authors can edit and delete their own posts, but not pin, close, move, merge, or split them.

## Moving a Topic to a Different Space

Use this when a topic was posted in the wrong space - for example, a bug report in a General Discussion space that belongs in a Support space.

1. Open the topic.
2. Click the **...** menu at the top right of the topic.
3. Select **Move Topic**.
4. A modal appears with a searchable space picker. Type to filter spaces by name.
5. Select the destination space.
6. Click **Move**.

The topic disappears from the original space immediately and appears at the top of the destination space's listing. Its URL changes to reflect the new space slug. The old URL redirects to the new one automatically.

All replies, votes, bookmarks, and subscriptions move with the topic. Nothing is lost.

> **Tip:** If you move a Q&A topic from a Q&A space into a Forum space, the accepted answer marking is preserved in the database, but the "Accepted" badge may not render in the Forum space depending on your display settings.

## Merging Duplicate Topics

Use this when two members post the same question or idea in the same space. Merging moves all replies from the source topic into the target topic and deletes the source.

1. Open the topic you want to remove (the duplicate).
2. Click the **...** menu and select **Merge Topic**.
3. A modal appears with a search field. Search for the target topic by title.
4. Select the target topic.
5. Click **Merge**.

All replies from the duplicate are appended to the target topic's reply list in chronological order. The source topic is permanently deleted - it is not moved to trash. A moderator note is added to the target topic indicating that replies were merged from another topic.

> **Warning:** Merging cannot be undone. Verify you have selected the correct target topic before confirming.

## Splitting a Reply Into a New Topic

Use this when a reply inside a topic starts a new conversation that deserves its own thread.

1. Find the reply you want to split.
2. Click the **...** menu on that reply.
3. Select **Split to New Topic**.
4. A modal appears asking for the new topic title.
5. Enter the title and click **Split**.

Jetonomy creates a new topic in the same space with your chosen title. The selected reply becomes the first post content of the new topic. All sub-replies (direct children of that reply) move with it.

The original reply is removed from the source topic. A moderator note appears in the source topic: "A reply was split into a new topic: [title with link]."

## Pinning and Unpinning Topics

Pinned topics appear at the top of the space listing, above all other topics, **regardless of which sort tab is selected** (Latest, Popular, or Unanswered). Use pinning for space rules, important reference threads, or a "start here" topic. Pinning here affects **only this space** - to feature a post across the whole community, see [Community Announcements](../pro-features/15-site-announcements.md) (Pro, admins only).

1. Open the topic.
2. Click the **...** menu and select **Pin**.

The topic moves to the top of the listing immediately and shows a green **Pinned** badge - on both the listing row and the topic's own header - so members can see at a glance that it is pinned.

To unpin, open the same menu and select **Unpin**.

Each space allows up to **3 pinned topics** by default. Once the limit is reached, pinning another topic returns "You can pin up to 3 topics in a space. Unpin one first." The cap keeps the top of the space scarce and meaningful; developers can change it with the [`jetonomy_max_space_pins`](../developer-guide/02-hooks-reference.md) filter.

## Closing Topics

Closing a topic prevents new replies while keeping all existing content visible and readable. Use this when a question has been answered, a discussion has run its course, or a thread is becoming unproductive.

1. Open the topic.
2. Click the **...** menu and select **Close Topic**.

Closed topics show a banner: "This topic is closed. No new replies can be added."

The reply composer is hidden for regular members. Moderators and admins can still reply to closed topics.

To reopen a topic, click the **...** menu and select **Reopen Topic**.

## Deleting Topics

Deleting a topic moves it to trash. It disappears from the space listing and is no longer accessible to members. The space's post count decrements.

1. Open the topic.
2. Click the **...** menu and select **Delete Topic**.
3. Confirm the deletion in the prompt.

Deleted topics can be recovered by a site admin from **Jetonomy → Content** in the WordPress admin - filter by status "Trash" to find them. A trashed topic can be restored or permanently deleted.

Permanent deletion removes the topic, all replies, all votes, all bookmarks, and all associated notifications from the database. It cannot be undone.

> **Note:** When you delete a topic, the topic author's reputation is reduced (a default of 20 points) to reflect the removed content. The penalty amount is admin-configurable under [Settings → Permissions → Reputation Points](../admin-settings/02-permissions.md#reputation-points).

## What's Next?

Return to the Spaces & Categories section to learn about managing space membership and access rules.

[Membership & Join Policies →](../spaces-and-categories/03-membership-policies.md)
