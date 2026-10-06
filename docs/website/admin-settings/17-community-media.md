---
title: "Community Media"
category: "admin-settings"
order: 17
---

When members upload images inside Jetonomy - in post bodies, reply bodies, or space cover images - those files land in the standard WordPress media library. On an active community this can flood the site owner's own media with thousands of member uploads.

Jetonomy 1.5.0 introduces **Community Media**: a dedicated admin view that tags member uploads and keeps them separate from the site owner's media, without moving any files or breaking third-party storage or image optimization plugins.

## Where to Find Community Media

Community Media is available to site administrators and users with the `jetonomy_manage_settings` capability at:

**WordPress admin → Jetonomy → Community Media**

## What It Shows

The Community Media page lists every upload made by community members through Jetonomy, paginated in a 24-item grid (newest first by default). You can filter the list by:

- **Space** - see all uploads made inside a specific space
- **Member** - type a username or email to see uploads from one person
- **Sort order** - switch between most recent and oldest first

Each item in the grid is a standard WordPress attachment. Click **View / edit** on a card to open that attachment's own edit screen in wp-admin - the same screen you would reach by opening any WordPress attachment.

## Deleting an Upload

The Community Media screen itself has no delete button - it is a browsing and cleanup view, not a moderation queue. To remove a specific upload, click **View / edit** on its card, then use **Delete Permanently** on the attachment's own edit screen. That deletes the underlying file, exactly as deleting any other WordPress attachment would.

If the upload is attached to a post or reply that was reported by a member, act on the content itself at **Jetonomy → Moderation** rather than on the file here - deleting the attachment does not remove the post or reply it is embedded in.

## Storage and the Abandoned-Upload Sweep

Community Media does not total disk usage anywhere on the page - for that, use your host's own disk tools or the main **Media → Library** screen, which is not filtered by uploader.

What Jetonomy does manage automatically is uploads nobody ever used: a member starts writing a reply, attaches an image, then abandons the draft without posting. That file has nowhere else to go, so Jetonomy sweeps it up with a daily background job:

- Only uploads Jetonomy itself created through the composer are eligible - never a file another plugin or an import brought in, and never anything Jetonomy merely recognized as a member's.
- A candidate has to be at least a day old, and unreferenced anywhere Jetonomy knows to look: no post or reply body, no member avatar, no space cover image or icon.
- **The first sweep on your site deletes nothing.** It only records what it would have removed, so upgrading into this feature never triggers a surprise bulk delete of files that had been quietly accumulating. The very next scheduled run acts on what it finds.

There is no setting or button for this - it runs on its own schedule. Developers can hook `jetonomy_media_cleanup_reported` (fires after that first, report-only pass) or `jetonomy_media_cleanup_ran` (fires after a pass that actually deletes) to log or alert on what the sweep found.

## Effect on the Main Media Library

By default, Jetonomy hides community uploads from **Media → Library** and from the media modal that appears when editing posts or pages. This keeps the site owner's own images, logos, and assets uncluttered.

A **Community uploads** dropdown on the media list toolbar lets you reveal member uploads on demand:

- **Hide community uploads** (default) - community uploads are excluded from the list and the modal
- **Show community uploads** - all uploads are visible

The choice in the list view is applied per-request via a URL parameter. The choice in the grid view (the media modal) is persisted in a browser cookie until changed.

> **Note:** Hiding community uploads in wp-admin does not affect front-end delivery. Images uploaded by members are always served at their original URLs regardless of this setting.

## What's Next?

Connect Jetonomy to your membership, LMS, and CRM tools.

[Integrations →](15-integrations.md)
