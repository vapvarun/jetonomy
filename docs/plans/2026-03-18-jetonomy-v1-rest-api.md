# Jetonomy v1.0 — REST API Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the complete REST API layer for Jetonomy v1.0 — all endpoints for spaces, posts, replies, voting, notifications, moderation, search, and real-time polling.

**Architecture:** All endpoints extend `WP_REST_Controller`. Namespace: `jetonomy/v1`. Every endpoint uses the Permission Engine for authorization. Cursor-based pagination on list endpoints. JSON schema validation on all inputs.

**Tech Stack:** PHP 8.1+, WordPress REST API (`register_rest_route`), `WP_REST_Controller` pattern.

**Spec Reference:** `docs/superpowers/specs/2026-03-17-jetonomy-forum-plugin-design.md` — Section 4: REST API Design

**Depends on:** Plan 1 (Core Engine) — all models, permissions, and trust system.

---

## File Structure

```
includes/api/
├── class-api.php                    # API bootstrapper — registers all controllers
├── class-base-controller.php        # Abstract base with shared helpers
├── class-categories-controller.php  # /categories
├── class-spaces-controller.php      # /spaces, /spaces/:id/members
├── class-posts-controller.php       # /spaces/:id/posts, /posts/:id
├── class-replies-controller.php     # /posts/:id/replies, /replies/:id
├── class-votes-controller.php       # /posts/:id/vote, /replies/:id/vote
├── class-users-controller.php       # /users/me, /users/:id
├── class-notifications-controller.php # /notifications
├── class-subscriptions-controller.php # /subscriptions
├── class-search-controller.php      # /search
├── class-moderation-controller.php  # /moderation/queue, /flags
├── class-tags-controller.php        # /tags, /space-tags
├── class-updates-controller.php     # /updates (polling)
└── class-leaderboards-controller.php # /leaderboards
```

---

## Task 1: API Bootstrap + Base Controller

**Files:**
- Create: `includes/api/class-api.php`
- Create: `includes/api/class-base-controller.php`

The API class hooks into `rest_api_init` and registers all controllers. The base controller provides shared methods for cursor pagination, permission checks, and response formatting.

### Base Controller shared methods:
- `get_cursor_params()` — register cursor/limit/sort params
- `paginate_response($items, $cursor_next, $has_more, $total)` — standard paginated response
- `check_permission($action, $space_id = null)` — wraps Permission_Engine::can()
- `current_user_profile()` — get or create UserProfile for current user
- `error_response($code, $message, $status)` — standard WP_Error

---

## Task 2: Categories Controller

**Files:**
- Create: `includes/api/class-categories-controller.php`

**Endpoints:**
- `GET /jetonomy/v1/categories` — list all (nested). Public.
- `GET /jetonomy/v1/categories/:id` — single with child spaces. Public.
- `POST /jetonomy/v1/categories` — create. Admin only.
- `PATCH /jetonomy/v1/categories/:id` — update. Admin only.
- `DELETE /jetonomy/v1/categories/:id` — delete. Admin only.

---

## Task 3: Spaces Controller

**Files:**
- Create: `includes/api/class-spaces-controller.php`

**Endpoints:**
- `GET /jetonomy/v1/spaces` — list with filters (category_id, type, tag, visibility). Public for public spaces.
- `GET /jetonomy/v1/spaces/:id` — single space with stats. Respects visibility.
- `POST /jetonomy/v1/spaces` — create. Requires jetonomy_create_spaces.
- `PATCH /jetonomy/v1/spaces/:id` — update. Space admin or WP admin.
- `DELETE /jetonomy/v1/spaces/:id` — trash. WP admin only.
- `GET /jetonomy/v1/spaces/:id/members` — list members. Members only for private spaces.
- `POST /jetonomy/v1/spaces/:id/members` — join/request. Creates SpaceMember or JoinRequest based on join_policy.
- `DELETE /jetonomy/v1/spaces/:id/members/:user_id` — leave/remove.
- `PATCH /jetonomy/v1/spaces/:id/members/:user_id` — change role. Space admin only.

---

## Task 4: Posts Controller

**Files:**
- Create: `includes/api/class-posts-controller.php`

