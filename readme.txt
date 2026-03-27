=== Jetonomy — Community Forums, Q&A & Discussions ===
Contributors: wbcomdesigns, vapvarun
Tags: forum, community, discussion, Q&A, bbpress alternative
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The community platform WordPress deserves. Forums, Q&A, Ideas, and social discussions — all in one fast, beautiful plugin.

== Description ==

**Jetonomy turns your WordPress site into a thriving community.** Whether you want a support forum, a Stack Overflow-style Q&A, an ideas board, or just a place for members to chat — Jetonomy handles it all without duct-taping six different plugins together.

It's built from scratch with modern WordPress tech: custom database tables (not slow post types), the WordPress Interactivity API (no jQuery, no React bundle), and a permission system that actually makes sense. The result is a community platform that feels snappy and looks great on every theme you throw at it.

If you're still running bbPress, wpForo, or Asgaros, Jetonomy ships with one-click importers for all three. Your community, your posts, your members — all move over cleanly.

---

### Four Community Types, One Plugin

**Forum** — Classic threaded discussion. Great for support, general chat, announcements.

**Q&A** — Questions get answers. Answers get voted on. The best answer floats to the top. Perfect for knowledge bases and support communities.

**Ideas** — Members submit ideas, vote, and see a roadmap. Works like UserVoice but lives inside WordPress. Ship the features your community actually wants.

**Social Feed** — Lightweight, scrollable discussion. Great for news communities or team spaces that don't need the full forum structure.

---

### Built to Be Fast at Scale

Most forum plugins store content in `wp_posts` and `wp_postmeta`. That works for 500 posts. It gets painful at 50,000. Jetonomy uses 21 purpose-built MySQL tables with proper indexes, denormalized counters, and FULLTEXT search. Your community can grow to 100,000+ posts without a performance crisis.

Every list view uses cursor-based pagination (no expensive `COUNT(*)` queries). Frequently accessed data is automatically cached with Redis or Memcached if you have them. Batch queries everywhere — no N+1 problems.

---

### A Permission System That Actually Works

Jetonomy has three layers of permissions that stack together cleanly:

1. **WordPress Capabilities** — Admins and editors get full access. Subscribers get participant access. You control the defaults.
2. **Space Roles** — Every space has its own owner, moderators, and members. A space owner can moderate their own space without being a site admin.
3. **Trust Levels (0–5)** — New users start at Level 0 (limited posting). As they participate, Jetonomy automatically promotes them to Level 1, 2, and 3 based on thresholds you configure. Levels 4 and 5 are granted manually.

The trust level system is your best spam defense. New accounts can post, but they can't spam the whole site with impunity. Regular contributors earn more abilities over time.

---

### Free Features

**Community Structure**
- Forum, Q&A, Ideas, and Social discussion types
- Categories and Spaces (sub-communities) with drag-drop ordering
- Sub-spaces for nesting communities within communities
- Join policies per space: open, request-to-join, or invite-only
- Invite links with configurable expiry dates

**Content & Editor**
- Rich text editor with bold, italic, lists, code blocks, and headings
- Drag-and-drop image upload directly into the editor
- Paste images from clipboard — they upload automatically
- @mention users with autocomplete and instant notifications
- Auto-embed YouTube, Twitter/X, Vimeo, and other oEmbed URLs
- Emoji picker for reactions in replies
- Code syntax highlighting via Prism.js (50+ languages)
- Quote-to-reply: select any text and click Reply to quote it
- Threaded replies up to 3 levels deep with collapsible threads

**Navigation & Search**
- Full-text search with instant search-as-you-type results
- Tag system with tag pages and space-level tag filters
- Keyboard shortcuts: `j`/`k` to navigate, `l` to upvote, `r` to reply, `/` to search
- Clean permalink structure: `/community/s/slug/t/post-slug/`

**Community & Reputation**
- User profiles with bio, website, location, and activity history
- User hover cards when you hover over any avatar or name
- Reputation system with points for posts, replies, votes, and accepted answers
- Trust Levels 0–5 with admin-configurable thresholds
- Leaderboard ranking community members by reputation
- Badge system (trust level badges automatic; custom badges in Pro)

**Voting**
- Upvote and downvote on posts and replies
- Accepted answers on Q&A spaces
- Idea status board (planned, in progress, complete) for Ideas spaces

**Notifications**
- In-app notification bell with unread count
- Email notifications (immediate) for replies, votes, mentions, and moderation actions
- Notification preferences per user

**Moderation**
- Flag system — members flag content, moderators review a queue
- Akismet spam detection on every post and reply
- IP address tracking for ban enforcement
- Ban and silence system: ban prevents login, silence prevents posting
- Moderator queue in wp-admin and at `/community/mod/`

