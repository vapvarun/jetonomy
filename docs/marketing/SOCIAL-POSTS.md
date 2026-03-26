# Jetonomy Social Media Posts

Copy-paste ready posts for launch. Replace [LINK] with the actual URL before posting.
Free: https://wbcomdesigns.com/downloads/jetonomy/
Pro: https://wbcomdesigns.com/downloads/jetonomy-pro/

---

## Twitter / X (8 Posts)

---

### 1. Launch Announcement

Jetonomy v1.0 is live at wbcomdesigns.com. 🎉

A modern forum plugin for WordPress — Forum, Q&A, and Ideas spaces, built on custom MySQL tables (fast), the Interactivity API (modern), and CSS that actually inherits from your theme.

Free. No upsells to read your own content.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #ForumPlugin #OpenSource

---

### 2. Feature Highlight: Abilities API

Jetonomy supports the WordPress Abilities API.

AI agents can now discover and operate your community — create posts, moderate content, manage memberships — without custom integration code.

18 abilities. 5 categories. Works with any agent that reads the WP Abilities registry.

No other forum plugin does this today.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #AI #WordPressAbilities

---

### 3. Feature Highlight: Theme-Adaptive Design

Most forum plugins look like a plugin dropped into your site.

Jetonomy inherits your theme's fonts, colors, and spacing automatically via CSS Custom Properties and theme.json. Drop it into any theme and it fits.

No CSS overrides. No shortcodes. No iframe hacks.

Try it free: https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #WebDesign #ForumPlugin

---

### 4. Feature Highlight: Import from bbPress / wpForo

Migrating off bbPress or wpForo?

Jetonomy's importer maps everything: forums → spaces, topics → posts, replies → replies, users → profiles. Auto-detects your source, shows you what it found, runs a dry run first.

Your community history comes with you.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #bbPress #wpForo #Migration

---

### 5. Feature Highlight: Real-Time Search

Jetonomy ships with full-text search across posts, spaces, and tags — FULLTEXT in BOOLEAN MODE, no external service required.

Need more? The search system is adapter-based. Swap in Meilisearch or Elasticsearch when you're ready. Same API, better results.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #Search #ForumPlugin

---

### 6. Free vs Pro Teaser

Jetonomy Free includes:
- Forum, Q&A + Ideas spaces
- Voting + trust levels
- Full moderation queue
- bbPress/wpForo import
- Full-text search + SEO

Jetonomy Pro adds 13 modules: messaging, polls, reactions, analytics, badges, email digest, webhooks, and more.

Free at wbcomdesigns.com. Pro also at wbcomdesigns.com.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #ForumPlugin

---

### 7. Developer-Focused

Jetonomy for developers:

- 35+ REST API endpoints (jetonomy/v1)
- Cursor-based pagination on every list
- 3-layer permission engine (WP Caps + Space Roles + Trust Levels)
- Template overrides via theme/jetonomy/
- Action/filter hooks throughout
- WP Interactivity API frontend (no jQuery)
- PHP 8.1+, WP 6.7+

Extensible from day one.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #WPDev #WordPressPlugin

---

### 8. Community / Engagement Focused

Building a community on WordPress?

Jetonomy gives your members a reason to come back:

- Trust levels that unlock as people contribute
- Voted-up answers that surface automatically
- Ideas spaces where members vote on what gets built
- Notifications for replies, mentions, and accepted answers

The community earns the tools it uses. That's the design.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #CommunityBuilding #ForumPlugin

---

## LinkedIn (3 Posts)

---

### 1. Professional Launch Announcement

We built a WordPress forum plugin. Here's why that was harder than it sounds.

Most forum plugins for WordPress were designed when WordPress was mostly blogs. They store every topic as a WordPress post, every reply as a comment or post, and every piece of metadata in wp_postmeta — a key-value table that gets painfully slow once you have tens of thousands of records.

It works fine for small communities. It starts to crack at scale.

We spent the past year building Jetonomy — a forum plugin that treats community data as what it actually is. Custom MySQL tables with proper indexes, denormalized counters, cursor-based pagination, and a caching layer that works with Redis when it's available and gracefully degrades when it isn't.

The result scales to 10,000+ users without architectural changes.

What's in v1.0:

- Forum, Q&A, and Ideas space types
- Three-layer permission system (WordPress roles, per-space roles, trust levels)
- 35+ REST API endpoints with cursor pagination
- WordPress Interactivity API frontend — SEO-friendly, no jQuery
- Schema.org markup for every page type
- bbPress and wpForo importer
- WordPress Abilities API support — AI agents can discover and operate your community

The free plugin is at wbcomdesigns.com. Jetonomy Pro (13 modules including messaging, analytics, polls, and badges) is also at wbcomdesigns.com.

If you're building community features on WordPress, I'd love to hear what you think.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #WebDevelopment #CommunityPlatform #OpenSource

---

### 2. "Why We Built Jetonomy" Story Post

Two years ago, a client came to us with a simple ask: "We want a forum on our WordPress site."

We looked at the options. bbPress — solid, but essentially unmaintained and based on post types from 2010. wpForo — more modern, but heavy. BuddyPress — overkill for what they needed, and a commitment to maintain. Paid options — expensive, closed, and not always a great fit for WordPress.

