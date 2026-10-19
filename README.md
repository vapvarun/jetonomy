<p align="center">
  <strong>Jetonomy</strong><br>
  Next-gen discussion platform for WordPress -- forums, Q&A, ideas, and more.
</p>

<p align="center">
  <a href="https://github.com/vapvarun/jetonomy/actions/workflows/ci.yml"><img src="https://github.com/vapvarun/jetonomy/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
  <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg?logo=php&logoColor=white" alt="PHP 8.1+"></a>
  <a href="https://wordpress.org/"><img src="https://img.shields.io/badge/WordPress-6.7%2B-21759B.svg?logo=wordpress&logoColor=white" alt="WordPress 6.7+"></a>
  <a href="https://wordpress.org/download/"><img src="https://img.shields.io/badge/Tested%20up%20to-WP%207.0-success" alt="Tested up to WP 7.0"></a>
  <a href="https://packagist.org/packages/wbcomdesigns/jetonomy"><img src="https://img.shields.io/badge/Composer-wbcomdesigns%2Fjetonomy-885630?logo=composer&logoColor=white" alt="Composer: wbcomdesigns/jetonomy"></a>
  <a href="https://www.gnu.org/licenses/gpl-2.0.html"><img src="https://img.shields.io/badge/License-GPLv2%2B-green.svg" alt="License: GPL v2+"></a>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHPUnit-1%2C100%2B%20tests-brightgreen?logo=testing-library&logoColor=white" alt="1,100+ PHPUnit tests">
  <img src="https://img.shields.io/badge/PHPStan-Level%205-brightgreen?logo=php&logoColor=white" alt="PHPStan Level 5">
  <img src="https://img.shields.io/badge/REST%20API-85%2B%20routes-blue?logo=json&logoColor=white" alt="85+ REST API routes">
  <img src="https://img.shields.io/badge/Security-OWASP%20tested-blue?logo=owasp&logoColor=white" alt="Security Tested">
</p>

<p align="center">
  <a href="https://app.instawp.io/launch?s=jetonomy&d=v2"><img src="https://img.shields.io/badge/Try%20Live%20Demo-Launch%20Sandbox-FF6B35?style=for-the-badge" alt="Try Live Demo"></a>
  &nbsp;
  <a href="https://wbcomdesigns.com/downloads/jetonomy/"><img src="https://img.shields.io/badge/Download-Free-brightgreen?style=for-the-badge&logo=wordpress&logoColor=white" alt="Download Free"></a>
  &nbsp;
  <a href="https://wbcomdesigns.com/downloads/jetonomy-pro/"><img src="https://img.shields.io/badge/Pro-17%20Extensions-7C3AED?style=for-the-badge" alt="Jetonomy Pro - 17 Extensions"></a>
  &nbsp;
  <a href="https://jetonomy.org/docs/"><img src="https://img.shields.io/badge/Docs-Read%20the%20Docs-blue?style=for-the-badge&logo=readthedocs&logoColor=white" alt="Documentation"></a>
</p>

---

## What is Jetonomy?

Jetonomy adds a fast, self-moderating discussion platform to any WordPress site. It stores forum data in dedicated database tables (not `wp_posts`), uses trust levels to automate moderation, and adapts to your theme via CSS custom properties.

**Free forever.** No feature locks, no nag screens, no premium wall on core features.

## Who it is for

Site owners who want forums, Q&A, idea boards or a social feed inside their own WordPress site: membership sites, course communities, product support forums and BuddyPress networks, including sites moving off bbPress or wpForo.

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
- Trust-based behavior gates for new accounts (rate limits on posts, replies and votes, plus upload and space-creation locks)
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
- 85+ REST routes, paginated with indexed `LIMIT`/`OFFSET`
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