**Memberships & Access Control**
- Gate spaces behind membership levels (MemberPress, Paid Memberships Pro)
- Space access rules tied to membership plans
- Works with any membership plugin via the adapter system

**Admin Tools**
- Setup wizard with two paths: start fresh or load realistic demo data
- One-click demo data cleanup
- Full content management from wp-admin (edit/delete any post or reply)
- Drag-drop category and space ordering
- Import from bbPress, wpForo, and Asgaros (batched with progress bar and resume)
- Trust level threshold configuration
- Email notification settings

**Performance**
- Object caching (auto-detects Redis/Memcached)
- Eager loading with batch queries — no N+1 database calls
- Cursor-based pagination on all REST API endpoints
- Denormalized counters (reply_count, post_count, vote_score updated on write)
- FULLTEXT indexes for instant search

**Developer Tools**
- 48+ REST API endpoints at `/wp-json/jetonomy/v1/`
- 18 abilities registered with the WordPress Abilities API (WP 6.9+)
- 20+ action hooks and filters for customization
- WP-CLI commands for trust level management and imports
- Template overrides: drop files in `your-theme/jetonomy/` to override any view
- RTL stylesheet included
- Translation-ready with `.pot` file

**SEO**
- Canonical URLs on every community page
- Open Graph tags (title, description, image for spaces)
- JSON-LD schema markup via Schema.org
- XML sitemap providers for spaces and posts
- Clean, SEO-friendly permalink structure

**Accessibility**
- Full WCAG accessibility audit on all templates
- Semantic HTML throughout
- Keyboard navigation support

---

### Pro Features

Jetonomy Pro extends the free plugin with power-user and enterprise features:

* **Private Messaging** — Direct messages between members
* **Emoji Reactions** — React to posts and replies with custom emoji sets
* **Polls** — Run polls inside posts and spaces
* **Custom Fields** — Add custom fields to user profiles and posts
* **Analytics Dashboard** — See what your community talks about most, top contributors, growth trends
* **Email Digests** — Weekly/daily community digest emails
* **Advanced Auto-Moderation** — Rule-based moderation (keyword filters, rate limits, user score gates)
* **WooCommerce, Restrict Content Pro, LearnDash adapters** — Gate spaces behind courses or purchases
* **Meilisearch / Elasticsearch integration** — Lightning-fast search for large communities
* **Real-time push** — Live reply updates via Mercure or Pusher (no page refresh needed)
* **Slack & Discord bridge** — Mirror community activity into your Slack/Discord server
* **White-label branding** — Remove Jetonomy branding, use your own logo
* **Custom badge builder** — Design badges and award them manually or automatically

