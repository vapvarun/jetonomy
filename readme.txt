=== Jetonomy - Community Forums, Q&A & Discussions ===
Contributors: wbcomdesigns, vapvarun
Tags: forum, community, discussion, Q&A, bbpress alternative
Requires at least: 6.7
Tested up to: 6.9
Stable tag: 1.8.0
Requires PHP: 8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The community platform WordPress deserves. Forums, Q&A, Ideas, and social discussions - all in one fast, beautiful plugin.

== Description ==

**Jetonomy turns your WordPress site into a thriving community.** Whether you want a support forum, a Stack Overflow-style Q&A, an ideas board, or just a place for members to chat - Jetonomy handles it all without duct-taping six different plugins together.

It's built from scratch with modern WordPress tech: custom database tables (not slow post types), the WordPress Interactivity API (no jQuery, no React bundle), and a permission system that actually makes sense. The result is a community platform that feels snappy and looks great on every theme you throw at it.

If you're still running bbPress, wpForo, or Asgaros, Jetonomy ships with one-click importers for all three. Your community, your posts, your members - all move over cleanly.

---

### Four Community Types, One Plugin

**Forum** - Classic threaded discussion. Great for support, general chat, announcements.

**Q&A** - Questions get answers. Answers get voted on. The best answer floats to the top. Perfect for knowledge bases and support communities.

**Ideas** - Members submit ideas, vote, and see a roadmap. Works like UserVoice but lives inside WordPress. Ship the features your community actually wants.

**Social Feed** - Lightweight, scrollable discussion. Great for news communities or team spaces that don't need the full forum structure.

---

### Built to Be Fast at Scale

Most forum plugins store content in `wp_posts` and `wp_postmeta`. That works for 500 posts. It gets painful at 50,000. Jetonomy uses 24 purpose-built MySQL tables with proper indexes, denormalized counters, and FULLTEXT search. Your community can grow to 100,000+ posts without a performance crisis.

Every list view uses cursor-based pagination (no expensive `COUNT(*)` queries). Frequently accessed data is automatically cached with Redis or Memcached if you have them. Batch queries everywhere - no N+1 problems.

---

### A Permission System That Actually Works

Jetonomy has three layers of permissions that stack together cleanly:

1. **WordPress Capabilities** - Admins and editors get full access. Subscribers get participant access. You control the defaults.
2. **Space Roles** - Every space has its own owner, moderators, and members. A space owner can moderate their own space without being a site admin.
3. **Trust Levels (0–5)** - New users start at Level 0 (limited posting). As they participate, Jetonomy automatically promotes them to Level 1, 2, and 3 based on thresholds you configure. Levels 4 and 5 are granted manually.

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
- Paste images from clipboard - they upload automatically
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
- Flag system - members flag content, moderators review a queue
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
- Eager loading with batch queries - no N+1 database calls
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

* **AI Integration** - Language-model-powered spam detection, content moderation, reply suggestions, and thread summaries. Pluggable providers including OpenAI, Anthropic, custom endpoints, and self-hosted Ollama (privacy-first).
* **Private Messaging** - Direct messages between members
* **Emoji Reactions** - React to posts and replies with custom emoji sets
* **Polls** - Run polls inside posts and spaces
* **Custom Fields** - Add custom fields to user profiles and posts
* **Analytics Dashboard** - See what your community talks about most, top contributors, growth trends
* **Email Digests** - Weekly/daily community digest emails
* **Advanced Auto-Moderation** - Rule-based moderation (keyword filters, rate limits, user score gates)
* **WooCommerce, Restrict Content Pro, LearnDash, Tutor LMS adapters** - Gate spaces behind courses or purchases
* **SEO Pro** - Per-space meta titles, Open Graph images, schema controls, and sitemap rules
* **Reply by Email** - Members reply to notification emails and the reply posts automatically
* **Web Push Notifications** - Browser push for replies, mentions, and moderation events
* **Webhooks** - Send HTTP POSTs to external services on community events
* **White-label branding** - Remove Jetonomy branding, use your own logo
* **Custom badge builder** - Design badges and award them manually or automatically

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
7. Setup wizard - choose custom setup or demo data
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

Yes. Jetonomy uses MySQL's native FULLTEXT indexes for fast, relevant search results. Typing in the search bar shows instant results as you type. The search system is built on a swappable adapter pattern - developers can write custom adapters for services like Meilisearch, Elasticsearch, or Algolia without touching the plugin core.

= Can my community members moderate their own spaces? =

Yes. Every space has its own owner and can have multiple moderators. Space moderators can manage content in their space without any wp-admin access. Site admins see a global moderation queue at `/community/mod/`.

= How do I import from bbPress, wpForo, or Asgaros? =

Go to **Jetonomy → Import** in your WordPress admin. Jetonomy detects which forum plugins are installed and shows the available importers. Imports run in batches with a progress bar - you can close the browser and the import continues. If the import stops, you can resume it exactly where it left off.

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

= 1.8.0 - July 2026 =

Forum imports now bring attachments across, attachments work with or without Pro, and cached data is never served stale after a change.

* New      - Import attachments and media from wpForo, bbPress, and Asgaros, not just the posts and replies.
* New      - Block another member, and report while blocking, from the mobile app and the REST API.
* New      - Delete your own account from the app (DELETE /users/me).
* Improve  - Attachments are shown and served by the free plugin, so a site without Pro keeps displaying its files instead of hiding them.
* Improve  - An import that cannot recover a file now says so ("N files could not be recovered") instead of reporting a silent success.
* Improve  - Object cache is invalidated the moment you change a space, profile, or membership, so counts and visibility are never a few minutes stale.
* Improve  - The subscriptions API now returns the title and link of what you are subscribed to.
* Improve  - Moderation queue items and flags now name the author and the reporter, and the flags status filter is honoured.
* Fix      - wpForo import no longer flattens the forum hierarchy, miscounts progress, or runs the whole import in a single request that times out on large forums.
* Fix      - Migrated inline images and attachments are registered into the media library, so deleting the old forum's uploads folder can no longer break them.
* Fix      - Blocked members' replies are hidden without dropping the innocent replies nested beneath them.
* Security - A moderator could globally ban the site administrator; banning an admin or a fellow moderator is now refused.
* Dev      - Post and reply REST payloads carry an attachments array (id, url, thumbnail, mime, name, size, type) whether or not Pro is active.

