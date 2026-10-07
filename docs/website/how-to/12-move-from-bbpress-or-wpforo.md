---
title: "Move From bbPress, wpForo or Asgaros"
category: "how-to"
order: 12
---

# Move From bbPress, wpForo or Asgaros

This guide is for site owners who already run a forum plugin and want to switch to Jetonomy without losing their discussions. You will back up, run the importer, check the result, and learn how to run it again safely.

## What you will set up

- A safe backup before anything changes
- Your old forums, topics and replies inside Jetonomy
- A checked, working community you can switch to
- A way to catch up on new posts just before you go live

## Before you start

- You need to be a WordPress administrator.
- Jetonomy is active and you have finished the setup wizard.
- Your old forum plugin still has its data. It can be active or turned off, but do not delete it until the end.
- A full database backup. This is not optional.

## Step 1: Back up your database

The Import screen says it plainly: back up first, because the import creates new records and cannot be reversed automatically. Use your host's backup tool or a backup plugin, and confirm you can download the file.

The importers only read your old forum's data. They never change it.

## Step 2: Pick your source

| You use | Importer name on the screen |
|---|---|
| bbPress | **bbPress** |
| wpForo | **wpForo** |
| Asgaros Forum | **Asgaros Forum** |

These are the only built-in sources. Each one brings across forums, topics, replies, inline images and attached files. Members are matched to their existing WordPress accounts, so nobody has to sign up again.

Some things never come across:

- Tags (all three)
- Moderator assignments (except bbPress forums linked to BuddyPress groups)
- Reputation and points
- bbPress topics and replies that are pending, spam or in the trash

Read the guide for your source before you start: [bbPress](../migration/01-bbpress-import.md), [wpForo](../migration/02-wpforo-import.md), [Asgaros Forum](../migration/03-asgaros-import.md).

## Step 3: Preview, if your source allows it

Only bbPress can preview. It runs from the command line and writes nothing:

```bash
wp jetonomy import bbpress --dry-run
```

wpForo and Asgaros cannot preview. For them, your backup is the safety net.

## Step 4: Run the import

1. Go to **Jetonomy → Import**.
2. Find your forum's card. It shows counts, for example forums, topics and replies. Check that the numbers look right.
3. Read the notes on the card. They say which statuses are included.
4. Click **Import from bbPress**, **Import from wpForo** or **Import from Asgaros Forum**.

A progress tracker shows **Forums → Topics → Replies → Profiles → Finalize**. Keep the tab open until it finishes.

If it stops, return to **Jetonomy → Import**. The card shows **Import Interrupted** with **Resume Import** and **Start Over**. Choose **Resume Import**. Nothing already brought over is repeated.

> **Tip:** For a large forum, use WP-CLI instead of the browser, so a timeout cannot stop it. Run `wp jetonomy import bbpress`, `wp jetonomy import wpforo` or `wp jetonomy import asgaros` from your server. Your host can run this for you.

## Step 5: Check the result

1. Open `/community/` and compare the spaces with your old forums.
2. Open a few busy topics. Check the text, images and reply order.
3. On the card, note any message about skipped items or files that "could not be recovered". Fix those few posts by hand.
4. Open **Past imports** at the bottom of the screen. It lists what was imported and what was skipped, with reasons.
5. Give moderators their roles again. In **Jetonomy → Spaces**, open a space, go to **Members** and set the role to **Moderator**.
6. If a space shows a "page not found", go to **Jetonomy → Dashboard** and click **Flush Rules** under **Quick Actions**.
7. Replace old forum shortcodes on your pages. They print as plain text once Jetonomy takes over.

## Step 6: Run it again before you go live

Your community keeps talking while you test. Run the import again just before the switch.

1. Go to **Jetonomy → Import**. The card now says **Previously Imported** with the date and count.
2. Click **Re-Import**.
3. Confirm the prompt. It says only new content is brought over and anything already imported is skipped.

Re-running is safe for all three sources. Jetonomy remembers each forum, topic and reply it brought over, so nothing is duplicated. The card reports how many items were "already imported, skipped". That number is proof, not an error.

When you are happy, turn off the old forum plugin. Delete it only after you have kept a backup of its data.

## Check that it works

1. Open a private browser window, signed out.
2. Visit `/community/` and open an old topic. It should load with its replies.
3. Sign in as a test member who posted in the old forum. Their name and avatar should be on their old posts.
4. Click **Re-Import** once. The result should show new items only.

## Common questions

**Can I keep the old plugin active while I test?**
Yes. Import before you delete it. Some plugins remove their data when deleted.

**Will old links keep working?**
Jetonomy uses its own addresses under `/community/`. The importer does not set up redirects from your old forum addresses. Plan them on your server or with a redirect plugin.

**What if I imported on an older version?**
A re-run recognises content from earlier imports and fills in what was skipped, without duplicates.

**Is there a browser dry run?**
No. The preview is command line only, and only for bbPress.

## Related guides

- [Migration Overview](../migration/00-overview.md)
- [Your First Community](../getting-started/03-first-community.md)
- [Space Status and Member Roles](../spaces-and-categories/05-space-status-and-roles.md)
