# Jetonomy — Landing Page Copy

**Version:** 1.0.0 Launch
**Last updated:** March 2026

---

## HERO SECTION

### Headline
The Modern Forum Plugin for WordPress

### Subheadline
Forums, Q&A, and idea boards — built for communities that grow.

Most forum plugins store everything in WordPress posts and rely on you to moderate every piece of content. Jetonomy uses dedicated database tables for performance, a trust system that lets your community moderate itself, and a design that adapts to your theme automatically.

### Primary CTA
Download Free from wbcomdesigns.com

### Secondary CTA
See Pro Features

---

## SOCIAL PROOF BAR

**22** custom database tables &nbsp;&nbsp; | &nbsp;&nbsp; **6** trust levels &nbsp;&nbsp; | &nbsp;&nbsp; **61+** REST API endpoints &nbsp;&nbsp; | &nbsp;&nbsp; Sub-200ms at 50K topics

---

## THREE KEY BENEFITS

### Built for performance, not just features

Jetonomy stores community data in 22 dedicated MySQL tables with proper indexes — not in wp_posts and wp_postmeta. Reply counts, vote scores, and post counts are stored directly on each record, so there are no slow COUNT queries on page load. At 50,000 topics with Redis, pages load in under 200ms.

### A community that moderates itself

New members are automatically rate-limited and can't post links until they've contributed enough to earn trust. As members participate, they move through 6 trust levels — unlocking more abilities as they go. By the time someone has moderator-level trust, they've already shown they deserve it.

### Fits any WordPress theme, automatically

Jetonomy inherits your theme's fonts, colors, and spacing via CSS custom properties and theme.json. Drop it into any theme and it looks like it was designed there. No CSS overrides. No shortcode wrappers. No style conflicts.

---

## FEATURE GRID

### Forum, Q&A, and Ideas — in one plugin

**Forum spaces** for open discussion. **Q&A spaces** where the best answer gets marked and rises to the top. **Ideas spaces** where members vote on what gets built next, with a public roadmap view. Run one type or all three on the same site.

### Three-layer permissions

WordPress capabilities set the baseline. Per-space roles (viewer, member, moderator, admin) let you manage individual spaces without giving out site-wide access. Trust levels handle the fine-grained rules automatically. All three resolve in a single permission check.

### Full-text search — no external service

Search across posts, spaces, and tags using MySQL FULLTEXT in BOOLEAN MODE. Results are grouped by type with counts and excerpts. When you need more, the search system uses a swappable adapter — connect Meilisearch or Elasticsearch without touching the core.

### Built-in importers for bbPress and wpForo

The importer auto-detects your existing installation, shows you a dry-run summary of what will be created, and migrates forums, topics, replies, and users. Large imports run with a live progress indicator and can resume from where they left off if something goes wrong.

### SEO ready out of the box

Every community page gets Schema.org structured data (DiscussionForumPosting, QAPage with acceptedAnswer, BreadcrumbList), Open Graph tags, Twitter card tags, and clean human-readable URLs. All pages render server-side — search engines see complete content, not a loading spinner.

### 61+ REST API endpoints

Everything Jetonomy does is available via REST API under the jetonomy/v1 namespace. Cursor-based pagination on every list endpoint. JSON schema validation on every input. Build custom frontends, mobile apps, or integrations without touching PHP.

### WordPress Abilities API support

Jetonomy registers 18 abilities across 5 categories — content, spaces, users, moderation, and engagement. AI agents and automation tools can discover and operate your community programmatically without custom integration code. No other WordPress forum plugin supports this today.

### Membership plugin integration

Gate spaces by MemberPress or Paid Memberships Pro membership level. Access rules automatically adjust when a membership activates, upgrades, or expires. No manual syncing, no custom code.

---

## HOW IT WORKS — THREE STEPS

### Step 1 — Install and run the setup wizard
Download from wbcomdesigns.com or upload the zip. The setup wizard walks you through creating your first space in about 5 minutes. Pick Forum, Q&A, or Ideas. Set visibility. Done.

