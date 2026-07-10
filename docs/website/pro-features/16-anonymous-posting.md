Let members post topics and replies without showing their name or avatar to other members, with an audited admin-only reveal.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to enable Anonymous Posting site-wide and allow it in individual spaces
- What members see when they post or reply anonymously
- Why the real author is still tracked behind the scenes
- How administrators reveal the real author, and how every reveal is logged
- How to use the REST API reveal endpoint

## Why Anonymous Posting Matters

Some conversations only happen if the poster's name is hidden - a support question about a sensitive personal issue, honest feedback about a manager, or a first post in a topic the member is nervous about. Anonymous Posting lets you open that door in the spaces where it fits, while keeping every anonymous author accountable behind the scenes.

## Enabling Anonymous Posting

Anonymous posting needs two switches, both on. It never appears with only one of them set.

1. **Site-wide:** Go to **Jetonomy → Extensions**, find **Anonymous Posting**, and click **Enable**. This is the global master switch - there is no separate settings screen for it.
2. **Per space:** Open the space, go to **Edit → Anonymous**, tick **Allow anonymous posts in this space**, and save.

Turn the space option on only where it fits - a support, feedback, or sensitive-topics space, for example. Leave it off everywhere else. A space where the option is off never shows the anonymous controls, even while the extension is enabled globally.

## What Members See

In a space that allows it, members get:

- **New topic:** a **Post anonymously** checkbox in the composer fields.
- **Reply:** a compact anonymity toggle (mask icon) in the reply toolbar, which highlights when active.

When a member submits with either one on, their post or reply shows as **Anonymous** to everyone else - in the feed, on the topic, in replies, in search, in the RSS feed, in notifications, on profiles, and in the mobile app. It uses the same author-resolution path everywhere, so no surface can leak the real name by accident.

## Server-Side Enforcement

The anonymity flag is never trusted from the client alone. Every post and reply create request is re-validated server-side against both gates (the global switch and the space opt-in) before it is allowed to save as anonymous - so a member cannot force anonymity in a space that has not opted in, even by editing the request.

## Revealing the Real Author

Only **site administrators** can reveal who is behind an anonymous post or reply - space moderators cannot. An admin uses the **Reveal author** button on the post or reply. The real name is shown only for that explicit action; ordinary admin browsing still shows "Anonymous."

Every reveal is written to the activity log, recording who revealed it, which item, and when - so reveals stay accountable. Use this sparingly, for abuse investigations rather than routine browsing.

## REST API

Anonymous Posting registers this endpoint under `jetonomy/v1`:

| Method | Endpoint | Description |
|--------|----------|--------------|
| `POST` | `/anonymous/reveal` | Reveal the real author of an anonymous post or reply |

**POST /anonymous/reveal - body**

```json
{
  "object_type": "post",
  "object_id": 128
}
```

`object_type` is `post` or `reply`. The route requires the `manage_options` capability (administrators by default) and returns `403` if the caller lacks it, or `404` if the object is not actually anonymous. A successful call returns the real author's `id` and `name` and writes the reveal to the activity log. See the [REST API reference](../developer-guide/01-rest-api.md) for full payloads.

## Related

- [Extensions](../admin-settings/13-extensions.md) - where Anonymous Posting is enabled or disabled
- [Activity Log](../admin-settings/08-activity-log.md) - where every author reveal is recorded
- [Anonymous Posting (Getting Started)](../getting-started/anonymous-posting.md) - the quick member-facing overview

## What's Next?

Let members attach images, PDFs, and documents to topics and replies.

[File Attachments →](17-file-attachments.md)
