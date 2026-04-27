Add expressive emoji reactions to every post and reply — so members can respond instantly without writing a full reply.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

![Reaction chips below a community post](../images/pro-reactions.png)
## What You Will Learn

- How to enable Reactions for your community
- How to customize which emojis appear per space
- How members react to and change their reaction on a post
- How to read reaction counts from the REST API

## Why Reactions Matter

A quick reaction lowers the bar for engagement. Members who would never write a reply will tap a rocket emoji or a heart. That micro-engagement adds up — you get richer signal on your best content and members feel heard without the pressure of composing a response.

## How It Works

Every post and every reply in your community shows a reaction strip. Members click any emoji to react. Clicking a different emoji replaces the previous one — each member can hold exactly one reaction per piece of content at a time. Clicking the same emoji again removes the reaction entirely.

The reaction counts are displayed as chips directly below the post body. Each chip shows the emoji and the total count. Hovering a chip reveals the names of recent reactors.

<!-- TODO screenshot needed: Reaction strip showing six emoji options (was ../images/pro-reactions-strip.png) -->
## Enabling Reactions

1. Go to **Jetonomy → Extensions** in your WordPress admin.
2. Find **Reactions** and click **Enable**.
3. Reactions appear on all posts and replies immediately — no page-level configuration needed.

> **Tip:** Enabling Reactions does not affect any existing posts. Historical content simply starts with zero reactions.

## Default Emoji Set

Jetonomy ships with six Fluent 3D emojis (Microsoft, MIT licensed) out of the box:

| Emoji | Label | Use case |
|-------|-------|----------|
| Like | Thumbs up | General agreement |
| Love | Heart | Enthusiasm, appreciation |
| Thinking | Thinking face | Interesting, thought-provoking |
| Celebrate | Party popper | Wins, announcements |
| Rocket | Rocket | Fast, shipped, love it |
| Sad | Sad face | Empathy, condolences |

All six are enabled globally by default. You can adjust which ones appear per space.

## Per-Space Customization

Different spaces have different tones. A Support space might not need a Celebrate emoji, and a General Chat space might not need Sad.

1. Go to **Jetonomy → Spaces** and open the space you want to customize.
2. Open the **Reactions** tab in the space settings panel.
3. Toggle individual emojis on or off for this space.
4. Save. The change takes effect immediately for all posts in that space.

<!-- TODO screenshot needed: Space settings panel — Reactions tab (was ../images/pro-reactions-space-settings.png) -->
## REST API

The Reactions extension adds four endpoints under `jetonomy/v1`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/reactions/{type}/{id}` | Get all reactions for a post or reply |
| `POST` | `/reactions/{type}/{id}` | Add or replace your reaction |
| `DELETE` | `/reactions/{type}/{id}` | Remove your reaction |
| `GET` | `/reactions/summary/{type}/{id}` | Aggregated counts by emoji |

`{type}` is either `post` or `reply`. `{id}` is the numeric ID of the item.

**Example — add a reaction:**

```json
POST /wp-json/jetonomy/v1/reactions/post/42
{
  "emoji": "rocket"
}
```

**Example — get reaction summary:**

```json
GET /wp-json/jetonomy/v1/reactions/summary/post/42

{
  "like": 14,
  "love": 6,
  "rocket": 23,
  "celebrate": 2
}
```

All reaction endpoints require the user to be logged in. Reading summaries is open to any authenticated user; adding and removing reactions requires `jetonomy_create_posts` capability.

## What's Next?

Allow members to message each other privately, without leaving your community.

[Private Messaging →](02-private-messaging.md)
