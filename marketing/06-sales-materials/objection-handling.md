# Jetonomy - Objection Handling Guide

**Version:** 1.3.0
**Last updated:** April 2026

For use by the sales team, support team, and anyone responding to pre-sales questions. Each entry follows the same structure: acknowledge the concern, address it with specifics, and close with confidence.

---

## Objection 1 - "bbPress is free and good enough."

**Acknowledge**

bbPress is free, has been around since 2010, and has a large add-on ecosystem. For small communities on older WordPress installs, it does the job. That is a fair position.

**Address**

"Good enough" depends on what your community needs to do and how it needs to perform.

Where bbPress runs into real limits:

- **Architecture at scale.** bbPress stores topics as WordPress posts and replies as comments or posts, with metadata in wp_postmeta. At a few thousand topics this is fine. At tens of thousands, wp_postmeta queries get slow in ways that are hard to fix without switching platforms. Jetonomy uses 24 dedicated tables with proper indexes. The performance difference at scale is measurable, not theoretical.

- **No Q&A or Ideas spaces.** If you want accepted answers or member voting on a roadmap, you're adding separate plugins on top of bbPress. Jetonomy includes all three space types in the free core.

- **No trust levels or automatic rate-limiting.** bbPress moderation is manual. Jetonomy's trust system rate-limits new accounts automatically. No configuration, no extra plugins.

- **No REST API.** If you ever want to build anything custom - a mobile view, an integration, a headless front end - bbPress has no native API. Jetonomy has 48+ endpoints from day one (90+ with Pro).

- **Development pace.** bbPress has had one minor release since 2021. Jetonomy is actively maintained by Wbcom Designs, the team behind BuddyX, BuddyPress extensions, and WPMediaVerse.

**Close**

If bbPress is currently serving a small community with no plans to grow and no need for Q&A or APIs, it may genuinely be enough. But if you're building something you expect to scale, or if you're tired of the moderation overhead, Jetonomy is worth a side-by-side comparison. The free plugin is at wbcomdesigns.com. You can test it without committing.

---

## Objection 2 - "Why not just use Discourse?"

**Acknowledge**

Discourse is well-regarded, actively developed, and has features Jetonomy doesn't match yet: a long track record, hosted SaaS option, and a large global community. The concern is reasonable.

**Address**

Discourse is a separate application that runs independently from WordPress. That distinction matters:

- **Separate user base.** Discourse requires a separate login system. Connecting it to WordPress typically requires a plugin or SSO setup. Your WordPress members are not automatically Discourse members. Jetonomy uses WordPress users natively. No SSO, no sync, no duplicate accounts.

- **Separate hosting and infrastructure.** Discourse requires a VPS with at least 1GB RAM and a separate setup process. Hosted Discourse starts at $100/month. Jetonomy lives inside your WordPress install on your existing hosting. No extra infrastructure.

- **Theme disconnect.** Discourse has its own visual design. Making it match your WordPress site requires custom Discourse theme work. Jetonomy inherits your WordPress theme's fonts and colors automatically.

- **Data ownership and portability.** Self-hosted Discourse gives you data ownership, but it's a separate system from your WordPress database. Jetonomy data lives in your WordPress database alongside everything else.

- **Per-seat pricing on hosted plans.** Discourse's hosted plans scale with community size. Jetonomy is a one-time license. No monthly fees, no per-member costs.

Where Discourse genuinely wins: for very large, standalone communities where the forum is the product and not a part of a WordPress site. Discourse's dedicated architecture and mature moderation tooling are hard to beat in that scenario.

**Close**

If you're running a WordPress site and want a forum that feels native to it - same users, same theme, same database, same hosting - Jetonomy is the better fit. If you're building a standalone community product where the forum is the whole thing, Discourse is worth evaluating seriously.

---

## Objection 3 - "Will it slow down my site?"

**Acknowledge**

This is the right question to ask about any forum plugin. Forum content is high-volume: thousands of posts, millions of votes, hundreds of active users at once. A plugin that handles this poorly can slow down your entire WordPress install.

**Address**

Jetonomy was specifically designed to not do that.

The key decisions:

- **Custom tables, not wp_posts.** Forum content never touches wp_posts or wp_postmeta. Those tables are for your pages, posts, and other WordPress content. Community data is completely separate. A busy forum has zero impact on the rest of your WordPress site.

- **Denormalized counters.** Reply counts, vote scores, and post counts are stored directly on each record. There are no COUNT(*) queries running on every page load to compute these numbers.

- **Object cache.** When Redis or Memcached is available, Jetonomy caches space data, user profiles, and permission results automatically. Sub-200ms page loads at 50,000 topics with Redis enabled.

- **Cursor-based pagination.** Offset pagination slows down as tables grow because the database has to scan past skipped rows. Jetonomy uses cursor-based pagination on all list endpoints. Query time stays consistent regardless of how many records are in the table.

- **No page reloads for interactive actions.** Voting, sorting, and loading more replies use the WordPress Interactivity API. No full page loads for these actions, which means no PHP execution, no database queries for the page frame.

**Close**

We can't promise performance on any hosting environment. Shared hosting with constrained MySQL resources is a real factor. But if your hosting can run WordPress, it can run Jetonomy. For communities expecting more than a few thousand topics, moving to managed WordPress hosting with Redis support is a good investment regardless of which forum plugin you use.

---

## Objection 4 - "I don't need Pro. The free version is enough."

**Acknowledge**

That might be true. The free version is genuinely complete. Saying otherwise would be dishonest.

**Address**

