# Jetonomy — Plugin Architecture Reference

> **Generated:** 2026-03-24 | **Scope:** hybrid | **Version:** 1.0.0
> **PHP:** 8.1+ | **WordPress:** 6.7+ | **Tables:** 22 custom | **REST endpoints:** 42 | **AJAX actions:** 34

---

## Table of Contents

1. [Overview](#1-overview)
2. [Bootstrap & Lifecycle](#2-bootstrap--lifecycle)
3. [Autoloader & Namespaces](#3-autoloader--namespaces)
4. [Directory Structure](#4-directory-structure)
5. [Database Layer](#5-database-layer)
6. [Model Layer](#6-model-layer)
7. [Permission System](#7-permission-system)
8. [Trust & Reputation System](#8-trust--reputation-system)
9. [REST API](#9-rest-api)
10. [Admin Layer](#10-admin-layer)
11. [AJAX Handlers](#11-ajax-handlers)
12. [Adapter System](#12-adapter-system)
13. [Frontend (Templates + Interactivity API)](#13-frontend-templates--interactivity-api)
14. [Notifications](#14-notifications)
15. [Activity Tracker](#15-activity-tracker)
16. [Import System](#16-import-system)
17. [SEO Layer](#17-seo-layer)
18. [WordPress Abilities API](#18-wordpress-abilities-api)
19. [Cron Jobs](#19-cron-jobs)
20. [CLI Commands](#20-cli-commands)
21. [Hook Reference](#21-hook-reference)
22. [Options & User Meta](#22-options--user-meta)
23. [Naming Conventions](#23-naming-conventions)
24. [Extension Points for Jetonomy Pro](#24-extension-points-for-jetonomy-pro)

---

## 1. Overview

Jetonomy is a next-gen discussion platform for WordPress. It provides **forums, Q&A boards, idea boards, and social feeds** through a single plugin. Key architectural decisions:

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Storage | Custom MySQL tables (22) | Performance at 10,000+ posts/space; CPTs cannot scale |
| API | WP REST API (`jetonomy/v1`) | 42 endpoints — clean decoupling of data from views |
| Frontend | PHP templates + WP Interactivity API | SSR for SEO; reactive for UX |
| Permissions | 3-layer engine (ban → WP caps → space roles) | Fine-grained control without custom role plugins |
| Integrations | Universal adapter interfaces | Swap membership/email/realtime providers without touching core |
| Pro tier | Separate plugin (`jetonomy-pro`) | Independent versioning; free plugin is fully functional |

---

## 2. Bootstrap & Lifecycle

### Entry Point: `jetonomy.php`

```
jetonomy.php
├── defines JETONOMY_VERSION, JETONOMY_DB_VERSION, JETONOMY_FILE, JETONOMY_DIR, JETONOMY_URL
├── requires includes/class-autoloader.php → registers spl_autoload
├── requires includes/class-jetonomy.php
├── function jetonomy() → Jetonomy\Jetonomy::instance()
└── WP_CLI::add_command('jetonomy', 'Jetonomy\CLI')  (if WP-CLI available)
```

### `Jetonomy\Jetonomy` (Singleton)

| Hook | Priority | Callback |
|------|----------|----------|
| `init` | 1 | `load_textdomain()` — must be priority 1 to beat `wp_cron()` at priority 10 |
| `plugins_loaded` | 10 | `init()` — loads all dependencies |
| `wp_theme_json_data_default` | — | `register_plugin_theme_json()` |
| activation | — | `activate()` — dbDelta, capabilities, cron, redirect |
| deactivation | — | `deactivate()` — unschedule cron, flush rewrites |

### `init()` sequence

```
init()
├── maybe_redirect_to_setup()    — one-time activation redirect
├── check_db_version()           — runs Migrator if version < JETONOMY_DB_VERSION
├── load_dependencies()
│   ├── require functions.php
│   ├── new Router()             — register rewrite rules
│   ├── flush_rewrite_rules()    — once per version (keyed option)
│   ├── Capabilities::register() — once per version (keyed option)
│   ├── new API\Api()
│   ├── Adapter_Registry::init_defaults()
│   ├── Adapter_Registry::register_email('wp-mail', ...)
│   ├── Adapter_Registry::register_search('fulltext', ...)
│   ├── new MemberPress_Adapter() (if MEPR_VERSION defined)
│   ├── new PMPro_Adapter()       (if PMPRO_VERSION defined)
│   ├── new Notifications\Notifier()
│   ├── new Cron()
│   ├── new Privacy()
│   ├── new SEO\Sitemap()
│   ├── new SEO\Schema_Markup()
│   ├── new Nav_Menus()
│   ├── new Media()
│   ├── new Activity_Tracker()
│   ├── new Abilities()
│   ├── Import_Manager::init()
│   └── new Admin\Admin()  (if is_admin())
└── maybe_backfill_activity()    — one-time backfill for pre-Activity_Tracker installs
```

---

## 3. Autoloader & Namespaces

### Custom PSR-4-style Autoloader

File: `includes/class-autoloader.php`

**Namespace → Directory map** (order matters — more specific entries first):

| Namespace prefix | Directory |
|-----------------|-----------|
| `Jetonomy\Models\` | `includes/models/` |
| `Jetonomy\Permissions\` | `includes/permissions/` |
| `Jetonomy\Trust\` | `includes/trust/` |
| `Jetonomy\API\` | `includes/api/` |
| `Jetonomy\Adapters\` | `includes/adapters/` |
| `Jetonomy\Search\` | `includes/search/` |
| `Jetonomy\Moderation\` | `includes/moderation/` |
| `Jetonomy\Notifications\` | `includes/notifications/` |
| `Jetonomy\Import\` | `includes/import/` |
| `Jetonomy\Admin\` | `includes/admin/` |
| `Jetonomy\SEO\` | `includes/seo/` |
| `Jetonomy\DB\` | `includes/db/` |
| `Jetonomy\DB\Migrations\` | `includes/db/migrations/` |
| `Jetonomy\` | `includes/` |

**IMPORTANT — Admin AJAX sub-namespace (pending):** The Admin split plan adds `Jetonomy\Admin\Ajax\` → `includes/admin/ajax/` as a new entry. This entry **must appear BEFORE** `Jetonomy\Admin\` in the map to resolve correctly.

**Class → filename conversion** (`class_to_file()`):
- `Permission_Engine` → `permission-engine` → `class-permission-engine.php`
- `WP_Mail_Adapter` → `wp-mail-adapter` → `class-wp-mail-adapter.php`
- `Base_Controller` → `base-controller` → `class-base-controller.php`
- `Migration_1_0_0` → `migration_1_0_0` → `class-migration_1_0_0.php` (numeric parts preserve underscores)

Both `class-*.php` and `interface-*.php` prefixes are attempted automatically.

---

## 4. Directory Structure

```
jetonomy/
├── jetonomy.php                  # Bootstrap, constants, WP-CLI registration
├── uninstall.php                 # Drop tables, options, caps on uninstall
├── theme.json                    # Baseline typography/spacing for classic themes
├── assets/
│   ├── css/
│   │   ├── jetonomy.css          # Frontend styles (CSS custom properties)
│   │   └── admin.css             # Admin UI styles
│   └── js/
│       ├── view.js               # Interactivity API store (voting, sorting, polls)
│       ├── admin.js              # Admin AJAX UI
│       └── composer.js           # Rich text composer
├── includes/
│   ├── class-jetonomy.php        # Main singleton
│   ├── class-autoloader.php      # Custom PSR-4 autoloader
│   ├── class-router.php          # URL rewrite rules
│   ├── class-template-loader.php # Template resolution (theme overridable)
│   ├── class-cache.php           # Thin in-memory + WP transient cache
│   ├── class-cron.php            # Cron event registration + handlers
│   ├── class-privacy.php         # GDPR privacy hooks
│   ├── class-media.php           # Image upload AJAX
│   ├── class-nav-menus.php       # Community nav menu items
│   ├── class-abilities.php       # WP Abilities API (18 abilities)
│   ├── class-activity-tracker.php# Centralized activity log hooks
│   ├── class-mentions.php        # @mention parsing
│   ├── class-embeds.php          # oEmbed handling in content
│   ├── class-cli.php             # WP-CLI commands
│   ├── functions.php             # Global helpers: table(), now(), get_profile_url(), etc.
│   ├── admin/
│   │   ├── class-admin.php       # Admin menu, assets, settings, AJAX (2,100+ lines — NEEDS SPLIT)
│   │   └── views/                # Admin page PHP templates
│   │       ├── dashboard.php
│   │       ├── categories.php
│   │       ├── spaces.php
│   │       ├── space-edit.php
│   │       ├── content.php
│   │       ├── users.php
│   │       ├── moderation.php
│   │       ├── settings.php
│   │       ├── import.php
│   │       ├── leaderboard.php
│   │       ├── activity.php
│   │       └── setup.php
│   ├── api/
│   │   ├── class-api.php         # Registers all controllers at rest_api_init
│   │   ├── class-base-controller.php # Shared helpers (auth, pagination, error codes)
│   │   └── class-*-controller.php    # 13 controllers
│   ├── models/
│   │   ├── class-model.php       # Abstract: find/insert/update/delete/count
│   │   └── class-*.php           # 18 concrete models
│   ├── permissions/
│   │   ├── class-permission-engine.php # 3-layer resolver (ban → cap → space role)
│   │   ├── class-capabilities.php      # WP capability registration
│   │   └── class-rate-limiter.php      # Request rate limiting
│   ├── trust/
│   │   ├── class-trust-levels.php   # Trust level definitions (0–5)
│   │   ├── class-trust-evaluator.php # Auto-evaluation logic
│   │   └── class-reputation.php     # Point calculations per action
│   ├── adapters/
│   │   ├── class-adapter-registry.php       # Static registry for all adapter types
│   │   ├── interface-membership-adapter.php
│   │   ├── interface-email-adapter.php
│   │   ├── interface-realtime-adapter.php
│   │   ├── interface-search-adapter.php
│   │   ├── class-wp-roles-adapter.php       # Default membership (WP roles)
│   │   ├── class-wp-mail-adapter.php        # Default email (wp_mail)
│   │   ├── class-polling-adapter.php        # Default realtime (HTTP polling)
│   │   ├── class-member-press-adapter.php   # MemberPress (conditional)
│   │   └── class-pmpro-adapter.php          # PMPro (conditional)
│   ├── search/
│   │   └── class-fulltext-search.php        # MySQL FULLTEXT search
│   ├── db/
│   │   ├── class-schema.php           # 22 CREATE TABLE definitions
│   │   ├── class-migrator.php         # Version-based migration runner
│   │   └── migrations/
│   │       └── class-migration_1_0_0.php
│   ├── notifications/
│   │   └── class-notifier.php         # Event-driven notification dispatcher
│   ├── import/
│   │   ├── class-import-manager.php
│   │   ├── class-importer.php         # Abstract base
│   │   ├── class-bbpress-importer.php
│   │   ├── class-wpforo-importer.php
│   │   └── class-asgaros-importer.php
│   ├── seo/
│   │   ├── class-sitemap.php
│   │   ├── class-posts-sitemap-provider.php
│   │   ├── class-spaces-sitemap-provider.php
│   │   └── class-schema-markup.php
│   └── moderation/
│       └── class-akismet.php
├── templates/
│   ├── partials/  (8 reusable partials)
│   └── views/     (15 page views)
├── languages/
├── tests/
│   ├── unit/
│   └── integration/
└── docs/
    ├── architecture/   ← this file lives here
    ├── plans/
    ├── specs/
    └── superpowers/
        └── plans/
```

---

## 5. Database Layer

### 22 Custom Tables (prefix: `wp_jt_*`)

#### Core Content

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `jt_categories` | id, parent_id, name, slug, sort_order, visibility | Hierarchical (self-referential) |
| `jt_spaces` | id, category_id, type (forum/qa/ideas/feed), visibility, join_policy | 4 space types |
| `jt_posts` | id, space_id, author_id, type, title, content, content_plain, status, vote_score, reply_count, is_pinned, is_closed | content_plain for FULLTEXT search |
| `jt_replies` | id, post_id, parent_reply_id, author_id, content, content_plain, vote_score, is_accepted | Nested (threaded) via parent_reply_id |
| `jt_votes` | id, user_id, object_type, object_id, value (+1/-1) | UNIQUE(user_id, object_type, object_id) |
| `jt_revisions` | id, object_type, object_id, content, editor_id | Audit trail for edits |

#### Users & Membership

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `jt_user_profiles` | user_id, bio, avatar_url, cover_image, website, trust_level, post_count, reply_count, reputation, is_banned | 1:1 with WP user |
| `jt_space_members` | space_id, user_id, role (viewer/member/moderator/admin), joined_at | FK to spaces |
| `jt_user_interests` | user_id, tag_id | Personalization basis |

#### Tagging

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `jt_tags` | id, name, slug, type (post/space), usage_count | |
| `jt_post_tags` | post_id, tag_id | M:M pivot |
| `jt_space_tags` | space_id, tag_id | Space-level tagging |
| `jt_space_tag_map` | space_id, tag_id | Space tag visibility rules |

#### Activity & Notifications

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `jt_activity_log` | id, user_id, action, object_type, object_id, metadata (JSON), created_at | All user actions |
| `jt_notifications` | id, user_id, type, object_type, object_id, actor_id, is_read, created_at | Push-style |
| `jt_subscriptions` | id, user_id, object_type, object_id | Watch/subscribe to posts or spaces |
| `jt_read_status` | user_id, object_type, object_id, read_at | Unread tracking |

#### Access Control

| Table | Key Columns | Notes |
|-------|-------------|-------|
| `jt_restrictions` | id, user_id, type (ban/silence), scope, expires_at | User restrictions |
| `jt_access_rules` | id, space_id, rule_type (membership/role/trust_level), value | Pro-style access gating |
| `jt_flags` | id, reporter_id, object_type, object_id, reason, status | Content flags |
| `jt_join_requests` | id, space_id, user_id, message, status | Private space requests |
| `jt_invite_links` | id, space_id, token, created_by, max_uses, use_count, expires_at | Invite system |

### Schema Management

- **`DB\Schema::create_tables()`** — runs `dbDelta()` for all 22 tables. Idempotent — safe to call repeatedly.
- **`DB\Migrator::run($current_version)`** — runs incremental migrations from `includes/db/migrations/`.
- DB version stored in `jetonomy_db_version` option (tracks against `JETONOMY_DB_VERSION` constant).

### Helper Function

```php
// Global helper — returns full prefixed table name
table('posts')  // → "wp_jt_posts"
table('spaces') // → "wp_jt_spaces"
```

---

## 6. Model Layer

### Abstract `Jetonomy\Models\Model`

All models extend this base. Provides:

| Method | Signature | Notes |
|--------|-----------|-------|
| `find()` | `static find(int $id): ?object` | Single row by PK |
| `insert()` | `static insert(array $data): int` | Returns insert ID |
| `update()` | `static update(int $id, array $data): bool` | |
| `delete()` | `static delete(int $id): bool` | Hard delete |
| `count()` | `static count(array $where = []): int` | |
| `table()` | `protected static table(): string` | Returns `wp_jt_{table_name}` |

Each concrete model implements `table_name(): string` and adds domain-specific methods.

### Denormalized Counters

Several counters are kept denormalized for performance:

| Model | Counter column | Updated on |
|-------|---------------|------------|
| `Post` | `reply_count` | reply created/deleted |
| `Post` | `vote_score` | vote cast |
| `Reply` | `vote_score` | vote cast |
| `Space` | `post_count` | post created/deleted |
| `Space` | `member_count` | member joined/left |
| `UserProfile` | `post_count`, `reply_count`, `reputation` | content created/voted |
| `Category` | `space_count` | space added/removed |
| `Tag` | `usage_count` | post tagged/untagged |

---

## 7. Permission System

### `Jetonomy\Permissions\Permission_Engine`

**Three-layer resolver** — resolved in order, short-circuits on first denial:

```
can($user_id, $action, $space_id?)
├── Layer 0: Global ban check
│   └── Restriction::is_banned($user_id) → FALSE immediately if banned
├── Layer 1: WordPress capability
│   └── user_can($user_id, 'jetonomy_' . $action) → FALSE if cap missing
│   └── manage_options → SKIP layers 1+2 (admin bypass)
└── Layer 2: Space role check (if $space_id provided)
    └── SpaceMember::get_role($space_id, $user_id) → checked against SPACE_ROLE_PERMS
```

**Space role hierarchy** (each role includes all actions of roles below):

| Role | Actions |
|------|---------|
| `viewer` | read |
| `member` | + create_posts, create_replies, vote, flag |
| `moderator` | + edit_others_posts, delete_others_posts, close_posts, pin_posts, move_posts |
| `admin` | + manage_spaces |

**Caching:** Results cached 60s per `perm:{user_id}:{action}:{space_id}` key.

### `Jetonomy\Permissions\Capabilities`

WP capabilities registered at activation and on version change:

| Capability | Default roles |
|------------|--------------|
| `jetonomy_create_posts` | subscriber+ |
| `jetonomy_create_replies` | subscriber+ |
| `jetonomy_moderate_content` | editor+ |
| `jetonomy_manage_spaces` | editor+ |
| `jetonomy_manage_users` | administrator |
| `jetonomy_view_analytics` | administrator |

---

## 8. Trust & Reputation System

**Trust levels 0–5** — auto-evaluated by `Trust_Evaluator` (runs twicedaily via cron).

| Level | Label | Typical threshold |
|-------|-------|-------------------|
| 0 | New Member | Default |
| 1 | Basic | Has read posts + time on site |
| 2 | Member | Regular contributor |
| 3 | Regular | High engagement |
| 4 | Trusted | Long-term active |
| 5 | Leader | Top community contributor |

**Reputation points** (`Reputation::award($user_id, $action)`):

| Action | Points |
|--------|--------|
| Post created | +5 |
| Reply created | +2 |
| Vote received (up) | +10 |
| Vote received (down) | -2 |
| Reply accepted | +15 |

Hook fired on change: `do_action('jetonomy_reputation_changed', $user_id, $action, $delta)`

Trust level change fires: `do_action('jetonomy_trust_level_changed', $user_id, $old_level, $new_level)`

---

## 9. REST API

### `Jetonomy\API\Api`

Registers all controllers at `rest_api_init`. All routes under namespace `jetonomy/v1`.

### `Jetonomy\API\Base_Controller` (abstract, extends `WP_REST_Controller`)

Shared methods:
- `get_current_user_id()` — returns WP user ID or 0
- `permission_error()` — standard 403 `WP_Error`
- `validate_space_access()` — checks `Permission_Engine::can()`
- Cursor-based pagination helpers

### Full Endpoint Reference

**Categories** (`Jetonomy\API\Categories_Controller`)
| Method | Route | Auth | Notes |
|--------|-------|------|-------|
| GET | `/categories` | public | All categories |
| POST | `/categories` | `manage_options` | |
| GET | `/categories/{id}` | public | |
| PATCH | `/categories/{id}` | `manage_options` | |
| DELETE | `/categories/{id}` | `manage_options` | |

**Spaces** (`Jetonomy\API\Spaces_Controller`)
| Method | Route | Auth |
|--------|-------|------|
| GET | `/spaces` | public (filtered by visibility) |
| POST | `/spaces` | `jetonomy_manage_spaces` |
| GET/PATCH/DELETE | `/spaces/{id}` | varied |
| GET/POST | `/spaces/{id}/members` | varies by action |
| DELETE/PATCH | `/spaces/{id}/members/{user_id}` | space admin |
| POST | `/spaces/{id}/invite` | space admin |
| GET | `/invite/{token}` | public |

**Posts** (`Jetonomy\API\Posts_Controller`)
| Method | Route | Notes |
|--------|-------|-------|
| GET | `/spaces/{space_id}/posts` | Cursor pagination; sorted by last activity |
| POST | `/spaces/{space_id}/posts` | Runs `jetonomy_check_content` filter |
| GET/PATCH/DELETE | `/posts/{id}` | |
| POST | `/posts/{id}/close` | space moderator+ |
| POST | `/posts/{id}/pin` | space moderator+ |
| POST | `/posts/{id}/move` | space moderator+ |

**Replies** (`Jetonomy\API\Replies_Controller`)
| Method | Route | Notes |
|--------|-------|-------|
| GET | `/posts/{post_id}/replies` | Threaded (parent_reply_id); cursor paginated |
| POST | `/posts/{post_id}/replies` | Runs `jetonomy_check_content` filter |
| GET/PATCH/DELETE | `/replies/{id}` | |
| POST | `/replies/{id}/accept` | OP only; fires `jetonomy_reply_accepted` |

**Votes** (`Jetonomy\API\Votes_Controller`)
| Method | Route |
|--------|-------|
| POST | `/posts/{id}/vote` |
| POST | `/replies/{id}/vote` |

**Moderation** (`Jetonomy\API\Moderation_Controller`)
| Method | Route |
|--------|-------|
| GET | `/moderation/queue` |
| POST | `/moderation/approve/{type}/{id}` |
| POST | `/moderation/spam/{type}/{id}` |
| POST | `/moderation/trash/{type}/{id}` |
| POST/GET | `/flags` / `/moderation/flags` |
| POST | `/moderation/flags/{id}/resolve` |
| POST/DELETE | `/moderation/ban` / `/moderation/ban/{id}` |

**Users** (`Jetonomy\API\Users_Controller`)
| Method | Route |
|--------|-------|
| GET | `/users/me` |
| GET/PATCH | `/users/{id}` |
| GET | `/users/by-login/{login}` |
| GET | `/users/{id}/posts` |

**Notifications** (`Jetonomy\API\Notifications_Controller`)
| Method | Route |
|--------|-------|
| GET | `/notifications` |
| GET | `/notifications/unread-count` |
| POST | `/notifications/mark-all-read` |
| PATCH | `/notifications/{id}` |

**Other endpoints**
| Method | Route | Controller |
|--------|-------|-----------|
| GET/POST | `/tags` | `Tags_Controller` |
| GET | `/space-tags` | `Tags_Controller` |
| GET | `/search` | `Search_Controller` |
| GET | `/leaderboards` | `Leaderboards_Controller` |
| GET | `/subscriptions` | `Subscriptions_Controller` |
| DELETE | `/subscriptions/{id}` | `Subscriptions_Controller` |
| GET | `/updates` | `Updates_Controller` |

---

## 10. Admin Layer

### `Jetonomy\Admin\Admin`

Registers all admin menu pages and enqueues assets.

**Menu structure:**

```
Jetonomy (main)            jetonomy          dashicons-groups
├── Dashboard              jetonomy
├── Spaces                 jetonomy-spaces
├── Content                jetonomy-content
├── Categories             jetonomy-categories
├── Users                  jetonomy-users
├── Moderation             jetonomy-moderation
├── Settings               jetonomy-settings
├── Import                 jetonomy-import
├── Leaderboard*           jetonomy-leaderboard  (conditional: jetonomy_setup_complete)
├── Activity*              jetonomy-activity     (conditional: jetonomy_setup_complete)
└── Setup (hidden)         jetonomy-setup
```

*Conditional pages — only visible after setup is complete.

**Admin extension hooks (for Jetonomy Pro):**

```
do_action('jetonomy_admin_render_extensions')   // Extensions submenu placeholder
do_action('jetonomy_admin_render_license')       // License submenu placeholder
```

**⚠️ Known architectural issue:** `Admin` class is currently ~2,100 lines — violates the 750-line rule from CLAUDE.md. **Admin split plan** is at `docs/superpowers/plans/2026-03-24-admin-class-split.md`.

### Admin Views (`includes/admin/views/`)

| File | Page | Key hooks fired |
|------|------|----------------|
| `dashboard.php` | Dashboard | `jetonomy_admin_dashboard_after_stats`, `jetonomy_admin_dashboard_widgets` |
| `settings.php` | Settings | `jetonomy_admin_settings_tabs`, `jetonomy_admin_settings_tab_content` |
| `moderation.php` | Moderation | `jetonomy_admin_moderation_tabs`, `jetonomy_admin_moderation_tab_content` |
| `space-edit.php` | Space edit | `jetonomy_admin_space_edit_tabs`, `jetonomy_admin_space_edit_tab_content` |

---

## 11. AJAX Handlers

All handlers are currently in `Admin` class. **Target state:** Extracted to `Jetonomy\Admin\Ajax\*_Handler` classes.

| Group | Actions | Target class |
|-------|---------|-------------|
| Categories | create, update, delete, reorder | `Categories_Handler` |
| Spaces | create, update, delete, member management, access rules | `Spaces_Handler` |
| Moderation | approve, spam, trash content, resolve flag | `Moderation_Handler` |
| Users | ban, unban, change trust level, search | `Users_Handler` |
| Import | run, batch, progress | `Import_Handler` |
| Settings | test email, flush rules | `Settings_Handler` |
| Content | CRUD post/reply, bulk actions, get replies | `Content_Handler` |
| Setup | save, create sample, cleanup sample | `Setup_Handler` |
| Media | upload image | `Media` class (already separate) |

**AJAX action naming:** All follow `wp_ajax_jetonomy_{action}` pattern. Never rename existing actions.

---

## 12. Adapter System

### `Jetonomy\Adapters\Adapter_Registry` (static registry)

4 adapter types:

| Type | Interface | Default | Purpose |
|------|-----------|---------|---------|
| Membership | `Membership_Adapter` | `WP_Roles_Adapter` | Access gating by membership status |
| Email | `Email_Adapter` | `WP_Mail_Adapter` | Transactional email delivery |
| Realtime | `Realtime_Adapter` | `Polling_Adapter` | Push/polling updates |
| Search | `Search_Adapter` | `Fulltext_Search` | Content search |

### Membership Adapters

| Plugin | Detection | Class |
|--------|-----------|-------|
| WP Roles | always active | `WP_Roles_Adapter` |
| MemberPress | `defined('MEPR_VERSION')` | `MemberPress_Adapter` |
| PMPro | `defined('PMPRO_VERSION')` | `PMPro_Adapter` |

Membership adapters fire:
- `do_action('jetonomy_membership_activated', $user_id, $level_id, $source)`
- `do_action('jetonomy_membership_deactivated', $user_id, $level_id, $source)`

### Pro Adapters (in jetonomy-pro)

| Plugin | Detection | Registered as |
|--------|-----------|--------------|
| WooCommerce | `class_exists('WooCommerce')` | `'woocommerce'` |
| Restrict Content Pro | `defined('RCP_PLUGIN_VERSION')` | `'rcp'` |
| LearnDash | `defined('LEARNDASH_VERSION')` | `'learndash'` |

---

## 13. Frontend (Templates + Interactivity API)

### URL Structure

| URL | Query vars | Template |
|-----|-----------|---------|
| `/community/` | `jetonomy_route=home` | `views/home.php` |
| `/community/category/{slug}/` | `jetonomy_route=category` | `views/category.php` |
| `/community/s/{slug}/` | `jetonomy_route=space` | `views/space.php` |
| `/community/s/{slug}/t/{slug}/` | `jetonomy_route=single_post` | `views/single-post.php` |
| `/community/s/{slug}/new/` | `jetonomy_route=new_post` | `views/new-post.php` |
| `/community/s/{slug}/members/` | `jetonomy_route=space_members` | `views/space-members.php` |
| `/community/s/{slug}/roadmap/` | `jetonomy_route=space_roadmap` | `views/space-roadmap.php` |
| `/community/u/{login}/` | `jetonomy_route=user_profile` | `views/user-profile.php` |
| `/community/u/{login}/edit/` | `jetonomy_route=edit_profile` | `views/edit-profile.php` |
| `/community/tag/{slug}/` | `jetonomy_route=tag` | `views/tag.php` |
| `/community/search/` | `jetonomy_route=search` | `views/search.php` |
| `/community/leaderboard/` | `jetonomy_route=leaderboard` | `views/leaderboard.php` |
| `/community/notifications/` | `jetonomy_route=notifications` | `views/notifications.php` |
| `/community/mod/` | `jetonomy_route=moderation` | `views/moderation.php` |

Base slug (`community`) configurable via `jetonomy_settings[base_slug]`.

### Template Loader

File: `includes/class-template-loader.php`

Resolves templates with theme override support:
1. Check `{active_theme}/jetonomy/{template}.php`
2. Fall back to `jetonomy/templates/{template}.php`

Template map is filterable: `apply_filters('jetonomy_template_map', $map)`

### Interactivity API (`assets/js/view.js`)

Extends the `jetonomy` store with:
- Voting (posts + replies) via `/posts/{id}/vote` and `/replies/{id}/vote`
- Post sorting
- Threaded reply toggling
- Poll voting (if polls extension active)

### Partials

| Partial | Usage |
|---------|-------|
| `header.php` | Community nav (fires `jetonomy_header_nav_items`) |
| `post-card.php` | Space listing rows |
| `reply-card.php` | Reply rendering (fires `jetonomy_reply_actions`) |
| `composer.php` | Rich text input (used in new-post + replies) |
| `pagination.php` | Cursor-based pagination controls |
| `avatar.php` | Reusable user avatar with trust badge |
| `breadcrumb.php` | Category → Space breadcrumbs |
| `sidebar.php` | Space/category sidebar widget area |

### CSS

`assets/css/jetonomy.css` uses CSS custom properties that inherit from the active theme's `theme.json`. Plugin provides a baseline via `register_plugin_theme_json()` filter on `wp_theme_json_data_default`.

**Trust level badge colors** use `data-jt-tl` attribute selectors — never inline styles.

---

## 14. Notifications

### `Jetonomy\Notifications\Notifier`

Event-driven — hooks into action hooks fired by API controllers:

| Trigger hook | Notification type |
|-------------|------------------|
| `jetonomy_after_create_reply` | "reply_to_post" to post author + subscribers |
| `jetonomy_reply_accepted` | "reply_accepted" to reply author |
| `jetonomy_after_vote` (up) | "post_upvoted" / "reply_upvoted" |
| `jetonomy_user_joined_space` | "member_joined" to space admins |

Fires on create: `do_action('jetonomy_notification_created', $notification_id, $user_id, $type, $object_type, $object_id)`

Email delivery via `Adapter_Registry::get_email()` — defaults to `WP_Mail_Adapter`.
Email headers filterable: `apply_filters('jetonomy_notification_email_headers', $headers, $to, $subject)`

---

## 15. Activity Tracker

### `Jetonomy\Activity_Tracker`

Central event logger — hooks into all lifecycle actions and inserts rows into `jt_activity_log`. Keeps activity logging out of controllers.

All activity logging goes through hooks. **Never call `ActivityLog::log()` directly in controllers.**

---

## 16. Import System

### `Jetonomy\Import\Import_Manager`

Manages importers registered via `apply_filters('jetonomy_importers', $importers)`.

Built-in importers:

| Importer | Source | Class |
|----------|--------|-------|
| bbPress | WordPress plugin | `BBPress_Importer` |
| wpForo | WordPress plugin | `WPForo_Importer` |
| Asgaros Forum | WordPress plugin | `Asgaros_Importer` |

All extend abstract `Importer` which defines the batch import contract.

Import runs via AJAX batch: `jetonomy_run_import` → `jetonomy_import_batch` (repeated) → `jetonomy_import_progress`.

---

## 17. SEO Layer

| Class | Purpose |
|-------|---------|
| `SEO\Sitemap` | Registers XML sitemap providers at `init` |
| `SEO\Posts_Sitemap_Provider` | Extends `WP_Sitemaps_Provider` for forum posts |
| `SEO\Spaces_Sitemap_Provider` | Extends `WP_Sitemaps_Provider` for spaces |
| `SEO\Schema_Markup` | Outputs `DiscussionForumPosting` JSON-LD |

---

## 18. WordPress Abilities API

### `Jetonomy\Abilities` (WP 6.9+)

Registers 18 abilities across 5 categories. Makes all forum operations discoverable by AI agents.

| Category | Abilities |
|----------|-----------|
| `jetonomy-content` | create-post, get-post, list-posts, update-post, delete-post, create-reply, accept-reply |
| `jetonomy-spaces` | list-spaces, get-space, join-space, leave-space |
| `jetonomy-users` | get-profile, update-profile, list-notifications, mark-notification-read |
| `jetonomy-moderation` | flag-content, get-moderation-queue |
| `jetonomy-search` | search |

Registered at:
- `wp_abilities_api_categories_init` → `register_categories()`
- `wp_abilities_api_init` → `register_abilities()`

---

## 19. Cron Jobs

All scheduled via `Cron::schedule()` on activation.

| Hook | Schedule | Handler | Purpose |
|------|----------|---------|---------|
| `jetonomy_trust_evaluation` | twicedaily | `Cron::evaluate_trust_levels()` | Runs `Trust_Evaluator` for all users |
| `jetonomy_cleanup_expired` | hourly | `Cron::cleanup_expired_restrictions()` | Removes expired bans/silences |
| `jetonomy_prune_activity` | weekly | `Cron::prune_activity_log()` | Keeps activity log lean |
| `jetonomy_cleanup_notifications` | weekly | `Cron::cleanup_old_notifications()` | Deletes old read notifications |

Custom schedule `'weekly'` registered via `cron_schedules` filter in `Cron::add_schedules()`.

---

## 20. CLI Commands

Registered at `WP_CLI::add_command('jetonomy', 'Jetonomy\CLI')`.

| Sub-command | Purpose |
|-------------|---------|
| `wp jetonomy recalc-trust` | Manually trigger trust level recalculation |
| `wp jetonomy recalc-reputation` | Recalculate reputation scores |
| `wp jetonomy import` | Run import from CLI |
| `wp jetonomy flush-rewrites` | Flush rewrite rules |

---

## 21. Hook Reference

### Action Hooks — Admin Extension Points

| Hook | Args | Fired in | Used by |
|------|------|---------|---------|
| `jetonomy_admin_dashboard_widgets` | none | `views/dashboard.php:266` | Jetonomy Pro: analytics mini-widget, Pro status |
| `jetonomy_admin_dashboard_after_stats` | `$stats` | `views/dashboard.php:45` | Custom dashboard panels |
| `jetonomy_admin_settings_tabs` | `$active_tab` | `views/settings.php:10` | Jetonomy Pro: "Integrations" tab |
| `jetonomy_admin_settings_tab_content` | `$active_tab` | `views/settings.php:19,537` | Jetonomy Pro: tab content |
| `jetonomy_admin_moderation_tabs` | `$active_tab` | `views/moderation.php:32` | Pro advanced moderation |
| `jetonomy_admin_moderation_tab_content` | `$active_tab` | `views/moderation.php:324` | Pro moderation content |
| `jetonomy_admin_space_edit_tabs` | `$space` | `views/space-edit.php:33` | Pro: custom fields tab |
| `jetonomy_admin_space_edit_tab_content` | `$active_tab, $space` | `views/space-edit.php:333` | Pro: custom field settings |
| `jetonomy_admin_render_extensions` | none | `admin/class-admin.php:551` | Jetonomy Pro: extensions page |
| `jetonomy_admin_render_license` | none | `admin/class-admin.php:562` | Jetonomy Pro: license page |

### Action Hooks — Content Lifecycle

| Hook | Args | Description |
|------|------|-------------|
| `jetonomy_after_create_post` | `$post_id, $space_id` | After post created via REST API or Abilities API |
| `jetonomy_after_create_reply` | `$reply_id, $post_id` | After reply created |
| `jetonomy_post_updated` | `$post_id, $space_id, $user_id` | After post edited |
| `jetonomy_post_deleted` | `$post_id, $space_id, $user_id` | After post deleted |
| `jetonomy_reply_updated` | `$reply_id, $space_id, $user_id` | After reply edited |
| `jetonomy_reply_deleted` | `$reply_id, $space_id, $user_id` | After reply deleted |
| `jetonomy_reply_accepted` | `$reply_id, $post_id` | After reply marked as accepted answer |
| `jetonomy_content_moderated` | `$action, $object_type, $object_id, $moderator_id` | After approve/spam/trash action |
| `jetonomy_after_vote` | `$object_type, $object_id, $user_id` | After any vote |

### Action Hooks — Users / Membership

| Hook | Args | Description |
|------|------|-------------|
| `jetonomy_user_joined_space` | `$space_id, $user_id, $role` | After user joins a space |
| `jetonomy_trust_level_changed` | `$user_id, $old_level, $new_level` | After trust level auto-evaluated |
| `jetonomy_reputation_changed` | `$user_id, $action, $delta` | After reputation point change |
| `jetonomy_membership_activated` | `$user_id, $level_id, $source` | Membership plugin activated |
| `jetonomy_membership_deactivated` | `$user_id, $level_id, $source` | Membership plugin deactivated |
| `jetonomy_notification_created` | `$notification_id, $user_id, $type, $object_type, $object_id` | After notification inserted |

### Action Hooks — Template Injection

| Hook | Args | Location |
|------|------|---------|
| `jetonomy_new_post_fields` | `$space` | After post fields in new-post form |
| `jetonomy_post_meta_fields` | `$post` | After post content on single-post page |
| `jetonomy_post_actions` | `$post` | Post action buttons area |
| `jetonomy_reply_actions` | `$reply` | Reply action buttons area |
| `jetonomy_profile_after_stats` | `$profile_user_id` | After stats on profile page |
| `jetonomy_profile_display_fields` | `$profile_user_id` | Custom field display on profile |
| `jetonomy_profile_edit_fields` | `$user_id` | Custom field inputs in edit-profile form |
| `jetonomy_header_nav_items` | none | Community nav header (after built-in items) |

### Filter Hooks

| Filter | Args | Returns | Description |
|--------|------|---------|-------------|
| `jetonomy_admin_menu_label` | `$label` | string | Customise "Jetonomy" menu label |
| `jetonomy_admin_menu_icon` | `$icon` | string | Customise menu dashicon |
| `jetonomy_profile_url` | `$url, $user_id, $user` | string | Override profile page URL |
| `jetonomy_template_map` | `$map` | array | Add/override template route map |
| `jetonomy_check_content` | `null, $data, $space_id, $user_id` | string\|null | Return `'pending'` to hold for moderation; `null` for pass |
| `jetonomy_notification_email_headers` | `$headers, $to, $subject` | array | Customise email headers |
| `jetonomy_importers` | `$importers` | array | Register custom importers |
| `jetonomy_show_community_nav` | `true` | bool | Hide/show community nav header |
| `jetonomy_after_post_content` | `'', $post` | string | Append HTML after post content |

---

## 22. Options & User Meta

### Plugin Options (`wp_options`)

| Option | Type | Purpose |
|--------|------|---------|
| `jetonomy_db_version` | string | Current DB schema version |
| `jetonomy_settings` | array | Plugin settings (base_slug, moderation, email, etc.) |
| `jetonomy_setup_complete` | bool | Whether setup wizard was completed |
| `jetonomy_activation_redirect` | transient | Triggers setup redirect on first load |
| `jetonomy_permalinks_flushed_{version}` | bool | Prevents repeat rewrite flushes |
| `jetonomy_caps_registered_{version}` | bool | Prevents repeat capability registration |
| `jetonomy_activity_backfilled` | bool | Guards one-time activity backfill |
| `jetonomy_demo_data` | array | IDs created by sample data; cleaned up by `cleanup_sample_data` |

### Naming Rules

- **Options:** `jetonomy_*` prefix always
- **User meta:** `jetonomy_*` prefix always
- **DB tables:** `jt_*` prefix (becomes `wp_jt_*`)
- **Hook names:** `jetonomy_*` prefix — never rename existing hooks
- **AJAX actions:** `wp_ajax_jetonomy_*` — never rename existing actions

---

## 23. Naming Conventions

| Item | Convention | Example |
|------|-----------|---------|
| PHP class | PascalCase | `Permission_Engine`, `Posts_Controller` |
| PHP file | `class-{slug}.php` | `class-permission-engine.php` |
| PHP interface file | `interface-{slug}.php` | `interface-membership-adapter.php` |
| Namespace | `Jetonomy\{Module}\` | `Jetonomy\Permissions\Permission_Engine` |
| WP option | `jetonomy_{key}` | `jetonomy_settings` |
| WP user meta | `jetonomy_{key}` | `jetonomy_trust_level` |
| DB table | `jt_{table}` | `jt_space_members` |
| Hook | `jetonomy_{event}` | `jetonomy_after_create_post` |
| AJAX action | `jetonomy_{action}` | `jetonomy_create_category` |
| CSS class | `.jt-{component}` | `.jt-post-card`, `.jt-avatar-sm` |
| Asset handle | `jetonomy` or `jetonomy-{variant}` | `jetonomy`, `jetonomy-admin` |

---

## 24. Extension Points for Jetonomy Pro

**Jetonomy Pro** (`jetonomy-pro`) extends the free plugin without modifying its code. Key integration points:

### Admin Integration

Pro hooks into core admin pages using:

```php
// Hook into dashboard
add_action('jetonomy_admin_dashboard_widgets', [$this, 'render_pro_status_widget']);

// Hook into settings page — add tabs
add_action('jetonomy_admin_settings_tabs', [$this, 'add_pro_tabs']);
add_action('jetonomy_admin_settings_tab_content', [$this, 'render_pro_tab_content']);

// Replace placeholder pages with actual content
add_action('jetonomy_admin_render_extensions', fn() => (new Extensions_Admin($this))->render());
add_action('jetonomy_admin_render_license', fn() => (new License_Admin())->render());
```

**Notice hoisting:** When Pro renders content into settings tab hooks, any `<div class="notice ...">` elements are extracted from the buffered output and rendered above `.jt-settings-layout` (not inside the form card).

### REST API Extension

Pro controllers register additional routes on `rest_api_init` under the same `jetonomy/v1` namespace.

### Membership Adapters

Pro adds WooCommerce, RCP, and LearnDash adapters via `Adapter_Registry::register_membership()`.

### Template Extension

Pro can add new routes by filtering `jetonomy_template_map`:

```php
add_filter('jetonomy_template_map', function($map) {
    $map['messages'] = JETONOMY_PRO_DIR . 'includes/extensions/private-messaging/views/messages.php';
    return $map;
});
```

### Content Moderation Hook

Pro Advanced Moderation extension hooks `jetonomy_check_content` to automatically flag or block content:

```php
add_filter('jetonomy_check_content', function($result, $data, $space_id, $user_id) {
    // Return 'pending' to hold for review, null to pass
    return $result;
}, 10, 4);
```
