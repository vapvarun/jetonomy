The moderation queue is your single dashboard for everything that needs human review — posts waiting for approval, flagged content, and items caught by spam filters. You can action everything from one page without digging through individual topics.

![Admin moderation queue with pending items and bulk action controls](../images/admin-moderation.png)

## What You Will Learn

- How to access the moderation queue
- What types of content appear in the queue
- What actions you can take on each item
- How to use bulk actions for efficiency
- How per-space moderation differs from global moderation
- How Akismet-held content appears in the queue

## Accessing the Moderation Queue

Go to **Jetonomy → Moderation** in your WordPress admin. The page is also accessible from the frontend at `/community/mod/` — WordPress Administrators and users with the `jetonomy_moderate` capability can access both routes.

The queue shows a count badge on the admin menu item whenever there are items waiting for review.

## What Appears in the Queue

The queue has two sections:

### Pending Posts

These are topics and replies that were submitted in a space with **Require Post Approval** enabled. They are not visible to other community members until a moderator approves them.

Each pending item shows the full content, the author, the space it was submitted to, and how long it has been waiting. Items are ordered oldest first so nothing sits in the queue unnoticed.

### Flagged Content

These are live topics and replies that members have flagged for review. Flagged content stays visible in the community until a moderator acts. Each item shows the content, the flag reason(s), how many unique members flagged it, and the timestamp of the most recent flag.

## Available Actions

For each item in the queue, you can take one of three actions:

| Action | What it does |
|--------|-------------|
| Approve | Publishes a pending post, or resolves a flag as "Valid" and leaves the content live |
| Mark as Spam | Marks the content as spam and moves it to trash; updates Akismet's spam training if Akismet is active |
| Trash | Moves the content to trash without marking it as spam |

For flagged content, Approve resolves the flag as dismissed — meaning the flag was unfounded and the content is fine. The content stays live. Use Trash or Mark as Spam to remove content where the flag was legitimate.

> **Tip:** Use Mark as Spam rather than Trash when content is clearly commercial spam. This trains Akismet for your site, making future auto-detection more accurate.

## Bulk Actions

Check the checkbox on multiple queue items, then choose an action from the **Bulk Action** dropdown and click **Apply**. All three actions — Approve, Mark as Spam, Trash — are available as bulk actions.

Bulk actions are the fastest way to clear a backlog. If you have 40 flagged items from a spam attack, select all and bulk-trash them in one click.

## Per-Space vs Global Moderation

The queue shows content from all spaces by default. Use the **Space** filter dropdown at the top of the queue to narrow to a single space. This is useful when you have dedicated space moderators — a moderator for your Support space only needs to see Support space items.

Space moderators who do not have global admin access see only their own spaces' items when they visit `/community/mod/`. They do not see content from spaces they do not moderate.

## Akismet Integration

If the Akismet Anti-Spam plugin is active and configured on your site, Jetonomy automatically passes new posts and replies through Akismet before saving them. If Akismet marks content as spam:

- The post or reply is saved with a Spam status (not Pending)
- It does not appear in the community
- It appears in the moderation queue under a "Spam" filter tab

You can review Akismet-held items and restore any that were incorrectly caught by clicking **Not Spam**. This action publishes the content and updates Akismet's model.

> **Note:** Akismet integration requires the Akismet plugin to be installed, activated, and connected with a valid API key. Jetonomy does not bundle Akismet — it integrates with it automatically when present.

## What's Next?

Learn about Jetonomy's built-in anti-spam tools — reCAPTCHA, Turnstile, and rate limiting — that reduce how much reaches the moderation queue in the first place.

[Anti-Spam Protection →](04-anti-spam.md)