None of them felt right for what WordPress looks like in 2025: block themes, theme.json, the Interactivity API, the REST API as a first-class citizen.

So we built our own.

Jetonomy started as an internal tool for that client. We've been running it in production, adding features, hitting the rough edges, and fixing them. What we're releasing today is the version we wish had existed when we started.

A few things we did differently:

1. Custom tables instead of posts. No wp_postmeta. Proper indexes for actual query patterns.

2. The frontend uses the WordPress Interactivity API — server-rendered HTML that hydrates in the browser. Pages are indexed by search engines without JavaScript. Interactions (voting, sorting, loading more replies) work without a full page reload.

3. The design adapts to your theme. CSS Custom Properties pull from theme.json. No overrides needed.

4. We built for the AI-native web from the start. Jetonomy supports the WordPress Abilities API — agents can discover what your community can do and interact with it programmatically.

It's free. We think that's the right call for something this foundational.

If you're building communities on WordPress, we'd genuinely appreciate you trying it and telling us what's missing.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #ProductLaunch #WebDevelopment #Community

---

### 3. Feature Comparison Post

We looked at how WordPress forum plugins handle permissions. Here's what most of them offer:

Global roles. That's it.

A user is either an admin, a moderator, or a member — across the entire community.

That works for a simple single-topic forum. It breaks down when your community has multiple spaces with different purposes, different audiences, and different moderation needs.

Jetonomy uses three layers:

**Layer 1 — WordPress Capabilities**
20 capabilities mapped to WP roles. The usual starting point, handled properly.

**Layer 2 — Space Roles**
Each space has its own roles: viewer, member, moderator, admin. A user can be a moderator in the Support space and a regular member everywhere else. Space admins manage their own space without WP Admin access.

**Layer 3 — Trust Levels + Access Rules**
Trust levels (0–5) are earned through activity and gate specific behaviors — new members can't post links, trusted members can close topics. Access rules can tie space access to MemberPress or Paid Memberships Pro levels.

All three layers resolve in a single permission check. The logic:

Banned? Deny.
WP Admin? Allow.
Capability missing? Deny.
Access rule applies? Grant or restrict.
Space role? Apply.
Trust gate? Check level.
Rate limit? Check count.
Otherwise: Allow.

This is the kind of permission system that grows with you — from a small community where defaults handle everything, to a large multi-space platform with different membership tiers.

Jetonomy is free at wbcomdesigns.com. The Pro version adds membership integrations for WooCommerce, LearnDash, and Restrict Content Pro.

https://wbcomdesigns.com/downloads/jetonomy/

#WordPress #CommunityManagement #ForumPlugin #WebDevelopment

---

## Facebook (3 Posts)

---

### 1. Launch Announcement (Casual, Community-Focused)

We just launched Jetonomy — a free WordPress forum plugin we've been working on for a long time, and we're really proud of it. 🎉

Here's the short version of what it does:

You install it on your WordPress site, and you get a fully-featured community platform at /community/ — spaces for discussion, Q&A boards where the best answers bubble up, and Ideas spaces where members vote on what gets built next.

It's fast (custom database tables, not WordPress posts), it looks right on any theme (inherits your fonts and colors automatically), and it's built with search engine optimization in mind from the start.

The free plugin includes everything you need to run a real community. If you need more — private messaging, polls, analytics, email digests, badges — Jetonomy Pro adds all of that at wbcomdesigns.com.

We'd love for you to try it. Download links are in the comments!

#WordPress #ForumPlugin #CommunityBuilding

---

### 2. Feature Showcase

One thing we're particularly happy about in Jetonomy: the three space types.

**Forum** — classic discussion. Members post topics, others reply, everyone can follow threads and get notified.

**Q&A** — like Stack Overflow but inside your WordPress site. The question author can mark one reply as the accepted answer, which gets highlighted at the top. Voting determines which answers surface first.

**Ideas** — community roadmap. Members submit ideas, vote on them, and watch status changes (Open → Planned → In Progress → Done). Great for feedback boards and product communities.

Each space can have its own rules, its own moderators, and its own access controls. Mix and match however your community needs.

Try it free: https://wbcomdesigns.com/downloads/jetonomy/

And if you migrate from bbPress or wpForo, the importer handles it. 🙌

#WordPress #WebDesign #CommunityPlatform

---

### 3. Call for Beta Testers / Early Adopters

Hey WordPress community — we could use your help. 👋

We just launched Jetonomy v1.0 (free forum plugin for WordPress) and we're looking for early adopters who want to be part of shaping what comes next.

What we're looking for:

- People running existing bbPress or wpForo communities who want to test the migration path
- Developers building community features for clients
- Anyone who's wanted a modern forum on their WordPress site and hasn't found quite the right fit

What you get:

- Early access to Jetonomy Pro at a discounted rate [PLACEHOLDER: confirm offer]
- Direct line to the team — we mean it, reply to this post
- Your feedback shapes v1.1

Download the free plugin: https://wbcomdesigns.com/downloads/jetonomy/
Pro details: https://wbcomdesigns.com/downloads/jetonomy-pro/

Drop a comment below or send us a message. We're listening.

#WordPress #BetaTesting #ForumPlugin #WordPressCommunity
