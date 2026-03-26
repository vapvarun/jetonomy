# Jetonomy — FAQ Content

**Version:** 1.0.0 Launch
**Last updated:** March 2026

For use on the website FAQ page and support documentation.

---

## Q1 — What are the server requirements?

**PHP 8.1 or higher, WordPress 6.7 or higher, MySQL 5.7 or higher.**

No external services are required. Jetonomy works on shared hosting, managed WordPress hosting (Kinsta, WP Engine, Cloudways), VPS, and dedicated servers.

Optional but recommended: Redis or Memcached for object caching. When available, Jetonomy uses them automatically for space data, user profiles, and permission results. Pages load in under 200ms at 50,000 topics with Redis enabled.

For the WordPress Abilities API features (AI agent integration), WordPress 6.9+ is required.

---

## Q2 — How much does Jetonomy cost?

**The core plugin is free.** Download it from wbcomdesigns.com. It includes forums, Q&A spaces, ideas boards, voting, trust levels, moderation, full-text search, notifications, SEO markup, 61+ REST API endpoints, and built-in importers for bbPress and wpForo.

**Jetonomy Pro** is a paid add-on that unlocks 13 additional modules: private messaging, emoji reactions, polls, analytics, email digest, custom badges, advanced auto-moderation, custom fields, per-space SEO controls, reply by email, web push notifications, webhooks, white label, and integrations with WooCommerce, LearnDash, and Restrict Content Pro.

Pro pricing is available at wbcomdesigns.com/downloads/jetonomy-pro/.

---

## Q3 — Can I migrate from bbPress or wpForo?

**Yes. Both are supported with built-in importers.**

The importer auto-detects your existing installation and shows you what it found: how many forums/spaces, topics/posts, replies, and users. Before committing, you can run a dry run that shows exactly what will be created in Jetonomy without touching any data.

What gets migrated:
- Forums become Spaces
- Topics become Posts
- Replies become Replies
- Users keep their accounts and post history

Large migrations run with a live progress indicator and can resume from where they left off if something goes wrong. There is no record limit — the importer handles communities of any size by batching the work.

---

## Q4 — Does it work with my theme?

**Yes, with any theme that follows WordPress standards.**

Jetonomy uses CSS custom properties that pull values from your theme's theme.json — font families, brand colors, border radius, and base spacing. Drop it into any block theme or classic theme and it adapts automatically.

No CSS overrides. No shortcode wrappers. No style conflicts.

If your theme uses the BuddyX theme from Wbcom Designs, integration is even tighter — Jetonomy inherits BuddyNext design tokens directly, including dark mode support.

If your theme doesn't publish theme.json values, Jetonomy falls back to sensible defaults that look clean on any background color.

---

## Q5 — How does Jetonomy perform at scale?

**Community data lives in 22 custom MySQL tables, not in WordPress posts.**

The short version of why that matters: wp_postmeta is a key-value table. At scale, joining it to retrieve forum content creates slow queries that get worse as the table grows. Jetonomy sidesteps this entirely with dedicated tables designed for forum query patterns.

Specific design decisions that affect performance:

- Reply counts, post counts, and vote scores are stored as denormalized counters directly on each record. No COUNT queries on page load.
- Object cache support is built in. When Redis or Memcached is available, space data, user profiles, and permission results are cached automatically.
- Cursor-based pagination on all list endpoints — results stay consistent even when new content is posted between pages.
- FULLTEXT indexes for search — no linear table scans.

Tested scale path: sub-200ms page loads at 50,000 topics with Redis. The architecture supports 10,000+ active users without any configuration changes — scaling beyond that point involves infrastructure (more caching, read replicas), not code changes.

---

## Q6 — What's the difference between Jetonomy Free and Jetonomy Pro?

**Free gives you a complete community platform. Pro adds tools for larger or more sophisticated communities.**

Free includes: Forum, Q&A, and Ideas spaces; voting and reputation; 6 trust levels with automatic promotion; moderation queue and flagging; full-text search; in-community and email notifications; subscriptions; leaderboard and user profiles; SEO markup (Schema.org, sitemaps, Open Graph); 61+ REST API endpoints; template overrides; bbPress and wpForo importers; MemberPress and Paid Memberships Pro integration; WordPress Abilities API support.

Pro adds 13 modules: private messaging, emoji reactions, polls, analytics dashboard, email digest, custom badges with criteria engine, advanced auto-moderation rules, custom fields for posts and profiles, per-space SEO controls, reply by email, web push notifications, webhooks, white label, and integrations with WooCommerce, LearnDash, and Restrict Content Pro.

The free plugin will stay free and fully functional. It is not a limited trial. Pro exists for communities that need the additional modules — not to lock features that should be free.

---

## Q7 — Who owns the data? Can I export it?

**You own your data. It lives in your WordPress database.**

Jetonomy creates custom MySQL tables in your database (wp_jt_* by default). You access them the same way you access any WordPress data — via phpMyAdmin, WP-CLI, or your hosting control panel.

There is no data sent to Wbcom Designs servers during normal operation. No telemetry. No usage tracking.

To export your community data, you can use standard WordPress database export tools, WP-CLI, or the Jetonomy REST API (which covers all content types). If you ever move to a different platform, your data is not locked in a proprietary format.

If you choose to delete the plugin, Jetonomy offers a complete data cleanup option — removing all tables, options, capabilities, and scheduled jobs. This is an explicit choice, not automatic, so you don't lose data by accident.

---

## Q8 — What kind of support is available?

**Support depends on your license.**

Free plugin users can reach out via Wbcom Designs support. The team monitors support requests and responds to issues.

Jetonomy Pro license holders get access to priority email support via the Wbcom Designs support portal. Response time targets and support scope details are on the Pro product page.

Documentation — including getting started guides, full settings reference, REST API reference, and developer guides — is published at wbcomdesigns.com/docs/jetonomy/.

---

## Q9 — How are updates delivered? What's the release policy?

**Updates are delivered through the standard WordPress plugin update system.**

For the free plugin: updates appear in your WordPress dashboard under Plugins > Updates. Install with one click.

For Jetonomy Pro: updates are delivered via the Wbcom Designs license system. Your Pro plugin will show available updates in the WordPress dashboard as long as your license is active.

Versioning follows semantic versioning: major releases (1.x) for significant new features or breaking changes, minor releases (1.x.x) for non-breaking features, and patch releases for bug fixes and security updates.

Database schema changes are handled automatically via the built-in migrator — you do not need to run manual SQL on updates.

---

## Q10 — Is Jetonomy GDPR compliant? What personal data does it store?

**Jetonomy stores only the data your members provide and that is necessary for the forum to function.**

Data stored per member:
- Display name, username, email (inherited from WordPress user account — Jetonomy does not duplicate these)
- Posts, replies, and votes authored
- Reputation score and trust level
- Notification preferences and subscriptions
- Activity log (posts created, replies, votes, trust level changes)
- If Pro is active: message threads, badge awards

Jetonomy does not collect payment information, does not integrate with advertising networks, and does not share data with third parties.

For GDPR compliance:

- The WordPress personal data export and erasure tools (added in WP 4.9.6) cover Jetonomy data. When a user requests data export or erasure via the WordPress privacy tools, Jetonomy includes its data in the response.
- Admins can delete any user's content from the moderation admin area.
- The clean uninstall option removes all plugin data from the database, including all user-generated content.

For specific GDPR obligations (privacy policy wording, data processing agreements, data retention schedules), consult a legal professional familiar with your jurisdiction.