**Endpoints:**
- `GET /jetonomy/v1/spaces/:space_id/posts` — list posts. Cursor pagination. Sort: latest/popular/unanswered. Filter: status, type.
- `GET /jetonomy/v1/posts/:id` — single post. Increments view_count.
- `POST /jetonomy/v1/spaces/:space_id/posts` — create. Permission: create_posts in space. Sanitize content via wp_kses_post(). Generate content_plain via wp_strip_all_tags(). Auto-subscribe author.
- `PATCH /jetonomy/v1/posts/:id` — update. Permission: own post or edit_others_posts. Create revision before updating.
- `DELETE /jetonomy/v1/posts/:id` — trash (set status=trash). Permission: own post or delete_others_posts.
- `POST /jetonomy/v1/posts/:id/close` — close. Permission: close_posts in space.
- `POST /jetonomy/v1/posts/:id/pin` — pin. Permission: pin_posts in space.
- `POST /jetonomy/v1/posts/:id/move` — move to different space. Permission: move_posts. Body: { space_id }.

---

## Task 5: Replies Controller

**Files:**
- Create: `includes/api/class-replies-controller.php`

**Endpoints:**
- `GET /jetonomy/v1/posts/:post_id/replies` — list. Sort: oldest/newest/best. Cursor pagination.
- `POST /jetonomy/v1/posts/:post_id/replies` — create. Permission: create_replies in post's space. Sanitize content. Notify post author + subscribers.
- `PATCH /jetonomy/v1/replies/:id` — update. Permission: own or edit_others. Create revision.
- `DELETE /jetonomy/v1/replies/:id` — trash. Permission: own or delete_others.
- `POST /jetonomy/v1/replies/:id/accept` — accept answer (Q&A). Permission: post author or moderator.

---

## Task 6: Votes Controller

**Files:**
- Create: `includes/api/class-votes-controller.php`

**Endpoints:**
- `POST /jetonomy/v1/posts/:id/vote` — body: { value: 1|-1 }. Permission: vote in space. Calls Vote::cast(). Awards reputation.
- `DELETE /jetonomy/v1/posts/:id/vote` — remove vote.
- `POST /jetonomy/v1/replies/:id/vote` — same pattern.
- `DELETE /jetonomy/v1/replies/:id/vote` — remove.

---

## Task 7: Users + Notifications + Subscriptions Controllers

**Files:**
- Create: `includes/api/class-users-controller.php`
- Create: `includes/api/class-notifications-controller.php`
- Create: `includes/api/class-subscriptions-controller.php`

**Users endpoints:**
- `GET /jetonomy/v1/users/me` — current user profile + stats
- `GET /jetonomy/v1/users/:id` — public profile
- `PATCH /jetonomy/v1/users/me` — update own profile
- `GET /jetonomy/v1/users/:id/posts` — user's posts
- `GET /jetonomy/v1/users/:id/badges` — earned badges

**Notifications:**
- `GET /jetonomy/v1/notifications` — current user, paginated
- `PATCH /jetonomy/v1/notifications/:id` — mark read
- `POST /jetonomy/v1/notifications/mark-all-read`
- `GET /jetonomy/v1/notifications/unread-count` — lightweight, cacheable

**Subscriptions:**
- `GET /jetonomy/v1/subscriptions` — current user
- `POST /jetonomy/v1/subscriptions` — subscribe { object_type, object_id }
- `DELETE /jetonomy/v1/subscriptions/:id` — unsubscribe

---

## Task 8: Search + Moderation + Tags + Updates Controllers

**Files:**
- Create: `includes/api/class-search-controller.php`
- Create: `includes/api/class-moderation-controller.php`
- Create: `includes/api/class-tags-controller.php`
- Create: `includes/api/class-updates-controller.php`

**Search:**
- `GET /jetonomy/v1/search?q=term&type=post|reply|space&space_id=N` — MySQL FULLTEXT search. Paginated.

**Moderation:**
- `GET /jetonomy/v1/moderation/queue` — pending + flagged. Permission: jetonomy_moderate.
- `POST /jetonomy/v1/moderation/approve/:type/:id` — approve.
- `POST /jetonomy/v1/moderation/spam/:type/:id` — mark spam.
- `POST /jetonomy/v1/flags` — create flag. Permission: jetonomy_flag.
- `GET /jetonomy/v1/moderation/flags` — list flags. Moderator only.

**Tags:**
- `GET /jetonomy/v1/tags` — list post tags. Sort: popular/alphabetical.
- `GET /jetonomy/v1/space-tags` — list space discovery tags.

**Updates (polling):**
- `GET /jetonomy/v1/updates?since=timestamp&scope=space|post|global&id=N` — returns IDs of new/updated content since timestamp. Lightweight, cached.

---

## Task 9: Wire API into Plugin Bootstrap

**Files:**
- Modify: `includes/class-jetonomy.php` — add API loading in init()

Add to `load_dependencies()`:
```php
require_once JETONOMY_DIR . 'includes/api/class-api.php';
new API\Api();
```