= 1.7.0 - July 2026 =

Foundations for two new Pro features - Anonymous Posting and File Attachments - plus avatar fallbacks, app parity, correct notification deep-links, and fuller translation coverage.

* New      - Members with no uploaded avatar now get a generated initials avatar instead of a blank placeholder, on the web and in any REST client.
* New      - User records returned by the REST API now include an avatar_display field, so a native app renders the same avatar the site does.
* Fix      - The profile-header avatar now uses the same resolver as every other avatar, so a member's initials fallback no longer disappears on their own profile.
* Fix      - BuddyPress activity-reply notifications now open the forum topic being replied to, instead of the activity feed.
* Fix      - Badge notifications now deep-link to the badges section of the recipient's profile, instead of the top of the profile.
* Fix      - Post and reply body text now share one alignment across every content type, so a reply no longer sits offset from the post it answers.
* Fix      - Vertical spacing above and below the post hashtag row is now balanced, instead of crowding the tags against the post body.
* Fix      - Compose toolbar labels, block-editor scripts, the threaded-reply toggle, and the remaining front-end script strings are now translatable.
* Security - Member media uploads are now validated against an explicit file-type allow-list with a content check, replacing behaviour where the accepted types depended on the member's role.
* Fix      - Composer and form inputs now show a single focus ring instead of a doubled outline.
* Dev      - Added the author-display resolver, the is_anonymous columns, and the upload allow-list and max-size filters that power Pro Anonymous Posting and File Attachments.
* Compat   - Aligned with Jetonomy Pro 1.7.0. Install both updates together.

= 1.6.0 - June 2026 =

Mobile and headless API release: new endpoints and per-viewer fields so a native app or any client can drive the community.

* New      - GET /app/config exposes site branding (accent colour, logo) and which Pro features are active, so a client can theme itself and show or hide features per site.
* New      - GET /feed serves a global cross-space home feed with hot, new, and top sorting and cursor pagination.
* New      - Posts and spaces now include per-viewer state (is_bookmarked, viewer_vote, is_member, viewer_role, is_subscribed) so clients render the right controls without extra requests.
* Improve  - REST write requests now reject banned and pending-verification users on every mutation, closing an Application Password bypass.
* Fix      - The admin Categories list now shows each category's full front-end URL plus a View link, so owners no longer hit a 404 by guessing the address from the bare slug.
* Fix      - Logged-out "Log in to vote" and "Log in to reply" links now return to the topic being viewed after login, instead of the site's front page.
* Fix      - Opening the new-post page for a space you have not joined now shows a Join prompt, instead of a composer that only rejects the post with a permission error after you have written it.
* Fix      - The moderation queue's pending-flag counter now updates the moment a flag is resolved, instead of showing the old count until the page is reloaded.
* Fix      - The sidebar login form now stays light in dark mode, as intended, instead of rendering as a dark card on themes (such as BuddyX) whose dark toggle the form's colour override was not catching.
* Dev      - Corrected manifest drift on the reply routes and the /users/suggest permission to match the shipped code.
* Compat   - Aligned with Jetonomy Pro 1.6.0. Install both updates together.

= 1.5.0 - June 2026 =

Instant in-place navigation across the community, built on the WordPress Interactivity API, plus an end-to-end audit that removed dead weight and verified every cross-surface contract.

