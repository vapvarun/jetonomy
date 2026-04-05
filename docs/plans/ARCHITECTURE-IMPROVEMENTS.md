# Jetonomy Architecture Improvements Plan

Created: 2026-04-05
Status: Approved for execution

## Context

At v1.3.0, the architecture is strong but has gaps that will become painful once users depend on the API and schema. These must be addressed before significant adoption — changing hooks, response shapes, or table engines later is a breaking change.

---

## Priority 1: Hook Architecture (NEEDS WORK)

**Why now:** The white-label app platform and LMS integrations both need these hooks. Without them, every integration is a workaround.

### 1a. Add `before_` action/filter hooks on all write operations

Allow third-party code to abort or modify data before it's saved.

| Hook | Location | Arguments |
|------|----------|-----------|
| `jetonomy_before_create_post` | `Post::create()` | `$data, $user_id, $space_id` — return `WP_Error` to abort |
| `jetonomy_before_create_reply` | `Reply::create()` | `$data, $user_id, $post_id` |
| `jetonomy_before_vote` | `Vote::cast()` | `$user_id, $object_type, $object_id, $value` |
| `jetonomy_before_join_space` | `SpaceMember::add()` | `$user_id, $space_id, $role` |
| `jetonomy_before_delete_post` | `Post::delete()` | `$post_id, $user_id` |
| `jetonomy_before_delete_reply` | `Reply::delete()` | `$reply_id, $user_id` |

### 1b. Add REST response shape filters

Allow Pro, app platform, and third-party plugins to add/remove fields.

| Filter | Location | Arguments |
|--------|----------|-----------|
| `jetonomy_rest_prepare_post` | `Posts_Controller::prepare_item()` | `$response_data, $post_object, $request` |
| `jetonomy_rest_prepare_reply` | `Replies_Controller::prepare_item()` | `$response_data, $reply_object, $request` |
| `jetonomy_rest_prepare_space` | `Spaces_Controller::prepare_space()` | `$response_data, $space_object, $request` |
| `jetonomy_rest_prepare_user` | `Users_Controller::prepare_item()` | `$response_data, $user_object, $request` |
| `jetonomy_rest_prepare_notification` | `Notifications_Controller::prepare_item()` | `$response_data, $notification, $request` |

### 1c. Add query args filters on model list methods

Allow custom sorting, filtering, WHERE clauses.

| Filter | Location |
|--------|----------|
| `jetonomy_posts_query_args` | `Post::list_by_space()` |
| `jetonomy_replies_query_args` | `Reply::list_by_post()` |
| `jetonomy_spaces_query_args` | `Space::list_visible()` |
| `jetonomy_users_query_args` | `UserProfile::list_all()` |

### 1d. Make permission roles extensible

```php
// In Permission_Engine::resolve()
$role_perms = apply_filters( 'jetonomy_space_role_permissions', self::SPACE_ROLE_PERMS[ $role ], $role, $space_id );
```

---

## Priority 2: Active Bugs

### 2a. Fix `has_more` pagination (active bug — affects all list endpoints)

**Problem:** `paginated_response()` in `Base_Controller` defaults `has_more` to `false`. Individual controllers override it with `count($items) === $limit` which is wrong on the last full page.

**Fix:** Require `total` in meta and compute in `paginated_response()`:
```php
'has_more' => isset($meta['total']) ? ($meta['offset'] + count($data)) < $meta['total'] : false
```

Remove all per-controller `has_more` overrides.

### 2b. Fix `jetonomy_after_create_post` dual signature

**Problem:** Fired with `($post_id, $space_id)` in `class-abilities.php` but `($post_id, $space_id, $request)` in `Posts_Controller`. Listeners get inconsistent args.

**Fix:** Standardize to 3 args everywhere. Update Abilities call to pass `null` for `$request` when not available. Document the hook signature in ARCHITECTURE.md.

---

## Priority 3: Database Safety

### 3a. Add `ENGINE=InnoDB` to all tables

21 of 23 tables rely on server default. On older hosting this is MyISAM (no row-level locking, no transactions).

**Fix:** Add `ENGINE=InnoDB` to every `CREATE TABLE` in `class-schema.php`. Safe to run on existing installs — `dbDelta()` handles this gracefully.

### 3b. Activity log pruning

`jt_activity_log` has no TTL. At scale: 10k users x 1k entries = 10M rows with no cleanup.

**Fix:** Add a daily cron in `class-cron.php` that deletes rows older than `jetonomy_settings[activity_log_retention_days]` (default 90). Run in batches of 5,000 to avoid lock contention.

### 3c. Base slug 301 redirect

Changing `base_slug` in settings breaks all existing URLs. No redirect.

**Fix:** When `base_slug` is saved, store old value in `jetonomy_old_base_slug`. Add a `template_redirect` hook that 301s requests matching the old slug pattern to the new one. Clear old slug after 90 days or manual dismiss.

### 3d. Vote transaction safety

Vote insert + score update are not atomic. Crash between them desynchronizes the score.

**Fix:** Wrap in `$wpdb->query('START TRANSACTION')` / `COMMIT` in `Vote::cast()`.

---

## Priority 4: Frontend (Low urgency)

### 4a. Extract template data assembly

`single-post.php` does 8-9 DB calls inline (lines 11-100). Move to a `jetonomy_get_post_view_data()` function that returns a single `$view_data` array. Template does zero DB calls.

### 4b. Modal refactor

`view.js` has 200+ lines of vanilla DOM construction for modals inside the Interactivity API store. Should be extracted to a separate utility or converted to Interactivity API patterns.

---

## Not Doing (Acceptable As-Is)

| Item | Why it's fine |
|------|--------------|
| Settings service class | `get_option()` is cached by WP core. Centralize when touching those files |
| Extension file sizes | Working correctly, extract when convenient |
| Notification message storage | Pre-rendered strings work for v1, revisit if translation needs change |
| SSO adapter | Not needed until enterprise customers request it |
| API versioning (v2) | Not needed until first breaking change — namespace `jetonomy/v1` is correct |

---

## Execution Order

1. **Hooks** (Priority 1a-1d) — do first, unblocks app platform + LMS
2. **has_more bug** (Priority 2a) — quick fix, user-facing bug
3. **Hook signature** (Priority 2b) — quick fix, prevents future listener bugs
4. **InnoDB + activity pruning** (Priority 3a, 3b) — do before any large deployment
5. **Base slug redirect** (Priority 3c) — do before any customer changes their slug
6. **Vote transaction** (Priority 3d) — low frequency but correct to fix
7. **Template data assembly** (Priority 4a) — do when working on single-post performance
