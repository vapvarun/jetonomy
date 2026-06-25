When BuddyPress is active alongside Jetonomy, groups and forum spaces feel like one membership. Members who join a group are enrolled in the paired forum space, new topics are announced on the group activity stream, and comments on those activities flow back to the topic as replies.

## What You Will Learn

- How the integration auto-detects BuddyPress
- How to pair a group with a forum space (create a new one or link an existing one)
- How member sync and role sync work across groups and spaces
- How topics broadcast to the group activity stream and how comments round-trip back as replies
- What surfaces appear on group pages and member profiles

## Auto-Detection

Jetonomy detects BuddyPress automatically when the Groups component is active - there is nothing to install or configure to turn the integration on. The pairing options, member sync, the Forum tab, and the profile nav all become available as soon as both plugins are active.

If you have BuddyPress Groups enabled but the Activity component turned off, everything still works - Jetonomy simply skips the activity-stream broadcast and the comment bridge, which need Activity.

> **Developer note:** detection runs on every page load via `bp_is_active( 'groups' )`; the broadcast and comment-bridge pieces additionally check `bp_is_active( 'activity' )` at runtime.

## Pairing Groups and Spaces

Group-to-space pairs are set directly inside BuddyPress's group creation wizard and the group Manage > Details screen. The Jetonomy integration adds a Discussion Forum section with three choices:

- **No forum.** The group has no paired forum space. Default for new groups.
- **Create new discussion forum.** Creates a matching Jetonomy space with the group's name, description, and visibility (public → public, private → private, hidden → hidden). The group creator becomes the space admin.
- **Link existing forum.** Pick from a dropdown of unlinked spaces the current user already owns or moderates. Site admins see every unlinked space.

The pairing is stored as `jetonomy_space_id` in BuddyPress's group meta (one value per group). Unpairing a group is as simple as setting the dropdown back to "No forum".

## Member Sync

Once a group is paired, membership changes propagate both ways:

- **Join a BP group** → added to the paired Jetonomy space as a member.
- **Join the paired Jetonomy space** → added to the BP group as a member.
- **Leave or be removed from the BP group** → removed from the paired space.
- **Banned from the group** → removed from the space.
- **Unbanned** → re-added to the space.
- **Promoted to group admin** → space admin.
- **Promoted to group moderator** → space moderator.
- **Demoted** → back to space member.

Unlike the FluentCommunity integration, BuddyPress member sync is bidirectional for both adds and leaves. Groups and forum spaces are treated as a single membership, which is the model most BuddyPress sites expect.

