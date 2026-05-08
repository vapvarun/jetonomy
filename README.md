<p align="center">
  <strong>Jetonomy</strong><br>
  Next-gen discussion platform for WordPress -- forums, Q&A, ideas, and more.
</p>

<p align="center">
  <a href="https://github.com/vapvarun/jetonomy/actions/workflows/tests.yml"><img src="https://github.com/vapvarun/jetonomy/actions/workflows/tests.yml/badge.svg" alt="Tests"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg?logo=php&logoColor=white" alt="PHP 8.1+"></a>
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.7%2B-21759B.svg?logo=wordpress&logoColor=white" alt="WordPress 6.7+"></a>
  <a href="https://img.shields.io/badge/Tested%20up%20to-WP%206.9-success"><img src="https://img.shields.io/badge/Tested%20up%20to-WP%206.9-success" alt="Tested up to WP 6.9"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2%2B-green.svg" alt="License: GPL v2+"></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHPUnit-226%20tests-brightgreen?logo=testing-library&logoColor=white" alt="226 Tests">
  <img src="https://img.shields.io/badge/PHPStan-Level%205-brightgreen?logo=php&logoColor=white" alt="PHPStan Level 5">
  <img src="https://img.shields.io/badge/REST%20API-61%2B%20endpoints-blue?logo=json&logoColor=white" alt="61+ REST API Endpoints">
  <img src="https://img.shields.io/badge/Security-OWASP%20tested-blue?logo=owasp&logoColor=white" alt="Security Tested">
</p>

<p align="center">
  <a href="https://app.instawp.io/launch?s=jetonomy&d=v2"><img src="https://img.shields.io/badge/Try%20Live%20Demo-Launch%20Sandbox-FF6B35?style=for-the-badge" alt="Try Live Demo"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy/"><img src="https://img.shields.io/badge/Download-Free-brightgreen?style=for-the-badge&logo=wordpress&logoColor=white" alt="Download Free"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy-pro/"><img src="https://img.shields.io/badge/Pro-14%20Extensions-7C3AED?style=for-the-badge" alt="Jetonomy Pro"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy/docs/"><img src="https://img.shields.io/badge/Docs-Read%20the%20Docs-blue?style=for-the-badge&logo=readthedocs&logoColor=white" alt="Documentation"></a>
</p>

---

## What is Jetonomy?

Jetonomy adds a fast, self-moderating discussion platform to any WordPress site. It stores forum data in dedicated database tables (not `wp_posts`), uses trust levels to automate moderation, and adapts to your theme via CSS custom properties.

**Free forever.** No feature locks, no nag screens, no premium wall on core features.

## Features

### Space Types
- **Forums** -- threaded discussions with replies
- **Q&A** -- questions with accepted answers
- **Ideas** -- feature voting and roadmap boards
- **Social Feed** -- activity-style posts

### Community
- 6 trust levels with automatic promotion
- Voting, reputation scores, and leaderboard
- User profiles with activity history and badges
- @mentions with notifications
- Post and reply subscriptions
- Flag/report system with moderation queue
- **Private topics** -- visible only to author and moderators
- **Topic prefixes** -- colored labels (Bug, Suggestion, Solved) configurable per space
- **Similar topic detection** -- see related topics as you type, before posting duplicates
- **Quote replies** -- click Quote on any reply for attributed blockquotes
- **Polls** -- add polls to any topic with real-time voting

### BuddyPress Integration (v1.2+)
- Link BuddyPress groups to forum spaces with automatic member sync
- Forum tab on BP group pages with topics and New Topic button
- Forum tab on BP member profiles with Posts, Replies, and Bookmarks sub-tabs
- Discussion Forum settings in group creation wizard and manage screen
- Space privacy auto-syncs with BP group privacy changes
- Linked group shown in forum sidebar About section

### LMS Integration (v1.1+)
- **LearnDash** -- course enrollment sync (supports LearnDash 5.x)
- **Tutor LMS** -- course enrollment sync with space access
- **LifterLMS** -- course and membership enrollment sync
- **Sensei LMS** -- enrollment status change sync
- **MasterStudy LMS** -- course enrollment sync
- Auto-create discussion space when a new course is published
- Course author assigned as space admin on auto-create
- Searchable autocomplete for 1000+ courses in access rules