* New      - Instant navigation: moving between the home feed, categories, spaces, profiles, search, leaderboard, notifications and messages swaps the view in place instead of reloading the whole page, with the address bar and browser back/forward kept in sync. Built on the WordPress Interactivity API client-side navigation and applied uniformly across every community view.
* New      - First-time visitors get a welcome banner on the community home with a one-line intro, a live member/post/this-week pulse, and a "Create free account" call-to-action. Filterable copy; hidden for logged-in members.
* New      - Empty pages now guide you forward: drafts, search, leaderboard, profile tabs and space listings show a real styled call-to-action and next step instead of a dead end.
* New      - Community Media: a member-facing dashboard to browse and manage your own uploads, kept out of the wp-admin Media Library.
* New      - One-click install of the Wbcom stack companions straight from Settings > Integrations.
* New      - Space cards now show an "Active N ago" recency signal so you can tell at a glance which spaces are alive.
* New      - Avatar square-crop: uploading a profile photo opens a drag-and-zoom crop dialog so avatars are saved square; animated GIFs upload unchanged.
* New      - Space RSS feeds: every public space serves RSS 2.0 at /community/s/{slug}/feed/ with auto-discovery on the space page; private spaces stay private.
* New      - Remove a bookmark directly from the My Bookmarks page - the card drops off the list when you do.
* New      - Tag pages have a "Start a discussion tagged X" button that opens the composer with the tag pre-filled.
* New      - Conversations admin page (with Pro): paginated messaging oversight in wp-admin with thread detail and a compliance purge action.
* New      - Learnomy LMS access rules: spaces can be gated by Learnomy course level through the access-rule engine.
* New      - Space moderators can approve or deny join requests from the community front-end (on the space Members page), not only in wp-admin; approving admits the member and the requester is notified by email.
* Improve  - Posting a reply now adds it to the thread in place instead of reloading the page.
* Improve  - Space empty states speak the space's language ("Ask the first question" in Q&A, "Share an update" in feeds, "Suggest an idea" in idea spaces).
* Improve  - Logged-out visitors clicking the vote control on a post are now taken to log in (with a return link) instead of nothing happening.
* Improve  - Admin Dashboard "Pending Flags" is now a link straight to the moderation queue.
* Improve  - Resolving a flag as valid through the REST API now applies the full contract on every surface: content trashed, related flags cleared, reporter rewarded, webhook event fired.
* Improve  - Settings warns when your CAPTCHA provider and keys are mismatched.
* Improve  - Private spaces are now discoverable in the directory and search, shown with a Join or Request-to-join action, while their posts stay members-only; only hidden spaces are kept out of listings.
* Improve  - The front-end edit-space form now exposes the "Require moderator approval for new posts" setting, so space owners can toggle it without opening wp-admin.
* Improve  - The forum adopts your active theme's brand colour automatically across BuddyX, BuddyX Pro, Reign and the popular page-builder themes (Astra, Kadence, GeneratePress, Blocksy), in light and dark mode, with no custom CSS; themes that expose no brand colour fall back to a clean default you can override under Settings > Appearance.
* Fix      - Members can now pause all email notifications from Edit Profile (and admins per user); the verification reminder honoured this preference but nothing could set it.
* Fix      - Spam and Trash in the admin moderation queue now ask for confirmation before removing content.
* Fix      - Long-lived tabs refresh their session nonce automatically, so members no longer lose a reply to "Cookie nonce is invalid".
* Fix      - BuddyPress broadcast and activity-comment bridge now have on/off controls under Settings > Integrations (shown when BuddyPress is active); previously only changeable via WP-CLI.
* Fix      - Block titles (login "Join the conversation", navigation, user panel, feed) stay legible on themes whose content-heading styles previously repainted them dark-on-dark.
* Fix      - Analytics counting stays accurate through the content lifecycle: pending posts count on approval, trashed posts decrement their original day.
* Fix      - The approval-hold check now evaluates the post author instead of the current user.
* Fix      - The forum no longer injects its own brand colour into the site's global colour palette, where it could override the active theme's Primary colour; it reads the theme's colour instead of competing with it.
* Fix      - The notification action menu (mark read, delete) opens anchored to its button instead of mis-positioned, and notification cards no longer stretch full width.
* Fix      - A restrictive access rule added to a public space now gates the space (it is converted to private) instead of being saved but ignored.
* Fix      - The community-uploads filter is available in the Media Library grid view, not only the list view.
* Fix      - Block panels (user panel, feed, login, navigation) rendered a tinted background in dark mode on themes that expose no dark token, such as BuddyX; they now use the neutral surface colour like the rest of the community.
* Fix      - The Messages link in the user panel and the Message button on profiles no longer appear when private messaging is inactive, so they no longer lead to a 404.
* Fix      - Load More on paginated community routes (notifications and listings) no longer returns a 404 on later pages.
* Fix      - Database version constant bumped so the 1.5.0 migration runs automatically on upgrade.
* Security - Search results, link-preview (oEmbed) cards, profile activity tabs, tag pages, trending lists and the recent-posts widgets no longer surface posts or replies from private or hidden spaces to people who are not members.
* Security - Importing a members-only wpForo board no longer flattens it to a public space; access-restricted forums import as private with approval to join, with a filter to map access per site.
* Dev      - Frontend REST calls route through a single client with automatic nonce-refresh on expiry; community views are fully declarative via the Interactivity API, so client-side navigation needs no per-route scripts.
* Dev      - jetonomy_post_publish_transition and jetonomy_reply_publish_transition hooks fire on every publish/unpublish transition with a +/-1 delta and the original creation date.
* Dev      - Removed three never-wired tables (space tags, user interests) with a guarded migration, the GET /space-tags route, three dead admin AJAX actions, and 30+ zero-reference methods.
* Dev      - PHPUnit suite green across PHP 8.1-8.4 x WP 6.7-6.9; contract-audit baseline and release gate added; CI now runs on dev branches with the full non-pro test matrix.
* Dev      - New front-end extension points so themes and companion plugins can customise without copying templates: jetonomy_profile_tabs and jetonomy_space_tabs (add or reorder profile and space tabs), jetonomy_space_card_after and jetonomy_member_card_after (inject into space and member cards), and jetonomy_dynamic_css (append --jt-* token overrides).
* Compat   - Aligned with Jetonomy Pro 1.5.0. Install both updates together.

= 1.4.5 - June 2026 =

* New      - Custom fields on spaces: Pro custom fields now render and save on the create-space form, edit-space screen, and space sidebar.
* New      - Sidebar extension points: per-section visibility filters and before/after slots around About, Managed By, Trending, Top Members, and Popular Tags.
* New      - jetonomy_composer_toolbar action for adding buttons to the post composer toolbar.
* Improve  - Frontend create-space form now exposes Visibility, Join policy, and Category.
* Fix      - Reply-by-email: emailed replies are created again (missing listener wired).
* Fix      - Logged-out visitors on public communities no longer trigger 401 errors from reply polling; the new-replies banner now works for them on posts they can read.
* Fix      - Inline post editor can edit Pro custom fields; custom_fields included on PATCH.
* Fix      - New-post composer includes custom field values in the payload.
* Fix      - Category icons render through the icon helper on home and category views; duplicate category title removed.
* Fix      - Setup wizard only redirects on admin requests, never on frontend page loads.
* Fix      - Space edit screen loads its script on the edit-space route.
* Fix      - Select All works in the Community nav-menus meta box.
* Dev      - Space custom-field lifecycle hooks for create, edit, and display contexts.
* Dev      - Shared custom-field collector (window.jetonomyCollectCustomFields) replaces duplicated JS.
* Compat   - Aligned with Jetonomy Pro 1.4.5. Install both updates together.

= 1.4.4 - May 2026 =

Background work that runs on time, a License screen that always loads, custom profile fields that save, fair handling of false reports, and admin polish across the plugin.