> **Want add-only sync?** If you would rather a member who leaves a group keep their forum-space access (the way the FluentCommunity integration behaves), a developer can disable leave-sync with a few lines of code. See [Disable member-leave propagation](../developer-guide/07-buddypress-integration.md#extending) in the BuddyPress integration reference.

## Forum Tab on BP Group Pages

Paired groups gain a **Forum** tab in their group nav. Clicking it renders a list of the most recent topics in the paired space with reply counts, author, and time-ago, plus a **+ New Topic** button for signed-in members.

The listing is visibility-aware: private topics only appear to the author or a moderator, so activity-stream widgets and group-page readers never see content they shouldn't.

## Forum Nav on BP Member Profiles

Every member profile gets a **Forum** primary nav item with three sub-tabs:

- **Posts**: the member's most recent topics with reply counts and links.
- **Replies**: the member's most recent replies with links to the original topics.
- **Bookmarks**: topics the member has bookmarked. Own profile only; others don't see your bookmarks.

A stats block above each sub-tab shows the member's posts, replies, votes, reputation, and trust level at a glance. A **View Full Forum Profile** link takes them to the Jetonomy profile for deeper history.

## Back-to-Group Banner on Jetonomy Pages

When a member clicks through from a BP group to a paired Jetonomy space (or a topic in it), a subtle back-link banner at the top of the Jetonomy page lets them return to the group with one click. Keeps the navigation continuous.

## Sidebar: Linked Group

On paired Jetonomy spaces, the sidebar About card shows a small tag linking to the paired BP group. Useful for members who enter the space from the forum side and want to jump back to the group's activity feed or members list.

## Activity Broadcast (New)

When a new topic is created in a paired Jetonomy space, an activity item is posted to the paired BP group's activity stream with:

- An action line: "Someone started a new forum topic in Group Name" (rendered by BuddyPress with the standard member + group links).
- The topic excerpt as paragraphs.
- A discreet "Shared from the forum · View discussion" attribution line at the bottom.

Properties:

- **One-way only.** Broadcast runs from Jetonomy to BuddyPress. BP activity posts never silently create forum topics.
- **Private topics are never broadcast.** If a topic is marked private on the Jetonomy side, no activity item is created. The group audience can be broader than the private-topic scope on the forum side.
- **Private/hidden groups stay private.** The activity is posted with `hide_sitewide` set, so site-wide activity feeds do not leak the item outside the group.
- **Paragraph breaks preserved.** The excerpt keeps its structure because the integration whitelists `<br>` and `<p>` on `bp_activity_allowed_tags` for broadcast content (tags are harmless on their own, with no attributes allowed through).

Broadcast is enabled by default. To turn it off, go to **Jetonomy → Settings → Integrations** and clear the **Broadcast topics to group activity** checkbox. (This tab appears only while BuddyPress Groups is active.)

## Comment-to-Reply Bridge (New)

When a member comments on one of those broadcast activity items, the comment is mirrored back to the originating Jetonomy topic as a reply, with author attribution preserved.

Only comments on broadcast activities round-trip. Comments on native BP activity posts (status updates, other plugins' activity types) are left alone. The integration identifies broadcast activities by a `jetonomy_post_id` activity-meta marker it sets at post time.

Add-only by design: edits and deletes on the BuddyPress side do not propagate back. The forum thread stays the durable record. Enabled by default. To turn it off, go to **Jetonomy → Settings → Integrations** and clear the **Round-trip activity comments** checkbox - it requires **Broadcast topics to group activity** to stay on.

## Identity Keying

Everything joins on `user_id`, which is the same primary key for BuddyPress user profiles and Jetonomy user profiles. Usernames and display names can diverge between the two without breaking the integration.

## Stability Guarantees

- **No core changes** to BuddyPress. Everything lives in Jetonomy's integration class; BP tables are read via its public API.
- **Group meta is the only data footprint** on the BP side (one `jetonomy_space_id` key per paired group). Deactivating Jetonomy leaves BuddyPress untouched.
- **Graceful degradation.** If BuddyPress is removed, the BP-specific surfaces disappear (the integration class is gated on `bp_is_active('groups')`), and the paired Jetonomy spaces continue to work on their own.
- **Activity component optional.** The broadcast and comment bridge gate themselves on `bp_is_active('activity')` at runtime, so sites with BP Groups but not Activity still get member sync and forum tabs.
- **Stale pair handling.** If a paired space is deleted on the Jetonomy side, the forum tab on the BP group quietly disappears on next render. No admin cleanup required, no fatal.

## What Is Not Integrated (Yet)

- **Cross-plugin notification merge.** BuddyPress notifications and Jetonomy notifications live in separate inboxes. Members see them in two places.
- **Unified search.** Each plugin has its own search UX.
- **Shared moderation queue.** Group moderation and forum moderation are independent. A user banned from a group is removed from the space but not flagged for forum moderation, and vice versa.
- **Two-way edit/delete sync** on comments and replies. The comment bridge creates a reply on round-trip but does not keep the reply and the activity comment in sync after that point.

---

Building on top of the integration? See the [BuddyPress integration reference](../developer-guide/07-buddypress-integration.md) in the Developer Guide for hooks, options, and extension points.
