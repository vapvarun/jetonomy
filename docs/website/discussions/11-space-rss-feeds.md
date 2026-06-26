---
title: "Space RSS Feeds"
category: "discussions"
order: 11
---

Every public space in Jetonomy publishes an RSS 2.0 feed. Members can subscribe in any feed reader and receive new discussions as they are published, without visiting the community.

## What You Will Learn

- The URL format for a space's RSS feed
- Which spaces publish a feed and which do not
- How feed readers auto-discover the feed
- What each feed item contains

## Feed URL

Each public space serves its feed at:

```
/community/s/{slug}/feed/
```

Replace `{slug}` with the space's URL slug - the same segment that appears in the space URL at `/community/s/{slug}/`. For example, the "Announcements" space at `/community/s/announcements/` publishes its feed at `/community/s/announcements/feed/`.

If your community uses a custom base slug (configured under **Jetonomy → Settings → General**), replace `community` with that slug.

## Which Spaces Publish a Feed

A feed is only served when two conditions are both true:

1. The community visibility is set to **Public** (the global setting under **Jetonomy → Settings**).
2. The space itself is **Public** - meaning an anonymous visitor can read it.

**Private spaces** and **Hidden spaces** do not publish a feed. Requesting `/community/s/{slug}/feed/` for a private or hidden space returns a 404, not a feed. This ensures that gated content never leaks through a feed URL that gets shared or cached by an aggregator.

## Auto-Discovery

When a visitor or browser extension loads a public space page, Jetonomy adds a `<link>` tag to the HTML `<head>` that points to the feed:

```html
<link rel="alternate" type="application/rss+xml" title="Space Name - RSS" href="/community/s/slug/feed/" />
```

Feed readers and browser extensions that support auto-discovery (most do) detect this tag automatically. Members can subscribe by pasting the space URL into their feed reader - they do not need to know the `/feed/` URL in advance.

Auto-discovery is only emitted on public spaces. Private and hidden space pages do not emit the `<link>` tag.

## What Each Feed Contains

Each feed includes the 20 most recently published topics in the space, newest first. There is no pagination - feeds are a rolling snapshot of recent activity.

Each item in the feed includes:

| Field | Value |
|---|---|
| Title | The topic title |
| Link | The canonical URL of the topic |
| Publication date | When the topic was published (in RFC 822 format) |
| Author | The member's display name |
| Description | A plain-text excerpt of the topic body (approximately 55 words) |

Replies are not included in the feed. The feed covers top-level topics only.

## Developer Notes

The feed respects the `jetonomy_space_feed_posts` filter, which lets developers modify the list of posts before the XML is rendered:

```php
add_filter( 'jetonomy_space_feed_posts', function ( array $posts, object $space ): array {
    // Remove posts by specific authors, add extra items, etc.
    return $posts;
}, 10, 2 );
```

## What's Next?

Learn about the Community Media page where site administrators can review and manage member uploads.

[Community Media →](../admin-settings/17-community-media.md)
