Feature an important post at the top of every space across your whole community.

> **PRO** - This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

## What You Will Learn

- How to enable Site Announcements
- What a community announcement is, and how it differs from pinning a topic in a space
- Who is allowed to create announcements
- How to pin a post to the community and where it appears
- How many announcements you can have at once
- How to remove an announcement

## Enabling Site Announcements

Site Announcements is one of the Pro extensions, enabled the same way as the others:

1. Go to **Jetonomy → Extensions** in your WordPress admin.
2. Find **Site Announcements** and click **Enable**.
3. A **Pin to community** button appears in the action bar of every post for administrators.

## Announcements vs Space Pinning

Jetonomy has two separate "pin" tools for two different jobs:

| | Space pin ("Pin") | Community announcement ("Pin to community") |
|---|---|---|
| Scope | Top of **one space** only | Top of **every space** across the community |
| Who can use it | Space moderators and admins | **Administrators only** |
| Badge shown | green **Pinned** | green **Announcement** |
| Where to find it | The topic's **...** menu | A **Pin to community** button on the topic |

Use a space pin for a "start here" thread inside one forum. Use a community announcement for something everyone should see no matter which space they are browsing - a maintenance notice, a major release, or community-wide rules. Space pinning is covered in [Topic Management](../discussions/06-topic-management.md).

## Who Can Create Announcements

Community announcements are **administrator-only**. The control is gated by the `manage_options` and `jetonomy_manage_spaces` capabilities, and `jetonomy_manage_spaces` is granted only to the Administrator role by default. Moderators who can pin topics within their own space cannot create site-wide announcements - that power stays with admins, because an announcement affects every space.

## Pinning a Post to the Community

1. Open the post you want to feature.
2. In the action bar below the post, click **Pin to community**.

The post is immediately featured across the community. The button changes to **Unpin from community**, and an **Announcement** badge appears on the post.

## Where Announcements Appear

A community announcement is shown in two places:

- **At the top of every space's listing** - above that space's own topics, with an **Announcement** badge, so members see it wherever they browse.
- **On the post's own header** - with the same **Announcement** badge.

A post can be both space-pinned and a community announcement at the same time; in that case it shows both the **Pinned** and **Announcement** badges, which is expected.

## The Announcement Limit

You can have up to **5 community announcements** at once. When the limit is reached, pinning another post returns "You can only pin 5 announcements at a time. Unpin one first." Keeping the set small protects the value of the slot - if everything is an announcement, nothing is.

## Removing an Announcement

Open the post and click **Unpin from community** in the action bar. The post returns to its normal position everywhere and the **Announcement** badge is removed. This does not delete or unpin the post within its own space - if it was also space-pinned, that pin remains.

## REST API

Site Announcements registers these endpoints under `jetonomy-pro/v1`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/site-announcements` | List the current set of pinned posts and the pin limit |
| `POST` | `/site-announcements/{id}` | Pin a post to the community |
| `DELETE` | `/site-announcements/{id}` | Unpin a post from the community |

`{id}` is the numeric post ID. Pinning and unpinning require the `manage_options` or `jetonomy_manage_spaces` capability (Administrator by default); the same capability is required to list the current pins. See the [REST API reference](../developer-guide/01-rest-api.md) for full payloads.

## Related

- [Topic Management](../discussions/06-topic-management.md) - space-level pinning, closing, moving, and merging topics

## What's Next?

You have now seen every Pro feature. Return to the [Pro getting-started guide](00-getting-started-pro.md) to choose which extensions to enable for your community.
