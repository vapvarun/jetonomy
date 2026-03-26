# Jetonomy — Plugin Architecture Reference

Last updated: 2026-03-24

This is the definitive technical reference for the Jetonomy free plugin. It covers the full code flow from entry point through every layer: bootstrap, autoloader, database, models, permissions, REST API, admin, adapters, notifications, activity tracking, caching, hooks, and extension points.

---

## Table of Contents

1. [Bootstrap & Lifecycle](#1-bootstrap--lifecycle)
2. [Autoloader & Namespaces](#2-autoloader--namespaces)
3. [Database Layer](#3-database-layer)
4. [Model Layer](#4-model-layer)
5. [Permission System](#5-permission-system)
6. [Trust & Reputation System](#6-trust--reputation-system)
7. [REST API](#7-rest-api)
8. [Admin Architecture](#8-admin-architecture)
9. [Adapter System](#9-adapter-system)
10. [Notification System](#10-notification-system)
11. [Activity Tracker](#11-activity-tracker)
12. [Cache Layer](#12-cache-layer)
13. [Hook Registry](#13-hook-registry)
14. [Naming Conventions](#14-naming-conventions)
15. [Extension Points for Jetonomy Pro](#15-extension-points-for-jetonomy-pro)
16. [Frontend Layer](#16-frontend-layer)

---

## 1. Bootstrap & Lifecycle

### Entry Point: `jetonomy.php`

The root file defines all plugin constants, loads two required files, and registers the public accessor function.

**Constants defined at boot:**

| Constant | Value |
|---|---|
| `JETONOMY_VERSION` | Plugin version string |
| `JETONOMY_DB_VERSION` | Current DB schema version |
| `JETONOMY_FILE` | Absolute path to `jetonomy.php` |
| `JETONOMY_DIR` | Absolute path to plugin directory |
| `JETONOMY_URL` | URL to plugin directory |

**Files required directly:**

```
includes/class-autoloader.php
includes/class-jetonomy.php
```

**Public accessor function:**

```php
function jetonomy(): \Jetonomy\Jetonomy {
    return \Jetonomy\Jetonomy::instance();
}
```

**WP-CLI registration** (conditional):

```php
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'jetonomy', 'Jetonomy\CLI' );
}
```

---

### Singleton: `Jetonomy\Jetonomy`

The main class is a singleton that owns all WP hook registrations. It never bootstraps itself — the entry point file triggers it via `jetonomy()`.

**Hook registrations on the singleton:**

| Hook | Priority | Callback | Notes |
|---|---|---|---|
| `init` | 1 | `load_textdomain()` | Priority 1 beats `wp_cron()` at priority 10 |
| `plugins_loaded` | 10 | `init()` | Loads all dependencies |
| `wp_theme_json_data_default` | — | `register_plugin_theme_json()` | Injects plugin theme.json data |
| activation | — | `activate()` | `dbDelta`, capabilities, cron, redirect |
| deactivation | — | `deactivate()` | Unschedule cron, flush rewrites |

---

### `init()` Sequence (called at `plugins_loaded:10`)

```
1. maybe_redirect_to_setup()       — one-time activation redirect to setup wizard
2. check_db_version()              — runs Migrator if stored version < JETONOMY_DB_VERSION
3. load_dependencies():
   a. require functions.php
   b. new Router()                 — register rewrite rules
   c. flush_rewrite_rules()        — once per version (keyed option)
   d. Capabilities::register()     — once per version
   e. new API\Api()                — registers all REST routes
   f. Adapter_Registry::init_defaults()
   g. new Notifications\Notifier()
   h. new Cron()
   i. new Privacy()
   j. new SEO\Sitemap()
   k. new SEO\Schema_Markup()
   l. new Nav_Menus()
   m. new Media()
   n. new Activity_Tracker()
   o. new Abilities()
   p. Import_Manager::init()
   q. new Admin\Admin()            — only when is_admin() is true
4. maybe_backfill_activity()       — one-time activity log backfill
```

---

### Activation / Deactivation

**`activate()`** runs: `dbDelta` (all 22 tables), capability registration, cron scheduling, and sets the one-time redirect flag.

**`deactivate()`** runs: unschedule all Jetonomy cron events, flush rewrite rules.

Neither function deletes data. Data removal is handled separately via uninstall.php (if present).

---

## 2. Autoloader & Namespaces

Jetonomy uses a custom PSR-4-style autoloader (`includes/class-autoloader.php`). There is no Composer autoloader in the free plugin.

### Namespace → Directory Map

| Namespace prefix | Directory |
|---|---|
| `Jetonomy\Admin\Ajax\` | `includes/admin/ajax/` |
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

### Class → Filename Conversion

The autoloader converts class names to filenames using these rules:

1. Strip the matched namespace prefix, leaving the bare class name.
2. Convert underscores and namespace separators to hyphens.
3. Lowercase the result.
4. Prepend `class-` and append `.php`.

**Examples:**

| Class name | Resolved filename |
|---|---|
| `Jetonomy\Permissions\Permission_Engine` | `includes/permissions/class-permission-engine.php` |
| `Jetonomy\Adapters\WP_Mail_Adapter` | `includes/adapters/class-wp-mail-adapter.php` |
| `Jetonomy\API\Posts_Controller` | `includes/api/class-posts-controller.php` |

Interface files follow the same pattern with the prefix `interface-` and suffix `-adapter.php` for adapter interfaces.

---

## 3. Database Layer

### Table Summary

All 22 tables use the `wp_jt_*` prefix (WP table prefix + `jt_`). The schema helper `table('posts')` returns `"wp_jt_posts"`.

#### Core Content Tables

| Table | Key Columns |
|---|---|
| `jt_categories` | `id`, `parent_id`, `name`, `slug`, `sort_order`, `visibility` |
| `jt_spaces` | `id`, `category_id`, `type` (forum/qa/ideas/feed), `visibility`, `join_policy` |
| `jt_posts` | `id`, `space_id`, `author_id`, `type`, `title`, `content`, `content_plain`, `status`, `vote_score`, `reply_count`, `is_pinned`, `is_closed` |
| `jt_replies` | `id`, `post_id`, `parent_reply_id`, `author_id`, `content`, `content_plain`, `vote_score`, `is_accepted` |
| `jt_votes` | `id`, `user_id`, `object_type`, `object_id`, `value` (+1/-1) — UNIQUE(`user_id`, `object_type`, `object_id`) |
| `jt_revisions` | `id`, `object_type`, `object_id`, `content`, `editor_id` |

#### Users & Membership Tables

| Table | Key Columns |
|---|---|
| `jt_user_profiles` | `user_id`, `bio`, `avatar_url`, `cover_image`, `website`, `trust_level`, `post_count`, `reply_count`, `reputation`, `is_banned` |
| `jt_space_members` | `space_id`, `user_id`, `role` (viewer/member/moderator/admin), `joined_at` |
| `jt_user_interests` | `user_id`, `tag_id` |

#### Tagging Tables

| Table | Purpose |
|---|---|
| `jt_tags` | `id`, `name`, `slug`, `type` (post/space), `usage_count` |
| `jt_post_tags` | Maps posts to tags |
| `jt_space_tags` | Tag definitions scoped to a space |
| `jt_space_tag_map` | Maps spaces to tags |

#### Activity & Notification Tables

| Table | Key Columns |
|---|---|
| `jt_activity_log` | `user_id`, `action`, `object_type`, `object_id`, `metadata` (JSON), `created_at` |
| `jt_notifications` | `user_id`, `type`, `object_type`, `object_id`, `actor_id`, `is_read` |
| `jt_subscriptions` | User subscription preferences |
| `jt_read_status` | Per-user read tracking for posts |

#### Access Control Tables

| Table | Key Columns |
|---|---|
| `jt_restrictions` | `user_id`, `type` (ban/silence), `scope`, `expires_at` |
| `jt_access_rules` | `space_id`, `rule_type`, `value` |
| `jt_flags` | `reporter_id`, `object_type`, `object_id`, `reason`, `status` |
| `jt_join_requests` | Space join request queue |
| `jt_invite_links` | Space invite link tokens |

---

### Denormalized Counters

Counter columns are updated on every write operation, never recalculated via `COUNT()` queries. This is a hard requirement for scale.

| Object | Counter columns |
|---|---|
| Post | `reply_count`, `vote_score` |
| Reply | `vote_score` |
| Space | `post_count`, `member_count` |
| UserProfile | `post_count`, `reply_count`, `reputation` |
| Category | `space_count` |
| Tag | `usage_count` |

---

## 4. Model Layer

### Abstract Base: `Jetonomy\Models\Model`

All models extend this abstract class. It provides:

```php
Model::find( int $id ): ?object
Model::insert( array $data ): int          // Returns inserted ID
Model::update( int $id, array $data ): bool
Model::delete( int $id ): bool
Model::count( array $where ): int
```

Each concrete model must implement:

```php
abstract protected function table_name(): string;
```

### The 18 Concrete Models

`Category`, `Space`, `Post`, `Reply`, `Vote`, `UserProfile`, `SpaceMember`, `Notification`, `Subscription`, `ReadStatus`, `Tag`, `PostTag`, `ActivityLog`, `Restriction`, `AccessRule`, `Flag`, `JoinRequest`, `InviteLink`

All model files live in `includes/models/` following the `class-{name}.php` convention.

---

## 5. Permission System

### 3-Layer Resolver

Entry point: `can( $user_id, $action, $space_id = null )`

```
Layer 0: Global ban check
    Restriction::is_banned( $user_id )
    → Returns FALSE immediately if banned (no further evaluation)

Layer 1: WP capability check
    user_can( $user_id, 'jetonomy_' . $action )
    → Users with manage_options bypass all space-role checks
    → Capability grants access immediately if found

Layer 2: Space role check
    SpaceMember::get_role( $space_id, $user_id )
    → Checked against SPACE_ROLE_PERMS matrix
```

### Space Role Hierarchy & Permissions

| Role | Permissions |
|---|---|
| `viewer` | read |
| `member` | read, `create_posts`, `create_replies`, `vote`, `flag` |
| `moderator` | all member permissions + edit/delete others' content, close, pin, move |
| `admin` | all moderator permissions + `manage_spaces` |

### WP Capability Map

23 capabilities registered at activation (and on version change).

| Capability | Minimum WP role |
|---|---|
| `jetonomy_read` | Subscriber |
| `jetonomy_create_posts` | Subscriber |
| `jetonomy_create_replies` | Subscriber |
| `jetonomy_edit_own_posts` | Subscriber |
| `jetonomy_delete_own_posts` | Subscriber |
| `jetonomy_vote` | Subscriber |
| `jetonomy_flag` | Subscriber |
| `jetonomy_join_spaces` | Subscriber |
| `jetonomy_upload_media` | Contributor |
| `jetonomy_create_spaces` | Author |
| `jetonomy_edit_others_posts` | Editor |
| `jetonomy_delete_others_posts` | Editor |
| `jetonomy_moderate` | Editor |
| `jetonomy_manage_users` | Editor |
| `jetonomy_move_posts` | Editor |
| `jetonomy_close_posts` | Editor |
| `jetonomy_pin_posts` | Editor |
| `jetonomy_manage_settings` | Administrator |
| `jetonomy_manage_categories` | Administrator |
| `jetonomy_manage_spaces` | Administrator |
| `jetonomy_manage_badges` | Administrator |
| `jetonomy_view_analytics` | Administrator |
| `jetonomy_manage_extensions` | Administrator |

### Permission Caching

Results are cached for 60 seconds per unique combination:

```
Cache key: perm:{user_id}:{action}:{space_id}
TTL: 60 seconds
```

---

## 6. Trust & Reputation System

### Trust Levels

| Level | Label | How assigned |
|---|---|---|
| 0 | Newcomer | Auto (default) |
| 1 | Member | Auto (cron) |
| 2 | Regular | Auto (cron) |
| 3 | Trusted | Auto (cron) |
| 4 | Leader | Manual (admin only) |
| 5 | Moderator | Manual (admin only) |

Levels 0–3 are evaluated automatically every 12 hours via cron. Thresholds for `posts`, `days_active`, `reputation`, and `replies_received` are configurable in `jetonomy_settings`.

Levels 4–5 are granted exclusively by an administrator and are never overwritten by the cron job.

### Reputation Point Values

| Event | Points |
|---|---|
| Post upvoted | +10 |
| Reply upvoted | +5 |
| Answer accepted | +15 |
| Downvoted | −2 |
| Post removed | −20 |

---

## 7. REST API

### Overview

- Base URL: `/wp-json/jetonomy/v1/`
- 42 total endpoints across 15 controllers
- All registered by `Jetonomy\API\Api` (file: `includes/api/class-api.php`)

### Standard Response Envelope

```json
{
    "data": [...],
    "meta": {
        "count": 20,
        "has_more": true,
        "cursor_next": 142,
        "total": 84
    }
}
```

### Pagination

Primary strategy is cursor-based:

```
?after=<ID>&limit=20
```

`cursor_next` in the `meta` object is the ID to pass as `after` on the next request. Offset-based pagination (`?page=&per_page=`) is supported as a fallback but cursor-based is preferred for all high-volume lists.

### Standard Error Codes

| Code | HTTP Status | Meaning |
|---|---|---|
| `jetonomy_unauthorized` | 401 | Not authenticated |
| `jetonomy_forbidden` | 403 | Authenticated but lacks permission |
| `jetonomy_not_found` | 404 | Resource does not exist |
| `jetonomy_validation` | 400 | Invalid request parameters |

### Endpoint Reference

| Resource | Method | Path | Description |
|---|---|---|---|
| Categories | GET | `/categories` | List all categories |
| Categories | GET | `/categories/:id` | Single category |
| Spaces | GET/POST | `/spaces` | List / create |
| Spaces | GET/PATCH/DELETE | `/spaces/:id` | Single space CRUD |
| Spaces | GET/POST | `/spaces/:id/members` | List / add member |
| Spaces | PATCH | `/spaces/:id/members/:user` | Update member role |
| Posts | GET | `/spaces/:id/posts` | List posts in space |
| Posts | GET/PATCH/DELETE | `/posts/:id` | Single post CRUD |
| Posts | POST/DELETE | `/posts/:id/vote` | Cast / retract vote |
| Posts | POST | `/posts/:id/close` | Close post |
| Posts | POST | `/posts/:id/pin` | Pin post |
| Posts | POST | `/posts/:id/move` | Move post to another space |
| Replies | GET/POST | `/posts/:id/replies` | List / create replies |
| Replies | PATCH/DELETE | `/replies/:id` | Single reply CRUD |
| Replies | POST | `/replies/:id/accept` | Mark reply as accepted answer |
| Replies | POST/DELETE | `/replies/:id/vote` | Cast / retract vote |
| Search | GET | `/search` | Search with `?q=&filter=all|posts|spaces|tags` |
| Notifications | GET | `/notifications` | List notifications |
| Notifications | POST | `/notifications/mark-read` | Mark notifications read |
| Subscriptions | POST/DELETE | `/subscriptions` | Subscribe / unsubscribe |
| Users | GET | `/users/:login` | Get user profile |
| Users | PATCH | `/users/:login` | Update user profile |
| Tags | GET | `/tags` | List / search tags |
| Moderation | POST | `/moderation/approve` | Approve flagged content |
| Moderation | POST | `/moderation/spam` | Mark as spam |
| Moderation | POST | `/moderation/trash` | Trash content |
| Moderation | GET | `/moderation/flags` | List open flags |
| Moderation | POST | `/moderation/ban` | Ban a user |
| Leaderboard | GET | `/leaderboard` | Users ranked by reputation |
| Updates | GET | `/updates` | Real-time polling endpoint |
| Activity | GET | `/activity` | Activity feed |
| Notifications | GET | `/notifications/unread-count` | Returns `{ count: N }` — polled every 30 s by `view.js` |
| Users | GET | `/users/by-login/:login` | User lookup for hover cards (name, avatar, bio, trust_level) |
| Replies | POST | `/posts/:id/replies/fill-gap` | Load replies between two cursor IDs (gap loading) |

### Controller Files

All in `includes/api/`:

| File | Class | Purpose |
|---|---|---|
| `class-api.php` | `Api` | Registers all routes |
| `class-base-controller.php` | `Base_Controller` | Shared helpers, auth, response formatting |
| `class-categories-controller.php` | `Categories_Controller` | |
| `class-spaces-controller.php` | `Spaces_Controller` | |
| `class-posts-controller.php` | `Posts_Controller` | |
| `class-replies-controller.php` | `Replies_Controller` | |
| `class-votes-controller.php` | `Votes_Controller` | |
| `class-search-controller.php` | `Search_Controller` | |
| `class-notifications-controller.php` | `Notifications_Controller` | |
| `class-subscriptions-controller.php` | `Subscriptions_Controller` | |
| `class-users-controller.php` | `Users_Controller` | |
| `class-tags-controller.php` | `Tags_Controller` | |
| `class-moderation-controller.php` | `Moderation_Controller` | |
| `class-updates-controller.php` | `Updates_Controller` | Real-time polling endpoint |
| `class-leaderboards-controller.php` | `Leaderboards_Controller` | Reputation leaderboard |

### Enriched Response Fields

All resource responses that include author data are enriched server-side:

`author_name`, `author_avatar`, `author_login`, `trust_level`, `reputation`, `time_ago`, `profile_url`

---

## 8. Admin Architecture

### Main Admin Class

File: `includes/admin/class-admin.php`
Class: `Jetonomy\Admin\Admin`

**Hard size limit: 750 lines.** This class handles only menu registration, settings page scaffolding, and asset enqueueing. All render logic lives in view files. All data mutations live in AJAX handlers.

### AJAX Handlers

All handlers live in `includes/admin/ajax/` under the `Jetonomy\Admin\Ajax\` namespace.

| File | Class | Responsibilities |
|---|---|---|
| `class-categories-handler.php` | `Categories_Handler` | Category CRUD, reorder |
| `class-spaces-handler.php` | `Spaces_Handler` | Space CRUD, member management, access rules |
| `class-moderation-handler.php` | `Moderation_Handler` | Approve, spam, trash, resolve flags |
| `class-users-handler.php` | `Users_Handler` | Ban/unban, trust level, user search |
| `class-import-handler.php` | `Import_Handler` | Run batch, get progress |
| `class-settings-handler.php` | `Settings_Handler` | Test email, flush rewrite rules |
| `class-content-handler.php` | `Content_Handler` | Post/reply CRUD, bulk actions |
| `class-setup-handler.php` | `Setup_Handler` | Setup wizard steps |

### Admin View Files

All views in `includes/admin/views/`:

`dashboard.php`, `categories.php`, `spaces.php`, `space-edit.php`, `content.php`, `users.php`, `moderation.php`, `settings.php`, `import.php`, `leaderboard.php`, `activity.php`, `setup.php`

---

## 9. Adapter System

Jetonomy uses an adapter pattern for four integration boundaries: membership, email, realtime, and search. Default adapters work out of the box; third-party adapters are registered via filters.

### Interface Files

All in `includes/adapters/`:

| Interface file | Namespace | Methods |
|---|---|---|
| `interface-membership-adapter.php` | `Jetonomy\Adapters` | `sync_user_spaces()`, `user_has_access()` |
| `interface-email-adapter.php` | `Jetonomy\Adapters` | `send()`, `supports_batch()` |
| `interface-realtime-adapter.php` | `Jetonomy\Adapters` | `publish()` |
| `interface-search-adapter.php` | `Jetonomy\Adapters` | `search()`, `index()` |

### Default Adapters

| Type | Default implementation | Notes |
|---|---|---|
| Membership | WP Roles adapter | Uses native WP roles |
| Email | `wp_mail` adapter | Standard `wp_mail()` |
| Realtime | Polling adapter | Client-side polling fallback |
| Search | FULLTEXT adapter | MySQL FULLTEXT search |

### Optional / Conditional Adapters

| Adapter | Condition |
|---|---|
| MemberPress adapter | `defined( 'MEPR_VERSION' )` |
| PMPro adapter | `defined( 'PMPRO_VERSION' )` |

### Registering Custom Adapters

```php
// Register a custom email adapter
add_filter( 'jetonomy_email_adapters', function( $adapters ) {
    $adapters['my-ses'] = new My_SES_Adapter();
    return $adapters;
} );
```

Available filter names: `jetonomy_membership_adapters`, `jetonomy_email_adapters`, `jetonomy_realtime_adapters`, `jetonomy_search_adapters`

### Adapter Registry

`Adapter_Registry::init_defaults()` is called during `init()` and registers all built-in adapters. It evaluates the conditional adapters at that point and registers them only if their parent plugin constant is defined.

---

## 10. Notification System

### Dispatch Chain

```
Action occurs in a controller
  └─ do_action( 'jetonomy_after_{event}', ... )
       └─ Notifier::on_{event}() catches the hook
            ├─ Notification::create()       → persists web notification (jt_notifications)
            └─ Check user's email preference
                 ├─ Immediate → Email_Adapter::send()
                 └─ Digest    → Queue entry for batch send
```

**Rule:** Notifications are never created directly inside controllers. All notification creation flows through the `jetonomy_after_*` action hooks and the `Notifier` class. This keeps controllers thin and makes notification behavior overridable.

---

## 11. Activity Tracker

`Activity_Tracker` (instantiated in `init()`) listens to all `jetonomy_after_*` action hooks and writes entries to `jt_activity_log`.

**Rule:** `ActivityLog::log()` is never called directly in controllers. All activity recording is centralized in `Activity_Tracker`. This ensures consistent metadata and makes it possible to suppress or redirect activity logging without touching controllers.

---

## 12. Cache Layer

File: `includes/class-cache.php`

A thin wrapper around WP's object cache and transient API. Uses in-memory storage for the current request, falling back to `wp_cache_*` and transients for persistence.

### Cache Key Reference

| Cache key | TTL | Contents |
|---|---|---|
| `jetonomy:space:{id}` | Until invalidated | Full space object |
| `jetonomy:space:slug:{slug}` | Until invalidated | Full space object by slug |
| `jetonomy:profile:{user_id}` | Until invalidated | Full user profile object |
| `jetonomy:perm:{user_id}:{action}:{space_id}` | 60 seconds | Boolean permission result |
| `jetonomy:trending` | 120 seconds | Trending posts list |
| `jetonomy:leaderboard:{scope}` | 300 seconds | Leaderboard data |

Cache entries are invalidated on relevant write operations (space update clears `jetonomy:space:{id}`, etc.).

---

## 13. Hook Registry

### Actions

#### Content Lifecycle

| Action | Parameters | Fired when |
|---|---|---|
| `jetonomy_after_create_post` | `$post_id, $space_id` | Post successfully created |
| `jetonomy_after_create_reply` | `$reply_id, $post_id` | Reply successfully created |
| `jetonomy_reply_accepted` | `$reply_id, $post_id` | Reply marked as accepted answer |
| `jetonomy_after_vote` | `$object_type, $object_id, $voter_id` | Vote cast or changed |
| `jetonomy_content_moderated` | `$action, $object_type, $object_id, $moderator_id` | Moderator action applied |
| `jetonomy_post_updated` | `$post_id, $space_id` | Post edited |
| `jetonomy_post_deleted` | `$post_id, $space_id` | Post deleted |
| `jetonomy_reply_updated` | `$reply_id, $post_id` | Reply edited |
| `jetonomy_reply_deleted` | `$reply_id, $post_id` | Reply deleted |

#### Space Lifecycle

| Action | Parameters | Fired when |
|---|---|---|
| `jetonomy_after_create_space` | `$space_id` | Space created |
| `jetonomy_after_update_space` | `$space_id, $changes` | Space settings updated |
| `jetonomy_after_join_space` | `$space_id, $user_id, $role` | User joins a space |
| `jetonomy_user_joined_space` | `$space_id, $user_id, $role` | Alias fired from `SpaceMember::join()` directly |
| `jetonomy_after_leave_space` | `$space_id, $user_id` | User leaves a space |
| `jetonomy_join_request_approved` | `$space_id, $user_id` | Pending join request approved |
| `jetonomy_join_request_denied` | `$space_id, $user_id` | Pending join request denied |

#### User & Trust

| Action | Parameters | Fired when |
|---|---|---|
| `jetonomy_trust_level_changed` | `$user_id, $old_level, $new_level` | Trust level changes (auto or manual) |
| `jetonomy_reputation_changed` | `$user_id, $points, $reason` | Reputation points awarded or deducted |
| `jetonomy_membership_activated` | `$user_id, $level_id` | Membership adapter fires on activation |
| `jetonomy_membership_deactivated` | `$user_id, $level_id` | Membership adapter fires on cancellation |
| `jetonomy_user_banned` | `$user_id, $restriction_id` | User banned |
| `jetonomy_user_unbanned` | `$user_id` | Ban lifted |
| `jetonomy_user_silenced` | `$user_id` | User silenced |

#### Notifications & Email

| Action | Parameters | Fired when |
|---|---|---|
| `jetonomy_notification_created` | `$notification_id, $user_id, $type` | Web notification persisted |
| `jetonomy_before_send_email` | `$to, $subject, $message, $headers` | Before email dispatch |
| `jetonomy_after_send_email` | `$to, $subject, $result` | After email dispatch |

#### Import

| Action | Parameters | Fired when |
|---|---|---|
| `jetonomy_import_started` | `$source, $user_id` | Import job begins |
| `jetonomy_import_completed` | `$source, $stats` | Import job finishes |

#### UI Injection

| Action | Parameters | Purpose |
|---|---|---|
| `jetonomy_header_nav_items` | — | Add items to the community navigation bar |
| `jetonomy_post_actions` | `$post, $space` | Add buttons below posts |
| `jetonomy_reply_actions` | `$reply, $post` | Add buttons below replies |
| `jetonomy_profile_after_stats` | `$user_id` | Add content after profile stat block |
| `jetonomy_profile_edit_fields` | `$user_id` | Add fields to the profile edit form |
| `jetonomy_profile_display_fields` | `$user_id` | Display custom profile fields |
| `jetonomy_new_post_fields` | `$space` | Add fields to the new post form |

#### Admin Injection Points

| Action | Parameters | Purpose |
|---|---|---|
| `jetonomy_admin_render_extensions` | — | Render Pro extensions page (Pro plugin hooks here) |
| `jetonomy_admin_render_license` | — | Render Pro license tab (Pro plugin hooks here) |
| `jetonomy_admin_space_edit_tabs` | `$space` | Add tabs to the space edit page |
| `jetonomy_admin_space_edit_tab_content` | `$space, $tab` | Render content for custom space edit tabs |
| `jetonomy_admin_settings_tab_content` | `$tab` | Render content for custom settings tabs |
| `jetonomy_admin_license_tab_content` | — | Render license tab content |
| `jetonomy_admin_moderation_tabs` | — | Add tabs to the moderation page |
| `jetonomy_admin_moderation_tab_content` | `$tab` | Render content for custom moderation tabs |
| `jetonomy_admin_dashboard_after_stats` | — | Add content below dashboard stat row |
| `jetonomy_admin_dashboard_widgets` | — | Add dashboard widget cards |

---

### Filters

#### UI & Display

| Filter | Parameters | Default | Purpose |
|---|---|---|---|
| `jetonomy_profile_url` | `$url, $user_id` | Built-in profile URL | Override profile link (e.g. BuddyPress) |
| `jetonomy_header_logo` | `$html` | Default logo HTML | Override header logo (white label) |
| `jetonomy_admin_menu_label` | `$label` | `"Jetonomy"` | Rename admin menu entry |
| `jetonomy_admin_menu_icon` | `$icon` | Default dashicon | Change admin menu icon |
| `jetonomy_show_community_nav` | `$bool, $page` | `true` | Show/hide the community nav on specific pages |
| `jetonomy_template_map` | `$map` | Core template map | Override or add template paths |
| `jetonomy_after_post_content` | `$html, $post` | `''` | Inject HTML after post body |

#### Content & Moderation

| Filter | Parameters | Purpose |
|---|---|---|
| `jetonomy_check_content` | `$content, $type, $user_id` | Pre-insertion content moderation hook |
| `jetonomy_allowed_post_types` | `$types, $space_id` | Control which post types are permitted per space |

#### Integration

| Filter | Parameters | Purpose |
|---|---|---|
| `jetonomy_membership_adapters` | `$adapters` | Register additional membership adapters |
| `jetonomy_email_adapters` | `$adapters` | Register additional email adapters |
| `jetonomy_search_adapters` | `$adapters` | Register additional search adapters |
| `jetonomy_importers` | `$importers` | Register additional import sources |
| `jetonomy_notification_email_headers` | `$headers, $to, $subject` | Modify email headers before dispatch |

#### Admin

| Filter | Parameters | Purpose |
|---|---|---|
| `jetonomy_admin_settings_tabs` | `$tabs` | Add tabs to the Settings screen |
| `jetonomy_admin_nav_items` | `$items` | Add items to the admin sidebar nav |
| `jetonomy_admin_space_edit_tabs` | `$tabs` | Add tabs to the Space Edit page |

---

## 14. Naming Conventions

These conventions are enforced across the entire codebase. **Never rename existing hooks or AJAX actions** — doing so is a breaking change.

| Thing | Convention | Example |
|---|---|---|
| WP options | `jetonomy_*` prefix | `jetonomy_settings` |
| User meta | `jetonomy_*` prefix | `jetonomy_notification_prefs` |
| DB tables | `jt_*` prefix (→ `wp_jt_*`) | `wp_jt_posts` |
| Hook names (actions + filters) | `jetonomy_*` prefix | `jetonomy_after_create_post` |
| AJAX actions | `wp_ajax_jetonomy_*` | `wp_ajax_jetonomy_create_space` |
| Asset handles | `jetonomy` or `jetonomy-{variant}` | `jetonomy-admin` |
| Class files | `class-{name}.php` | `class-permission-engine.php` |
| Interface files | `interface-{name}-adapter.php` | `interface-email-adapter.php` |
| Controller files | `class-{name}-controller.php` | `class-posts-controller.php` |
| Adapter files | `class-{name}-adapter.php` | `class-wp-mail-adapter.php` |
| Template files | `{name}.php` (no prefix) | `single-post.php` |

---

## 15. Extension Points for Jetonomy Pro

### Extension Contract

Every Jetonomy Pro extension must extend `\Jetonomy_Pro\Extension` and implement four methods:

```php
class My_Extension extends \Jetonomy_Pro\Extension {

    public function meta(): array {
        return [
            'id'       => 'my-extension',
            'name'     => 'My Extension',
            'version'  => '1.0.0',
            'category' => 'engagement',
        ];
    }

    public function boot(): void {
        // Register hooks, REST routes, inline CSS
    }

    public function activate(): void {
        // Create extension-specific DB tables
    }

    public function deactivate(): void {
        // Cleanup (unschedule cron, flush cache, etc.)
    }
}
```

### Auto-Discovery

Jetonomy Pro discovers extensions by scanning:

```
jetonomy-pro/includes/extensions/*/class-extension.php
```

Directory name to class name mapping: directory `private-messaging` → class `Jetonomy_Pro\Extensions\Private_Messaging\Extension`

### Enabling Extensions

Extensions are enabled by storing their IDs in the `jetonomy_pro_extensions` option:

```php
get_option( 'jetonomy_pro_extensions', [] ); // Returns array of enabled IDs
```

Before `boot()` is called, the loader checks:

```php
License::can_use_extension( $id ); // Must return true
```

### Extension Rules

These rules are mandatory — violations create coupling that prevents safe upgrades:

1. **Extensions never modify core plugin files.** All integration is via hooks.
2. **Extensions create their own tables** using the `jt_pro_` prefix (→ `wp_jt_pro_*`).
3. **Extensions register REST routes** under the existing `jetonomy/v1` namespace, not a separate namespace.
4. **Extensions enqueue CSS** via `wp_add_inline_style( 'jetonomy', $css )` — not separate stylesheet files.
5. **License tier gating** is enforced by the Pro plugin loader before `boot()` is called. Extensions do not check the license themselves.

### Bridge Pattern

Jetonomy Pro accesses Jetonomy free via the public `jetonomy()` singleton accessor. It never instantiates free plugin classes directly or calls private/protected methods. Any data needed from the free plugin must flow through:

- Public model methods (`Post::find()`, etc.)
- The hook system (`jetonomy_after_*` actions)
- REST API endpoints (for loose coupling)
- The `jetonomy()` accessor for service access where needed

This ensures extensions remain compatible across free plugin upgrades without needing to track internal refactors.

---

## 16. Frontend Layer

### JavaScript Files

Three JS assets are enqueued:

| Handle | File | Loaded on |
|---|---|---|
| `jetonomy` | `assets/js/view.js` | All `/community/*` frontend pages |
| `jetonomy-composer` | `assets/js/composer.js` | Pages with composer or search (space, post, new-post, profile) |
| `jetonomy-admin` | `assets/js/admin.js` | All wp-admin Jetonomy pages |

---

### `view.js` — Interactivity API Store

Registered store namespace: `jetonomy`

#### State Variables

| Key | Type | Purpose |
|---|---|---|
| `postScores` | `{}` | Vote scores keyed by post ID (pre-populated server-side) |
| `replyScores` | `{}` | Vote scores keyed by reply ID |
| `currentSort` | `string` | Reply sort order: `latest`, `oldest`, `best` |
| `isLoading` | `boolean` | Global loading state |
| `unreadCount` | `number` | Notification bell badge count |
| `composerVisible` | `boolean` | Reply composer open/closed |
| `composerReplyTo` | `number\|null` | Reply-to context (post or reply ID) |
| `replyToId` | `number\|null` | Target reply ID for nested replies |
| `replyToAuthor` | `string` | Display name of reply target |
| `isSubmitting` | `boolean` | Prevents double-submit |
| `submitLabel` | `string` | CTA button text (context-aware: "Post Topic" vs "Reply") |
| `nonce` | getter | Returns `wp.data.select('core').getWPNonce()` |

**Server-side state injection** (in `Template_Loader::render()`):

```php
wp_interactivity_state( 'jetonomy', [
    'apiBase'       => get_rest_url( null, 'jetonomy/v1/' ),
    'nonce'         => wp_create_nonce( 'wp_rest' ),
    'communityBase' => home_url( '/community' ),
    'currentPostId' => $post->id,          // single-post only
    'postScores'    => [ $post->id => $post->vote_score ],
    'replyScores'   => $reply_scores_map,  // keyed by reply ID
    'currentSort'   => $sort,             // from $_GET['sort']
] );
```

#### Actions

| Action | REST call | Notes |
|---|---|---|
| `voteUp(postId)` | `POST /posts/{id}/vote` direction=up | Optimistic render with rollback on error |
| `voteDown(postId)` | `POST /posts/{id}/vote` direction=down | |
| `voteReplyUp(replyId)` | `POST /replies/{id}/vote` direction=up | |
| `voteReplyDown(replyId)` | `POST /replies/{id}/vote` direction=down | |
| `editReply(replyId, content)` | `PATCH /replies/{id}` | Inline edit |
| `toggleThread(replyId)` | — | Client-side visibility toggle |
| `changeSort(sortOrder)` | `GET /posts/{id}/replies?sort=` | Reloads reply list |
| `showReplyComposer(replyId, authorName)` | — | Sets composer context |
| `cancelReplyComposer()` | — | Clears composer state |
| `setReplyTo(replyId, authorName)` | — | Auto-focuses composer |
| `loadGapReplies(startId, endId)` | `POST /posts/{id}/replies/fill-gap` | Loads skipped replies between cursors |
| `loadMoreReplies(postId, cursor)` | `GET /posts/{id}/replies?cursor=&limit=10` | Cursor-based pagination |
| `dismissFlag(flagId)` | `PATCH /moderation/flags/{id}` status=dismissed | |
| `submitReply(postId, content, replyToId)` | `POST /posts/{id}/replies` | |
| `submitNewPost(spaceId, title, content)` | `POST /spaces/{id}/posts` | |
| `saveProfile(userId, profileData)` | `PATCH /users/me` | |
| `pollNotifications()` | `GET /notifications/unread-count` | Called every 30 s via `setInterval()` |
| `onEditorInput(event)` | — | Sanitizes HTML input |

#### Observers & Polling

- `startPolling()` — 30-second `setInterval` for notification count
- `initInfiniteScroll()` — `IntersectionObserver` on `.jt-load-gap` triggers gap loading
- `initReplyPolling()` — 15-second polling for new-replies banner
- `buildReplyHtml(replyObj)` — Builds DOM string for optimistically rendered replies

---

### `composer.js` — Rich Text Editor & UI Behaviours

#### Toolbar Commands (`data-cmd` attribute)

| Command | HTML result |
|---|---|
| `bold` | `<strong>` |
| `italic` | `<em>` |
| `code` | `<code>` (inline) |
| `link` | `<a href="">` |
| `quote` | `<blockquote>` |
| `image` | Opens media upload dialog → `<img>` |
| `emoji` | Opens emoji picker singleton |

#### Keyboard Shortcuts

| Key | Action |
|---|---|
| `?` | Toggle help modal |
| `/` | Focus instant search |
| `n` | Navigate to new post (`/new` in current space) |
| `j` | Next item in list |
| `k` | Previous item in list |
| `Enter` | Open focused item |
| `Ctrl+Enter` / `Cmd+Enter` | Submit composer form |

#### AJAX Actions (frontend)

| Action | Handler class | Payload | Returns |
|---|---|---|---|
| `jetonomy_upload_image` | `Jetonomy\Media` | FormData (image file) | `{ id, url }` |
| `jetonomy_upload_import_file` | `Jetonomy\Admin\Ajax\Import_Handler` | FormData (bbPress/wpForo export file) | progress token |

#### REST calls from `composer.js`

| Endpoint | Trigger |
|---|---|
| `GET /search?q=&type=` | Instant search (250 ms debounce) |
| `GET /users/by-login/:login` | Hover card data (400 ms delay) |
| `POST /spaces/:id/members` | Join space / request access gate |
| `GET /spaces/:id` | Verify space access before post creation |

---

### CSS Design System (`assets/css/jetonomy.css`)

#### Custom Properties (`:root`)

**Typography:**

| Property | Purpose |
|---|---|
| `--jt-font` | Body font (inherited from `theme.json` or system sans-serif) |
| `--jt-font-heading` | Heading font |
| `--jt-font-mono` | Monospace for code blocks |

**Colors (semantic):**

| Property | Purpose |
|---|---|
| `--jt-accent` | Primary action / brand color |
| `--jt-accent-hover` | Hover state |
| `--jt-accent-light` | Badge / pill backgrounds |
| `--jt-accent-muted` | Disabled states |
| `--jt-text` | Primary text |
| `--jt-text-secondary` | Secondary text |
| `--jt-text-tertiary` | Placeholders |
| `--jt-bg` | Page background |
| `--jt-bg-subtle` | Slightly offset sections |
| `--jt-bg-muted` | Hover / active backgrounds |
| `--jt-bg-hover` | Explicit hover background |
| `--jt-border` | Default border |
| `--jt-border-strong` | Emphasis border |
| `--jt-success` / `--jt-success-light` | Success states |
| `--jt-warn` / `--jt-warn-light` | Warning states |
| `--jt-danger` / `--jt-danger-light` | Error / destructive states |

**Trust level colors** (used via `data-jt-tl` selector):

| Property | Level |
|---|---|
| `--jt-tl0` | Member (gray) |
| `--jt-tl1` | Active (blue) |
| `--jt-tl2` | Trusted (green) |
| `--jt-tl3` | Expert (purple) |
| `--jt-tl4` | Moderator (orange) |
| `--jt-tl5` | Admin (red) |

**Badge tier colors:** `--jt-badge-bronze`, `--jt-badge-silver`, `--jt-badge-gold`, `--jt-badge-platinum`

**Spacing / sizing:** `--jt-radius` (8px), `--jt-radius-sm` (4px), `--jt-radius-lg` (12px), `--jt-radius-full` (9999px)

**Animation:** `--jt-ease` (cubic-bezier easing), `--jt-dur` (200–300 ms)

#### Keyframe Animations

| Name | Effect | Used for |
|---|---|---|
| `jt-fadeIn` | Fade + translateY(-8px → 0) | Card entry, reply appearance |
| `jt-gradientShift` | background-position 0% → 200% | Profile banner gradient |
| `jt-countPop` | scale(0.5 → 1.1 → 1) | Vote count update burst |
| `jt-slideUp` | Fade + translateY(16px → 0) | Kanban columns (staggered 0–0.3s) |
| `jt-progressGrow` | width 0% → 100% | Progress bars, upload indicators |
| `jt-pulse` | opacity 1 → 0.5 → 1 | Loading placeholders |
| `jt-emptyPulse` | scale(1 → 1.05 → 1) | Empty state icons |

#### Dark Mode

Activated by `.jt-dark` class on `<body>` (not `prefers-color-scheme`). All custom properties are redefined in `:root.jt-dark {}`. Theme controls the toggle.

#### Responsive Breakpoints

| Breakpoint | Changes |
|---|---|
| `@media (max-width: 640px)` | `.jt-two-col` → single column; `.jt-space-grid` → single column; `.jt-row` → flex-direction: column; vote buttons reorder to top |
| `@media (max-width: 900px)` | Kanban column widths adjusted; item gap reduced |
| `@media (max-width: 1024px)` | Container padding reduced |
| `@media (prefers-reduced-motion: reduce)` | All `animation` and `transition` rules disabled (accessibility) |

#### RTL Support

`assets/css/jetonomy-rtl.css` is enqueued conditionally when `is_rtl()` returns true. Mirrors all directional properties.

---

### Template System

#### File Layout

```
templates/
├── views/
│   ├── home.php             Community homepage
│   ├── category.php         Category detail page
│   ├── space.php            Space post listing
│   ├── space-members.php    Space members directory
│   ├── space-roadmap.php    Ideas/roadmap (Pro feature)
│   ├── single-post.php      Single post + replies
│   ├── user-profile.php     User profile (posts/badges/activity tabs)
│   ├── notifications.php    Notification center (auth required)
│   ├── new-post.php         Create post form (auth required)
│   ├── edit-profile.php     Edit profile form (auth required)
│   ├── search.php           Search results
│   ├── leaderboard.php      Reputation leaderboard
│   ├── moderation.php       Moderation admin panel
│   ├── tag.php              Tag results
│   └── invite.php           Invite link landing page
└── partials/
    ├── header.php            Community navigation bar
    ├── breadcrumb.php        Breadcrumb trail
    ├── sidebar.php           Activity feed + trending topics
    ├── post-card.php         Post row (list view)
    ├── reply-card.php        Single reply card (recursive for nested threads)
    ├── composer.php          Rich text editor + toolbar
    ├── pagination.php        Load more control
    └── avatar.php            User avatar with initials fallback
```

#### Theme Override Mechanism (`Template_Loader`)

Resolution order for every template and partial:

```
1. theme/jetonomy/views/{name}.php   (child theme or active theme)
2. plugin/templates/views/{name}.php (plugin default)
```

Partial loading:

```php
Template_Loader::partial( 'post-card', [ 'post' => $post ] );
// Resolves to:  theme/jetonomy/partials/post-card.php  OR
//               plugin/templates/partials/post-card.php
```

#### Smart Reply Loading (Single Post)

The single-post template uses a **first-10 + last-10 + gap** strategy to avoid loading hundreds of replies on page load:

- Always load the first 10 top-level replies (oldest)
- Always load the last 10 top-level replies (newest)
- If total > 20: show a `.jt-load-gap` button between them
- Gap loading fires `loadGapReplies(startId, endId)` → `POST /posts/:id/replies/fill-gap`
- Cursor-based pagination loads additional pages

#### Post View Tracking

`Template_Loader::maybe_track_post_view()` fires on every single-post page load:
- Cookie name: `jt_viewed_{post_id}` (24-hour expiry, set before any output)
- Cookie deduplication prevents double-counting on refresh
- Increments `Post::view_count` on first unique view per user/session

#### Auth-Required Routes

These routes redirect to `wp_login_url()` if user is not authenticated:

- `/community/notifications/`
- `/community/messages/`
- `/community/messages/:id/`
- `/community/u/:login/edit/`
- `/community/s/:slug/new/`

---

### Router (`includes/class-router.php`)

#### URL → Query Var Map

| URL Pattern | Query Vars Set | Route |
|---|---|---|
| `/community/` | `jetonomy_route=home` | home |
| `/community/category/:slug/` | `jetonomy_route=category&jetonomy_slug=$1` | category |
| `/community/s/:slug/` | `jetonomy_route=space&jetonomy_slug=$1` | space |
| `/community/s/:slug/members/` | `jetonomy_route=space-members&jetonomy_slug=$1` | space-members |
| `/community/s/:slug/roadmap/` | `jetonomy_route=space-roadmap&jetonomy_slug=$1` | space-roadmap |
| `/community/s/:slug/new/` | `jetonomy_route=new-post&jetonomy_slug=$1` | new-post |
| `/community/s/:slug/t/:slug/` | `jetonomy_route=post&jetonomy_space_slug=$1&jetonomy_slug=$2` | post |
| `/community/u/:login/` | `jetonomy_route=profile&jetonomy_slug=$1` | profile |
| `/community/u/:login/edit/` | `jetonomy_route=edit-profile&jetonomy_slug=$1` | edit-profile |
| `/community/u/:login/(posts\|badges\|activity)/` | `jetonomy_route=profile&jetonomy_slug=$1&jetonomy_tab=$2` | profile |
| `/community/notifications/` | `jetonomy_route=notifications` | notifications |
| `/community/search/` | `jetonomy_route=search` | search |
| `/community/leaderboard/` | `jetonomy_route=leaderboard` | leaderboard |
| `/community/mod/` | `jetonomy_route=moderation` | moderation |
| `/community/tag/:slug/` | `jetonomy_route=tag&jetonomy_slug=$1` | tag |
| `/community/invite/:code/` | `jetonomy_route=invite&jetonomy_slug=$1` | invite |
| `/community/messages/` | `jetonomy_route=messages` | messages (Pro) |
| `/community/messages/:id/` | `jetonomy_route=conversation&jetonomy_slug=$1` | conversation (Pro) |

**Registered query vars:** `jetonomy_route`, `jetonomy_slug`, `jetonomy_space_slug`, `jetonomy_tab`

Rewrite rules are registered at `init` and auto-flushed on first load after activation/upgrade via the `jetonomy_permalinks_flushed` option.

---

### Global Helper Functions (`includes/functions.php`)

| Function | Signature | Returns |
|---|---|---|
| `table()` | `table( string $name ): string` | `wp_jt_{$name}` — prefixed table name |
| `now()` | `now(): string` | Current UTC timestamp in `Y-m-d H:i:s` format |
| `get_profile_url()` | `get_profile_url( int $user_id ): string` | Profile URL; filterable via `jetonomy_profile_url` |
| `get_user_link()` | `get_user_link( int $user_id, string $class='', int $size=40, bool $name=true ): string` | Avatar + name HTML |
| `format_relative_time()` | `format_relative_time( int $timestamp ): string` | "2 hours ago", "Yesterday", etc. |
| `sanitize_post_content()` | `sanitize_post_content( string $content ): string` | `wp_kses_post()` wrapper |
| `get_post_excerpt()` | `get_post_excerpt( string $content, int $length=160 ): string` | Plaintext excerpt |

---

### Nonce Strategy

| Nonce name | Created by | Verified by |
|---|---|---|
| `wp_rest` | `Template_Loader::render()` → `wp_interactivity_state` | All REST requests from `view.js` |
| `jetonomy_upload` | `Template_Loader::render()` → `wp_localize_script` | `jetonomy_upload_image` AJAX action |
| `jetonomy_reply_{post_id}` | `partials/composer.php` | Reply submit action |
| `jetonomy_join_space_{space_id}` | `views/space.php` (space gate form) | Space join/request action |

---

### SEO Meta Tags (`Template_Loader::set_seo_meta()`)

| Route | `<title>` | OG Image | Canonical |
|---|---|---|---|
| home | "Community" | — | `/community/` |
| space | Space title + " — Community" | Space cover image | `/community/s/{slug}/` |
| post | Post title | First image in post content | `/community/s/{space}/t/{slug}/` |
| profile | User display name | User avatar | `/community/u/{login}/` |
| category | Category name | — | `/community/category/{slug}/` |
| search | "Search" | — | `/community/search/` |
