# Advanced Moderation

Define rules that catch bad content automatically - before it ever appears in your community.

> **PRO** - This feature requires [Jetonomy Pro](https://wbcomdesigns.com/downloads/jetonomy-pro/).

## What You Will Learn

- How to enable Advanced Moderation Rules
- How to create keyword, regex, link-limit, new-user, and spam-score rules
- What actions each rule can take on matched content
- How to scope rules to a specific space or apply them globally
- How to read rule trigger statistics

## Why Auto-Moderation Matters

Manual moderation does not scale. A single moderator reviewing every post works fine at 10 posts per day - it fails at 1,000. Auto-moderation rules handle the obvious cases automatically so your human moderators can focus on edge cases that require judgment.

Advanced Moderation complements the free trust level system. Auto-moderation rules add a content layer on top of it.

## Enabling Advanced Moderation

1. Go to **Jetonomy → Extensions** in your WordPress admin.
2. Find **Advanced Moderation** and switch its toggle on.
3. An **Auto-Rules** tab appears under **Jetonomy → Moderation**.

## Creating a Rule

1. Go to **Jetonomy → Moderation → Auto-Rules**.
2. Fill in the **Add Auto-Moderation Rule** form at the top of the tab:

| Field | Description |
|-------|-------------|
| **Name** | Internal label - members never see this |
| **Type** | Keyword Filter, Regex Pattern, Link Limit, New User Restriction, or Spam Score |
| **Pattern** | The words, regex, or number to match. The input changes with the Type |
| **Action** | What happens when the rule triggers |
| **Scope** | **Global (all spaces)** or **Specific Space**, with a space picker |

3. Click **Save Rule**.

New rules start active. Use the **Disable** and **Enable** buttons in the **Active Rules** list below the form to switch a rule off without deleting it. **Edit** reopens the form and the button reads **Update Rule**. A rule scoped to a specific space needs a space chosen, or it is refused.

## Pattern Types

### Keyword Filter

Matches any post or reply that contains the word or phrase in its text or title (case-insensitive). Use comma-separated values to match any of several words with a single rule.

Example: `buy now, click here, limited offer`

### Regex Pattern

Full regular expression, entered without delimiters, matched against the post text and title. Use this for patterns a keyword list cannot capture - phone number patterns, URL shortener patterns, or obfuscated spam.

Example: `\b(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b`

> **Note:** Regex patterns are evaluated server-side using PHP `preg_match()`. Test your regex at regex101.com before adding it to a live rule.

### Link Limit

Triggers when a post or reply contains more than the number of links you set (it counts `http://` and `https://` addresses). New spammers often post content with 5-10 outbound links. A limit of 3 catches most of these while allowing legitimate "here are some resources" posts. The default is 3.

### New User Restriction

Triggers on posts and replies from members whose trust level is below the number you set (0 to 5). Members who have no Jetonomy profile yet also trigger it. The default is 1, which catches brand-new members at Trust Level 0. Pair it with **Hold for Approval** to review every first post.

### Spam Score

Jetonomy adds up points for each post, and the rule triggers when the total is above your threshold:

- 2 points for each link
- 3 points for each of your Keyword Filter rules that matches
- 5 points if the account is less than 24 hours old
- 3 points if the member has no posts or replies yet

The default threshold is 10. Lower it if spam slips through, and raise it if legitimate posts are caught.

## Rule Actions

| Action | What happens |
|--------|--------------|
| **Flag for Review** | Content publishes normally and is added to the mod queue with a flag |
| **Hold for Approval** | Content is held as Pending and does not appear until a moderator approves it |
| **Block (reject)** | The post is rejected and the member sees "Your post was blocked by our content policy." |
| **Mark as Spam** | Content is marked as spam and hidden immediately |

If several rules match the same post, the most severe action wins: Block, then Mark as Spam, then Hold for Approval, then Flag for Review.

Choose the least restrictive action that solves the problem. Use **Flag for Review** for borderline content, **Hold for Approval** for likely-bad content, and **Block (reject)** or **Mark as Spam** for clearly harmful content.

## Rule Scope

**Global rules** apply to every post and reply across all spaces. Use these for site-wide policies - prohibited words, adult content, competitor spam.

**Space-scoped rules** apply only within a specific space. Use these for space-specific norms - a Support space might block all links to prevent fishing attacks, while General Chat allows them freely.

## Rule Statistics

The **Active Rules** list shows a **Hits** count for each rule - how many times it has fired since the rule was created. There is no per-space breakdown or timeline chart.

Use this data to tune your rules. A rule with a very high hit count that sends everything to **Mark as Spam** probably needs a higher threshold - it is catching legitimate content.

## REST API

Advanced Moderation manages rules under `jetonomy/v1`:

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/moderation/rules` | List all moderation rules |
| `POST` | `/moderation/rules` | Create a rule |
| `PATCH` | `/moderation/rules/{id}` | Update a rule |
| `DELETE` | `/moderation/rules/{id}` | Delete a rule |
| `GET` | `/moderation/rules/{id}/stats` | Get a rule's details and hit count |

Creating, updating and deleting rules require the `jetonomy_moderate` capability. Listing rules and reading stats also allow `manage_options`. See the [REST API reference](../developer-guide/01-rest-api.md) for full payloads.

## What's Next?

Re-engage members who have not visited recently with automated email digests.

[Email Digest →](08-email-digest.md)
