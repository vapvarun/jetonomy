# Jetonomy — WordPress Forum Plugin

## Quick Reference
- **Type**: WordPress Plugin (forum, Q&A, ideas, social feed)
- **PHP**: 8.1+ required
- **WP**: 6.7+ required
- **Namespace**: `Jetonomy\`
- **Table prefix**: `jt_` (21 custom tables)
- **REST API**: `jetonomy/v1` (35+ endpoints)

## Architecture
- **Database**: Custom MySQL tables via `dbDelta()` — NOT WordPress CPTs
- **Models**: `includes/models/` — all extend abstract `Model` class
- **Permissions**: 3-layer system (WP Caps → Space Roles → Trust Levels)
- **API**: `includes/api/` — all extend `Base_Controller` → `WP_REST_Controller`
- **Frontend**: PHP templates + WP Interactivity API + CSS Custom Properties
- **Adapters**: Universal adapter pattern for membership, search, real-time, email

## Key Files
| Path | Purpose |
|---|---|
| `jetonomy.php` | Main plugin file, constants, bootstrap |
| `includes/class-jetonomy.php` | Singleton, activation, dependency loading |
| `includes/class-router.php` | URL rewrite rules for /community/* |
| `includes/class-template-loader.php` | Template resolution with theme overrides |
| `includes/db/class-schema.php` | All 21 table definitions |
| `includes/db/class-migrator.php` | Version-based schema migrations |
| `includes/models/` | 15 model classes (Category, Space, Post, Reply, Vote, etc.) |
| `includes/permissions/class-permission-engine.php` | 3-layer permission resolver |
| `includes/trust/` | Trust levels (0-5), reputation calculator, auto-evaluator |
| `includes/api/` | 12 REST API controllers |
| `includes/adapters/` | 4 interfaces + WP Roles, Polling, wp_mail, MemberPress, PMPro adapters |
| `includes/notifications/class-notifier.php` | Event-driven notification dispatcher |
| `includes/import/` | bbPress + wpForo import tools |
| `templates/` | 12 views + 6 partials (theme-overridable) |
| `assets/css/jetonomy.css` | Theme-adaptive CSS (inherits from theme.json) |
| `assets/js/view.js` | Interactivity API store (voting, sorting, polling) |

## Documentation
- **Design Spec**: `docs/specs/2026-03-17-jetonomy-forum-plugin-design.md`
- **Implementation Plans**: `docs/plans/`
- **Design Prototypes**: `docs/prototype/` (open HTML files in browser)

## URL Structure
```
/community/                     → Home
/community/category/:slug/      → Category
/community/s/:slug/             → Space (topic listing)
/community/s/:slug/t/:slug/     → Single post + replies
/community/s/:slug/new/         → New post in space
/community/s/:slug/members/     → Space members
/community/s/:slug/roadmap/     → Space roadmap (ideas)
/community/u/:login/            → User profile
/community/u/:login/edit/       → Edit profile
/community/tag/:slug/           → Tag view
/community/search/              → Search
/community/leaderboard/         → Leaderboard
/community/notifications/       → Notifications
/community/mod/                 → Moderation (admin)
```

## Database Tables
Categories, Spaces, Posts, Replies, Votes, UserProfiles, Notifications, Subscriptions, ReadStatus, SpaceMembers, Tags, PostTags, SpaceTags, SpaceTagMap, UserInterests, ActivityLog, Restrictions, AccessRules, Flags, Revisions, JoinRequests

## Recent Changes
| Date | Commit | Summary |
|---|---|---|
| 2026-03-18 | `efdd455` | Fix font sizing — remove 32 unnecessary overrides, convert 45 from em to rem to prevent compounding |
| 2026-03-18 | `97002fe` | Remove all hardcoded px font-sizes — inherit from theme, use relative em units only |
| 2026-03-18 | `26fb607` | QA pass: inline style cleanup (60+ CSS classes), tag URL fix, rewrite flush, IA state init |
| 2026-03-18 | `d391681` | Unit + integration tests for models, permissions, trust, schema, API |
| 2026-03-18 | `3335ecb` | Asgaros Forum importer, i18n POT template, readme improvements |
| 2026-03-18 | `5633c98` | Fix 9 critical breaks: reply submission, permissions, notifications, search, trust |

## Coding Patterns
- All data access via model classes — no raw SQL outside `includes/db/`
- Denormalized counters updated on write (reply_count, post_count, vote_score)
- Permission checks via `Permission_Engine::can($user_id, $action, $space_id)`
- Content stored as sanitized HTML (`wp_kses_post`), plain text copy for FULLTEXT search
- All external integrations via adapter interfaces
- Templates overridable via `theme/jetonomy/` directory
- Zero inline styles in templates (except truly dynamic values like kanban column colors)
- Trust level badges use `data-jt-tl` attribute selectors for background color
- Rewrite rules auto-flush on first load via `jetonomy_permalinks_flushed` option

## Release Phasing
- **v1.0**: Forum + Q&A, flat replies, trust levels, MemberPress/PMPro, bbPress/wpForo import
- **v1.1**: Threaded replies, badges, leaderboards, email digests
- **v1.2**: Ideas module, emoji reactions, polls, private messaging
- **v2.0**: Social feed, real-time push, advanced search, Slack/Discord bridge, PWA
