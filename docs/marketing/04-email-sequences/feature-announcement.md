# Jetonomy 1.0.0 — Launch Announcement Email

For WordPress users who don't yet have a forum on their site. Segment: existing WordPress site owners and developers who've expressed interest in community features, downloaded forum-adjacent plugins, or attended WP events.

This complements the launch email in `LAUNCH-EMAIL.md`. That file targets the Wbcom Designs list. This file is written for cold and warm outreach to the broader WordPress audience — WP Weekly, newsletter sponsorships, affiliate sends, and partner list cross-promotions.

---

## Subject Line Options

1. Finally — a WordPress forum plugin that doesn't look like 2012
2. WordPress forums have been broken for years. We fixed them.
3. If you've tried bbPress and moved on — Jetonomy might change that

---

## Preheader Text

Custom database tables, theme-adaptive design, Q&A spaces, and trust levels. Free on WordPress.org.

---

## Full Email Body

Hi [Name],

If you run a WordPress site and you've ever wanted a community forum — a real one, not a hacked-together comment section — you've probably already discovered the problem.

The options are mostly old. bbPress was built for WordPress as it existed in 2010. wpForo is more modern but heavy. Most alternatives either look out of place in a current theme, slow down once you have real volume, or require a monthly subscription for features that should be standard.

Today we're releasing Jetonomy — and we think it's the forum plugin this ecosystem has been missing.

---

**What Jetonomy is**

Jetonomy gives your WordPress site three types of community spaces, depending on what you need:

- **Forum** — classic threaded discussions. Members post topics, others reply, everyone can follow and get notified.

- **Q&A** — question-and-answer boards where the question author marks the best reply as the accepted answer. That answer rises to the top. Voted-up answers follow. It's how Stack Overflow works, inside your WordPress site.

- **Ideas** — a community roadmap. Members submit ideas, vote on them, and see status updates (Open, Planned, In Progress, Done). Useful for product feedback, feature requests, and member-driven communities.

You can mix and match. A SaaS company might run a Q&A support space, an Ideas roadmap space, and a general Forum space — all under one community.

---

**Why it's actually fast**

Most WordPress forum plugins store topics as WordPress posts and replies as post metadata — a design that works fine at small scale and degrades badly as volume grows.

Jetonomy uses dedicated MySQL tables — 22 of them — designed specifically for forum data patterns. Indexed correctly for the queries that actually run. Counters denormalized so that loading a space listing doesn't require counting replies across thousands of records.

The difference shows up when your community has 5,000 topics. Page loads stay under 300ms without caching. With Redis caching enabled, they stay under 50ms.

---

**It fits any theme automatically**

The frontend reads your active theme's CSS custom properties and theme.json values. Fonts, colors, border radius, and spacing are inherited from your theme — not overridden by the plugin's styles. You don't write a line of CSS to make it look like your site.

---

**The free plugin covers the real basics**

Jetonomy's free version (on WordPress.org) includes:

- Unlimited spaces and sub-spaces with category organization
- Full-text search across posts, spaces, and tags
- Trust levels (0-5) that auto-moderate new members — new accounts can't post links, are rate-limited to 3 posts/day
- Voting on posts and replies (with animations and score display)
- Moderation queue with approve, spam, and trash actions
- Web notifications (bell icon, unread badge)
- Member profiles with activity history, reputation score, and badges
- Schema.org markup for forum pages (good for search engine indexing)
- Import from bbPress and wpForo in a few clicks

No license required. No feature gates on the basics.

---

**Screenshot descriptions**

*[Community Home — image suggestion: screenshot of /community/ showing multiple space cards with post counts, member counts, and last-activity timestamps. Clean card layout, inheriting a neutral theme.]*

*[Q&A Space — image suggestion: screenshot of a Q&A topic with the accepted answer highlighted in green/accent color at the top, and voted replies below it in order.]*

*[Trust Level Badge — image suggestion: close-up of a member's avatar with a small trust level badge (Level 3 "Regular") displayed below their username in a post.]*

*[Setup Wizard — image suggestion: the 4-step wizard UI showing Step 2 "Create your first space" with Forum, Q&A, and Ideas options as selectable cards.]*

---

**Migrating from bbPress or wpForo?**

The built-in importer maps your existing forums to Jetonomy spaces, preserves topics, replies, and author history, and runs a dry-run pass before touching any data. Most migrations complete in under 10 minutes for communities under 10,000 posts.

---

**Ready to try it?**

Download the free plugin on WordPress.org:
[Download Jetonomy — Free] [LINK-WP-ORG]

If you want polls, private messaging, analytics, custom badges, webhooks, and more — Jetonomy Pro adds 10 additional modules starting at $99/yr:
[See Jetonomy Pro] [LINK-PRO-PAGE]

Questions before you install? Reply here. We respond personally.

The Wbcom Designs Team

---

## Segment Notes

**Send to:**
- WordPress newsletter subscribers who have installed or starred forum-category plugins
- WordPress developer and agency lists (lean on REST API, Interactivity API, theme-adaptive points)
- Site owners who've searched for bbPress alternatives (e.g., purchased survey data, WP-adjacent lists)
- Partner plugin newsletters (membership plugins, LMS plugins, page builder communities) — use the use-case angle relevant to their audience

**Do not send to:**
- Existing Wbcom Designs customers (covered by `LAUNCH-EMAIL.md`)
- Users already on Jetonomy welcome sequence
