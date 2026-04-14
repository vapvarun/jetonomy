# Jetonomy — Product Descriptions

**Version:** 1.3.0
**Last updated:** April 2026

---

## VERSION 1 — Store Short Description

### Character limit: 150 characters

Forums, Q&A boards, and idea trackers for WordPress. Custom database tables, 6 trust levels, 90+ REST endpoints, and a design that fits any theme.

*(145 characters)*

---

## VERSION 2 — Store Long Description

### Jetonomy — Forums, Q&A, and Idea Boards for WordPress

Jetonomy adds a complete community platform to any WordPress site. Create discussion forums, Q&A boards where the best answers surface automatically, or idea trackers where members vote on what gets built next.

Unlike older forum plugins, Jetonomy stores community data in 24 dedicated MySQL tables — not in wp_posts. That means your site stays fast, WordPress stays clean, and your community scales without architectural changes.

**Three types of community spaces**

- **Forum** — open discussion. Topics, threaded replies, voting, subscriptions.
- **Q&A** — the author marks the accepted answer. It's highlighted at the top and earns the replier reputation. Voted answers surface automatically.
- **Ideas** — members submit and vote on ideas. Admins move them through statuses: Open, Planned, In Progress, Done. A public roadmap view shows progress at a glance.

**A trust system that reduces moderation work**

Jetonomy includes 6 trust levels: Newcomer, Member, Regular, Trusted, Leader, and Moderator. New members are automatically rate-limited to 3 posts per day and can't post links. As they contribute, restrictions lift. By the time a member reaches Trust Level 4, the community has already vetted them.

There is nothing to configure. It works from day one.

**AI-powered moderation — on your own server**

New in Pro 1.3.0: an AI layer that reads every new post and reply for spam, abuse, and rule violations before publish. Four providers supported (OpenAI, Anthropic, custom endpoint, and self-hosted Ollama). For privacy-sensitive communities, run Ollama on the same server as WordPress — no data leaves your machine, no per-request API bill, and every decision is logged for compliance review.

**Performance built in**

- 24 custom MySQL tables with proper indexes for forum query patterns
- Denormalized counters — reply counts and vote scores are on each record, not computed on load
- Object cache support — works with Redis and Memcached when available
- Cursor-based pagination on all list endpoints — consistent results on active communities
- Sub-200ms page loads at 50,000 topics with Redis

**Full REST API**

48+ endpoints in the free plugin under the jetonomy/v1 namespace, 90+ endpoints with Pro. Cursor-based pagination, JSON schema validation, and complete documentation. Every feature is accessible programmatically.

Jetonomy also registers 19 abilities via the WordPress Abilities API (WP 6.9+), so AI agents and automation tools can discover and interact with your community without custom integration code.

**Works with your theme**

Jetonomy uses CSS custom properties that inherit from your theme's theme.json. Fonts, colors, and border radius adapt automatically. No CSS overrides needed.

**SEO included**

Schema.org markup on every page (DiscussionForumPosting, QAPage with acceptedAnswer, BreadcrumbList), XML sitemaps, Open Graph tags, Twitter cards, and clean human-readable URLs. All pages are server-side rendered — search engines index complete content.

**Migrate from bbPress or wpForo**

Built-in importers for both. Auto-detects your source, runs a dry run first, shows what will be created, then migrates forums, topics, replies, and users. Large imports run with a progress indicator and resume from where they left off.

**What's included in the free plugin**

- Forum, Q&A, and Ideas spaces with categories and sub-spaces
- Space visibility settings (public, private, hidden) and join policies
- Voting, reputation, and 6 trust levels with automatic promotion
- Moderation queue, flagging, bans, silencing, and revision history
- Full-text search across posts, spaces, and tags
- In-community and email notifications, subscriptions
- Leaderboard and user profiles
- Schema.org markup, sitemaps, Open Graph, and clean URLs
- 48+ REST API endpoints with cursor-based pagination
- Template overrides via your-theme/jetonomy/ directory
- bbPress and wpForo importers with dry run and progress tracking
- WordPress Abilities API support (19 abilities)
- Three-layer permissions (WP capabilities, space roles, trust levels)
- MemberPress and Paid Memberships Pro integration for space gating
- Clean uninstall — removes all tables, options, capabilities, and cron jobs