### Step 2 — Your community finds its home
Members can join spaces, post topics, vote on replies, and follow discussions. Trust levels start working from day one — newcomers are gently rate-limited while active contributors earn more abilities over time.

### Step 3 — The community grows without you
Top answers surface automatically in Q&A spaces. Flagged content goes to a moderation queue. Ideas collect votes and move through statuses. Your job becomes shaping the community, not policing it.

---

## FREE VS PRO COMPARISON

| | Free | Pro |
|---|---|---|
| Forum, Q&A, and Ideas spaces | Yes | Yes |
| Voting and reputation | Yes | Yes |
| 6 trust levels with auto-promotion | Yes | Yes |
| Full moderation queue and flagging | Yes | Yes |
| Full-text search | Yes | Yes |
| In-community notifications | Yes | Yes |
| Email notifications | Yes | Yes |
| bbPress and wpForo importer | Yes | Yes |
| Schema.org and SEO markup | Yes | Yes |
| 61+ REST API endpoints | Yes | Yes |
| Template overrides | Yes | Yes |
| WordPress Abilities API (18 abilities) | Yes | Yes |
| Private messaging | — | Yes |
| Emoji reactions | — | Yes |
| Polls | — | Yes |
| Community analytics dashboard | — | Yes |
| Email digest (daily and weekly) | — | Yes |
| Custom badges with criteria engine | — | Yes |
| Advanced auto-moderation rules | — | Yes |
| Custom fields for posts, profiles, spaces | — | Yes |
| SEO controls per space | — | Yes |
| Reply by email | — | Yes |
| Web push notifications | — | Yes |
| Webhooks | — | Yes |
| White label | — | Yes |
| WooCommerce, LearnDash, and RCP integrations | — | Yes |

The free plugin covers everything a real community needs. Pro adds the tools that help larger communities grow faster.

---

## FAQ

### Is Jetonomy really free?
Yes. The free plugin is available at wbcomdesigns.com and includes forums, Q&A, ideas, voting, trust levels, moderation, search, notifications, importers, and the full REST API. There are no paywalls or feature locks in the free version. Jetonomy Pro is a separate paid plugin that adds 13 additional modules.

### Will it slow down my WordPress site?
Jetonomy does not use wp_posts or wp_postmeta for community content. It uses 22 custom MySQL tables with indexes designed for forum query patterns. Reply counts and vote scores are stored as denormalized counters — no COUNT queries on page load. With Redis, pages load in under 200ms at 50,000 topics.

### Does it work with my theme?
Jetonomy uses CSS custom properties that pull values from your theme's theme.json — fonts, colors, border radius, and spacing. It works with any theme that follows the WordPress standard. If your theme doesn't use theme.json, Jetonomy falls back to sensible defaults.

### Can I migrate from bbPress or wpForo?
Yes. Jetonomy ships with a built-in importer for both. The importer auto-detects your source, runs a dry run so you can see what will happen before committing, and migrates forums, topics, replies, and users. Large migrations include a progress indicator and can resume if interrupted.

### What are the server requirements?
PHP 8.1 or higher, WordPress 6.7 or higher, MySQL 5.7 or higher. No external services required. Redis and Memcached are supported when available but not required.

### What happens if I deactivate Jetonomy?
Your data stays in the database until you choose to remove it. If you delete the plugin via WordPress admin, Jetonomy will offer a complete data cleanup — all custom tables, options, capabilities, and scheduled jobs removed. Nothing is done automatically on deactivation.

---

## FINAL CTA SECTION

### Headline
Start your community today — it's free.

### Body
Download Jetonomy from wbcomdesigns.com and have a forum running in about 5 minutes. If you outgrow the free version, Pro is waiting.

### Primary CTA
Download Free from wbcomdesigns.com

### Secondary CTA
Compare Free and Pro