* New       - Background work runs reliably on every site, no extra plugins required.
* New       - One-click "Edit this space" link from any space page for owners and space admins, on desktop and mobile.
* New       - Set your own reputation point values for posts, replies, votes, and accepted answers right from Settings.
* New       - Moderators get quick jumps to the moderation queue and space tools from the admin bar on any community page.
* Improve   - Trust promotions, expired bans, scheduled posts, activity cleanup, and notification cleanup happen on time even on quiet sites.
* Improve   - The Report button tells you up front if you have already reported a post, instead of opening the reason form again.
* Improve   - The Edit Profile page now shows a clear "More about you" section above your additional fields.
* Improve   - Form fields and destructive actions stay readable in dark mode.
* Improve   - Award Badge in wp-admin uses the same look as every other Jetonomy admin button.
* Improve   - One consistent icon picker across categories, spaces, and badges.
* Improve   - Redesigned notifications page with filter tabs for all, unread, mentions, replies, votes, and badges.
* Improve   - Consistent "nothing here yet" screens across the plugin.
* Improve   - Tighter spacing on single Idea pages.
* Fix       - The License screen loads correctly on fresh installs.
* Fix       - Custom profile fields you fill out save properly and stay saved on reload.
* Fix       - Reporting a post deducts the published reputation penalty from the author. If a moderator decides the report was invalid, the points are restored automatically.
* Fix       - Moderation rules set to "Flag for Review" now actually flag matching content and surface it in the moderation queue.
* Fix       - BuddyPress dark mode flows through to community surfaces correctly.
* Fix       - Composer Private toggle alignment and Banned Users dialog tone match the rest of the admin.
* Fix       - Keyboard focus indicators stay where they should on the editor and feed actions.
* Fix       - Private ideas stay private when published from the inline composer.
* Fix       - Polls accept votes again.
* Fix       - Downvoting a reply reverts cleanly if the server rejects it.
* Fix       - The "More" menu on the last reply no longer gets clipped at the bottom of the screen.
* Fix       - Idea-planned reputation awards consistently across the activity log and notifications.
* Security  - All write requests share a single permission contract enforcing login, nonce, capability, and ownership in one check.
* Dev       - New filter `jetonomy_reputation_points_for` lets you override per-action point values; Jetonomy Pro uses it for the Settings UI.
* Dev       - New filter `jetonomy_reputation_points_map` lets integrations wholesale-replace the POINTS_MAP (e.g. WB Gamification per-community ladders).
* Dev       - New filter `jetonomy_reputation_pre_change` runs immediately before the DB write; return 0 to veto a delta (campaigns, sandboxed users).
* Dev       - New filter `jetonomy_leaderboard_items` lets host plugins enrich GET /leaderboards rows with cross-engine totals (badge count, level, alt currency) without a second REST round-trip.
* Dev       - `jetonomy_reputation_changed` documented signature aligned to the 4-arg shape the action has always fired (`$user_id`, `$action`, `$delta`, `$context`); previous 3-arg docs example was wrong.
* Dev       - New actions `jetonomy_post_created`, `jetonomy_reply_created`, `jetonomy_vote_cast`, `jetonomy_vote_retracted` for gamification integrations.
* Dev       - `jetonomy_idea_status_changed` now passes the post author ID as a fifth argument.
* Dev       - New filter `jetonomy_trust_level_pre_change` lets you veto or override auto-promotions.
* Dev       - New hooks documented in `docs/website/developer-guide/02-hooks-reference.md`.
* Dev       - Release zips verify bundled libraries are present before packaging so a clean clone cannot ship a broken release.
* i18n      - About 30 previously-hardcoded JS strings are now translatable.
* Compat    - Aligned with Jetonomy Pro 1.4.4. Install both updates together.

= 1.4.2 - May 2026 =

Scale and multisite release. Three new content types, multisite-aware tables, batched cleanup crons, plus accessibility and translation fixes.

* New      - Show & Tell short-form feed space type with optional title and inline content cards.
* New      - Ideas roadmap view with four status lanes: Planned, In Progress, Shipped, Declined. Ideas without a curated status stay in the space's normal feed until an owner assigns one.
* New      - Q&A "Answered" badge surfaces on the space list when an owner pins the accepted answer.
* New      - Setup wizard step 1 offers all four community types (Forum, Q&A, Ideas, Show & Tell); sample data ships 6 spaces across all four.
* Improve  - Cleanup cron handlers (trust evaluation, expired restrictions, old notifications, scheduled posts) batch 500 rows per run. Filterable via `jetonomy_cron_batch_size`.
* Improve  - Network activation creates the required tables on every existing subsite and every new subsite created later.
* Improve  - Composer, login block, IA state, banned-member notice, header escape hint, prefix builder, and admin import flow are fully translatable.
* Improve  - Shared modal toolkit button labels and view.js modal helpers now translate (retag fix).
* Improve  - Keyboard focus indicators visible everywhere; aria-labels added to filter, bulk-action, and select-all controls.
* Improve  - Native browser confirm dialogs swapped for in-product modals.
* Fix      - Posts/replies-per-page setting controls the actual list length and the Load More click count.
* Fix      - Vote controls hide when an admin disables voting on a space.
* Fix      - Idea status changes notify the right people across the activity log, email digest, and in-app inbox.
* Fix      - Import progress AJAX requires the right capability, not just a nonce.
* Fix      - Setup wizard redirect skips under WP-CLI / REST contexts.
* Fix      - Settings save confirmations no longer disappear before the user can see them.
* Fix      - Login block stays light when the rest of the app is in dark mode.
* Fix      - Q&A accepting a second answer correctly clears the previously accepted reply.

= 1.4.1 - April 2026 =

Public/private community mode, drafts and bookmarks for members, activity log and revisions for owners, plus a large bug-fix sweep.

