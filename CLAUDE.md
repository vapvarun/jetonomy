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
Categories, Spaces, Posts, Replies, Votes, UserProfiles, Notifications, Subscriptions, ReadStatus, SpaceMembers, Tags, PostTags, SpaceTags, SpaceTagMap, UserInterests, ActivityLog, Restrictions, AccessRules, Flags, Revisions, JoinRequests, InviteLinks

## Recent Changes
| Date | Commit | Summary |
|---|---|---|
| 2026-03-20 | pending | Human-readable activity feed, activity backfill, uninstall.php, model methods, Pro abilities rewrite |
| 2026-03-20 | `2c554ea` | Admin Content page (post/reply management), realistic demo data, demo cleanup |
| 2026-03-20 | `49381a2` | WordPress Abilities API (18 abilities) + centralized Activity Tracker |
| 2026-03-19 | `ea1dfe2` | Fix Spaces admin page — unregistered capability |
| 2026-03-19 | `2043cfb` | Enterprise import UX: completion tracking, resume on failure, step indicators |
| 2026-03-19 | `c2440f8` | G4-G10: RTL, quote text, hover cards, IP ban, shortcuts, emoji picker, invite links |
| 2026-03-19 | `439553d` | Cache layer, eager loading, cursor-based pagination |

## Key Files (recent additions)
| Path | Purpose |
|---|---|
| `includes/class-abilities.php` | WP Abilities API — 18 abilities in 5 categories |
| `includes/class-activity-tracker.php` | Centralized event logging via hooks |
| `includes/admin/views/content.php` | Admin post/reply management page |
| `uninstall.php` | Clean removal of all tables, options, caps, cron |

## Coding Patterns
- All data access via model classes — no raw SQL outside `includes/db/` (exception: Abilities execute callbacks for cross-table queries)
- Denormalized counters updated on write (reply_count, post_count, vote_score)
- Permission checks via `Permission_Engine::can($user_id, $action, $space_id)`
- Content stored as sanitized HTML (`wp_kses_post`), plain text copy for FULLTEXT search
- All external integrations via adapter interfaces
- Templates overridable via `theme/jetonomy/` directory
- Zero inline styles in templates (except truly dynamic values like kanban column colors)
- Trust level badges use `data-jt-tl` attribute selectors for background color
- Rewrite rules auto-flush on first load via `jetonomy_permalinks_flushed` option
- Activity logging via `Activity_Tracker` hooks — no direct `ActivityLog::log()` in controllers
- Demo data tracked in `jetonomy_demo_data` option for one-click cleanup
- Activity backfill runs automatically once via `jetonomy_activity_backfilled` flag

## Basecamp Board

- **Project ID**: `46596502`
- **Board ID**: `9706083020`
- **URL**: https://3.basecamp.com/5798509/buckets/46596502/card_tables/9706083020

| Column | ID | Cards |
|--------|----|-------|
| Triage | `9706083021` | 0 |
| Not now | `9706083022` | 0 |
| Scope | `9706083326` | 0 |
| Figuring it out | `9706083023` | 0 |
| Bugs | `9706083723` | 12 |
| UI | `9706189351` | 3 |
| In progress | `9706083024` | 0 |
| In Testing | `9706083581` | 0 |
| Done | `9706083025` | 0 |
