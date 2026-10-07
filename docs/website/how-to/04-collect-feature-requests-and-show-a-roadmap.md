---
title: "Collect Feature Requests and Show a Roadmap"
category: "how-to"
order: 4
---

# Collect Feature Requests and Show a Roadmap

Use this guide to let customers suggest ideas, vote on them, and see what you plan to build. When you finish, you will have an Ideas space with voting and a public roadmap board. Everything here is in free Jetonomy.

## What you will set up

- An **Ideas** space where members post and vote
- A status on each idea: **Planned**, **In Progress**, **Shipped** or **Declined**
- A **Roadmap** board that groups ideas by status
- A way to link to the roadmap from your menu

## Before you start

- Jetonomy is installed and you have at least one category. See [Categories](../spaces-and-categories/00-categories.md).
- You are signed in as an administrator, or as a moderator of the space.
- You have a second browser window and a test member account.

## Step 1: Create the Ideas space

1. Go to **Jetonomy → Spaces** and click **Add New**.
2. Enter a **Title** such as `Feature Requests` and a short **Description**.
3. Choose a **Category**.
4. Set **Type** to **Ideas**.
5. Set **Visibility** to **Public** and **Join Policy** to **Open**. This lets customers vote without waiting for approval.
6. Click **Create Space**.

> **Tip:** Pick the `lightbulb` icon in the icon picker. The search field finds any icon by name.

## Step 2: Check that voting is on

1. Edit the space and open the **Settings** tab.
2. Make sure **Allow Voting** is ticked. It is ticked by default.
3. If you changed it, click **Save Settings**.

If you switch voting off, the vote buttons disappear from every idea in this space, and the roadmap hides vote counts too.

## Step 3: Show members how to post an idea

Signed-in members see a **+ Share an Idea** button in the space. The form is titled **Share an Idea** and its button reads **Submit Idea**.

New ideas start with no status. They appear in the space list and members can vote on them straight away. They stay off the roadmap until you give them a status.

## Step 4: Set a status

Only moderators and admins of the space see the status buttons.

1. Open an idea.
2. Under the title, find the **Status:** row.
3. Click **Planned**, **In Progress**, **Shipped** or **Declined**.

The change saves at once and a coloured label appears beside the idea title. You can change the status again at any time. There is no button to clear a status once it is set.

| Status | Use it when |
|---|---|
| **Planned** | You accepted the idea but have not started |
| **In Progress** | You are building it now |
| **Shipped** | It is live |
| **Declined** | You will not build it |

> **Tip:** When you click **Declined**, also post a reply that explains why. People who voted deserve an answer.

## Step 5: View the roadmap

1. Open the space on the community side.
2. Click the **Roadmap** tab, next to **Ideas**.

The board has four columns: **Planned**, **In Progress**, **Shipped** and **Declined**. Each column lists its ideas from most votes to fewest, with the vote count and reply count. A column shows up to 50 ideas. A link such as "+3 more ideas in the space feed" covers the rest. An idea marked private shows only to its author and to moderators.

The address is `/community/s/your-space-slug/roadmap/`. Use your own community address if you changed it.

## Step 6: Link to the roadmap

1. Go to **Appearance → Menus** and choose your menu.
2. Open **Custom Links**, paste the roadmap address, and set **Link Text** to `Roadmap`.
3. Click **Add to Menu**, then **Save Menu**.

There is no block or shortcode that embeds the roadmap board in a page, so link to it.

## Check that it works

1. As the test member, open the space and click **+ Share an Idea**. Post one idea.
2. Vote on a second idea from another account. A member cannot vote on their own idea.
3. As a moderator, open the idea and click **Planned**.
4. Open the **Roadmap** tab. The idea is under **Planned**.
5. In the test member's notifications, you should see: Your idea "..." is now Planned.

## Common questions

**Who is told when I change a status?**
The person who posted the idea gets a notification. You get none for your own ideas. They also get an email if they switched on **My idea roadmap status changed** under **Notification Preferences** on their profile. Email is off by default. You can change the default under **Jetonomy → Settings → Email → Notification Defaults**, on the **Your idea roadmap status changed** row.

**Can guests see the roadmap?**
Yes, in a public space. Guests can read it but cannot vote. In a private space only members can see it.

**Can I limit who posts ideas?**
Yes. On the space **Settings** tab, change **Who Can Post**.

**Can I run more than one roadmap?**
Yes. Each Ideas space has its own roadmap, for example one per product.

## Related guides

- [Ideas Roadmap](../spaces-and-categories/06-ideas-roadmap.md)
- [Space Types](../spaces-and-categories/02-space-types.md)
- [Voting & Reputation](../discussions/03-voting.md)
- [Space Settings](../spaces-and-categories/04-space-settings.md)
- [Email Settings](../admin-settings/03-email.md)