* New      - Public/Private community mode in Settings. Choose Public (anyone can read) or Private (every page requires sign-in). Applies to the whole front-end and to the REST API.
* New      - Activity Log admin page browses every audit event (post created, reply approved, member banned, role changed) with filters by user, type, and date range.
* New      - Revisions admin page browses every saved post/reply revision with a side-by-side diff between any two revisions.
* New      - Drafts tab at `/community/drafts/` lists every post you saved as a draft.
* New      - Bookmarks tab at `/community/bookmarks/` lists every post you bookmarked.
* New      - Verification reminder email sent once after 24 hours to members who registered but did not click the verification link.
* New      - "Reset to default" button on every notification email template restores the shipped subject and body in one click.
* New      - REST endpoints `POST /jetonomy/v1/moderation/bulk` and `GET /jetonomy/v1/posts/{id}/flags` for moderation tooling.
* Improve  - Every empty state uses the same partial with consistent icon, copy, and CTA placement.
* Improve  - Every customer-facing icon now comes from the Lucide icon set via `jetonomy_echo_icon()`. No inline SVG on customer surfaces; no WordPress emoji fallback.
* Improve  - Action density on post and reply cards capped at three primary actions, with the rest moved into a kebab menu.
* Improve  - Voting controls present upvote and downvote at equal weight, same touch target size.
* Improve  - Touch targets across the plugin meet the 44 x 44 px minimum; spacing uses CSS logical properties so RTL flips for free.
* Improve  - Per-role REST access matrix is now a verifiable contract. `bin/access-matrix-check.sh` runs 78 checks across 6 roles in either public or private mode and gates the build.
* Improve  - Manifest schema bumped to v2 with `auth`, `capability`, and `ownership_check` declared per REST endpoint.
* Improve  - Verification reminder cron and the admin "send test email" button route through the Email_Adapter registry.
* Fix      - Hidden spaces no longer leak from the homepage spaces list to non-admins.
* Fix      - Multi-space moderators see every queue they own at `/community/mod/`.
* Fix      - Remove button on flagged content actually removes the content (used to mark the flag valid without trashing the post or reply).
* Fix      - Voting works on every install (Pro analytics aggregator AJAX response corruption fixed).
* Fix      - Space cards render their icon correctly when only the icon name was saved.
* Fix      - Default qa-type space icon switched from a question-mark to an open book so multiple Q&A spaces do not all look identical.
* Fix      - New-post composer with Pro polls active no longer drops form fields between submit attempts.
* Fix      - 13 lifecycle hooks the Pro extensions had been listening for now fire end-to-end. White Label, Custom Fields, and Webhooks extensions activate on customer sites.
* Fix      - `jetonomy/search` Ability no longer fatals at runtime.
* Fix      - Search results report accurate totals; pagination of large result sets works correctly.
* Dev      - New helper `Jetonomy\Visibility` centralizes the public-or-private check (`can_view_community()`, `get_mode()`, `rest_check()`).
* Dev      - Schema migration adds `jt_user_profiles.verification_reminder_sent_at`. Runs automatically; rolls back cleanly.
* Dev      - `bin/local-ci.sh` runs full PHPStan + WPCS + PHPUnit gate locally against a wp-env Docker stack in under 2 minutes after warm-up.
* Dev      - New helper `Jetonomy\Models\Post::count_by_space_visible()` for accurate cursor pagination.
* Dev      - Manifest schema upgraded to v2.1 with cross-plugin consumed_by index and audit/derived/ cache.
* Dev      - New helpers `Jetonomy\header_logo()` and `Jetonomy\footer_text()` expose the documented branding hook contract.

= 1.4.0 - April 2026 =

Run a community without leaving the front end. Show up in search. Cleaner accessible interface throughout.

* New      - Edit a space from the front end: title, description, cover, icon, type, visibility, join policy, category, posts-per-page, prefixes.
* New      - Create a space from the front end. Pick which roles can use the form in Settings -> Front-end space creation.
* New      - "My Spaces" page lists every space you run and every space you are in.
* New      - Visual icon picker with 16 icons, search, and "Show more" for 8 extras.
* New      - Cover image uploader works without the WordPress upload-files permission.
* New      - @mention autocomplete in the composer.
* New      - "New" pill on threads with replies you have not read.
* New      - "Managed by" sidebar card on every space.
* New      - Admin / Mod pills next to staff names on posts and replies.
* New      - Layout panel fits Jetonomy to your theme (Container Width, Sidebar, Padding).
* New      - Full search and social cards on every public page; smart fallback share image when a page has no image of its own.
* New      - Richer structured data: Sitelinks Searchbox on home, Person cards on profiles, Collection indexes on spaces and tags, breadcrumbs everywhere.
* New      - Settings -> SEO grew Twitter/X handle, default share image, sitemap link.
* New      - Image alt text fills in automatically on upload.
* New      - Login, register, forgot-password are faster and in-page (no wp-login.php bounce).
* New      - In-product confirm/alert/prompt replaces browser pop-ups (WCAG 2.1 AA accessible).
* New      - All 8 Jetonomy blocks now visible in the block inserter; shortcodes render styled on any page or page-builder canvas.
* Improve  - Role dropdown cannot accidentally orphan a space (no self-demote, no last-admin-out).
* Improve  - Pages that should not show in search (moderation, search, composer, notifications) are excluded automatically.
* Improve  - Captcha actually fires on signup when configured.
* Fix      - Category dropdown on Edit Space is no longer empty; "No category" saves correctly.
* Fix      - Space moderators without a WordPress editor role can again use the inline mod tools.
* Fix      - Join-request notifications link to the right place per recipient.
* Fix      - Notifications page no longer auto-marks everything read on render.
* Fix      - GDPR export contains the user's display name.
* Fix      - Tags on post cards link to the tag page; tag page paginates instead of capping at 30.
* Fix      - Share dropdown closes when you scroll.
* Fix      - Auth rate-limit window does not reset on retry.
* Security - Banned users can no longer log in.
* Security - Private-post structured data no longer leaks to anonymous visitors.
* Dev      - `[jetonomy_widget id="..."]` shortcode embeds any widget on any page.
* Dev      - `jetonomy_seo_meta` filter to tweak the entire SEO payload.
* Dev      - `jetonomy_use_frontend_space_edit` filter to swap Edit Space target.
* Dev      - New REST endpoints: `POST /media`, `POST /auth/{login,register,lost-password}`, `GET /spaces/{id}/privileged-members`, `GET /users/suggest`.
* Dev      - Browser QA checklist and Phase D SEO plan ship with the plugin.

= 1.3.8 - April 2026 =

Space moderation, front-end member management, BuddyPress and FluentCommunity integrations, dark-mode polish, and a long tail of bug fixes.

