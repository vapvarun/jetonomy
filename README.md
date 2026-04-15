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
  <img src="https://img.shields.io/badge/PHPUnit-194%20tests-brightgreen?logo=testing-library&logoColor=white" alt="194 Tests">
  <img src="https://img.shields.io/badge/PHPStan-Level%205-brightgreen?logo=php&logoColor=white" alt="PHPStan Level 5">
  <img src="https://img.shields.io/badge/REST%20API-61%2B%20endpoints-blue?logo=json&logoColor=white" alt="61+ REST API Endpoints">
  <img src="https://img.shields.io/badge/Security-OWASP%20tested-blue?logo=owasp&logoColor=white" alt="Security Tested">
</p>

<p align="center">
  <a href="https://app.instawp.io/launch?s=jetonomy&d=v2"><img src="https://img.shields.io/badge/Try%20Live%20Demo-Launch%20Sandbox-FF6B35?style=for-the-badge" alt="Try Live Demo"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy/"><img src="https://img.shields.io/badge/Download-Free-brightgreen?style=for-the-badge&logo=wordpress&logoColor=white" alt="Download Free"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy-pro/"><img src="https://img.shields.io/badge/Pro-13%20Extensions-7C3AED?style=for-the-badge" alt="Jetonomy Pro"></a>
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

For growing communities that need more, [Jetonomy Pro](https://store.wbcomdesigns.com/jetonomy-pro/) adds 13 modular extensions:

| Extension | What it does |
|-----------|-------------|
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

### 1.3.0 (April 2026)

**Share forum threads anywhere**
- New: Outbound oEmbed — thread URLs unfurl in Slack, Twitter/X, Discord, Facebook, and other WordPress sites with a rich preview card (title, author, excerpt, thumbnail)
- New: Inbound embed expansion — pasted YouTube, Vimeo, SoundCloud, Spotify, TED Talks and other supported links in posts or replies render as embedded players instead of plain URLs
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
