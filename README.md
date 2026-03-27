# Jetonomy

Next-gen discussion platform for WordPress -- forums, Q&A, ideas, and more.

[![Tests](https://github.com/vapvarun/jetonomy/actions/workflows/tests.yml/badge.svg)](https://github.com/vapvarun/jetonomy/actions/workflows/tests.yml)
[![WordPress](https://img.shields.io/badge/WordPress-6.7%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

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

### Moderation
- Trust-based behavior gates (rate limits, link blocks for new accounts)
- Content flagging with one-click moderation actions
- Banned users management
- Space-level access rules and join policies

### Search & SEO
- Full-text search with type filtering
- Schema.org structured data (DiscussionForumPosting, QAPage)
- Open Graph and Twitter Cards
- XML sitemap inclusion

### Developer
- 48+ REST API endpoints with cursor-based pagination
- Template override system (`theme/jetonomy/` directory)
- Adapter pattern for search, email, membership, and real-time
- WordPress Interactivity API for real-time UI updates
- MemberPress and Paid Memberships Pro integration included

### Migration
- bbPress importer with dry-run and resume
- wpForo importer with dry-run and resume
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

## License

GPL v2 or later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

---

Built by [Wbcom Designs](https://wbcomdesigns.com/)
