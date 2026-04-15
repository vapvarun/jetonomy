# Post-Launch 30-Day Retention Email

**Version:** 1.3.0
**Last updated:** April 2026

Single email sent ~30 days after a user installs Jetonomy Free. Goal: bring back members who tried the plugin, shipped a community, and may have let it idle. Soft Pro mention with AI moderation as the headline feature. Not a hard sale — this is a check-in.

**Send trigger:** 30 days after the `jetonomy_activated` flag is set (track via admin option `jetonomy_activated_at`).
**Exclude if:** User has already purchased Pro (check EDD license table).
**Do not send to:** Users whose site shows zero topics created (separate reactivation email for that group).

---

## Subject Line Options

1. 30 days in — how's your community going?
2. Your Jetonomy community is 30 days old. Here's what's new.
3. Three updates since you installed Jetonomy

## Preheader Text

AI moderation, Topic Prefixes, and what we've shipped since launch.

---

## Body

Hi [Name],

It's been about 30 days since you installed Jetonomy. We hope your community is getting off the ground.

Quick check-in — and some updates worth knowing about.

---

### Since you installed, we've shipped three releases

- **1.1** — theme compatibility polish across 12 popular WordPress themes
- **1.2** — Private Topics, Topic Prefixes, Similar Topics detection, Quote Replies (all in the free plugin)
- **1.3** — AI integration in Pro (with self-hosted Ollama support)

The free plugin update is automatic — your **Jetonomy → Dashboard** should show the new features. If you haven't poked at it in a while, the new discussion controls are in the new-post composer.

---

### Two tips if your community is quieter than you hoped

**1. Enable trust levels if you haven't.** Trust levels are on by default, but a lot of early communities disable them because new accounts feel "restricted." The restrictions are the point — they prevent spam and push new members to participate before they get full posting ability. If you turned them off, turn them back on for a week and see what happens to your spam volume.

**2. Clean up your demo data.** If you ran the setup wizard with "Start with demo content," you have example topics and replies in your database. Real members are not going to post in a space full of fake content from a week ago. Go to **Jetonomy → Settings → Demo Data** and click **Clean Up**. One click, reversible from a backup.

---

### The 1.3 release — AI moderation, your way

If moderation is taking time you do not have, **Jetonomy Pro 1.3** now reads every new post and reply for spam, abuse, and rule violations before publish.

What is new:

- **AI spam detection** — catches the spam Akismet and pattern matching miss
- **Content moderation from plain-English rules** — describe your rules in a few sentences and the model reads every post against them
- **Reply suggestions** — drafts replies for knowledge-base spaces
- **Thread summaries** — pins a summary on long topics so new readers don't have to scroll

Four providers supported, including **self-hosted Ollama** — run everything on your own server, no data leaves your machine, no per-request bill. For privacy-sensitive communities, this is the difference between having AI moderation and not.

[See how Jetonomy Pro 1.3 handles moderation →](https://wbcomdesigns.com/downloads/jetonomy-pro/)

Not ready for Pro? That is completely fine. The free plugin is not a trial — you can run it forever. This email is just to let you know the option exists if the moderation load is building up.

---

### One ask — tell us what's working and what isn't

We read every reply. If your community is going well, we would love to see it — drop us a link and we might feature it on our site.

If it is not going well, that is even more useful for us to hear. Reply to this email and tell us what is broken, unclear, or slower than you expected. We fix things fast — three releases in 14 days post-launch is not a coincidence.

**Reply to this email** or open a ticket at support.wbcomdesigns.com.

---

Thanks for giving Jetonomy a try.

The Wbcom Designs Team
wbcomdesigns.com

---

**P.S.** Did you know Jetonomy supports the WordPress Abilities API? If you are building AI agents or automation on top of WordPress, Jetonomy registers 19 abilities that your agent can discover and call directly. No custom integration code.

---

## Personalization Variables

| Variable | Source | Fallback |
|---|---|---|
| `[Name]` | `user_meta.first_name` | "there" |
| (post count) | `SELECT COUNT(*) FROM wp_jt_posts WHERE author_id = {user}` | used in the "if your community is quieter" logic — if > 10 posts, drop that section entirely |
| (trust level) | `wp_jt_user_profiles.trust_level` | — |
| (install date) | `jetonomy_activated_at` option | — |

## A/B Test Options

- **Subject A:** "30 days in — how's your community going?"
- **Subject B:** "Your Jetonomy community is 30 days old. Here's what's new."

Subject A reads as a check-in. Subject B reads as an announcement. Send 50/50 for the first batch and pick the winner for subsequent sends.

## What to Avoid

- Do not use this email as a hard Pro sale. If you do, this becomes a spam email and the reply rate drops to zero. The tone is: we are checking in, by the way here is what's new, by the way Pro exists if it's useful.
- Do not send if the user has zero topics. That case needs a separate "getting unstuck" email.
- Do not include feature comparison tables or bullet lists with 10+ items. This is a check-in, not a sales page.