### Moderation
- Trust-based behavior gates (rate limits, link blocks for new accounts)
- Content flagging with one-click moderation actions
- Banned users management
- Space-level access rules and join policies

### GDPR Compliance
- Personal data export for messages, reactions, badges, polls, and custom fields
- Personal data erasure through WordPress privacy tools

### Search & SEO
- Full-text search with type filtering
- Schema.org structured data (DiscussionForumPosting, QAPage)
- Open Graph and Twitter Cards
- XML sitemap inclusion
- Configurable community H1 heading for SEO

### Developer
- 61+ REST API endpoints with cursor-based pagination
- Template override system (`theme/jetonomy/` directory)
- Adapter pattern for search, email, membership, and real-time
- WordPress Interactivity API for real-time UI updates
- MemberPress, Paid Memberships Pro, and 5 LMS adapters included

### Migration
- bbPress importer with dry-run and resume
- wpForo importer with dry-run and resume (multi-board support)
- Live progress tracking, no record limit

## Requirements

- WordPress 6.7+
- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.3+

## Installation

1. Download the latest release from [Releases](https://github.com/vapvarun/jetonomy/releases)
2. Upload via **WordPress > Plugins > Add New > Upload Plugin**
3. Activate the plugin
4. Run the setup wizard to create your first spaces

Your community will be live at `yoursite.com/community/`.

## Jetonomy Pro

For growing communities that need more, [Jetonomy Pro](https://store.wbcomdesigns.com/jetonomy-pro/) adds 14 modular extensions:

| Extension | What it does |
|-----------|-------------|
| AI Integration | Spam detection, auto-moderation, reply suggestions, thread summaries — OpenAI, Anthropic, or self-hosted Ollama |
| Emoji Reactions | Slack-style reactions on posts and replies |
| Private Messaging | 1:1 and group conversations |
| Polls | Community voting within posts |
| Analytics Dashboard | Engagement graphs, top spaces, CSV export |
| Email Digests | Daily/weekly activity summaries |
| Web Push | Browser notifications |
| Webhooks | HTTP POST to Zapier, Slack, n8n |
| Reply by Email | Reply to notifications without logging in |
| Custom Badges | Auto-award badges based on activity |
| Custom Fields | Profile and post custom fields |
| Advanced Moderation | Keyword filters, regex, spam scoring |
| SEO Pro | Per-space meta, Schema.org, sitemap controls |
| White Label | Replace all Jetonomy branding |

Each extension is independent -- enable only what you need. Disabled extensions load zero code.

**Pricing:** Personal $69/yr | Developer $99/yr | Agency $199/yr | [Lifetime plans available](https://store.wbcomdesigns.com/jetonomy-pro/)

## Documentation

Full documentation is available at [store.wbcomdesigns.com/jetonomy/docs/](https://store.wbcomdesigns.com/jetonomy/docs/)

## Support

- [Documentation](https://store.wbcomdesigns.com/jetonomy/docs/)
- [Support](https://wbcomdesigns.com/support/)
- [Feature Requests & Bug Reports](https://github.com/vapvarun/jetonomy/issues)

## Contributing

Contributions are welcome. Please open an issue first to discuss what you'd like to change.

## Changelog

### 1.4.2 (May 2026)

**New content types**
- Show & Tell short-form feed spaces. Optional title, inline content cards.
- Ideas spaces ship a real roadmap with statuses (planned, in progress, shipped, declined).
- Q&A spaces let owners pin the accepted answer; an "Answered" badge surfaces on the space list.

**Performance and scale**
- Cleanup cron handlers (trust evaluation, expired restrictions, old notifications, scheduled posts) now process at most 500 rows per run. Filterable via `jetonomy_cron_batch_size`. Sites with large activity logs no longer time out.

**Multisite**
- Activating Jetonomy across a network now creates the required tables on every existing subsite, and on every new subsite created later. Previously only the current blog got tables.

**Translations and accessibility**
- Composer, login block, IA state, banned-member notice, header escape hint, prefix builder, and admin import flow are fully translatable.
- Keyboard focus indicators are visible everywhere; aria-labels added to filter, bulk-action, and select-all controls.

**Fixes**
- Posts/replies-per-page setting now controls the actual list length and the Load More click count.
- Vote controls hide when an admin disables voting on a space.
- Idea status changes notify the right people across the activity log, email digest, and in-app inbox.
- Native browser confirm dialogs swapped for in-product modals so admin actions feel consistent.
- Import progress AJAX requires the right capability, not just a nonce.
- Setup wizard redirect skips under WP-CLI / REST contexts so automation does not get bounced to the wizard.
- Settings save confirmations no longer disappear before the user can see them.
- Q&A: accepting a second answer now correctly clears the previously accepted reply (one accepted answer per post).

### 1.4.1 (April 2026)

**Public or private community**
- New Access Control mode in Settings: Public (anyone can read) or Private (every page requires sign-in). The mode applies to the whole front-end and to the REST API, so private really means private.
- The sign-in page itself stays reachable in private mode so guests can register or recover their account.
- Public mode is the default and is unchanged from 1.4.0; existing communities keep working without any setting changes.

**For people who run a space**
- New Activity Log admin page browses every audit event (post created, reply approved, member banned, role changed) with filters by user, type, and date range.
- New Revisions admin page browses every saved post / reply revision with a side-by-side diff between any two revisions.
- Two new REST endpoints for moderation tooling: `POST /jetonomy/v1/moderation/bulk` and `GET /jetonomy/v1/posts/{id}/flags`.

**For members**
- New Drafts tab at `/community/drafts/` lists every post you saved as a draft.
- New Bookmarks tab at `/community/bookmarks/` lists every post you bookmarked.
- Both are personal pages: require sign-in, excluded from search engines.

**Email templates editor**
- New Reset to default button on every notification template restores the shipped subject and body in one click.
- The Verification reminder template is now editable from the same screen.
- Single source of truth for defaults so reset always restores the exact copy the plugin ships with.

**Members who have not confirmed their email**
- New hourly nudge: members who registered but did not click the verification link receive a single follow-up email after 24 hours (configurable in Settings).

**Under the hood**
- Per-role REST access matrix is now a verifiable contract: `bin/access-matrix-check.sh` runs 78 checks across 6 roles in either public or private mode, gates the build.
- Manifest schema bumped to v2: every REST endpoint declares `auth`, `capability`, and `ownership_check` in `audit/manifest.json`.

**Bug fixes**
- Hidden spaces no longer leak from the homepage spaces list to non-admins.
- Multi-space moderators see every queue they own at `/community/mod/` instead of being redirected away.
- The Remove button on flagged content now actually removes the content.
- Voting works on every install (fixed a Pro analytics aggregator bug that corrupted the AJAX response on sites without the analytics table).
- Space cards render their icon correctly even when only the icon name was saved.
- The default qa-type space icon switched from a question-mark to an open book.
- New-post composer with Pro polls active no longer drops form fields between submit attempts.

### 1.4.0 (April 2026)

**For people who run a space**
- Edit a space from the front end — title, description, cover, icon, type, visibility, join policy, category, posts-per-page, prefixes
- Create a space from the front end. Pick which WordPress roles can use the form in Settings → Front-end space creation
- "My Spaces" page lists every space you run + every space you're in
- Visual icon picker — 16 icons with search and "Show more" for 8 extras
- Cover image uploader works without the WordPress upload-files permission
- Role dropdown can't accidentally orphan a space — no self-demote, no last-admin-out

**For members**
- @mention autocomplete in the composer
- "New" pill on threads with replies you haven't read
- "Managed by" sidebar card on every space
- Admin / Mod pills next to staff names on posts and replies
- Layout panel (Container Width / Sidebar / Padding) fits Jetonomy to your theme

**Search and sharing**
- Every public page now ships full search and social cards (title, meta description, canonical, Open Graph, Twitter / X, structured data)
- Smart fallback share image when a page has no image of its own
- Pages not meant for search (moderation, search, composer, notifications) excluded automatically
- Richer structured data — Sitelinks Searchbox on home, Person cards on profiles, Collection indexes on spaces and tags, breadcrumbs everywhere
- Settings → SEO grew Twitter / X handle, default share image, sitemap link
- Image alt text fills in automatically on upload

**Sign-in**
- Login, register, forgot-password — all faster, all in-page (no wp-login.php bounce)
- Captcha now actually fires on signup when configured

**Polish**
- In-product confirms and prompts replace browser pop-ups; WCAG 2.1 AA accessible
- All 8 Jetonomy blocks now visible in the block inserter
- Shortcodes render styled on any page or page-builder canvas
- `[jetonomy_widget id="..."]` shortcode embeds any classic widget on any page

**Bug fixes**
- Category dropdown on Edit Space is no longer empty; "No category" saves correctly
- Space moderators without a WordPress editor role can again use the inline mod tools
- Join-request notifications link to the right place per recipient
- Notifications page no longer auto-marks everything read on render
- GDPR export contains the user's display name
- Tags on post cards link to the tag page; tag page paginates instead of capping at 30
- Share dropdown closes when you scroll
- Auth rate-limit window doesn't reset on retry
- Banned users can no longer log in (security)
- Private-post structured data no longer leaks to anonymous visitors (security)

### 1.3.8 (April 2026)
- New: Cross-space moderation dashboard at `/community/mod/` for site admins; per-space moderation queue at `/community/s/:slug/mod/` for space admins and moderators
- New: Front-end member role management on `/community/s/:slug/members/` so space admins can promote members to moderator or admin without going through wp-admin
- New: FluentCommunity integration with paired spaces, two-way profile cross-links, unified avatars, member sync, activity broadcast, and a comment-to-reply bridge
- New: BuddyPress group integration broadcasts new topics to the paired group's activity stream and round-trips comments back as forum replies
- Fix: Plugin headings, accent tints, locked-space banner, and warning notices stay readable on dark-panel themes (Reign etc.); dark mode now follows the theme only and no longer auto-applies based on the visitor's OS preference
- Fix: Sort modes (oldest, newest, unanswered) return the correct topic set; space settings merge on save instead of overwriting; similar-topics widget no longer leaks HTML entities; sitewide search ranks by relevance; rewrite rules flush on activation
- Fix: Profile tabs no longer clip Drafts on mobile; share dropdown closes on scroll; long words wrap on mobile; member upload permissions; vote-flip optimistic score; TikTok oEmbed renders as an iframe; copy-link feedback when the browser blocks clipboard writes
- Polish: Fourteen translation-ready strings rewritten for cleaner localisation; the Interactivity API exposes `isLoggedIn` and `loginUrl` so blocks and embeds can render the right CTA without extra REST calls

### 1.3.7 (April 2026)
- Fix: Reaction picker stays visible across browsers, CDNs, and security plugins that strip the WordPress emoji loader; reactions now ship as bundled colour SVG icons
- Fix: Posting a Polls topic without a body shows the same friendly inline error as a regular topic instead of silently doing nothing
- Improved: Plain-language polish across admin labels and emails (Pro extensions, AI provider settings, GDPR exporter, license status, email digest copy)

### 1.3.6 (April 2026)
- New: Private Messaging recipient typeahead autocompletes from members of spaces you share with the recipient
- Fix (security): `POST /conversations` enforces shared-space scope on every recipient, matching the UI
- Fix: "Message" actions on the Top Members widget and profile hover cards now open the working compose flow

### 1.3.5 (April 2026)
- New: Jetonomy Navigation block — permission-aware Category/Space tree for sidebars, scales to thousands of spaces
- New: Jetonomy Login block — inline login/register panel with rate limiting and nonce protection (renders nothing for logged-in users — no layout shift)
- Fix: Inline editor no longer collapses paragraphs into a single run-on line on save; historical flattened posts render with paragraphs restored on next page load
- Release hygiene: `bin/build-release.sh` is now the only path to a release zip — enforces clean-tree gate, version triangulation (Version header + constant + readme Stable tag), production composer install, `php -l` on every staged file, smoke test through `plugins_loaded` + `init`, zip/re-extract/re-smoke-test

### 1.3.4 (April 2026)
- New: Akismet bypass for admin/space-admin/moderator replies — staff answers no longer quarantined on support communities
- New: One-click "Approve" / "Not Spam" action in Replies and Posts admin lists for content held for moderation
- New: Moderation queue REST endpoint accepts `status=pending|spam|all`
- New: Bulk trust-level promotion via admin API — useful after migrations and onboarding batches
- New: Spaces now track real membership — posting or replying in an open space auto-joins the author; one-time upgrade back-fills historical authors
- Fix: Approve/spam/trash actions from the admin list now correctly update denormalized topic, reply, and member counters
- New: Admin counter-rebuild tool extended to repair member-count drift (the 1.3.3 tool now covers members too)

### 1.3.3 (April 2026)
- New: Access Control collapsed from three options to two — "Public community" and "Private community"; existing installs migrated automatically
- New: Admin counter rebuild button — refresh topic, reply, and vote counters when they drift after a bulk import or manual DB change (no WP-CLI required)
- New: Imported/seeded topics preserve their original `created_at` timestamp instead of being stamped with today's date
- Fix: "Default Space Type" setting now actually applies when creating new spaces (both admin UI and REST API); previously defaulted to Forum regardless

### 1.3.2 (April 2026)
- Fix: Setup wizard PHP deprecation warnings under PHP 8.1+ with WordPress 6.4+ (`strip_tags(null)`, `print_emoji_styles`, `wp_admin_bar_header`)
- New: `jetonomy_new_post_submit_action` filter — Pro extensions can intercept the new-post form submit URL without mutating DOM after hydration

### 1.3.1 (April 2026)
- Fix: Theme button hover styles no longer bleed into Jetonomy button states — scoped CSS reset for BuddyX/Reign compatibility

### 1.3.0 (April 2026)

**Share forum threads anywhere**
- New: Outbound oEmbed — thread URLs unfurl in Slack, Twitter/X, Discord, Facebook, and other WordPress sites with a rich preview card (title, author, excerpt, thumbnail)
- New: Inbound embed expansion — pasted YouTube, Vimeo, SoundCloud, Spotify, TED Talks and other supported links in posts or replies render as embedded players instead of plain URLs
- New: Instagram + Facebook embed support via optional Meta Developer App credentials — Settings → SEO → Social Embeds card with a collapsible 6-step setup guide; empty credentials = graceful plain-link fallback
- New: Richer Open Graph + Twitter Card meta on every thread page — `og:type=article`, `article:author`, `article:published_time`, `article:section`, first-inline-image as `og:image`

**Theme compatibility**
- New: BuddyX, BuddyX Pro, and Reign theme color + dark mode bridge — forum accent and dark scheme automatically match the active theme with zero custom CSS
- New: Unified Design Token Bridge — `--jt-*` tokens reference BuddyNext, then theme.json, then hardcoded fallbacks

**AI moderation**
- New: AI Adapter Layer — pluggable interface for AI providers with built-in self-hosted Ollama support
- New: AI-powered spam detection for new posts and replies (free, local, no API costs)

**Mobile UX pass**
- New: `docs/DESIGN-SYSTEM.md` — long-term UI/UX source of truth (breakpoints, typography scale, spacing scale, component patterns, anti-patterns)
- New: Token scale — `--jt-space-1..12`, `--jt-text-2xs..3xl`, `--jt-tap` (40px)
- New: Community nav uses Lucide icons with `title` tooltips on mobile, icon+label on desktop/tablet
- New: Post + reply action bars are uniformly icon-only on mobile (vote / share / bookmark / quote / report / more / react)
- Fix: Topic listing title/count column rebalance on mobile so titles get 76% of the row width
- Fix: Post meta row (`.jt-meta`) — "3 weeks ago" no longer breaks mid-word on narrow viewports
- Fix: Firefox time picker on scheduled publish form — split `datetime-local` into separate `date` + `time` inputs so Firefox shows proper native pickers
- Fix: Publish mode menu flash-of-visible-content on the new topic form
- Fix: Preact/Interactivity API hydration console warnings from inline `onclick` attributes — replaced with delegated handlers using `data-jt-href`
- Fix: More menu 3-dots dropdown now visible on touch devices (hover-reveal was hiding it)

**Extensibility**
- New: 6 ad/content injection hooks for sidebar and reply flow (`jetonomy_sidebar_*`, `jetonomy_reply_*`, `jetonomy_sidebar_after_about`)
- New: `before_delete_*` filters on every model — third-party plugins can reject deletions by returning `WP_Error`
- New: Query args filters on every model list method (`jetonomy_posts_query_args`, `jetonomy_spaces_query_args`, etc.)
- New: Base slug 301 redirect — changing community base in settings now permanently redirects old URLs for SEO continuity
- New: WP-CLI command module — 13 command roots covering every user/admin journey, plus 5 bundled scenarios (`wp jetonomy scenario run <name>`)

**Quality + CI**
- New: GitHub Actions CI pipeline — PHP Lint (8.1–8.4), WPCS, PHPStan level 5, Plugin Check (PCP), PHPUnit matrix
- New: `composer test:free` and `composer test:combo` scripts
- Improvement: WP_Error checks at every model caller site — prevents fatal errors when `before_delete` hooks reject an operation
- Improvement: `has_more` pagination accuracy across every list endpoint
- Improvement: InnoDB engine enforced on all 23 custom tables (migration 1.2.3)
- Improvement: Vote operations wrapped in DB transactions
- Improvement: Spaces N+1 query eliminated — visibility filter moved to SQL `LEFT JOIN`
- Improvement: `jt_notifications.object_type` ENUM extended with `'message'` so Pro private-messaging notifications persist cleanly

**Bug fixes**
- Fix: `posts_per_page` space setting now actually applies to the topic listing
- Fix: Guarded EDD Software Licensing SDK's `plugins_api_filter` against non-object `$_data`
- Fix: Space settings merge (not replace) on save — previously full JSON replacement dropped keys
- Fix: 10 earlier customer-reported bugs — BP compat crash, notification defaults, vote state indicator, admin View link, join request admin UI, post scheduling defaults, settings write consistency, REST nonce handling, fetch cookie credentials
- Fix: PHP 8.1 `bool` return type compat
- Fix: Double reply counter increment on new reply
- Fix: Space settings cache invalidation

### 1.2.0 (April 2026)
- New: Private Topics -- mark topics visible only to author and moderators
- New: Topic Prefixes -- colored labels (Bug, Suggestion, Solved) configurable per space
- New: Similar Topics -- see related topics as you type, before posting duplicates
- New: Quote Replies -- click Quote on any reply for attributed blockquotes
- New: BuddyPress Integration -- link BP Groups to forum spaces with automatic member sync
- New: Forum tab in BP Group pages with topics and New Topic button
- New: Forum tab on BP Member profiles with Posts, Replies, and Bookmarks sub-tabs
- New: Discussion Forum settings in group creation wizard and manage screen
- New: Linked group shown in sidebar About section
- Improvement: Third-party admin notices hidden on Jetonomy pages
- Improvement: Space privacy auto-syncs with BP group privacy changes
- Improvement: wpForo multi-board import support
- Fix: Topic title placeholder alignment on all themes
- Fix: New Topic button hidden for logged-out visitors
- Fix: Spaces can only be linked to one group at a time

### 1.1.0 (March 2026)
- New: Configurable Community Title setting for H1 on community home page
- New: Adapter-specific rule type options in Access Rules (e.g. "Tutor Course", "LearnDash Course")
- New: Searchable autocomplete for membership levels -- scales to 1000+ courses
- New: Human-readable labels in access rules table -- shows course names instead of raw IDs
- New: Sync Members button to pull in existing enrolled users when creating a rule
- Fix: H1 heading added to community home page for SEO and accessibility
- Fix: Membership deactivation now fully removes space access instead of downgrading to viewer
- Improvement: Priority column hidden from access rules UI for cleaner admin experience
- Improvement: Action buttons with icons (Sync Members, Delete) in access rules table

### 1.0.1
- Fix: Renamed internal `.container` to `.jt-container` to prevent CSS class collisions
- Fix: Community app wrapper fills theme flex/grid parents correctly
- Fix: Container width auto-detects from theme settings
- Fix: Hide theme page title bars on community pages
- Tested with 12 popular themes

### 1.0.0
- Initial release

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [Wbcom Designs](https://wbcomdesigns.com/)
