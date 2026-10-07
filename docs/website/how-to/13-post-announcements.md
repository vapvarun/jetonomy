---
title: "Post Announcements"
category: "how-to"
order: 13
---

# Post Announcements

This guide is for community owners who want news that members see first and only staff can write. You will make a staff-only space, pin important topics, and check the welcome banner. Pro adds a way to feature one post across every space.

## What you will set up

- An Announcements space where only your team can start topics
- Pinned topics that stay at the top
- A look at the welcome banner new visitors see
- (Pro) A community-wide announcement

## Before you start

- You need to be a WordPress administrator.
- The people who will write announcements need WordPress accounts.
- Jetonomy has no site-wide banner for free members. Announcements work through spaces and pins. The welcome banner is only for signed-out visitors.

## Step 1: Create the Announcements space

1. Go to **Jetonomy → Spaces** and click **Add New**.
2. Enter **Title**, for example "Announcements", and a short **Description**.
3. Set **Type** to **Forum**.
4. Set **Visibility** to **Public** so everyone can read it.
5. Click **Create Space**.

## Step 2: Make it staff-only

1. In **Jetonomy → Spaces**, click **Edit** on Announcements.
2. Open the **Settings** tab.
3. Set **Who Can Post** to **Moderators & Admins**. Choose **Admins Only** for the strictest option.
4. Decide about **Who Can Reply**. Leave it at **Anyone who can see it (no restriction)** for open discussion. Choose **Moderators & Admins** to keep it one-way.
5. Click **Save Settings**.

Who counts as staff here:

- WordPress administrators always can post.
- Anyone with a **Moderator** or **Admin** role in this space can post.
- A site-wide role, such as WordPress Editor, does not count on its own. Add that person to the space instead: open the **Members** tab, search for them under **Add Member**, set the role to **Moderator**, and click **Add**.

Members can still read the space. Take care with **Visibility**: **Hidden** spaces are invite only.

> **Tip:** Ask members to follow the space. Followers get the "New post in subscribed space" notification. In the bell it is on by default. By email it is off until you tick it in **Jetonomy → Settings → Email → Notification Defaults**.

## Step 3: Pin a topic

Pinned topics sit above all others in the space, whichever sort tab a member picks.

1. Open the topic.
2. Click the **...** (**More options**) button.
3. Choose **Pin to space**.

A green **Pinned** badge appears on the topic and in the list. To undo it, open the same menu and choose **Unpin from space**.

Each space can hold 3 pins. A fourth shows "You can pin up to 3 topics in a space. Unpin one first." Only space moderators, space admins and WordPress administrators see the pin option. See [Topic Management](../discussions/06-topic-management.md).

## Step 4: Check the welcome banner

Visitors who are signed out see a welcome banner at the top of `/community/`. Signed-in members never see it.

The banner shows:

- A heading, "Welcome to" followed by your **Community Title**
- A short line of context
- Live counts of members, topics and, when above zero, topics this week
- Two buttons, **Create free account** and **Log in**

To change the heading text, edit **Community Title** on **Jetonomy → Settings → General**. The long line under the heading has no setting in the admin. A developer can change it with a filter. See [Welcome Banner](../getting-started/07-welcome-banner.md).

## Step 5 (Pro): Announce across every space

Site Announcements features one post at the top of every space.

1. Go to **Jetonomy → Extensions** and switch on **Site Announcements**.
2. Open the post you want to feature.
3. Click **Pin to community** in the post's action bar.

The post gets a green **Announcement** badge and shows above each space's topics. Click **Unpin from community** to remove it. You can feature up to 5 at a time.

Only administrators can do this. WordPress Administrators have it by default, as does any role you give **Manage all spaces**. Space moderators cannot. See [Site Announcements](../pro-features/15-site-announcements.md).

## Check that it works

1. Open a private browser window and sign in as a regular test member.
2. Open the Announcements space. You should be able to read it.
3. Confirm there is no button to start a topic. If one still shows, recheck that **Who Can Post** saved.
4. Sign out and visit `/community/`. Confirm the welcome banner shows.
5. Pin a topic as an administrator. Reload as the test member and check it is first in the list with the **Pinned** badge.

## Common questions

**Can I post in a staff-only space as an administrator?**
Yes. Administrators always can.

**Can members comment on announcements?**
Only if **Who Can Reply** allows it. The default allows anyone who can see the space.

**Why can my Editor not post?**
They need a **Moderator** role in that space. A site-wide role does not count here.

**Is there a popup or top bar for everyone?**
Not in the community pages. Use a pinned topic, or with Pro, **Pin to community**.

## Related guides

- [Space Settings](../spaces-and-categories/04-space-settings.md)
- [Space Status and Member Roles](../spaces-and-categories/05-space-status-and-roles.md)
- [Topic Management](../discussions/06-topic-management.md)
- [Welcome Banner](../getting-started/07-welcome-banner.md)