[Learn more about Jetonomy Pro →](https://store.wbcomdesigns.com/jetonomy-pro/)

---

### Works With Your Theme

Jetonomy reads your active theme's `theme.json` and automatically inherits fonts, colors, and spacing. No fighting with CSS specificity. No ugly white box inside your beautiful theme. The community looks like it belongs there.

Templates are fully overridable: create a `jetonomy/` folder in your theme directory and drop in any view or partial file to customize the markup.

---

== Installation ==

1. Upload the `jetonomy` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Click **Set Up Community** in the admin notice, or go to **Jetonomy → Dashboard** to launch the setup wizard

That's it. Your community lives at `/community/` (you can change the base slug in Settings).

== Screenshots ==

1. Community homepage with categories and spaces
2. Space view with topic listing and voting
3. Single post view with threaded replies
4. Q&A mode with accepted answer highlighted
5. Ideas board with roadmap view
6. Admin dashboard with statistics
7. Setup wizard — choose custom setup or demo data
8. Moderation queue with pending posts and flags

== Frequently Asked Questions ==

= Does Jetonomy replace bbPress? =

Yes. Jetonomy is a complete replacement for bbPress, built with modern WordPress architecture. It includes a one-click bbPress importer that migrates your forums, topics, replies, and user data. Your SEO is protected with 301 redirects from old bbPress URLs.

= Does it work with my theme? =

Jetonomy inherits your theme's fonts, colors, and spacing automatically using CSS custom properties and `theme.json`. The community looks native to your site, not bolted on. You can also override any template file by placing it in `your-theme/jetonomy/`.

= Will it handle my large community? =

Jetonomy was designed with scale in mind. It uses custom MySQL tables (not `wp_posts`), proper indexes, denormalized counters, and FULLTEXT search indexes. Redis and Memcached are auto-detected and used when available. Cursor-based pagination means no slow `OFFSET` queries on large datasets.

= Can I gate spaces behind paid memberships? =

Yes. Jetonomy has built-in adapters for MemberPress and Paid Memberships Pro. You can restrict any space to specific membership plans. Additional adapters for WooCommerce, Restrict Content Pro, and LearnDash are available in Pro.

= What are Trust Levels and why do they matter? =

Trust Levels are Jetonomy's built-in spam defense and community health system. New users start at Level 0 with basic posting access. As they participate (create posts, receive replies, earn reputation), Jetonomy automatically promotes them. Levels 0–3 are earned automatically based on thresholds you configure. Levels 4 and 5 (Leader and Moderator) are granted manually by admins.

The result: new spammers can't immediately flood your community, and your most trusted members earn expanded abilities over time.

= Does it have full-text search? =

Yes. Jetonomy uses MySQL's native FULLTEXT indexes for fast, relevant search results. Typing in the search bar shows instant results as you type. For communities with 100,000+ posts, Jetonomy Pro supports Meilisearch and Elasticsearch integration.

= Can my community members moderate their own spaces? =

Yes. Every space has its own owner and can have multiple moderators. Space moderators can manage content in their space without any wp-admin access. Site admins see a global moderation queue at `/community/mod/`.

= How do I import from bbPress, wpForo, or Asgaros? =

Go to **Jetonomy → Import** in your WordPress admin. Jetonomy detects which forum plugins are installed and shows the available importers. Imports run in batches with a progress bar — you can close the browser and the import continues. If the import stops, you can resume it exactly where it left off.

= Can I create a Q&A community like Stack Overflow? =

Yes. Create a space and set its type to Q&A. Questions get posted as normal topics. Any logged-in member can post answers (replies). The original poster can mark one answer as accepted, which pins it to the top. All answers are voteable, so the community surfaces the best ones.

= Does it support right-to-left languages? =

Yes. Jetonomy includes a dedicated RTL stylesheet that loads automatically when WordPress is using a right-to-left locale. The layout, text alignment, and interactive elements all adjust correctly.

= What keyboard shortcuts does it support? =

In any post listing: `j` moves focus down, `k` moves up, `l` upvotes the focused post, `r` opens a reply, and `/` focuses the search bar. These shortcuts work exactly like you'd expect from a modern web app.

= How do invite links work? =

Space owners and moderators can generate invite links from the space's members page. Each link has a configurable expiry date. When someone visits the link, they're immediately added to the space as a member. Great for onboarding new team members or running private communities.

= Can I customize the email notifications? =

Jetonomy sends email using WordPress's built-in `wp_mail()` function, so any SMTP plugin you're using will handle delivery. Users can configure their notification preferences (which events send emails) from their profile page.

= Can developers extend Jetonomy? =

Absolutely. Jetonomy has 48+ REST API endpoints, 18 WordPress Abilities (WP 6.9+), 20+ action hooks and filters, WP-CLI commands, and full template override support. The adapter pattern makes it straightforward to integrate external services. See the [Hooks Reference](https://store.wbcomdesigns.com/jetonomy/docs/) for the full list.

= Does it support WordPress Multisite? =

Each site in a Multisite network gets its own independent community. Network activation works. Tables are created per-site with the standard table prefix. There is no cross-site feed functionality in the free version.

== Changelog ==

= 1.0.0 — March 2026 =

### Added
- Forum, Q&A, Ideas, and Social discussion types
- Custom MySQL tables (21 tables) — no `wp_posts` bottleneck
- 3-layer permission engine: WP Capabilities + Space Roles + Trust Levels 0–5
- WordPress Interactivity API frontend — no jQuery, no React bundle
- 18 abilities registered with the WordPress Abilities API (WP 6.9+)
- Rich text editor with drag-drop image upload and paste-to-upload
- @mention notifications with autocomplete
- Auto-embed for YouTube, Twitter/X, Vimeo, and other oEmbed providers
- Emoji picker in reply composer
- Code syntax highlighting via Prism.js
- Quote-to-reply: select text and reply to quote it
- Keyboard shortcuts: j/k navigate, l upvote, r reply, / search
- Threaded replies up to 3 levels deep with collapsible threads
- Full-text search with instant search-as-you-type
- User hover cards on avatar/name hover
- Invite links with configurable expiry
- Leaderboard with reputation rankings
- Space roadmap view for Ideas spaces
- Flag system with moderator queue
- IP tracking and Akismet spam integration
- Ban and silence system
- Setup wizard with realistic demo data and one-click cleanup
- Full content management from wp-admin
- Drag-drop category and space ordering
- Import from bbPress, wpForo, and Asgaros (batched with progress bar and resume)
- Trust level threshold configuration
- Object caching (Redis/Memcached auto-detection)
- Eager loading with batch queries — no N+1 problems
- Cursor-based pagination on all REST API endpoints
- MemberPress and Paid Memberships Pro integration
- Canonical URLs, Open Graph tags, JSON-LD schema markup
- XML sitemap providers for spaces and posts
- RTL stylesheet
- Translation-ready with `.pot` file
- WP-CLI commands
- 48+ REST API endpoints at `/wp-json/jetonomy/v1/`
