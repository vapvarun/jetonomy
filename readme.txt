=== Jetonomy — Community Forums, Q&A & Discussions ===
Contributors: wbcomdesigns, vapvarun
Tags: forum, community, discussion, Q&A, bbpress alternative
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.4.0
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

Most forum plugins store content in `wp_posts` and `wp_postmeta`. That works for 500 posts. It gets painful at 50,000. Jetonomy uses 24 purpose-built MySQL tables with proper indexes, denormalized counters, and FULLTEXT search. Your community can grow to 100,000+ posts without a performance crisis.

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
- 19 abilities registered with the WordPress Abilities API (WP 6.9+)
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

* **AI Integration** — Language-model-powered spam detection, content moderation, reply suggestions, and thread summaries. Pluggable providers including OpenAI, Anthropic, custom endpoints, and self-hosted Ollama (privacy-first).
* **Private Messaging** — Direct messages between members
* **Emoji Reactions** — React to posts and replies with custom emoji sets
* **Polls** — Run polls inside posts and spaces
* **Custom Fields** — Add custom fields to user profiles and posts
* **Analytics Dashboard** — See what your community talks about most, top contributors, growth trends
* **Email Digests** — Weekly/daily community digest emails
* **Advanced Auto-Moderation** — Rule-based moderation (keyword filters, rate limits, user score gates)
* **WooCommerce, Restrict Content Pro, LearnDash, Tutor LMS adapters** — Gate spaces behind courses or purchases
* **SEO Pro** — Per-space meta titles, Open Graph images, schema controls, and sitemap rules
* **Reply by Email** — Members reply to notification emails and the reply posts automatically
* **Web Push Notifications** — Browser push for replies, mentions, and moderation events
* **Webhooks** — Send HTTP POSTs to external services on community events
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

Yes. Jetonomy uses MySQL's native FULLTEXT indexes for fast, relevant search results. Typing in the search bar shows instant results as you type. The search system is built on a swappable adapter pattern — developers can write custom adapters for services like Meilisearch, Elasticsearch, or Algolia without touching the plugin core.

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