* New      - Space admins and space moderators get their own moderation queue at `/community/s/:slug/mod/` showing only the flagged content inside that space.
* New      - Site admins land on a cross-space moderation dashboard at `/community/mod/` that summarises pending flags per space.
* New      - Space admins can promote members to moderator or admin from the front-end members page (`/community/s/:slug/members/`).
* New      - FluentCommunity integration auto-enables when FluentCommunity is detected. Pair spaces from Settings > FluentCommunity to render a "Discussions" tab on the FC space header and a sidebar card on the Jetonomy space.
* New      - Configurable Discussions tab label on the integration settings page.
* New      - FluentCommunity profile pages show a Discussions block listing the member's five most recent topics and the five topics they follow on the Jetonomy side.
* New      - Jetonomy profile pages cross-link to the member's FluentCommunity profile (button uses the FC Site Title for natural "View on <community>" reading).
* New      - Unified member avatars across Jetonomy and FluentCommunity.
* New      - Member sync across paired spaces (add-only on purpose so leaves do not propagate).
* New      - Activity broadcast posts new Jetonomy topics into the paired FluentCommunity space (one-way; toggle on the settings page).
* New      - Private topics never broadcast to FluentCommunity.
* New      - Comment-to-reply bridge mirrors FluentCommunity comments back as Jetonomy replies (only on broadcast feeds, preserves author attribution).
* New      - "Sync existing members now" button backfills paired space membership in both directions (capped at 5,000 per space per run).
* New      - BuddyPress integration broadcasts new Jetonomy topics into the paired BuddyPress group's activity stream; activity comments round-trip back as replies.
* Improve  - Plugin headings stay readable when the Reign theme (or any dark-panel theme) is in dark mode.
* Improve  - Accent tints (--jt-accent-light, --jt-accent-muted) re-derived against the panel background in dark mode.
* Improve  - Locked-space banner and warning notices are legible against dark panels.
* Improve  - Jetonomy no longer auto-applies dark mode based on the visitor's OS preference. Dark mode follows the theme only.
* Improve  - 14 translation-ready strings rewritten to drop em-dashes and awkward typography. No string keys changed.
* Improve  - Interactivity API exposes `isLoggedIn` and `loginUrl` so blocks and embeds can render the right CTA without extra REST calls.
* Improve  - Integration settings pages got section banners and clearer loader-gate documentation.
* Fix      - Sort modes (oldest, newest, unanswered) return the right set of topics.
* Fix      - Space settings are merged on save instead of overwritten.
* Fix      - Similar-topics widget no longer leaks HTML entities into titles; sitewide search ranks results by relevance.
* Fix      - Time picker on scheduled posts uses cross-browser hour and minute selects when the browser does not provide a native picker.
* Fix      - Online indicator sits on the avatar's top-right corner across all sizes.
* Fix      - Space listing Load More does not auto-preload the next page on first render.
* Fix      - Share dropdown closes when the page scrolls.
* Fix      - Profile tabs do not clip the Drafts tab on mobile.
* Fix      - Rewrite rules are flushed during activation so community URLs resolve immediately.
* Fix      - Profile Drafts rows navigate to the draft itself for one-click edit or publish.
* Fix      - Long words in user content wrap on mobile instead of pushing the app wider than the viewport.
* Fix      - TikTok videos render as proper iframes instead of caption-only text cards; copy-link shows visible feedback when clipboard write is blocked.
* Fix      - Forum members can attach images when creating posts (upload permissions no longer require a higher role).
* Fix      - Voting optimistically shows the correct score when flipping a prior vote.
* Fix      - FluentCommunity broadcast preserves paragraph breaks in the excerpt.
* Compat   - Upgrading from 1.3.7 does not require any migration.

= 1.3.7 - April 2026 =

Compose Topic block for sidebars and landing pages, plus a round of share, embed, and editor fixes.

* New      - "Jetonomy Compose Topic" block (and `[jetonomy_compose_topic]` shortcode) lets signed-in members start a topic from any page on your site. Works in Site Editor, Elementor, Divi, Bricks, and WPBakery.
* New      - Sign-in prompt on the Compose Topic block shows a Lucide chat icon with side-by-side "Sign in to post" and "Create an account" buttons for guests. Register link only appears when registration is open.
* Fix      - Share button on a single topic opens the share menu in the right place; auto-flips above the button when there is no room below. Picks up crisp icons for Copy link, Twitter/X, Facebook, and LinkedIn.
* Fix      - Posting a topic without filling in the body now shows a clear inline message asking for the missing field (Pro Polls topics included).
* Fix      - Editing a topic that contains an uploaded image no longer wipes the image on save. Same fix applies to replies.
* Fix      - TikTok video previews and embedded players work everywhere, including short `tiktok.com/t/...` mobile-share URLs. Same fix covers Twitter (t.co), Reddit (redd.it and new mobile share links), Facebook (fb.watch), and Spotify (spoti.fi).
* Fix      - Embedded TikTok, Instagram, and Twitter posts no longer pick up the theme's blockquote styling.
* Improve  - Reaction picker draws crisp colour SVG icons that look identical on every browser and host configuration.
* Improve  - Block inserter reliably finds every Jetonomy block.
* Improve  - Compose Topic block's CSS and JS only load on pages that use the block.
* Compat   - Upgrading from 1.3.6 does not require any migration.

= 1.3.6 - April 2026 =

Trending Topics block, scoped Forum Feed block, rich link previews, per-type email templates.

