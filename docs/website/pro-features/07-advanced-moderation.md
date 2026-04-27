Define rules that catch bad content automatically — before it ever appears in your community.

> **PRO** — This feature requires [Jetonomy Pro](https://jetonomy.com/pro/).

<!-- TODO screenshot needed: Advanced moderation rules list in the admin panel (was ../images/pro-advanced-mod-rules-list.png) -->
## What You Will Learn

- How to enable Advanced Moderation Rules
- How to create keyword, regex, link-limit, and spam-score rules
- What actions each rule can take on matched content
- How to scope rules to a specific space or apply them globally
- How to read rule trigger statistics

## Why Auto-Moderation Matters

Manual moderation does not scale. A single moderator reviewing every post works fine at 10 posts per day — it fails at 1,000. Auto-moderation rules handle the obvious cases automatically so your human moderators can focus on edge cases that require judgment.

Advanced Moderation complements the free trust level system. New members with Trust Level 0 are already rate-limited. Auto-moderation rules add a content layer on top of that.

## Enabling Advanced Moderation

1. Go to **Jetonomy → Extensions** in your WordPress admin.
2. Find **Advanced Moderation** and click **Enable**.
3. A **Moderation Rules** tab appears under **Jetonomy → Moderation**.

## Creating a Rule

1. Go to **Jetonomy → Moderation → Rules**.
2. Click **Add Rule**.
3. Configure the rule:

| Setting | Description |
|---------|-------------|
| **Name** | Internal label — members never see this |
| **Pattern type** | Keyword, Regex, Link limit, or Spam score |
| **Pattern** | The word, phrase, regex, or threshold to match |
| **Action** | What happens when the rule triggers |
| **Scope** | Global (all spaces) or a specific space |
| **Active** | Enable or disable the rule without deleting it |

4. Click **Save Rule**.

<!-- TODO screenshot needed: Rule editor form (was ../images/pro-advanced-mod-rule-editor.png) -->
## Pattern Types

### Keyword

Matches any post or reply body that contains the exact word or phrase (case-insensitive). Use comma-separated values to match any of several words with a single rule.

Example: `buy now, click here, limited offer`

### Regex

Full regular expression matched against the post body. Use this for patterns a keyword list cannot capture — phone number patterns, URL shortener patterns, or obfuscated spam.

Example: `\b(\+?1[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}\b`

> **Note:** Regex patterns are evaluated server-side using PHP `preg_match()`. Test your regex at regex101.com before adding it to a live rule.

### Link Limit

Triggers when a post or reply contains more than a set number of links. New spammers often post content with 5–10 outbound links. A limit of 3 catches most of these while allowing legitimate "here are some resources" posts.

### Spam Score

Jetonomy calculates a spam probability score (0–100) for each post based on content patterns, account age, and posting frequency. Set a threshold — any post above that score triggers the rule.

A threshold of 80 is a good starting point. Lower it only if you are seeing spam slip through.

## Rule Actions

| Action | What happens |
|--------|--------------|
| **Flag** | Content publishes normally and is added to the mod queue with a flag |
| **Hold** | Content is held as Pending and does not appear until a moderator approves it |
| **Block** | The post is rejected and the member sees an error message |
| **Spam** | Content is marked as spam and hidden immediately |

Choose the least restrictive action that solves the problem. Use **Flag** for borderline content, **Hold** for likely-bad content, and **Block** or **Spam** for clearly harmful content.

## Rule Scope

**Global rules** apply to every post and reply across all spaces. Use these for site-wide policies — prohibited words, adult content, competitor spam.

**Space-scoped rules** apply only within a specific space. Use these for space-specific norms — a Support space might block all links to prevent fishing attacks, while General Chat allows them freely.

## Rule Statistics

The rules list shows a **Triggered** count for each rule — how many times it has fired since the rule was created. Click a rule to see a breakdown by action, by space, and a timeline chart.

Use this data to tune your rules. A rule that triggers 500 times in a week and sends everything to Spam probably needs a higher threshold — it is catching legitimate content.

## What's Next?

Re-engage members who have not visited recently with automated email digests.

[Email Digest →](08-email-digest.md)
