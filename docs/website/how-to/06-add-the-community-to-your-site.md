---
title: "Add the Community to Your Site"
category: "how-to"
order: 6
---

# Add the Community to Your Site

Use this guide to make your community easy to find on your WordPress site. You will add a menu link, choose the community address, and show recent topics on a normal page. All of it is in free Jetonomy.

## What you will set up

- A **Community** link in your main menu
- The community address you want (for example `yoursite.com/forum/`)
- Optionally, the community as your front page
- Recent topics, spaces or a leaderboard on any page

## Before you start

- Jetonomy is installed and has at least one space with a few topics. The demo data is a quick way to fill it: [Try It With Demo Content](07-try-it-with-demo-content.md).
- You are signed in as an administrator.
- You know whether your theme is a classic theme (it has **Appearance → Menus**) or a block theme (it has **Appearance → Editor**).

## Step 1: Add a menu link

**Classic theme:**

1. Go to **Appearance → Menus** and choose your menu.
2. Find the **Community** box on the left. If you do not see it, open **Screen Options** at the top and tick **Community**.
3. Tick **Community Home**. You can also tick **Search**, **Leaderboard**, **Notifications**, **My Profile** or any of your public spaces.
4. Click **Add to Menu**, then **Save Menu**.

**My Profile** shows each visitor their own profile page. Signed-out visitors are sent to sign in.

**Block theme:**

1. Go to **Appearance → Editor** and open **Navigation**.
2. Add a custom link and paste your community address, for example `https://yoursite.com/community/`.
3. Click **Save**.

## Step 2: Choose the community address

1. Go to **Jetonomy → Settings → General**.
2. In the **Community Setup** card, change **Community Base URL**. The line under the field shows the full address.
3. Optionally change **Community Title**. It is the main heading on the community home page.
4. Click **Save Settings**.

Use lowercase words and hyphens, such as `forum` or `help-centre`. Pick a word that none of your WordPress pages already use.

Your old address redirects to the new one automatically, so existing links keep working. If a community page shows "not found" after the change, go to **Jetonomy → Dashboard** and click **Flush Rules**.

> **Tip:** Renaming "Space", "Topic" or "Member" is a separate setting. Look for **Terminology** on the same card.

## Step 3: Make the community your front page (optional)

1. On the same **General** screen, find **Community as Homepage**.
2. Tick **Show the community home on the site front page.**
3. Click **Save Settings**.

Visitors who open your site address now see the community home. This takes priority over the WordPress **Your homepage displays** setting. All other community addresses stay the same.

## Step 4: Show community content on a page

You can place a live feed anywhere, such as a landing page or your home page.

**With blocks:**

1. Edit a page and click **+** to add a block.
2. Search for `Forum Feed`. The Jetonomy blocks are under **Widgets**.
3. Pick a block:

| Block | Shows |
|---|---|
| **Forum Feed** | Recent topics |
| **Trending Topics** | Topics with the most recent activity |
| **Space List** | A grid of spaces |
| **Leaderboard** | Top members by reputation |

4. Use the settings in the right sidebar: **Count**, **Space ID (0 = all)**, **Sort**, and **Show header**.

The editor shows a preview card, not your real topics. Click **Preview** to see the real list.

**With shortcodes** (classic editor and page builders):

```
[jetonomy_recent_posts count="5"]
[jetonomy_recent_posts count="5" space_id="help-desk"]
[jetonomy_trending_posts count="5"]
[jetonomy_spaces count="6"]
[jetonomy_leaderboard count="10"]
```

In `space_id` you can use the space's address word (its slug, as in `/community/s/help-desk/`) or its number. The number is the `space_id=` value in the browser address while you edit that space. The blocks accept the number only.

**With widgets:** go to **Appearance → Widgets** and look for **Jetonomy: Recent Posts**, **Jetonomy: Active Spaces**, **Jetonomy: Leaderboard** and **Jetonomy: User Stats**.

Visitors only see what they may read. Posts in private and hidden spaces are left out for people who are not members.

There is no block that embeds the whole community inside a page. Link to it from your menu, or use **Community as Homepage**.

## Check that it works

1. Open your site in a private window. The menu shows your link.
2. Click it. You land on the community home at the address you chose.
3. Open the old address, if you changed it. It should redirect.
4. Open the page with the block or shortcode. It lists real topics. Private posts do not show for a guest.

## Common questions

**Can I put the community on a WordPress page I created?**
No. The community has its own addresses under the base URL. Use a menu link, or **Community as Homepage**.

**The blocks do not appear in the editor.**
Search for the word `Jetonomy` or `Forum Feed`. They sit in the **Widgets** group.

**Will my theme style the community?**
Jetonomy picks up your theme's fonts and colours. See [Theme Compatibility](../integrations/07-theme-compatibility.md).

## Related guides

- [General Settings](../admin-settings/01-general.md)
- [Shortcodes, Widgets and Blocks](../developer-guide/04-shortcodes-widgets-blocks.md)
- [Appearance Settings](../admin-settings/04-appearance.md)
- [Admin Dashboard](../getting-started/05-admin-dashboard.md)