* New      - Trending Topics block ranks community posts by recent engagement (votes + replies over a trailing window with time decay).
* New      - Forum Feed block scopable to a single space with a styled header and "View all" link.
* New      - Rich link previews on topics and replies render preview cards with title, description, and favicon via a local unfurling service.
* New      - Per-type email templates (welcome, mention, reply, digest, moderation, system) with theme override directory at `jetonomy/emails/`. Richer template context.
* New      - External plugins can open the community message composer programmatically via the shared `msgComposeOpen` state.
* Fix      - Three-dot dropdown on topic and reply cards no longer gets trapped or clipped inside the card.
* Fix      - Users can no longer downvote their own posts or replies; REST vote endpoint rejects self-downvotes.
* Fix      - Private (`is_private`) topics are now truly private across archives, search, tag pages, and REST listings.
* Fix      - TikTok, Instagram, and Twitter/X links embed as real video/post players instead of falling back to oEmbed blockquotes.
* Fix      - Invite-only space journey: visiting `/new/` without an invite returns to the space with an inline error; stale "Join" CTAs hidden when the viewer has joined or has a pending request.
* Fix      - Spacing between the Post Topic button and the publish-options dropdown on the composer.
* Fix      - Tags admin page now writes nonce-protected requests.
* Improve  - `wp jetonomy config get` dotted-path lookups return the effective runtime value when the admin has not explicitly saved that block.

= 1.3.5 - April 2026 =

Paragraph-preserving editor, plus Navigation and Login blocks.

* New      - Jetonomy Navigation block renders the Category -> Space tree as sidebar navigation. Permission-aware, highlights the current space, scales to thousands of spaces.
* New      - Jetonomy Login block is a quick login and register panel for the community sidebar. Logged-out viewers see inline Login and Register tabs; logged-in viewers see nothing (no layout shift). Rate-limited and nonce-protected.
* Fix      - Editing a topic or reply no longer collapses paragraphs into a single run-on line. Historically broken posts render with their paragraphs restored on the next page load.

= 1.3.4 - April 2026 =

Akismet false-positive fix, one-click moderation actions, denormalized counter integrity.

* New      - Admins can approve a spam-flagged topic or reply in one click. Replies and Posts admin lists show an "Approve" / "Not Spam" action next to Trash for any row currently held for moderation.
* New      - Admins can refresh community member counts alongside topic and reply counters (one-click rebuild from 1.3.3 now repairs member drift too).
* New      - Spaces track real membership: posting a topic or replying in an open community auto-joins the author as a member.
* New      - One-time upgrade routine adds every historical author to the spaces they have contributed to so existing communities show accurate member counts immediately.
* New      - Moderation queue endpoint accepts `status=pending|spam|all` so admins managing by API can see spam-flagged items alongside pending ones.
* New      - Site admins can promote many members to a trust level in one call via the admin API.
* Fix      - Akismet no longer flags replies written by site admins or space admins/moderators as spam.
* Fix      - Approving, marking-as-spam, or trashing a topic or reply from the admin list correctly updates the topic count, reply count, and member contribution totals.

= 1.3.3 - April 2026 =

Date preservation, counter rebuild, Access Control rewrite.

* New      - Preserve original dates when seeding or migrating discussions. Topics and replies added by admins keep the date they were originally written.
* New      - Admins can rebuild community counters when they look off (topic totals, reply totals, member stats, vote scores).
* Improve  - Access Control settings collapsed to a single, clearer choice: "Public community" (anyone can read, login required to post) or "Private community" (login required to view anything).
* Fix      - "Default Space Type" setting now actually applies to new spaces (admin panel and API).
* Compat   - Existing installs migrated automatically. Private-community installs keep that behaviour and any default space type set during setup is carried over.

= 1.3.2 - April 2026 =

* Fix      - Setup wizard no longer triggers PHP deprecation warnings (strip_tags null, print_emoji_styles, wp_admin_bar_header) on WP 6.4+ with PHP 8.1+.
* Dev      - New-post form submit action is filterable via `jetonomy_new_post_submit_action`.

= 1.3.1 - April 2026 =

* Fix      - Theme button hover styles no longer override Jetonomy button states. Scoped CSS reset for BuddyX/Reign compatibility.

= 1.3.0 - April 2026 =

Rich link unfurling, video/music embeds, theme compatibility, AI spam detection, ad slots.

* New      - Share forum threads anywhere. Paste any topic URL into Slack, Twitter/X, Discord, Facebook, or another WordPress site and see a rich preview card with title, author, excerpt, space, and thumbnail.
* New      - Embed YouTube, Vimeo, SoundCloud, Spotify, TED Talks, and other supported links inline.
* New      - Instagram and Facebook embed support via optional free Meta Developer App integration under Settings -> SEO -> Social Embeds. Includes a 5-minute step-by-step setup guide.
* New      - BuddyX, BuddyX Pro, and Reign theme compatibility. Your forum picks up the active theme's accent colour and dark mode with zero custom CSS.
* New      - AI spam detection for new posts and replies. Self-hosted via Ollama (no API costs, no data leaves your server).
* New      - Ad and content injection slots. New hooks drop banners, promotions, or extra content into the sidebar, reply flow, and below the space intro.
* New      - Change your community URL safely. Renaming the community base (e.g. `/forum/` to `/community/`) auto-redirects visitors from the old URLs.
* New      - WordPress Abilities API integration. 18 abilities across 5 categories let AI assistants and automation tools drive Jetonomy from the terminal.
* New      - Demo data seeder. One-click demo content for previewing how your community will look, with a cleanup button.
* New      - Richer search preview snippets. Search engines see thread titles, author names, publish dates, and space categories.
* Improve  - Theme compatibility across 12+ popular themes. Page titles, container widths, and responsive layouts tested and tuned.
* Improve  - Forum topics render 2-3x faster on big spaces (10,000+ topics).
* Improve  - Vote buttons stay consistent under heavy concurrent use.
* Improve  - Pagination accuracy. "Load more" and "has more" work correctly across all lists.
* Improve  - Admin list pages load faster with fewer database hits.
* Improve  - Daily activity cleanup runs without locking the database on large sites.
* Improve  - All admin pages and forms easier on screen readers and keyboard users.
* Improve  - Content displayed in forum threads is fully sanitized and escaped for safety.
* Fix      - Firefox scheduled-post time picker shows a proper picker widget.
* Fix      - "..." more menu on posts and replies opens reliably across browsers.
* Fix      - Publish mode menu does not flash open-then-closed when loading the new topic page.
* Fix      - Notification bell dropdown does not throw a console warning on page load.
* Fix      - Clicking a single notification in the header dropdown marks just that one as read.
* Fix      - "Join Space" button shows correctly for non-members on public spaces.
* Fix      - Join request emails send to the right space admins and link to the admin screen.
* Fix      - Join request admin tab stays visible while pending requests exist.
* Fix      - Posts-per-page setting at the space level applies to that space's topic listing.
* Fix      - Space settings do not lose unrelated keys when you save a partial form.
* Fix      - Dark mode token bridge mirrors the theme's real dark state on every page load.
* Fix      - Notifications persist for private message events.
* Fix      - Private message notifications dispatch reliably when Pro is active.
* Fix      - License activation flow does not error on third-party plugin-info calls.
* Fix      - Double reply counter increment on new replies.
* Fix      - 10 earlier customer-reported bugs fixed (BuddyPress compatibility crash, notification defaults, vote state indicator, admin "View" link, join request admin UI, post scheduling default timestamps, settings write consistency, REST nonce handling, fetch cookie credentials).
* Compat   - Database update runs automatically on the next admin page load. No manual action required.