**Jetonomy Pro (sold separately)**

Pro adds 14 modules on top of the free core: AI integration (spam detection, content moderation, reply suggestions, thread summaries — with OpenAI, Anthropic, and self-hosted Ollama support), private messaging, emoji reactions, polls, community analytics dashboard, email digest, custom badges with a criteria engine, advanced auto-moderation rules, custom fields, per-space SEO controls, reply by email, web push notifications, webhooks, white label, and integrations with WooCommerce, LearnDash, and Restrict Content Pro.

**Requirements**

PHP 8.1+, WordPress 6.7+, MySQL 5.7+.

---

## VERSION 3 — Marketplace / Directory Listing (500 words)

### Jetonomy — The Modern Forum Plugin for WordPress

If your WordPress site needs a discussion forum, a Q&A board, or a community ideas tracker, Jetonomy is built for it.

Most WordPress forum plugins were designed when WordPress was primarily a blogging platform. They store forum topics as WordPress posts, replies as comments, and every piece of metadata in wp_postmeta — a key-value table that gets painfully slow once your community grows. They look like plugins that were dropped into your site because they were.

Jetonomy takes a different approach.

**Custom tables, not CPTs**

Community content lives in 24 dedicated MySQL tables with indexes designed for actual forum query patterns. There are no joins back to wp_posts on every page load. Reply counts and vote scores are stored directly on each record — no COUNT queries. At 50,000 topics with object caching, pages load in under 200ms.

**Three community modes**

Create Forum spaces for open discussion, Q&A spaces where the best answer is highlighted at the top (and the person who wrote it earns reputation), or Ideas spaces where members submit requests and vote on what gets built next. A public roadmap view tracks each idea through Open, Planned, In Progress, and Done statuses. You can run all three modes on the same site with different access controls for each.

**A trust system that does the moderation work**

Jetonomy includes 6 trust levels — Newcomer through Moderator. New members start at Level 0 and are automatically rate-limited: 3 posts per day, no link posting. Nothing to configure. As they contribute — posting, receiving upvotes, getting accepted answers — they earn higher levels and unlock more abilities. Spam and low-effort posts drop naturally because new accounts have limited reach. Active contributors earn moderation capabilities over time.

**Fits your theme automatically**

The frontend uses CSS custom properties that pull values from your theme's theme.json. Fonts, colors, border radius, and spacing adapt to your site. Drop Jetonomy into any theme and it looks like it belongs there.

**Full REST API and developer tools**

48+ endpoints in free (90+ with Pro) under the jetonomy/v1 namespace with cursor-based pagination and JSON schema validation. Full template override support via your-theme/jetonomy/. Action and filter hooks throughout. An adapter pattern for search, email, real-time updates, and membership integrations means you can swap components without touching the core.

For sites running WordPress 6.9+, Jetonomy registers 19 abilities via the WordPress Abilities API — so AI agents and automation tools can discover and interact with your community without custom code.

**Migration included**

Built-in importers for bbPress and wpForo. Auto-detection, dry run mode, and progress tracking for large migrations.

**Free, with a clear upgrade path**

The free plugin at wbcomdesigns.com includes everything described above — spaces, voting, trust levels, moderation, search, notifications, SEO, REST API, and importers. Jetonomy Pro adds 14 additional modules including AI integration (self-hosted Ollama-powered moderation), private messaging, polls, analytics, badges, webhooks, and integrations with WooCommerce, LearnDash, and Restrict Content Pro.

**Requirements:** PHP 8.1+, WordPress 6.7+, MySQL 5.7+.
