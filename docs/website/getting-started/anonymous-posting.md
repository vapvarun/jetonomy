---
title: "Anonymous Posting"
category: "getting-started"
order: 8
---

# Anonymous Posting

Anonymous Posting lets members start topics and write replies without showing their name or avatar to other members. It is a **Pro** feature, and it is off until you turn it on — both site-wide and per space.

## How it works at a glance

- A member ticks **Post anonymously** (new topic) or the **Reply anonymously** toggle (reply) when writing.
- Everyone else sees the author as **"Anonymous"** with a neutral silhouette — in the feed, on the topic, in replies, in search, in the RSS feed, in notifications, on profiles, and in the mobile app.
- The member's real identity is still stored privately, so moderation, rate limits, and reputation keep working — and a **site administrator** can reveal the real author when needed. Every reveal is written to the activity log.

## Turn it on

Anonymous posting needs **two switches**, both on:

### 1. Enable the feature (site-wide)

Go to **Jetonomy ▸ Extensions** and switch **Anonymous Posting** on. That extension toggle is the global master switch — there is no separate settings screen for it.

### 2. Allow it in a space

Anonymous posting only appears in spaces where a space admin has opted in:

1. Open the space and go to **Edit ▸ Anonymous**.
2. Tick **Allow anonymous posts** and save.

Turn it on only where it fits — for example a support, feedback, or sensitive-topics space. Leave it off everywhere else. A space where it is not allowed never shows the anonymous option, even while the extension is enabled.

## What a member sees

In a space that allows it:

- **New topic:** a **Post anonymously** checkbox in the composer.
- **Reply:** an **anonymity toggle** (mask icon) in the reply toolbar — it highlights when active.

When they submit with it on, their post shows as **Anonymous** to everyone else. They can still edit or delete their own anonymous post, and they still receive replies and reactions to it.

## Revealing the real author (administrators only)

Only **site administrators** can reveal who is behind an anonymous post or reply — space moderators cannot. On a post or reply, an admin uses **Reveal author**. The real name is shown only for that explicit action, and **every reveal is recorded in the activity log** (who revealed it, which item, and when), so reveals stay accountable.

Use this sparingly — for abuse investigations, not routine browsing. Anonymous stays anonymous by default, even for administrators, until they explicitly reveal.

## Good to know

- **The real author is never discarded.** Anonymity hides identity from other members on every surface; it does not make the post authorless. That is what lets you moderate, rate-limit, and — if necessary — reveal.
- **Both switches are required.** Disabling the extension, or turning the space option off, immediately stops new anonymous posts. Posts that were already anonymous stay masked.
- **Abuse:** because the poster is still a real, rate-limited member behind the scenes, blocking and standard moderation remain the remedy for misuse.

## Related

- [Extensions](../admin-settings/13-extensions.md) - where every Pro extension, including Anonymous Posting, is enabled or disabled
- [Activity Log](../admin-settings/08-activity-log.md) - where every author reveal is recorded
- [REST API reference](../developer-guide/01-rest-api.md#pro-endpoints) - the `POST /anonymous/reveal` endpoint for a custom admin surface
