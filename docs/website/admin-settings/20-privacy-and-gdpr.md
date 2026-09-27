Jetonomy plugs into WordPress's own privacy tools rather than building a separate system, so a data request or a deletion request is handled the same way you would already handle one for any other plugin. This page is the owner's runbook: what to do when a member asks for their data, what happens when an account is deleted, and what to put in your privacy policy.

## What You Will Learn

- How to fulfil a "send me my data" request using WordPress's own tools
- What happens to a member's posts and replies when their account is deleted
- What text to add to your privacy policy, and where Jetonomy already suggests it for you
- How to find and clean up data left behind by accounts deleted outside Jetonomy (multisite, or an account removed before an update closed a gap)

## A Member Asks for Their Data

Jetonomy registers itself with WordPress's core personal-data export tool - there is no separate Jetonomy export screen.

1. Go to **Tools → Export Personal Data**.
2. Enter the member's email address and send (or confirm) the request the same way you would for any other plugin's data.
3. The generated export includes several Jetonomy groups: **Jetonomy Profile** (bio, trust level, reputation, post and reply counts), **Jetonomy Posts**, **Jetonomy Replies**, **Jetonomy Bookmarks**, **Jetonomy Votes**, **Jetonomy Subscriptions**, **Jetonomy Notifications**, **Jetonomy Activity Log**, and **Jetonomy Blocked Users**.

Nothing further is needed on your part - WordPress core drives the export request lifecycle (confirmation email, admin approval if you require it, download link) exactly as it does for every other registered exporter.

## A Member Asks to Be Deleted

You have two paths, depending on how the request arrives.

**A member asks you, the owner, to erase their account.** Use **Tools → Erase Personal Data**. Jetonomy registers one eraser that anonymizes their authored content and deletes everything else personal:

- **Posts, replies, and any space they own** are kept but anonymized - `author_id` is cleared so the writing stays in place (other members' threads are not left with holes) but is no longer attributed to them.
- **Everything else personal is deleted outright**: their Jetonomy profile, notifications, subscriptions, read status, space memberships, votes, activity log entries, restrictions, flags they filed, join requests, bookmarks, and both sides of any block they were part of.

**A member deletes their own account from the app or API.** This is `DELETE /users/me` (documented for members in [User Profiles](../user-profiles/01-profiles.md), under "Deleting Your Account"). It runs the exact same anonymize-and-delete behaviour described above by default. A member can additionally opt in to `delete_content: true`, which hard-deletes their posts and replies instead of anonymizing them - make sure any interface you build in front of this API makes that distinction obvious, since "delete my account" and "delete everything I wrote" are different requests members can mean either of.

**If the departing member was the sole admin of a space**, Jetonomy hands that space to another site administrator and archives it, rather than leaving it stranded with no one able to manage it. If another admin remains on the space, that admin takes over and the space stays active. This includes you: to keep a member's spaces running after you delete their account, make yourself (or anyone else) an admin of those spaces first. Either way, no other member's post or reply is affected.

## What to Put in Your Privacy Policy

Go to **Settings → Privacy** in wp-admin and open the **Policy Guide**. Jetonomy adds its own suggested paragraph automatically - you do not have to write this from scratch:

> Jetonomy sets no cookies on community pages. It counts topic views with the storage described below.
>
> **Suggested text:** When you open a discussion topic in our community, your browser notes the topic ID in session storage, which is cleared when you close the tab, so reloading a topic does not count as a new view. To stop a view from being counted twice, our server also keeps a one-way hash of your IP address and the topic ID for 30 minutes. Neither is used for tracking or advertising.
>
> If you have an account, the topics, replies, votes, reactions, follows and profile details you add to the community are stored with your account. You can request an export or erasure of this data.

Copy this into your published privacy policy page - the Policy Guide only shows it to you while you are editing.

## Cleaning Up Data From Accounts Deleted Outside Jetonomy

Two situations leave Jetonomy rows behind that the flows above never touch:

- An account was removed on **multisite** through `remove_user_from_blog()` or the network "delete from all sites" flow, on a version of Jetonomy old enough not to listen for those actions.
- A space was deleted before its content had anywhere to go, leaving its topics, replies, members and notifications pointing at a space id that no longer exists.

Both are checked and repaired with WP-CLI, safe to run at any time and safe to run repeatedly - a clean site simply reports nothing to do:

```
wp jetonomy privacy scan            # report rows still held for deleted accounts
wp jetonomy privacy purge-orphans   # remove them
wp jetonomy space scan-orphans      # report rows left behind by deleted spaces
wp jetonomy space purge-orphans     # remove them
```

Both `scan` commands are read-only. Both `purge-orphans` commands accept `--dry-run` if you want to see exactly what a real run would remove first, and both replay the same cleanup the live deletion path already runs, so counts and caches end up correct rather than just "rows gone."

> **Developers:** a Pro extension or third-party plugin that stores its own per-user or per-space table is swept automatically by adding its columns to the `jetonomy_privacy_orphan_columns` filter (user data) or `jetonomy_space_relations` filter (space data), rather than writing a parallel cleanup routine.

## Permanently Deleting a Space

Deleting a space in wp-admin or the front end defaults to **Transfer**: the space is archived and handed to another admin, and every topic and reply inside it is kept. This is deliberate - a space holds other members' contributions, not just its owner's, so the default never destroys anyone's writing.

An administrator can permanently destroy a space and everything in it instead. This is a separate, explicit action (not the default "delete" button's behaviour), and site administrators can always do it. To let a space's own admin do the same, turn on **Let space admins permanently delete a space and everything in it** under **Jetonomy → Settings → General** ("Deleting spaces"). It is off by default. A permanent purge runs in the background and cannot be undone.

## What's Next?

Manage your community's global tag namespace from wp-admin.

[Tags →](19-tags.md)
