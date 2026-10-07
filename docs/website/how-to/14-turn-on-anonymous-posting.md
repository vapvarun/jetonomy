---
title: "Turn On Anonymous Posting"
category: "how-to"
order: 14
---

# Turn On Anonymous Posting

This guide is for community owners who run sensitive spaces, such as support, feedback or personal topics. You will switch on anonymous posting for chosen spaces and learn what members, moderators and administrators can see.

> **PRO** - Anonymous Posting is a Jetonomy Pro feature. The free plugin has no way to post anonymously.

## What you will set up

- The Anonymous Posting extension, which is the site-wide switch
- The spaces where members may post anonymously
- A clear idea of who can still see the real author

## Before you start

- You need Jetonomy Pro, active and licensed, and you must be a WordPress administrator.
- Decide which spaces really need this. Leave it off everywhere else.
- Tell your moderators how it works. They will see "Anonymous" on community pages like everyone else.

## What anonymous posting is

A member ticks a box when they write a topic or reply. Other members then see the author as "Anonymous" with a plain silhouette, instead of a name and avatar. This holds in lists, topics, replies, search, the RSS feed, notifications, profiles and the mobile app.

The real author is still stored privately. That is why rate limits, bans and moderation keep working.

## Step 1: Switch on the extension (site-wide)

1. Go to **Jetonomy → Extensions**.
2. Find the **Anonymous Posting** card.
3. Turn its switch on.

This is the only site-wide control. There is no separate settings page for it, and no setting that turns it on in every space at once.

## Step 2: Allow it in each space

1. Go to **Jetonomy → Spaces** and click **Edit** on the space.
2. Open the **Anonymous** tab.
3. Tick **Allow anonymous posts in this space**.
4. Click **Save**.

Repeat for every space where you want it. A space that has not opted in never shows the anonymous option, even while the extension is on.

> **Tip:** Good fits are a support space, a feedback space or a sensitive-topics space. Poor fits are spaces where reputation and names matter, such as showcase spaces.

## Step 3: See what members see

In an allowed space:

- **New topic:** a **Post anonymously** checkbox appears in the form. It starts unticked, so the member chooses each time.
- **Reply:** a mask icon button in the reply toolbar, labelled with your word for reply plus "anonymously". It highlights when on.

Members must be signed in. Both switches are checked again on the server, so a member cannot force anonymity in a space that has not opted in.

## What moderators and administrators can see

| Person | On community pages | Real author |
|---|---|---|
| Regular member | "Anonymous" | Hidden |
| Space moderator or space admin | "Anonymous" | Hidden. They cannot reveal it |
| WordPress administrator | "Anonymous" | Click **Reveal author** on the post or reply |

Each reveal is written to the activity log: who revealed it, which item and when. See [Activity Log](../admin-settings/08-activity-log.md). Use it for abuse cases only.

Moderators can still do their job without knowing who wrote something. They can review reports, trash content and approve held posts. If someone needs banning, ask an administrator to reveal the author first.

**One thing to plan for:** wp-admin screens do not mask the author. The **Author** column on **Jetonomy → Content** and on the **Pending Posts** and **Pending Replies** tabs of **Jetonomy → Moderation** shows the real account name. The **Awaiting approval** cards on the community pages do the same. Only give wp-admin access to people you trust with that.

## Check that it works

1. Sign in as a regular test member in a private browser window.
2. Open a space where you ticked **Allow anonymous posts in this space**. Start a topic with **Post anonymously** ticked and publish it.
3. Open a space that has not opted in. Confirm there is no **Post anonymously** box.
4. Sign out and open the topic. The author should read "Anonymous".
5. Sign in as an administrator and open the topic. It should still read "Anonymous", with a **Reveal author** button.
6. Click **Reveal author**. Then open **Jetonomy → Activity Log** and confirm the reveal was recorded.

## Common questions

**Can I turn it on for every space at once?**
No. The extension is the site-wide switch, and each space must opt in.

**What happens to old anonymous posts if I switch it off?**
New anonymous posts stop. Posts that were already anonymous stay masked.

**Can a moderator reveal an author?**
No. Only a WordPress administrator can.

**Do notifications give the author away?**
No. Notifications that other members receive hide the real name too.

**Can members stay anonymous forever and still get banned?**
Yes. The account behind the post is real and rate-limited, so bans and limits still apply.

## Related guides

- [Anonymous Posting (Pro Features)](../pro-features/16-anonymous-posting.md)
- [Anonymous Posting (Getting Started)](../getting-started/09-anonymous-posting.md)
- [Extensions](../admin-settings/13-extensions.md)
- [Add a Moderator and Handle Reports](08-add-a-moderator-and-handle-reports.md)