The free plugin includes everything you need to run a real community: Forum, Q&A, and Ideas spaces; voting and reputation; 6 trust levels; moderation queue; full-text search; notifications; SEO markup; 48+ REST API endpoints; bbPress and wpForo importers; MemberPress and PMPro integration.

Pro is not "free but with features removed." It's free plus 14 additional modules for communities with specific needs:

- You need **private messaging** between members
- You want **emoji reactions** on posts without adding another plugin
- You want **polls** built into posts
- You need an **analytics dashboard** to track engagement trends
- You want to send an **email digest** to bring inactive members back
- You want a **badge system** that awards badges based on custom criteria
- You want **webhooks** to connect community events to Slack, Zapier, or a CRM
- You need **WooCommerce or LearnDash integration** for membership gating
- You want **white label** to present it under your own brand to clients

If none of those apply to you right now, stick with Free. You can upgrade to Pro at any time, and your community data stays intact.

**Close**

Start with Free. If your community grows to where you need any of those Pro modules, they're available. There is no pressure to upgrade and no features hidden behind a paywall in the free version.

---

## Objection 5 - "Can I migrate my existing forum?"

**Acknowledge**

This is often the biggest barrier. Years of community history - posts, replies, user accounts, reputation - represent real value. Losing it or disrupting it during a migration is a legitimate risk.

**Address**

Jetonomy ships with built-in importers for both bbPress and wpForo. The migration process is:

1. **Auto-detection.** The importer scans your WordPress install and shows you what it found: number of forums, topics, replies, and users.

2. **Dry run.** Before touching any data, you can run a preview that shows exactly what will be created in Jetonomy without making any changes.

3. **Import.** Confirm and run. The importer processes records in batches with a live progress indicator. If the import is interrupted (server timeout, browser closed), you can resume from where it stopped.

What migrates:
- Forums become Spaces
- Topics become Posts with full content
- Replies become Replies with threading preserved
- Users keep their WordPress accounts and post history

What to watch for:
- Custom add-on data (bbPress add-on-specific fields) may not map. Check the dry run output for anything unexpected.
- Private messages in bbPress require a third-party add-on and may not have a direct migration path
- Large imports (100,000+ topics) work best during low-traffic periods

If you have a community that falls outside the standard bbPress or wpForo structure, the Jetonomy REST API can be used to import custom formats programmatically.

**Close**

The dry run exists specifically to remove the risk of committing before you're confident. Run it, review the output, and only proceed when you're satisfied. If something in the dry run output looks wrong, contact support before running the full import.

---

## Objection 6 - "Does it work with my theme?"

**Acknowledge**

Theme compatibility is a real problem with forum plugins. Some embed iframes. Some ship with hundreds of lines of !important overrides. Some require specific CSS classes from specific page builders. It's a fair concern.

**Address**

Jetonomy's frontend is built with CSS custom properties that pull values from your theme's theme.json. Specifically:

- Font family - pulled from --wp--preset--font-family--body
- Brand color - pulled from --wp--preset--color--primary
- Background color - pulled from --wp--preset--color--base
- Text color - pulled from --wp--preset--color--contrast
- Border radius - pulled from --wp--custom--border-radius

If your theme publishes these values in theme.json (any modern block theme does), Jetonomy adapts automatically. No CSS overrides required on your end.

For classic themes that don't use theme.json, Jetonomy falls back to clean, neutral defaults that work with any background color.

For the BuddyX theme from Wbcom Designs, integration goes further. Jetonomy inherits BuddyNext design tokens directly, including dark mode support.

There are no !important overrides in Jetonomy's CSS. No iframes. No shortcode wrappers that create layout containers out of sync with your theme.

If you encounter a specific theme conflict after installing, the template override system lets you copy any Jetonomy template to your-theme/jetonomy/ and modify it without touching the plugin.

**Close**

The best way to verify is to install it. The free plugin is at wbcomdesigns.com. Drop it into your site and check /community/. If something looks off, it is almost always fixable with a CSS custom property override at the theme level, not a plugin-level change.

---

## Objection 7 - "Is it secure?"

**Acknowledge**

Security is not optional for a community plugin. Forums handle user-generated content, user accounts, and potentially sensitive discussions. It is exactly the right thing to ask about.

**Address**

Security decisions in Jetonomy's design:

- **Input sanitization.** All user-submitted content is sanitized before storage. Post and reply content uses wp_kses_post - the same standard WordPress uses for post editor content. No raw HTML is passed through to the database.

- **Capability checks.** Every admin action and REST API endpoint verifies the current user's capabilities before executing. The permission engine (WordPress Caps + Space Roles + Trust Levels) runs on every request.

- **Nonce verification.** All form submissions use WordPress nonces. REST API endpoints use standard WordPress REST authentication (cookie with nonce for browsers, application passwords for programmatic access).

- **Automatic trust gating as spam defense.** New accounts can't post links and are rate-limited. This reduces the surface area for XSS-via-link attacks and spam campaigns before they reach the moderation queue.

- **Clean data access.** All database queries go through model classes with parameterized queries. There is no raw SQL string interpolation in user-facing paths.

- **Minimal footprint.** Jetonomy does not phone home, does not load external scripts from third-party CDNs on the frontend, and does not introduce third-party dependencies that expand the attack surface.

- **Clean uninstall.** If the plugin is removed, all data is removed on request. No orphaned data left in the database.

For security issue reporting: contact security@wbcomdesigns.com. We treat security reports as priority items and aim to respond within 48 hours.

**Close**

No software is perfectly secure, and Jetonomy 1.0.0 is a new plugin. We encourage security researchers to test it and report findings. What we can commit to: every security report will be taken seriously, fixed promptly, and disclosed responsibly.
