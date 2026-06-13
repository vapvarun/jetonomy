---
title: "Private Topics & Prefixes"
category: "discussions"
order: 7
---

Two features that give you finer control over individual topics - mark sensitive topics private so only you and moderators can see them, and use colored prefixes to classify topics at a glance in the space listing.

![Space topic listing showing colored topic prefix labels in front of titles and a Private badge on a private topic](../images/discussions/topic-prefixes-and-private.png)

## What You Will Learn

- How to mark a topic as private and who can read it
- How to enable topic prefixes for a space
- How to create, edit, and color prefixes
- How prefixes appear in the space listing and topic page
- When to choose a private topic over a private space

## Private Topics

A private topic is visible only to its author and to space moderators. Other members of the space cannot see it in the listing, cannot find it through search, and cannot open its URL directly - they receive a "not found" response instead.

Use private topics for:

- Support requests that include account details, order numbers, or personal information
- Reports of abuse or harassment where naming the other member in public would escalate the situation
- Personal asks to a space owner that do not need a full private message thread
- Sensitive billing or legal questions where a full support ticket is too formal

### Enabling Private Topics on a Space

Private topics are an opt-in feature per space. The space owner turns them on from **Jetonomy → Spaces → (your space) → Settings → Posting**.

1. Toggle **Allow private topics** on.
2. Save.

If the setting is off, the Private toggle does not appear in the new post composer and members cannot create private topics in the space.

### Creating a Private Topic

When the feature is enabled for a space, a **Private** toggle appears at the bottom of the new post composer. Turn it on before submitting and your topic is flagged as private.

Private topics show a **Private** badge next to the title on the topic page. The badge is visible only to you and to moderators (other members cannot see the topic at all).

### Who Can See Private Topics

| Role | Can see private topics? |
|---|---|
| Topic author | Yes |
| Space moderators | Yes |
| Space owner | Yes |
| WP admins | Yes |
| Other space members | No |
| Logged-out visitors | No |

A moderator can reply to a private topic, mark it resolved, or escalate it to a full support ticket using whatever workflow your community has. The topic author is notified of replies the same way they would be on a normal topic.

> **Note:** Private topics are different from private spaces. A private space is hidden entirely - a member either has access to everything in it or nothing at all. A private topic is a one-off exception inside an otherwise public space. Pick private spaces for ongoing confidential work (staff lounges, paying customer lounges) and private topics for one-off sensitive conversations.

## Topic Prefixes

Prefixes are colored labels that appear in front of topic titles in the space listing. They let members classify topics at a glance - `Bug`, `Question`, `Solved`, `Announcement`, `Discussion`, and so on.

Prefixes are configured per space by the space owner. Different spaces can have entirely different prefix sets - a support space might use `Bug`, `Question`, `Solved`, while a marketing space might use `Idea`, `Campaign`, `Report`.

### Enabling and Creating Prefixes

Go to **Jetonomy → Spaces → (your space) → Settings → Prefixes**.

1. Toggle **Enable topic prefixes** on.
2. Click **Add Prefix**.
3. Type a short label (up to 20 characters).
4. Pick a color from the palette, or enter a custom hex value.
5. Save.

Repeat for each prefix you want. Re-ordering a prefix in the admin changes its order in the composer picker. Prefixes you no longer need can be deleted - topics that used a deleted prefix revert to having no prefix.

### Using a Prefix When Creating a Topic

When prefixes are enabled for a space, a **Prefix** dropdown appears next to the title field in the new post composer. Members pick one prefix per topic, or leave it blank.

If the space is configured with **Require prefix** enabled, the composer rejects the submission unless a prefix is selected.

### Where Prefixes Appear

- In the space listing next to the topic title
- On the topic page, in the topic header above the title
- In search results
- In notification emails that reference the topic
- In the admin moderation queue

Filter the space listing by prefix by clicking any prefix label - the listing re-renders to show only topics with that prefix.

### Prefix Color Guidelines

- Use high-contrast colors for critical prefixes (`Bug` in red, `Announcement` in blue)
- Use muted colors for passive prefixes (`Discussion` in grey)
- Avoid using green except for `Solved` or `Done` - green is a strong "resolved" signal
- Avoid using red except for critical prefixes - red grabs attention

## What's Next?

Return to [Creating Topics](01-creating-topics.md) for a full walkthrough of the new post composer, or continue to the next discussion guide.
