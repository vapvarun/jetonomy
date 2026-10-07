---
title: "Try It With Demo Content"
category: "how-to"
order: 7
---

# Try It With Demo Content

Use this guide to fill an empty community with realistic sample content, try every feature, and remove it all again in one click. It is the fastest way to see what Jetonomy can do before you write your own content.

## What you will set up

- A ready-made sample community on your site
- A look at each space type working with real-looking content
- A clean removal when you are ready to launch

## Before you start

- Jetonomy is active. Free is enough. With Pro active, you also get sample Pro content.
- Use a test or staging site if you can. Demo spaces are public, and the sample members are real WordPress accounts.
- You are signed in as an administrator.

## Step 1: Import the demo data

1. Go to **Jetonomy → Dashboard**.
2. Find the **Demo Data** card in the right-hand column.
3. Click **Import Demo Data**.
4. Read the prompt "Add sample members, categories, spaces and topics to this community?" and confirm.
5. Wait. The button shows **Importing…**. This is a large import, so it can take a minute or more on slower hosting. Keep the page open.

The Dashboard reloads with the message "Demo data imported." The card turns yellow and is now titled **Demo Data Active**.

## What it creates

| Item | What you get |
|---|---|
| Categories | Four: **Start Here**, **Product & Engineering**, **Community**, **Help & Support** |
| Spaces | Twenty, covering all four types (Forum, Q&A, Ideas and Feed) |
| Members | About 20 sample members, two of them moderators, with different trust levels and reputation |
| Content | Around 220 topics with replies, votes, tags, accepted answers and roadmap statuses, spread over the last 90 days |
| Moderation | A few reports waiting in the moderation queue |
| (Pro) | Reactions, polls, private message threads and badges |

Good places to look:

- **Help Desk** is a Q&A space with accepted answers.
- **Feature Requests** is an Ideas space with a roadmap.
- **Announcements** is a Feed space.
- **Insiders / Beta** is Private with **Requires Approval**. It is a handy space for trying join requests.

All other demo spaces are Public and Open.

The sample members use made-up addresses ending in `@jetonomy.local` and random passwords. You cannot sign in as them. To see the community as a normal member, create a test account of your own.

## Step 2: Explore

1. Click **View Community** in the **Quick Actions** card on the Dashboard.
2. Try each space type, open a Q&A thread, vote, and visit a roadmap.
3. Open **Jetonomy → Moderation** to see the queue.

Then follow the other how-to guides against this content:
[Launch a Support Forum](01-launch-a-support-forum.md),
[Collect Feature Requests](04-collect-feature-requests-and-show-a-roadmap.md),
[Invite Your First Members](05-invite-your-first-members.md).

## Step 3: Remove the demo data

1. Go to **Jetonomy → Dashboard**.
2. On the **Demo Data Active** card, click **Remove All Demo Data**.
3. Confirm the prompt: "Delete all sample categories, spaces, posts, and replies? Your own content is not affected."

The Dashboard reloads with "All demo data has been removed." The card goes back to **Demo Data** with **Import Demo Data**.

Removal deletes the demo categories, the demo spaces, everything inside those spaces, and the sample member accounts.

> **Warning:** Anything posted inside a demo space is removed with it, even if a real member wrote it. Keep real content out of demo spaces. Create your own spaces instead.

## Importing again

Importing never doubles the content. Each import first removes the previous demo set and builds a fresh one. To start over from the Dashboard, click **Remove All Demo Data**, then **Import Demo Data**.

## From the setup wizard

The wizard offers the same sample community. On its second step, click **Create sample data instead** instead of creating one space by hand. The wizard opens by itself the first time you activate Jetonomy. Until setup is complete, the Dashboard shows **Run Setup Wizard**. See [Setup Wizard](../getting-started/02-setup-wizard.md).

Developers can also use `wp jetonomy demo-seed` and `wp jetonomy demo-cleanup`, or the `/jetonomy/v1/admin/demo-data` REST route.

## Check that it works

1. The Dashboard card is yellow and says **Demo Data Active**.
2. **Jetonomy → Spaces** lists the twenty demo spaces.
3. The community home at `/community/` shows four categories. Use your own address if you changed it.
4. After removal, the spaces list no longer shows the demo spaces, and the card is back to **Demo Data**.

## Common questions

**Will it delete my own spaces or posts?**
No. It removes only the items it created. Your own categories, spaces and posts stay.

**Can I keep just a few demo spaces?**
No. Demo content is removed as one set. Recreate any space you like under your own name.

**Should I leave it on a live site?**
Remove it before launch. Demo spaces are public, so visitors and search engines can read them.

**Why can I not log in as a sample member?**
They have random passwords by design. Use your own test account.

## Related guides

- [Admin Dashboard](../getting-started/05-admin-dashboard.md)
- [Setup Wizard](../getting-started/02-setup-wizard.md)
- [Your First Community](../getting-started/03-first-community.md)
- [WP-CLI Commands](../developer-guide/10-wp-cli.md)
