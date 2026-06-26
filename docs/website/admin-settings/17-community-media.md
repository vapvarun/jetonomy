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

Each item in the grid is a standard WordPress attachment. Clicking one opens its details in the media modal, the same as clicking any attachment in the main media library.

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
