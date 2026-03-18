=== Jetonomy — Community Forums, Q&A & Discussions ===
Contributors: jetonomy
Tags: forum, community, discussion, Q&A, bbpress alternative
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Next-gen discussion platform for WordPress. Modern forums, Q&A, and community spaces with real-time features.

== Description ==

Jetonomy is a next-generation community platform for WordPress that replaces outdated forum plugins with a modern, fast, and engaging discussion experience.

**Why Jetonomy?**

* **Modern Architecture** — Custom database tables (not CPTs), REST API, cursor pagination
* **Multiple Community Types** — Forums, Q&A (like Stack Overflow), Ideas (like UserVoice), all in one plugin
* **Trust & Reputation** — Automatic trust levels that reward participation and reduce spam
* **Theme Adaptive** — Inherits your theme's fonts and colors automatically
* **Performance First** — Designed for 100K+ posts with denormalized counters and FULLTEXT search
* **Membership Ready** — Works with MemberPress, Paid Memberships Pro, and any membership plugin via adapter system

**Free Features:**

* 4 community types (Forum, Q&A, Ideas, Social Feed)
* Categories, Spaces, Sub-spaces
* Voting (upvote/downvote)
* Trust levels (0-5) with automatic progression
* Reputation system
* 3-layer permission engine
* Moderation tools (flags, queue, ban/silence)
* REST API (35+ endpoints)
* SEO (Schema.org, XML Sitemaps, Open Graph)
* MemberPress & PMPro integration
* bbPress & wpForo import
* Theme-adaptive CSS
* WP-CLI commands

**Pro Features:**

* Private messaging
* Emoji reactions
* Polls
* Custom fields
* Analytics dashboard
* Email digests
* Advanced auto-moderation
* WooCommerce, Restrict Content Pro, LearnDash adapters
* Meilisearch / Elasticsearch integration
* Real-time push (Mercure, Pusher)
* Slack & Discord bridge
* White-label branding
* Custom badge builder

== Installation ==

1. Upload `jetonomy` to `/wp-content/plugins/`
2. Activate the plugin
3. Follow the setup wizard to create your first community space
4. Visit yoursite.com/community/ to see your forum

== Frequently Asked Questions ==

= Does Jetonomy replace bbPress? =
Yes. Jetonomy is built from the ground up with modern architecture. It includes a bbPress import tool.

= Does it work with my theme? =
Jetonomy automatically inherits your theme's fonts, colors, and spacing via CSS custom properties and theme.json.

= Can I gate spaces behind memberships? =
Yes. Built-in adapters for MemberPress and Paid Memberships Pro. More adapters in Pro.

= Is it fast? =
Jetonomy uses custom database tables with denormalized counters and proper indexes. No EAV (wp_postmeta) bottleneck.

== Changelog ==

= 1.0.0 =
* Initial release
* Forum + Q&A community types
* Trust levels and reputation system
* REST API with 35+ endpoints
* WP Interactivity API frontend
* MemberPress and PMPro integration
* bbPress and wpForo import tools
* Admin dashboard, moderation, settings
