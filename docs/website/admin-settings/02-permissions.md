The Permissions tab controls how quickly members earn trust in your community and how much they can do before you've had a chance to evaluate their behavior.

## What You Will Learn

- What Jetonomy's trust level system is and how it auto-promotes members
- How to configure the thresholds for each trust level
- What each trust level unlocks
- How to adjust rate limits to match your community's size and risk tolerance

Go to **Jetonomy → Settings → Permissions** to access these settings.

## The Trust Level System

Jetonomy uses six trust levels (0 through 5) to gradually extend posting privileges to members as they demonstrate good behavior. Levels 0 through 3 are earned automatically by the background cron job. Levels 4 and 5 are granted manually by moderators or admins.

| Level | Name | Earned By |
|---|---|---|
| 0 | New Member | Default on registration |
| 1 | Basic | Automatic — light activity |
| 2 | Member | Automatic — consistent participation |
| 3 | Regular | Automatic — high engagement and reputation |
| 4 | Trusted | Manual — granted by moderator or admin |
| 5 | Leader | Manual — granted by admin only |

The cron job runs every 12 hours and re-evaluates every member against the configured thresholds. Demotion is also possible — if a member is muted or receives too many spam flags, their trust level can drop.

## Trust Level Thresholds

**Setting:** `trust_thresholds`
**Location:** Permissions tab → Trust Levels section

Each auto-promotion level (1, 2, and 3) has a configurable set of thresholds. A member must meet all thresholds for a level to be promoted to it.

| Threshold | Description | Configurable |
|---|---|---|
| `min_posts` | Minimum posts created | Yes |
| `min_days` | Minimum days since registration | Yes |
| `min_visits` | Minimum session visits | Yes |
| `min_reputation` | Minimum reputation score | Yes |
| `max_flags` | Maximum accepted spam flags before block | Yes |

**Default thresholds (Level 1 example):**

| Threshold | Default Value |
|---|---|
| `min_posts` | 1 |
| `min_days` | 1 |
| `min_visits` | 3 |
| `min_reputation` | 0 |

For small communities (under 200 members), lower the thresholds. Members can feel stuck if Level 1 requirements take weeks to meet. For larger communities, raise the thresholds to protect against spam waves.

> **Tip:** Start with low thresholds and tighten them if you see abuse. It is easier to tighten later than to manually promote members who are stuck at Level 0.

## What Each Trust Level Unlocks

| Ability | Level Required |
|---|---|
| Read public spaces | 0 (any visitor) |
| Create posts | 0 |
| Reply to posts | 0 |
| Add images to posts | 1 |
| Include external links | 1 |
| Use @mentions | 1 |
| Follow spaces | 0 |
| Vote on posts and replies | 0 |
| Flag content for moderation | 1 |
| Edit own posts | 0 |
| Delete own posts | 1 |
| Access invite links | 1 |
| Create invite links | 2 |
| Skip anti-spam checks | 2 |
| Moderate content (space moderator) | Assigned by admin |

> **Note:** The anti-spam exemption at Level 2 is important. Members who have earned Level 2 are trusted enough to skip reCAPTCHA and Turnstile checks entirely. This keeps the experience smooth for your most active members.

## Rate Limits

**Setting:** `rate_limits`
**Location:** Permissions tab → Rate Limits section

Rate limits cap how many actions a member can take in a given time window. They protect your community from spam bursts and accidental double-submissions.

| Action | Default Limit | Window |
|---|---|---|
| Create post | 5 | per hour |
| Create reply | 10 | per hour |
| Vote | 30 | per hour |
| Flag | 5 | per hour |

Admins and Level 4+ members are exempt from all rate limits.

For high-traffic communities, raise these limits. For communities experiencing spam problems, lower them. Changes take effect immediately — no cache flush needed.

## Adjusting for Community Size

**Small community (under 500 members):**
- Lower all trust thresholds significantly — active members should reach Level 2 within a week
- Keep rate limits at defaults — spam volume is low
- Consider setting `min_days` to 0 for Level 1 to avoid frustrating early adopters

**Large community (10,000+ members):**
- Keep thresholds at defaults or raise them — organic promotion still happens, just slower
- Raise `max_flags` threshold at Level 0 to prevent easy reputational attacks
- Lower post rate limits if you see spam bursts

## What's Next?

Configure which notification types are enabled by default and set your community email identity.

[Email Settings →](03-email.md)
