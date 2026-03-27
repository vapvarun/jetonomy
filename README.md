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
  <a href="https://store.wbcomdesigns.com/jetonomy/"><img src="https://img.shields.io/badge/Download-Free-brightgreen?style=for-the-badge&logo=wordpress&logoColor=white" alt="Download Free"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy/docs/"><img src="https://img.shields.io/badge/Docs-Read%20the%20Docs-blue?style=for-the-badge&logo=readthedocs&logoColor=white" alt="Documentation"></a>
  &nbsp;
  <a href="https://store.wbcomdesigns.com/jetonomy-pro/"><img src="https://img.shields.io/badge/Pro-13%20Extensions-7C3AED?style=for-the-badge" alt="Jetonomy Pro"></a>
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