1. Download the plugin from [wbcomdesigns.com](https://wbcomdesigns.com/downloads/jetonomy/) or the latest [GitHub release](https://github.com/vapvarun/jetonomy/releases)
2. Upload via **WordPress > Plugins > Add New > Upload Plugin**
3. Activate the plugin
4. Run the setup wizard to create your first spaces

Your community will be live at `yoursite.com/community/`.

### With Composer

Jetonomy is on [Packagist](https://packagist.org/packages/wbcomdesigns/jetonomy) as `wbcomdesigns/jetonomy` (type `wordpress-plugin`). Tell Composer where plugins go and allow `composer/installers`:

```json
{
  "extra": {
    "installer-paths": {
      "wp-content/plugins/{$name}/": ["type:wordpress-plugin"]
    }
  },
  "config": {
    "allow-plugins": { "composer/installers": true }
  }
}
```

Then run `composer require wbcomdesigns/jetonomy` and activate it with `wp plugin activate jetonomy`. Jetonomy uses its own autoloader, so no `vendor/autoload.php` include is needed.

## Jetonomy Pro

For growing communities that need more, [Jetonomy Pro](https://wbcomdesigns.com/downloads/jetonomy-pro/) adds 17 modular extensions:

| Extension | What it does |
|-----------|-------------|
| AI Integration | Spam detection, auto-moderation, reply suggestions, thread summaries - OpenAI, Anthropic, or self-hosted Ollama |
| Emoji Reactions | Slack-style reactions on posts and replies |
| Private Messaging | 1:1 and group conversations |
| Polls | Community voting within posts |
| File Attachments | Attach images, PDFs, and Office docs to posts and replies |
| Anonymous Posting | Let members author topics and replies without showing their identity |
| Site Announcements | Super-sticky announcements pinned across the community |
| Analytics Dashboard | Engagement graphs, top spaces, CSV export |
| Email Digests | Daily/weekly activity summaries |
| Web Push | Browser notifications |
| Webhooks | Signed JSON POST to any URL, such as Zapier, n8n or your own endpoint |
| Reply by Email | Reply to notifications without logging in |
| Custom Badges | Auto-award badges based on activity |
| Custom Fields | Profile and post custom fields |
| Advanced Moderation | Keyword filters, regex, spam scoring |
| SEO Pro | Per-space meta, Schema.org, sitemap controls |
| White Label | Replace all Jetonomy branding |

Each extension is independent -- enable only what you need. Disabled extensions load zero code.

Pricing, including lifetime plans: [jetonomy.org/pricing](https://jetonomy.org/pricing/)

## Documentation

- Docs: [jetonomy.org/docs](https://jetonomy.org/docs/) and [docs.wbcomdesigns.com/products/jetonomy](https://docs.wbcomdesigns.com/products/jetonomy/)
- Release notes: [Free](https://wbcomdesigns.com/release-notes/jetonomy/) and [Pro](https://wbcomdesigns.com/release-notes/jetonomy-pro/)
- Product site: [jetonomy.org](https://jetonomy.org/)

## Support

- [Documentation](https://jetonomy.org/docs/)
- [Support ticket](https://wbcomdesigns.com/support/#submit-ticket-section)
- [Feature Requests & Bug Reports](https://github.com/vapvarun/jetonomy/issues)

## Quality gates

Every push and pull request runs these jobs in [`.github/workflows/ci.yml`](.github/workflows/ci.yml):

| Gate | What it does |
|---|---|
| `php-lint` | `php -l` across the tree on PHP 8.1-8.4 |
| `phpunit` | Free standalone - every non-Pro testsuite |
| `coverage-gates` | Every shipped file belongs to a browser journey, and every journey was walked against the code being merged |
| `phpunit-with-pro` | Checks out Pro alongside free and runs the combined suite |
| `phpstan` | Static analysis at level 5, honouring the committed baseline |
| `wpcs` | WordPress Coding Standards |
| `eslint` | JavaScript lint |
| `surface-smoke` | Installs WordPress, cycles activation, runs `wp jetonomy qa-actions` and renders every admin page and front-end route |
| `plugin-check` | WordPress.org Plugin Check (PCP) |

Locally, `bin/local-ci.sh` runs the blocking set in one command (PHPStan, WPCS on
free *and* Pro, and the unit suite inside wp-env). Prefer the Docker suite over a
host run: the host database is usually already seeded, and a clean database has
caught bugs the host run reported green.

Three further gates run outside CI, before a tag:

- `php bin/audit-rest-routes.php includes/` - every mutation route must go through
  `REST_Auth`. Run it for `../jetonomy-pro/includes/` too.
- `wp jetonomy qa-actions` - live-stack smoke across REST, model, Pro and journey
  layers.
- `bin/build-release.sh` - the only supported way to build a release zip. It
  regenerates assets, triangulates the version across the header, the constant and
  `readme.txt`, boots the plugin in a WP stub to catch load-time fatals, and refuses
  to package if the browser-smoke report does not match the version being built.

## Contributing

Contributions are welcome. Please open an issue first to discuss what you'd like to change.

## Changelog

[`readme.txt`](readme.txt) is the changelog, and the [GitHub releases](https://github.com/vapvarun/jetonomy/releases) mirror it.

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [Wbcom Designs](https://wbcomdesigns.com/)