= 1.2.0 - April 2026 =

* New      - Private Topics. Mark individual topics as private so only you and moderators can see them.
* New      - Topic Prefixes. Coloured labels (Bug, Suggestion, Solved) configurable per space.
* New      - Similar Topics suggested as you type your title to prevent duplicates.
* New      - Quote Replies. Click Quote on any reply to insert a styled blockquote.
* New      - BuddyPress integration. Link BP Groups to forum spaces with automatic member sync.
* New      - Forum tab in BP Group pages with topics and a New Topic button.
* New      - Forum tab on BP Member profiles with Posts, Replies, and Bookmarks sub-tabs.
* New      - Discussion Forum settings in group creation wizard and group manage screen.
* New      - Linked group shown in the sidebar About section.
* Improve  - Third-party admin notices hidden on Jetonomy pages.
* Improve  - Space privacy auto-syncs with BP group privacy changes.
* Improve  - wpForo multi-board import support.
* Fix      - Topic title placeholder alignment on all themes.
* Fix      - New Topic button hidden for logged-out visitors.
* Fix      - Spaces can only be linked to one group at a time.

= 1.1.0 - March 2026 =

* New      - Configurable Community Title setting displayed as H1 on the community home page.
* New      - Adapter-specific rule type options in Access Rules (e.g. "Tutor Course", "LearnDash Course" instead of generic "Membership").
* New      - Searchable autocomplete for membership levels (scales to 1000+ courses).
* New      - Human-readable labels in access rules table (course names instead of raw IDs).
* New      - Sync Members button to pull in existing enrolled users when creating a rule.
* Improve  - Priority column hidden from access rules UI for a cleaner admin experience.
* Improve  - Action buttons with icons (Sync Members, Delete) in access rules table.
* Fix      - H1 heading added to community home page for SEO and accessibility.
* Fix      - Membership deactivation fully removes space access instead of downgrading to viewer.

= 1.0.1 - March 2026 =

* Fix      - Renamed internal `.container` to `.jt-container` to prevent CSS class collisions with theme frameworks.
* Fix      - Community app wrapper fills theme flex/grid parents correctly (resolves blank sidebar space).
* Fix      - Container width auto-detects from theme settings (theme.json wideSize -> $content_width -> 1200px fallback).
* Fix      - Community sub-nav moved inside content container for proper alignment.
* Fix      - Hide theme page title bars ("Recent Posts", "Blog") on community pages.
* Fix      - Improved spacing between sub-nav and content.
* Compat   - Tested with 12 popular themes: Astra, GeneratePress, Kadence, Neve, OceanWP, Storefront, Hestia, Hello Elementor, Blocksy, TT5, TT4, TT3.

= 1.0.0 - March 2026 =

Initial release.

* New      - Forum, Q&A, Ideas, and Social discussion types.
* New      - Custom MySQL tables (21 tables) instead of `wp_posts`.
* New      - 3-layer permission engine: WP Capabilities + Space Roles + Trust Levels 0-5.
* New      - WordPress Interactivity API frontend (no jQuery, no React bundle).
* New      - 19 abilities registered with the WordPress Abilities API (WP 6.9+).
* New      - Rich text editor with drag-drop image upload and paste-to-upload.
* New      - @mention notifications with autocomplete.
* New      - Auto-embed for YouTube, Twitter/X, Vimeo, and other oEmbed providers.
* New      - Emoji picker in reply composer.
* New      - Code syntax highlighting via Prism.js.
* New      - Quote-to-reply: select text and reply to quote it.
* New      - Keyboard shortcuts: j/k navigate, l upvote, r reply, / search.
* New      - Threaded replies up to 3 levels deep with collapsible threads.
* New      - Full-text search with instant search-as-you-type.
* New      - User hover cards on avatar/name hover.
* New      - Invite links with configurable expiry.
* New      - Leaderboard with reputation rankings.
* New      - Space roadmap view for Ideas spaces.
* New      - Flag system with moderator queue.
* New      - IP tracking and Akismet spam integration.
* New      - Ban and silence system.
* New      - Setup wizard with realistic demo data and one-click cleanup.
* New      - Full content management from wp-admin.
* New      - Drag-drop category and space ordering.
* New      - Import from bbPress, wpForo, and Asgaros (batched with progress bar and resume).
* New      - Trust level threshold configuration.
* New      - Object caching (Redis/Memcached auto-detection).
* New      - Eager loading with batch queries (no N+1 problems).
* New      - Cursor-based pagination on all REST API endpoints.
* New      - MemberPress and Paid Memberships Pro integration.
* New      - Canonical URLs, Open Graph tags, JSON-LD schema markup.
* New      - XML sitemap providers for spaces and posts.
* New      - RTL stylesheet.
* New      - Translation-ready with `.pot` file.
* New      - WP-CLI commands.
* New      - 48+ REST API endpoints at `/wp-json/jetonomy/v1/`.