Absolutely. Jetonomy has 48+ REST API endpoints (90+ with Pro), 19 WordPress Abilities (WP 6.9+), 20+ action hooks and filters, WP-CLI commands, and full template override support. The adapter pattern makes it straightforward to integrate external services. See the [Hooks Reference](https://store.wbcomdesigns.com/jetonomy/docs/) for the full list.

= Does it support WordPress Multisite? =

Each site in a Multisite network gets its own independent community. Network activation works. Tables are created per-site with the standard table prefix. There is no cross-site feed functionality in the free version.

== Changelog ==

= 1.4.1 - Unreleased =

Run public or private communities. Browse drafts and bookmarks. Audit who did what. See every edit. Plus tighter sign-up follow-through and a friendlier email templates editor.

**Public or private community**

* New Access Control mode in Settings — choose **Public** (anyone can read) or **Private** (every page requires sign-in). The mode applies to the whole front-end and to the REST API, so private really means private.
* The sign-in page itself stays reachable in private mode so guests can register or recover their account.
* Public mode is the default and is unchanged from 1.4.0 — existing communities keep working without any setting changes.

**For people who run a space**

* New "Activity Log" admin page browses every audit event (post created, reply approved, member banned, role changed, …) with filters by user, type, and date range. Read-only — no edits.
* New "Revisions" admin page browses every saved post / reply revision with a side-by-side diff between any two revisions. Read-only.
* Two new REST endpoints for moderation tooling: `POST /jetonomy/v1/moderation/bulk` (approve / spam / trash many posts at once) and `GET /jetonomy/v1/posts/{id}/flags` (the flags raised against a post).

**Members who haven't confirmed their email**

* New hourly nudge: members who registered but haven't clicked the verification link receive a single follow-up email after 24 hours (configurable in Settings). One reminder per member, never duplicates.

**Email templates editor**

* New "Reset to default" button on every notification template — one click restores the shipped subject and body without retyping.
* The "Verification reminder" template is now editable from the same screen.
* Defaults now have a single source of truth, so reset always restores the exact copy the plugin ships with.

**For members**

* New "Drafts" tab at `/community/drafts/` lists every post you saved as a draft.
* New "Bookmarks" tab at `/community/bookmarks/` lists every post you bookmarked.
* Both tabs are personal pages — they require sign-in and are excluded from search engines.

**Under the hood**

* Per-role REST access matrix is now a verifiable contract — `bin/access-matrix-check.sh` runs 78 checks across 6 roles in either public or private mode, gates the build.
* Manifest schema bumped to v2: every REST endpoint declares `auth`, `capability`, and `ownership_check` in `audit/manifest.json`.

**For developers**

* New helper `Jetonomy\Visibility` centralizes the public-or-private check (`can_view_community()`, `get_mode()`, `rest_check()`).
* New filters / hooks reused — no public hook removals.
* New schema migration: `jt_user_profiles` gains a `verification_reminder_sent_at` column. Runs automatically; rolls back cleanly.

= 1.4.0 - April 2026 =

Run a community without leaving the front end. Show up in search. Cleaner, accessible interface throughout.

**For people who run a space**

* Edit a space from the front end — title, description, cover, icon, type, visibility, join policy, category, posts-per-page, prefixes.
* Create a space from the front end. Pick which roles can use the form in Settings → Front-end space creation.
* "My Spaces" page lists every space you run + every space you're in.
* Visual icon picker — 16 icons with search and "Show more" for 8 extras.
* Cover image uploader works without the WordPress upload-files permission.
* Role dropdown can't accidentally orphan a space — no self-demote, no last-admin-out.

**For members**

* @mention autocomplete in the composer.
* "New" pill on threads with replies you haven't read.
* "Managed by" sidebar card on every space.
* Admin / Mod pills next to staff names on posts and replies.
* Layout panel fits Jetonomy to your theme — Container Width, Sidebar, Padding.

**Search and sharing**

* Every public page now has full search and social cards.
* Smart fallback share image when a page has no image of its own.
* Pages that shouldn't show in search (moderation, search, composer, notifications) are excluded automatically.
* Richer structured data — Sitelinks Searchbox on home, Person cards on profiles, Collection indexes on spaces and tags, breadcrumbs everywhere.
* Settings → SEO grew Twitter / X handle, default share image, sitemap link.
* Image alt text fills in automatically on upload.

**Sign-in**

* Login, register, forgot-password — all faster, all in-page (no wp-login.php bounce).
* Captcha now actually fires on signup when configured.

**Polish**

* In-product confirms and prompts replace browser pop-ups. Accessible (WCAG 2.1 AA).
* All 8 Jetonomy blocks now visible in the block inserter.
* Shortcodes render styled on any page or page-builder canvas.

**Bug fixes**

* Category dropdown on Edit Space is no longer empty; "No category" saves correctly.
* Space moderators without a WordPress editor role can again use the inline mod tools.
* Join-request notifications link to the right place per recipient.
* Notifications page no longer auto-marks everything read on render.
* GDPR export contains the user's display name.
* Tags on post cards link to the tag page; tag page paginates instead of capping at 30.
* Share dropdown closes when you scroll.
* Auth rate-limit window doesn't reset on retry.
* Banned users can no longer log in (security).
* Private-post structured data no longer leaks to anonymous visitors (security).

**For developers**

* `[jetonomy_widget id="..."]` shortcode embeds any widget on any page.
* `jetonomy_seo_meta` filter to tweak the entire SEO payload.
* `jetonomy_use_frontend_space_edit` filter to swap Edit Space target.
* New REST endpoints: `POST /media`, `POST /auth/{login,register,lost-password}`, `GET /spaces/{id}/privileged-members`, `GET /users/suggest`.
* Browser QA checklist + Phase D SEO plan ship with the plugin.

= 1.3.8 - April 2026 =

Space moderation, front-end member management, BuddyPress and FluentCommunity integrations, dark-mode polish, and a long tail of bug fixes.

**Moderation**

* New: Space admins and space moderators now have their own moderation queue at /community/s/:slug/mod/, showing only the flagged posts and replies inside that space. Before 1.3.8 the queue was restricted to site editors, so a community owner had no way to police their own space without handing out WP-editor roles.
* New: Site admins land on a cross-space moderation dashboard at /community/mod/ that summarises pending flags per space and links straight into each space's queue. If you admin multiple communities, you see them all at once; if you moderate a single space, the header link takes you straight to that space's queue.
* New: Space admins can promote members to moderator or admin from the front-end members page (/community/s/:slug/members/). A role dropdown on each member row PATCHes the change live with inline success and error feedback. No more wp-admin round-trip to add a moderator.

**Integrations**

* New: FluentCommunity integration. If you run both Jetonomy and FluentCommunity on the same site, they now feel like one product instead of two. Auto-enables when FluentCommunity is detected, no toggle needed.
* New: Pair FluentCommunity spaces with Jetonomy spaces from a new Settings > FluentCommunity admin page. Each pair renders a "Discussions" tab on the FC space header linking to the Jetonomy forum, plus a sidebar card on the Jetonomy space linking back to the FC feed.
* New: Configurable tab label on the integration settings page (default "Discussions"). Rename it to "Forum", "Q and A", or whatever fits your community's language. It updates everywhere the tab appears.
* New: FluentCommunity profile pages now show a Discussions block listing the member's five most recent topics started and the five topics they follow on the Jetonomy side, with a "View all on forum" link to their Jetonomy profile.
* New: Jetonomy profile pages show a cross-link to the member's FluentCommunity profile. The button picks up the name you set in FluentCommunity's Site Title, so it reads "View on Acme Community" instead of something generic. Paired with the existing "View all on forum" link on FC profiles, navigation between the two profile pages now works both ways.
* New: Member avatars are unified. When a FluentCommunity user has a custom avatar on their FC profile, it is used everywhere on the site including Jetonomy pages. One identity, one avatar, one bio, no "two versions of me" effect.
* New: Member sync across paired spaces. When someone joins a paired FluentCommunity space they are automatically added to the paired Jetonomy space, and vice versa. Sync is add-only on purpose: leaves do not propagate so nobody is accidentally removed from both sides at once. Toggle on the settings page; defaults to on.
* New: Activity broadcast. When a new topic is created in a paired Jetonomy space, an announcement feed post appears in the paired FluentCommunity space with the topic title, excerpt, and a discreet "Shared from the forum" attribution link. No duplicated titles, no shouty CTA, it reads like a normal feed post. Toggle on the settings page; defaults to on. Broadcast is one-way (Jetonomy to FluentCommunity only) so FC posts never silently create forum topics.
* Privacy: topics marked as private in Jetonomy are never broadcast. The FC feed audience can be broader than the private-topic scope, so private content stays on the forum side.
* New: Comment-to-reply bridge. When a member comments on one of the broadcast feed posts on FluentCommunity, the comment is automatically mirrored back as a reply on the original Jetonomy topic, preserving author attribution. Only comments on broadcast feeds round-trip; native FC feed posts are untouched. Add-only like member sync: edits and deletes on FC do not propagate, so your forum thread is always the durable record.
* New: "Sync existing members now" button on the settings page. One-click backfill that enrols each side's existing members into the paired side. Safe to re-run, capped at 5,000 members per space per run, reports pairs processed and members added in both directions.
* Note: Writes to FluentCommunity happen only through FluentCommunity's own public helpers (addToSpace) and Feed model, never by direct SQL. Deactivating FluentCommunity leaves both plugins working independently.
* New: BuddyPress integration broadcasts new Jetonomy topics into the paired BuddyPress group's activity stream, and comments on that activity round-trip back as replies on the forum topic. Uses real HTML paragraphs and a discreet attribution footer so the activity feed reads naturally.

**Dark mode and theme polish**

* Fixed: Plugin headings stay readable when the Reign theme (or any theme that renders the forum inside a dark panel) is in dark mode. The contrast bug only surfaced on dark-panel themes and had nothing to do with the OS-level dark mode setting.
* Fixed: Accent tints (--jt-accent-light and --jt-accent-muted) are now re-derived against the panel background in dark mode, so hover states and muted backgrounds look right on dark themes instead of washing out to near-invisible.
* Fixed: Locked-space banner and warning notices are legible against dark panels instead of disappearing.
* Fixed: Jetonomy no longer auto-applies dark mode based on the visitor's OS preference. Dark mode now follows the theme only, which is less surprising when your theme is in light mode but the visitor's system is set to dark.

**Bug fixes**

* Fixed: Sort modes (oldest, newest, unanswered) now return the right set of topics. The earlier query merged them into latest.
* Fixed: Space settings are merged on save instead of overwritten, so editing one field no longer clears the others.
* Fixed: Similar-topics widget no longer leaks HTML entities into the titles, and sitewide search now ranks results by relevance instead of flat creation order.
* Fixed: Time picker on scheduled posts now uses cross-browser hour and minute selects when the browser does not provide a native picker, and the native date/time picker is restored on browsers that do.
* Fixed: Online indicator sits on the avatar's top-right corner, including on the 64px profile-header avatar where it used to drift.
* Fixed: Space listing Load More no longer auto-preloads the next page on first render; it waits for a real scroll.
* Fixed: Share dropdown now closes when the page scrolls instead of floating free over the content.
* Fixed: Profile tabs no longer clip the Drafts tab on mobile.
* Fixed: Rewrite rules are flushed during activation so community URLs resolve immediately after activating the plugin, instead of 404ing until the next page load.
* Fixed: Profile Drafts rows now navigate to the draft itself so you can edit or publish from one click.
* Fixed: Long words in user content wrap on mobile instead of pushing the app wider than the viewport. The Jetonomy app width is also clamped to prevent overflow.
* Fixed: TikTok videos render as proper iframes instead of falling back to a caption-only text card. Copy-link now shows visible feedback when the browser blocks clipboard writes.
* Fixed: Forum members can attach images when creating posts. Upload permissions used to require a higher role than member.
* Fixed: Voting optimistically shows the correct score when flipping a prior vote (for example up to down), instead of showing a stale delta until the server confirmed.
* Fixed: FluentCommunity broadcast now preserves paragraph breaks in the excerpt so long topics read the way they were written.
* Fixed: FluentCommunity cross-link buttons use the FC site title in their labels for a natural "View on <your community>" reading.

**Polish**

* Improved: Fourteen translation-ready strings were rewritten to drop em-dashes and other typography that made translations awkward. No string keys changed.
* Improved: The Interactivity API now exposes isLoggedIn and loginUrl so blocks and embeds can render the right CTA for anonymous viewers without extra REST calls.
* Improved: Integration settings pages got section banners and clearer loader-gate documentation.

Upgrading from 1.3.7 does not require any migration; nothing in your database changes.

= 1.3.7 - April 2026 =

* New: Members can now start a new topic from any page on your site. Drop the new "Jetonomy Compose Topic" block onto a landing page, sidebar, or footer (or use the [jetonomy_compose_topic] shortcode if you prefer) and signed-in members get a clean composer right where they are. Works in the Site Editor, Elementor, Divi, Bricks, WPBakery, and any other page builder. Choose between a single fixed space or a picker that shows only the spaces the member can actually post in. Visitors who aren't signed in see a friendly "Join the conversation" prompt with sign-in and register links instead of a broken form.
* New: Sign-in prompt on the compose-topic block welcomes visitors with a Lucide chat icon, a one-line invitation that mentions the target space by name, and side-by-side "Sign in to post" and "Create an account" buttons. The register link only appears when registration is open in your WP settings.
* Fixed: Share button on a single topic now opens a share menu in the right place. Previously it was rendering off-screen on most themes — clicking it appeared to do nothing. The dropdown also picks up crisp icons for Copy link, Twitter/X, Facebook, and LinkedIn, and auto-flips above the button when there's no room below.
* Fixed: Posting a topic without filling in the body used to silently reset the form with no feedback. You now see a clear inline message asking for the missing field, and Pro Polls topics get the same protection.
* Fixed: Editing a topic that contained an uploaded image no longer wipes the image when you save. Same fix lands for replies, so embeds, images, and formatting all survive an edit cleanly.
* Fixed: TikTok video previews and embedded players now work everywhere. Pasting a TikTok URL from the mobile Share button (the short tiktok.com/t/... links) used to leave you with just a hyperlink and a generic "TikTok – Make Your Day" card instead of the actual video. The same fix covers Twitter (t.co), Reddit (redd.it and the new mobile share links), Facebook (fb.watch), and Spotify (spoti.fi), so paste-from-app flows across all major platforms now produce the proper rich preview or embedded player. Also fixes x.com tweets that previously fell back to a plain link.
* Fixed: Embedded TikTok, Instagram, and Twitter posts no longer pick up your theme's blockquote styling — no more out-of-place blue or grey side borders, italic text, or tinted backgrounds on social embeds. Tested across BuddyX, Reign, and Twenty Twenty-* themes.
* Improved: Reaction picker no longer disappears on sites with emoji rendering disabled. Reactions are now drawn as crisp colour SVG icons that look identical on every browser, operating system, and host configuration.
* Improved: Block inserter now reliably finds every Jetonomy block. Some users reported having to refresh repeatedly to make blocks appear in the inserter — that's fixed for all blocks with an editor script.
* Improved: The compose-topic block's CSS and JavaScript only load on pages that actually use the block, so pages without it pay no overhead.

Upgrading from 1.3.6 does not require any migration; nothing in your database changes.

= 1.3.6 - April 2026 =

* New: Trending Topics block — a Gutenberg block that ranks community posts by recent engagement (votes + replies over a trailing window, with time decay) so the list surfaces what's hot right now rather than what's popular all-time. Drop it on the homepage, a landing page, or any WordPress page outside the community routes.
* New: Forum Feed block can now be scoped to a single space with a styled header and "View all" link — a drop-in "Topics from this space" widget for marketing pages, sidebars, and FSE templates.
* New: Rich link previews — topics and replies that include a URL now render a preview card with title, description, and favicon, using a local unfurling service so sites stay fast and privacy-preserving.
* New: Per-type email templates — welcome, mention, reply, digest, moderation, and system notifications each have their own template file, and themes can override any of them from a `jetonomy/emails/` directory. The context passed to each template is now richer (actor name, space, post excerpt, action URL).
* New: External plugins and extensions can now open the community message composer programmatically via the shared `msgComposeOpen` state — used by Pro Messages to wire in the "Message" action from Top Members and elsewhere.
* Fixed: The three-dot dropdown on topic and reply cards no longer gets trapped or clipped inside the card. Opening, closing, and clicking menu items now behaves consistently on every theme.
* Fixed: Users can no longer downvote their own posts or replies — the REST vote endpoint now rejects self-downvotes and the vote button on the author's own content reflects that.
* Fixed: Private (is_private) topics are now truly private across every read surface — archives, search, tag pages, and REST listings now honour the private flag so draft or sensitive content can't be enumerated.
* Fixed: TikTok, Instagram, and Twitter/X links now embed as real video/post players instead of falling back to oEmbed blockquotes.
* Fixed: Invite-only space journey — visiting `/new/` without an invite now returns users to the space with a clear inline error, REST errors surface inline on the invite form, and stale "Join" CTAs are hidden when the viewer has already joined or has a pending request.
* Fixed: Spacing between the Post Topic button and the publish-options dropdown on the composer — no more visual collision.
* Fixed: Tags admin page now writes nonce-protected requests; invalid nonces are rejected cleanly instead of silently failing.
* Improvement: `wp jetonomy config get` dotted-path lookups (e.g. `trust_thresholds.1.posts`) now return the effective runtime value when the admin has not explicitly saved that block — defaults fill the gap so automation scripts don't trip on "Key not found".

= 1.3.5 - April 2026 =

* Fixed: Editing a topic or reply no longer collapses paragraphs into a single run-on line. The inline editor now preserves blank lines between paragraphs all the way through open, save, and display. Historically broken posts also render with their paragraphs restored on the next page load.
* New: Jetonomy Navigation block — a drop-in Gutenberg block that renders the Category → Space tree as sidebar navigation. Permission-aware (private spaces stay hidden from anonymous viewers), highlights the current space, and scales to sites with thousands of spaces.
* New: Jetonomy Login block — a quick login and register panel built for the community sidebar. Logged-out viewers see inline Login and Register tabs without leaving the page; logged-in viewers see nothing, so there is no layout shift. Rate-limited and nonce-protected.

= 1.3.4 - April 2026 =

* Fixed: Akismet no longer flags replies written by site admins or space admins/moderators as spam. Staff responses were getting quarantined on sites with Akismet active, hiding legitimate support answers from members.
* New: Admins can approve a spam-flagged topic or reply in one click. The Replies and Posts admin lists now show an "Approve" / "Not Spam" action next to Trash on any row currently held for moderation.
* Fixed: Approving, marking-as-spam, or trashing a topic or reply from the admin list now correctly updates the topic count, reply count, and member contribution totals. Previously, moving content between publish and other statuses left the denormalized totals stuck at their old values.
* New: Admins can refresh community member counts alongside topic and reply counters — the one-click counter rebuild added in 1.3.3 now repairs member drift too.
* New: Spaces now track their real membership. Posting a topic or replying in an open community automatically joins the author as a member, so spaces correctly show the number of people actually contributing instead of just the space creator.
* New: A one-time upgrade routine adds every historical author to the spaces they've contributed to, so your existing communities start showing accurate member counts immediately after the update.
* New: Admins managing moderation by API can now see spam-flagged items alongside pending ones — the moderation queue endpoint accepts `status=pending|spam|all`.
* New: Site admins can promote many members to a trust level in one call via the admin API — useful after migrations, onboarding batches, or granting long-standing members a higher tier.

= 1.3.3 - April 2026 =

* New: Preserve original dates when seeding or migrating discussions. Topics and replies added by admins now keep the date they were originally written, so imported content doesn't all show up stamped with today's date.
* New: Admins can rebuild community counters when they look off. If topic totals, reply totals, member stats, or vote scores ever drift after a bulk import or manual database change, admins can refresh them all in one go without needing command-line access.
* Improved: Access Control settings are now a single, clearer choice — "Public community" (anyone can read, login required to post) or "Private community" (login required to view anything). Matches how most communities actually work and removes a setting whose label didn't match its behaviour.
* Fixed: The "Default Space Type" setting now really applies to new spaces — both in the admin panel and when creating spaces through the API. Previously the choice was saved but nothing read it.
* Upgrade: Existing installs are migrated automatically — a private-community install (everyone required to log in) keeps that behaviour, and any default space type set during setup is carried over to the new unified setting.

= 1.3.2 - April 2026 =

* Fix: Setup wizard no longer triggers PHP deprecation warnings (strip_tags null, print_emoji_styles, wp_admin_bar_header) on WP 6.4+ with PHP 8.1+.
* Enhancement: New-post form submit action is now filterable via `jetonomy_new_post_submit_action` for Pro extensions.

= 1.3.1 - April 2026 =

* Fix: Theme button hover styles no longer override Jetonomy button states — scoped CSS reset for BuddyX/Reign compatibility.

= 1.3.0 - April 2026 =

* New: Share forum threads anywhere — paste any topic URL into Slack, Twitter/X, Discord, Facebook, or another WordPress site and you'll see a rich preview card with the title, author, excerpt, space, and thumbnail. No extra setup needed.
* New: Embed videos and music in posts — just paste a YouTube, Vimeo, SoundCloud, Spotify, TED Talks, or other supported link into a post or reply and it plays inline instead of showing as a plain URL.
* New: Instagram and Facebook embed support — optional free Meta Developer App integration under Settings → SEO → Social Embeds lets pasted Instagram and Facebook URLs unfurl as rich previews. Includes a 5-minute step-by-step setup guide right in the settings screen. Leave the fields blank to skip — URLs fall back to plain clickable links.
* New: BuddyX, BuddyX Pro, and Reign theme compatibility — your forum automatically picks up the active theme's accent color and dark mode, so your community looks at home with zero custom CSS.
* New: AI spam detection — optional AI-powered spam filter for new posts and replies. Free, self-hosted via Ollama (no API costs, no data leaves your server).
* New: Ad and content injection slots — new hooks let you (or your custom code) drop banners, promotions, or extra content into the sidebar, reply flow, and below the space intro.
* New: Change your community URL safely — if you rename your community base (e.g. /forum/ → /community/), Jetonomy now automatically redirects visitors from the old URLs so you don't lose search traffic or bookmarks.
* New: WordPress Abilities API integration — 18 abilities across 5 categories let AI assistants and automation tools drive Jetonomy from the terminal.
* New: Demo data seeder — one-click demo content for previewing how your community will look, with a cleanup button when you're done.
* New: Richer search preview snippets — search engines see thread titles, author names, publish dates, and space categories for better listings.
* Improvement: Theme compatibility across 12+ popular themes — page titles, container widths, and responsive layouts tested and tuned.
* Improvement: Forum topics now render 2–3× faster on big spaces (10,000+ topics) thanks to smarter database queries.
* Improvement: Vote buttons stay consistent even under heavy concurrent use — no more stuck or duplicate votes.
* Improvement: Pagination is more accurate — "Load more" and "has more" work correctly across all lists.
* Improvement: Admin list pages load faster with fewer database hits.
* Improvement: Daily activity cleanup runs without locking the database on large sites.
* Improvement: All admin pages and forms are easier on screen readers and keyboard users.
* Improvement: Content displayed in forum threads is fully sanitized and escaped for safety.
* Fix: Firefox scheduled-post time picker — the time field now shows a proper picker widget (was previously date-only on Firefox).
* Fix: The "..." more menu on posts and replies now opens reliably across browsers.
* Fix: Publish mode menu no longer flashes open-then-closed when you load the new topic page.
* Fix: Notification bell dropdown no longer throws a console warning on page load.
* Fix: Clicking a single notification in the header dropdown now marks just that one as read (no more "mark all or nothing").
* Fix: "Join Space" button now shows correctly for non-members on public spaces.
* Fix: Join request emails now send to the right space admins and link to the admin screen instead of the public page.
* Fix: Join request admin tab stays visible while pending requests exist.
* Fix: Posts-per-page setting at the space level now actually applies to that space's topic listing.
* Fix: Space settings no longer lose unrelated keys when you save a partial form.
* Fix: Dark mode token bridge now mirrors the theme's real dark state on every page load.
* Fix: Notifications properly persist for private message events.
* Fix: Private message notifications now dispatch reliably when Pro is active.
* Fix: License activation flow no longer errors on third-party plugin-info calls.
* Fix: Double reply counter increment on new replies.
* Fix: 10 earlier customer-reported bugs fixed — BuddyPress compatibility crash, notification defaults, vote state indicator, admin "View" link, join request admin UI, post scheduling default timestamps, settings write consistency, REST nonce handling, and cookie credentials on fetch calls.

**Upgrade notes**
Jetonomy 1.3.0 includes a small database update that runs automatically on the next admin page load. No manual action required. Free activation is unchanged.

= 1.2.0 - April 2026 =

* New: Private Topics — mark individual topics as private so only you and moderators can see them
* New: Topic Prefixes — colored labels (Bug, Suggestion, Solved) configurable per space
* New: Similar Topics — see related topics as you type your title, before posting duplicates
* New: Quote Replies — click Quote on any reply to insert a styled blockquote in your response
* New: BuddyPress Integration — link BP Groups to forum spaces with automatic member sync
* New: Forum tab in BP Group pages showing topics and a New Topic button
* New: Forum tab on BP Member profiles with Posts, Replies, and Bookmarks sub-tabs
* New: Discussion Forum settings in group creation wizard and group manage screen
* New: Linked group shown in the sidebar About section
* Improvement: Third-party admin notices hidden on Jetonomy pages
* Improvement: Space privacy automatically syncs with BP group privacy changes
* Improvement: wpForo multi-board import support
* Fix: Topic title placeholder alignment on all themes
* Fix: New Topic button hidden for logged-out visitors
* Fix: Spaces can only be linked to one group at a time

= 1.1.0 - March 2026 =

* New: Configurable Community Title setting — displayed as H1 on the community home page
* New: Adapter-specific rule type options in Access Rules (e.g. "Tutor Course", "LearnDash Course" instead of generic "Membership")
* New: Searchable autocomplete for membership levels — scales to 1000+ courses
* New: Human-readable labels in access rules table — shows course names instead of raw IDs
* New: Sync Members button to pull in existing enrolled users when creating a rule
* Fix: H1 heading added to community home page for SEO and accessibility
* Fix: Membership deactivation now fully removes space access instead of downgrading to viewer
* Improvement: Priority column hidden from access rules UI for cleaner admin experience
* Improvement: Action buttons with icons (Sync Members, Delete) in access rules table

= 1.0.1 - March 2026 =

* Fix: Renamed internal `.container` to `.jt-container` to prevent CSS class collisions with theme frameworks
* Fix: Community app wrapper fills theme flex/grid parents correctly — resolves blank sidebar space
* Fix: Container width auto-detects from theme settings (theme.json wideSize → $content_width → 1200px fallback)
* Fix: Community sub-nav moved inside content container for proper alignment
* Fix: Hide theme page title bars ("Recent Posts", "Blog") on community pages
* Fix: Improved spacing between sub-nav and content
* Tested with 12 popular themes: Astra, GeneratePress, Kadence, Neve, OceanWP, Storefront, Hestia, Hello Elementor, Blocksy, TT5, TT4, TT3

= 1.0.0 - March 2026 =

### Added
- Forum, Q&A, Ideas, and Social discussion types
- Custom MySQL tables (21 tables) — no `wp_posts` bottleneck
- 3-layer permission engine: WP Capabilities + Space Roles + Trust Levels 0–5
- WordPress Interactivity API frontend — no jQuery, no React bundle
- 19 abilities registered with the WordPress Abilities API (WP 6.9+)
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
