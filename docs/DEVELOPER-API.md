# Jetonomy Developer API Reference

**Version**: 1.0.0 | **Requires**: WordPress 6.7+, PHP 8.1+

This reference covers every endpoint in the `jetonomy/v1` REST namespace and all 18 Abilities API abilities. It is written for WordPress theme and plugin developers who want to build on top of Jetonomy programmatically or integrate it with external tools and AI agents.

---

## Table of Contents

1. [REST API Quick Reference](#1-rest-api-quick-reference)
   - [Base URL and Conventions](#base-url-and-conventions)
   - [Categories](#categories)
   - [Spaces](#spaces)
   - [Posts](#posts)
   - [Replies](#replies)
   - [Votes](#votes)
   - [Search](#search)
   - [Notifications](#notifications)
   - [Subscriptions](#subscriptions)
   - [Users](#users)
   - [Tags](#tags)
   - [Moderation](#moderation)
   - [Updates (Polling)](#updates-polling)
2. [Abilities API](#2-abilities-api)
   - [Discovery and Execution](#discovery-and-execution)
   - [Annotations](#annotations)
   - [Ability Reference](#ability-reference)
   - [Example Flow](#example-flow)
3. [Authentication](#3-authentication)
4. [Pagination](#4-pagination)
5. [Extending Jetonomy](#5-extending-jetonomy)

---

## 1. REST API Quick Reference

### Base URL and Conventions

All endpoints live under:

```
https://example.com/wp-json/jetonomy/v1/
```

**Common response envelope** for collection endpoints:

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

The `X-WP-Total` header is also set when `total` is known.

**Common error format** (standard WP REST API):

```json
{
  "code": "jetonomy_forbidden",
  "message": "You do not have permission to perform this action.",
  "data": { "status": 403 }
}
```

| Error Code | HTTP Status | Meaning |
|---|---|---|
| `jetonomy_unauthorized` | 401 | Not logged in |
| `jetonomy_forbidden` | 403 | Logged in but lacks capability |
| `jetonomy_not_found` | 404 | Resource does not exist |
| `jetonomy_validation` | 400 | Missing or invalid parameter |

---

### Categories

Categories are the top-level grouping for spaces. They support nesting (parent/child). All category endpoints are public for reads; writes require the `jetonomy_manage_categories` capability (administrators only).

---

#### `GET /categories`

List all top-level categories. The response includes each category's immediate `spaces` and recursively nested `children`.

**Auth required**: No

**Query parameters**: None

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 1,
      "name": "Support",
      "slug": "support",
      "description": "Get help with your account.",
      "parent_id": null,
      "icon": "life-ring",
      "color": "#3b82f6",
      "visibility": "public",
      "sort_order": 0,
      "space_count": 3,
      "created_at": "2026-01-01 00:00:00",
      "spaces": [...],
      "children": [...]
    }
  ],
  "meta": { "count": 4, "has_more": false, "cursor_next": 4, "total": 4 }
}
```

**Notes**: `visibility` is one of `public`, `private`, or `hidden`. `children` is recursive — each child carries its own `children` array. This endpoint does not support pagination; it returns all categories.

---

#### `GET /categories/{id}`

Get a single category with its spaces.

**Auth required**: No

**Response** `200 OK`:

```json
{
  "id": 1,
  "name": "Support",
  "slug": "support",
  "description": "Get help with your account.",
  "parent_id": null,
  "icon": "life-ring",
  "color": "#3b82f6",
  "visibility": "public",
  "sort_order": 0,
  "space_count": 3,
  "created_at": "2026-01-01 00:00:00",
  "spaces": [...]
}
```

---

#### `POST /categories`

Create a new category.

**Auth required**: Yes — `jetonomy_manage_categories` (administrator)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `name` | string | Yes | Category display name |
| `slug` | string | No | Auto-derived from `name` if omitted; uniqueness enforced |
| `description` | string | No | |
| `parent_id` | integer | No | ID of parent category for nesting |
| `icon` | string | No | Icon identifier (e.g. `life-ring`) |
| `color` | string | No | Hex color (`#3b82f6`) or Tailwind class |
| `visibility` | string | No | `public` (default), `private`, or `hidden` |
| `sort_order` | integer | No | Lower numbers sort first |

**Response** `201 Created`: The created category object.

---

#### `PATCH /categories/{id}`

Partially update a category. Send only the fields you want to change.

**Auth required**: Yes — `jetonomy_manage_categories`

**Body**: Any subset of the `POST /categories` fields.

**Response** `200 OK`: The updated category object.

---

#### `DELETE /categories/{id}`

Delete a category permanently.

**Auth required**: Yes — `jetonomy_manage_categories`

**Response** `200 OK`:

```json
{ "deleted": true, "id": 1 }
```

---

### Spaces

Spaces are the core container for community content — equivalent to boards or channels. Each space has a `type` (`forum`, `qa`, `ideas`, `social`), a `visibility` level, and a `join_policy` that controls how users become members.

Space roles (`viewer`, `member`, `moderator`, `admin`) are independent of WordPress roles and are checked by the Permission Engine alongside WP capabilities.

---

#### `GET /spaces`

List all spaces the current user can access. Private and hidden spaces are filtered out unless the user is a member.

**Auth required**: No (optional — logged-in users see spaces they are members of)

**Query parameters**:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `category_id` | integer | — | Filter by parent category |
| `type` | string | — | `forum`, `qa`, `ideas`, or `social` |
| `visibility` | string | — | `public`, `private`, or `hidden` |
| `limit` | integer | 20 | Max 100 |
| `after` | integer | 0 | Cursor-based pagination |
| `sort` | string | `latest` | `latest`, `popular`, `oldest`, `newest` |

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 5,
      "category_id": 1,
      "title": "General Discussion",
      "slug": "general-discussion",
      "description": "Talk about anything.",
      "type": "forum",
      "visibility": "public",
      "join_policy": "open",
      "icon": "chat",
      "settings": {},
      "member_count": 142,
      "post_count": 87,
      "sort_order": 0,
      "author_id": 1,
      "created_at": "2026-01-01 00:00:00",
      "updated_at": "2026-03-01 10:00:00",
      "last_activity_at": "2026-03-20 08:45:00"
    }
  ],
  "meta": { "count": 10, "has_more": false, "cursor_next": 14, "total": 10 }
}
```

---

#### `GET /spaces/{id}`

Get a single space. Returns `403` for private/hidden spaces if the current user is not a member.

**Auth required**: No (optional)

**Response** `200 OK`: A single space object as above.

---

#### `POST /spaces`

Create a new space.

**Auth required**: Yes — `jetonomy_create_spaces` (author or higher)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | Yes | Space display name |
| `slug` | string | No | Auto-derived; uniqueness enforced |
| `description` | string | No | |
| `category_id` | integer | No | |
| `type` | string | No | `forum` (default), `qa`, `ideas`, `social` |
| `visibility` | string | No | `public` (default), `private`, `hidden` |
| `join_policy` | string | No | `open` (default), `approval`, `invite` |
| `icon` | string | No | |
| `settings` | object/string | No | JSON object of space-specific settings |

**Response** `201 Created`: The created space object. The creator is automatically added as `admin`.

---

#### `PATCH /spaces/{id}`

Update space settings. The current user must be a space admin or have `manage_options`.

**Auth required**: Yes — space admin or WordPress administrator

**Body**: Any subset of the `POST /spaces` fields.

**Response** `200 OK`: The updated space object.

---

#### `DELETE /spaces/{id}`

Permanently delete a space and all its content.

**Auth required**: Yes — space admin or WordPress administrator

**Response** `200 OK`:

```json
{ "deleted": true, "id": 5 }
```

---

#### `POST /spaces/{id}/members`

Join a space (or submit a join request if the space uses approval policy).

**Auth required**: Yes — any logged-in user

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `message` | string | No | Message to include with approval request |

**Response**:

- `201 Created` for open spaces:

  ```json
  { "status": "joined", "space_id": 5, "user_id": 12, "role": "member" }
  ```

- `202 Accepted` for approval-policy spaces:

  ```json
  { "status": "pending", "message": "Join request submitted. Awaiting approval." }
  ```

- `403 Forbidden` for invite-only spaces.

---

#### `GET /spaces/{id}/members`

List members of a space. Private/hidden spaces require the current user to already be a member.

**Auth required**: No (optional — private spaces require membership)

**Query parameters**: Standard pagination (`limit`, `after`, `offset`).

**Response** `200 OK`:

```json
{
  "data": [
    {
      "space_id": 5,
      "user_id": 12,
      "role": "member",
      "joined_at": "2026-02-10 09:00:00",
      "display_name": "Jane Smith",
      "avatar_url": "https://example.com/avatars/jane.jpg",
      "trust_level": 2,
      "reputation": 340,
      "profile_url": "https://example.com/community/u/janesmith/"
    }
  ],
  "meta": { "count": 50, "has_more": false, "cursor_next": 61, "total": 50 }
}
```

---

#### `DELETE /spaces/{id}/members/{user_id}`

Leave a space (remove yourself) or remove another user (space admin only).

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "removed": true, "space_id": 5, "user_id": 12 }
```

---

#### `PATCH /spaces/{id}/members/{user_id}`

Change a member's role. Requires space admin.

**Auth required**: Yes — space admin

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `role` | string | Yes | `viewer`, `member`, `moderator`, or `admin` |

**Response** `200 OK`:

```json
{ "updated": true, "space_id": 5, "user_id": 12, "role": "moderator" }
```

---

#### `POST /spaces/{id}/invite`

Generate an invite link for invite-only or approval-policy spaces. Space admin only.

**Auth required**: Yes — space admin

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `max_uses` | integer | No | `0` = unlimited |
| `expires_at` | string | No | ISO 8601 or MySQL datetime |

**Response** `201 Created`:

```json
{
  "token": "abc123xyz",
  "invite_url": "https://example.com/community/invite/abc123xyz/",
  "max_uses": 10,
  "expires_at": "2026-04-01 00:00:00"
}
```

---

#### `GET /invite/{token}`

Validate and redeem an invite link. Adds the current user to the space immediately.

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "status": "joined", "space_id": 5, "space_slug": "general-discussion" }
```

Returns `{ "status": "already_member", ... }` if already joined. Returns `410 Gone` if the link is expired or at its usage limit.

---

### Posts

Posts are the primary content items in a space. The `type` field varies by space type: `topic` for forums, `question` for Q&A, `discussion` for social, or `announcement` for any type. Delete operations are soft deletes (status set to `trash`).

---

#### `GET /spaces/{space_id}/posts`

List posts in a space.

**Auth required**: No (optional — private spaces require membership via the Permission Engine)

**Query parameters**:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `limit` | integer | 20 | Max 100 |
| `after` | integer | 0 | Cursor: return posts after this post ID |
| `offset` | integer | 0 | Legacy offset (use `after` instead) |
| `sort` | string | `latest` | `latest`, `popular`, `oldest`, `newest` |

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 88,
      "space_id": 5,
      "author_id": 12,
      "title": "How do I reset my password?",
      "slug": "how-do-i-reset-my-password",
      "content": "<p>I forgot my password...</p>",
      "content_plain": "I forgot my password...",
      "type": "question",
      "status": "publish",
      "is_sticky": false,
      "is_closed": false,
      "is_resolved": true,
      "accepted_reply_id": 204,
      "view_count": 412,
      "reply_count": 7,
      "vote_score": 14,
      "last_reply_at": "2026-03-19 14:22:00",
      "edited_at": null,
      "edited_by": null,
      "created_at": "2026-03-18 10:00:00",
      "updated_at": "2026-03-19 14:22:00",
      "author_name": "Jane Smith",
      "author_avatar": "https://example.com/avatars/jane.jpg",
      "author_login": "janesmith",
      "trust_level": 2,
      "reputation": 340,
      "time_ago": "2 days ago",
      "profile_url": "https://example.com/community/u/janesmith/",
      "space_title": "General Discussion",
      "space_slug": "general-discussion"
    }
  ],
  "meta": { "count": 20, "has_more": true, "cursor_next": 108, "total": 87 }
}
```

---

#### `POST /spaces/{space_id}/posts`

Create a new post. Runs Akismet spam check and the `jetonomy_check_content` filter. Rate-limited per trust level.

**Auth required**: Yes — `jetonomy_create_posts` (subscriber or higher)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | Yes | |
| `content` | string | Yes | Sanitized as `wp_kses_post` |
| `type` | string | No | `topic`, `question`, `discussion`, `announcement`; auto-derived from space type if omitted |
| `tags` | array | No | Array of tag name strings |

**Response** `201 Created`: The created post object.

**Notes**: The author is automatically subscribed to the post. `@mention` notifications are sent for any `@username` references in `content`.

---

#### `GET /posts/{id}`

Get a single post. Increments `view_count` on each call.

**Auth required**: No (optional)

**Response** `200 OK`: The post object.

---

#### `PATCH /posts/{id}`

Update a post's `title` or `content`. A revision is saved before each edit.

**Auth required**: Yes — post author with `jetonomy_create_posts`, or a moderator with `jetonomy_edit_others_posts`

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | No | |
| `content` | string | No | |

**Response** `200 OK`: The updated post object with `edited_at` and `edited_by` set.

---

#### `DELETE /posts/{id}`

Soft-delete a post (sets `status` to `trash`).

**Auth required**: Yes — post author with `jetonomy_create_posts`, or moderator with `jetonomy_delete_others_posts`

**Response** `200 OK`:

```json
{ "deleted": true, "id": 88 }
```

---

#### `POST /posts/{id}/close`

Close a post to prevent new replies. Toggle: calling again on a closed post re-opens it (if implemented at the model level).

**Auth required**: Yes — `jetonomy_close_posts` (editor or higher)

**Response** `200 OK`: The updated post object with `is_closed: true`.

---

#### `POST /posts/{id}/pin`

Pin (sticky) a post within its space.

**Auth required**: Yes — `jetonomy_pin_posts` (editor or higher)

**Response** `200 OK`: The updated post object with `is_sticky: true`.

---

#### `POST /posts/{id}/move`

Move a post to a different space. Requires `jetonomy_move_posts` permission in both the source and target spaces. Updates post counts on both spaces.

**Auth required**: Yes — `jetonomy_move_posts` (editor or higher, in both spaces)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `target_space_id` | integer | Yes | The destination space ID |

**Response** `200 OK`: The updated post object with the new `space_id`.

---

### Replies

Replies support threading up to 3 levels via `parent_id`. Delete operations are soft deletes (status set to `trash`). In Q&A spaces, one reply per post can be marked as the accepted answer.

---

#### `GET /posts/{post_id}/replies`

List replies for a post.

**Auth required**: No (optional)

**Query parameters**:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `sort` | string | `oldest` | `oldest`, `newest`, `best` |
| `limit` | integer | 20 | Max 100 |
| `after` | integer | 0 | Cursor pagination |
| `offset` | integer | 0 | Legacy offset |

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 204,
      "post_id": 88,
      "parent_id": null,
      "author_id": 7,
      "content": "<p>You can reset it via the login page.</p>",
      "content_plain": "You can reset it via the login page.",
      "status": "publish",
      "is_accepted": true,
      "vote_score": 8,
      "edited_at": null,
      "edited_by": null,
      "created_at": "2026-03-18 11:00:00",
      "author_name": "Bob Admin",
      "author_avatar": "https://example.com/avatars/bob.jpg",
      "author_login": "bobadmin",
      "trust_level": 4,
      "reputation": 1250,
      "time_ago": "2 days ago",
      "profile_url": "https://example.com/community/u/bobadmin/"
    }
  ],
  "meta": { "count": 7, "has_more": false, "cursor_next": 210, "total": 7 }
}
```

---

#### `POST /posts/{post_id}/replies`

Create a reply to a post. Blocked if the post is closed. Runs Akismet check and `jetonomy_check_content` filter. Rate-limited per trust level.

**Auth required**: Yes — `jetonomy_create_replies` (subscriber or higher)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `content` | string | Yes | Sanitized as `wp_kses_post` |
| `parent_id` | integer | No | Parent reply ID for threaded replies |

**Response** `201 Created`: The created reply object.

**Notes**: Fires `jetonomy_after_create_reply` which triggers notifications to post subscribers and mentioned users.

---

#### `PATCH /replies/{id}`

Update reply content. Saves a revision before editing.

**Auth required**: Yes — reply author with `jetonomy_create_replies`, or moderator with `jetonomy_edit_others_posts`

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `content` | string | Yes | |

**Response** `200 OK`: The updated reply object.

---

#### `DELETE /replies/{id}`

Soft-delete a reply.

**Auth required**: Yes — reply author with `jetonomy_create_replies`, or moderator with `jetonomy_delete_others_posts`

**Response** `200 OK`:

```json
{ "deleted": true, "id": 204 }
```

---

#### `POST /replies/{id}/accept`

Mark a reply as the accepted answer for a Q&A post. Only the post author or a moderator may accept a reply. Awards +15 reputation to the reply author and fires `jetonomy_reply_accepted`.

**Auth required**: Yes — post author or space moderator/admin

**Response** `200 OK`: The updated reply object with `is_accepted: true`. The parent post also gets `is_resolved: true` and `accepted_reply_id` set.

---

### Votes

Votes are separate from the content hierarchy. Each route handles both posts and replies. Casting the same vote direction again toggles it off. Changing direction (upvote to downvote) updates the vote in place and adjusts reputation accordingly.

---

#### `POST /posts/{id}/vote`

Upvote or downvote a post.

**Auth required**: Yes — `jetonomy_vote` (subscriber or higher), rate-limited

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `value` | integer | Yes | `1` (upvote) or `-1` (downvote) |

**Response** `200 OK`:

```json
{
  "action": "created",
  "old_value": null,
  "score": 15
}
```

`action` is one of `created`, `updated`, `removed`, or `none`.

---

#### `DELETE /posts/{id}/vote`

Remove your existing vote on a post (equivalent to toggling off).

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "action": "removed", "score": 14 }
```

---

#### `POST /replies/{id}/vote`

Upvote or downvote a reply. Same interface as post voting.

**Auth required**: Yes — `jetonomy_vote`, rate-limited

**Body**: `{ "value": 1 }` or `{ "value": -1 }`

**Response** `200 OK`: Vote action result with updated `score`.

---

#### `DELETE /replies/{id}/vote`

Remove your existing vote on a reply.

**Auth required**: Yes

**Response** `200 OK`: Vote action result.

---

### Search

Full-text search uses MySQL `MATCH … AGAINST` in Boolean Mode on `title` and `content_plain` columns (which store plain-text copies of HTML content). Space name/description search uses `LIKE`. Results are capped at 20 per type.

---

#### `GET /search`

Search across posts, replies, spaces, and tags.

**Auth required**: No

**Query parameters**:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `q` | string | — | **Required.** Min 2 characters |
| `type` | string | `post` | `post`, `reply`, `space`, `tag`, or `all` |
| `space_id` | integer | — | Scope `post` or `reply` searches to a specific space |

**Response for `type=all`** `200 OK`:

```json
{
  "data": {
    "posts": [
      { "id": 88, "title": "How do I reset my password?", "type": "post", ... }
    ],
    "spaces": [
      { "id": 5, "title": "General Discussion", "type": "space", ... }
    ],
    "tags": [
      { "id": 3, "name": "account", "post_count": 12 }
    ]
  },
  "meta": { "total": 15 }
}
```

**Response for single `type`** `200 OK`: Standard paginated response with `data` array.

---

### Notifications

Notifications are created automatically by the `Notifier` class in response to events (new replies, votes, mentions, accepted answers, badges). The API provides read/mark-read operations.

---

#### `GET /notifications`

List notifications for the authenticated user.

**Auth required**: Yes

**Query parameters**: Standard pagination (`limit`, `offset`).

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 301,
      "user_id": 12,
      "type": "reply_created",
      "object_type": "reply",
      "object_id": 204,
      "actor_id": 7,
      "is_read": false,
      "created_at": "2026-03-18 11:00:00",
      "message": "Bob Admin replied to your post.",
      "actor_name": "Bob Admin",
      "actor_avatar": "https://example.com/avatars/bob.jpg",
      "actor_login": "bobadmin",
      "time_ago": "2 days ago",
      "profile_url": "https://example.com/community/u/bobadmin/"
    }
  ],
  "meta": { "count": 5, "has_more": false, "cursor_next": 305 }
}
```

---

#### `GET /notifications/unread-count`

Get the count of unread notifications for the authenticated user. Sets `Cache-Control: max-age=15`.

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "count": 3 }
```

---

#### `POST /notifications/mark-all-read`

Mark all notifications as read for the current user.

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "success": true }
```

---

#### `PATCH /notifications/{id}`

Mark a single notification as read. Returns `403` if the notification belongs to a different user.

**Auth required**: Yes

**Response** `200 OK`: The updated notification object with `is_read: true`.

---

### Subscriptions

Users can subscribe to posts or spaces to receive notifications about new activity. The `object_type` is either `post` or `space`.

---

#### `GET /subscriptions`

List subscriptions for the authenticated user.

**Auth required**: Yes

**Query parameters**: Standard pagination.

**Response** `200 OK`: Paginated list of subscription objects.

```json
{
  "data": [
    {
      "id": 45,
      "user_id": 12,
      "object_type": "post",
      "object_id": 88,
      "created_at": "2026-03-18 10:00:00"
    }
  ],
  "meta": { "count": 3, "has_more": false, "cursor_next": 47, "total": 3 }
}
```

---

#### `POST /subscriptions`

Subscribe to a post or space.

**Auth required**: Yes

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `object_type` | string | Yes | `post` or `space` |
| `object_id` | integer | Yes | ID of the post or space |

**Response** `201 Created`: The created subscription object.

---

#### `DELETE /subscriptions/{id}`

Unsubscribe. Users can only delete their own subscriptions.

**Auth required**: Yes

**Response** `200 OK`:

```json
{ "deleted": true, "id": 45 }
```

---

### Users

The Users API exposes community profiles. `GET /users/me` returns the full authenticated profile including private fields (`email`, `settings`). All other user endpoints are public.

---

#### `GET /users/me`

Get the authenticated user's full profile.

**Auth required**: Yes

**Response** `200 OK`:

```json
{
  "id": 12,
  "user_id": 12,
  "email": "jane@example.com",
  "display_name": "Jane Smith",
  "reputation": 340,
  "post_count": 22,
  "reply_count": 87,
  "trust_level": 2,
  "trust_level_name": "Member",
  "bio": "I help people.",
  "avatar_url": "https://example.com/avatars/jane.jpg",
  "last_seen_at": "2026-03-20 09:00:00",
  "created_at": "2025-12-01 00:00:00",
  "updated_at": "2026-03-20 09:00:00",
  "spaces_joined_count": 4,
  "settings": { "email_notifications": true }
}
```

---

#### `PATCH /users/me`

Update the authenticated user's profile.

**Auth required**: Yes

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `display_name` | string | No | Updates WP `display_name` via `wp_update_user` |
| `bio` | string | No | |
| `avatar_url` | string | No | URI format |
| `settings` | object | No | Arbitrary JSON settings object |

**Response** `200 OK`: The updated full profile object.

---

#### `GET /users/{id}`

Get a public profile by WordPress user ID. Does not expose `email` or `settings`.

**Auth required**: No

**Response** `200 OK`:

```json
{
  "id": 12,
  "display_name": "Jane Smith",
  "trust_level": 2,
  "trust_level_name": "Member",
  "reputation": 340,
  "post_count": 22,
  "reply_count": 87,
  "bio": "I help people.",
  "avatar_url": "https://example.com/avatars/jane.jpg",
  "created_at": "2025-12-01 00:00:00",
  "last_seen_at": "2026-03-20 09:00:00"
}
```

---

#### `GET /users/by-login/{login}`

Get a public profile by WordPress `user_login` (username).

**Auth required**: No

**Response** `200 OK`: Same shape as `GET /users/{id}`.

---

#### `GET /users/{id}/posts`

Paginated list of published posts by a user.

**Auth required**: No

**Query parameters**: Standard pagination (`limit`, `offset`).

**Response** `200 OK`:

```json
{
  "data": [
    {
      "id": 88,
      "space_id": 5,
      "title": "How do I reset my password?",
      "slug": "how-do-i-reset-my-password",
      "type": "question",
      "status": "publish",
      "vote_score": 14,
      "reply_count": 7,
      "view_count": 412,
      "created_at": "2026-03-18 10:00:00"
    }
  ],
  "meta": { "count": 22, "has_more": false, "cursor_next": 109, "total": 22 }
}
```

---

### Tags

Tags are attached to posts. Space tags are a separate taxonomy used to categorize spaces themselves (stored in `jt_space_tags`).

---

#### `GET /tags`

List post tags.

**Auth required**: No

**Query parameters**:

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `limit` | integer | 30 | Max 100 |
| `sort` | string | `popular` | `popular` (by `post_count` desc) or `alphabetical` |

**Response** `200 OK`:

```json
{
  "data": [
    { "id": 3, "name": "account", "slug": "account", "post_count": 12 }
  ],
  "meta": { "count": 30, "has_more": true, "cursor_next": 33, "total": 30 }
}
```

---

#### `GET /space-tags`

List space tags (the taxonomy used to tag spaces, not posts).

**Auth required**: No

**Query parameters**: Same as `GET /tags` (`limit`, `sort`).

**Response** `200 OK`: Paginated list of space tag objects with `space_count` instead of `post_count`.

---

### Moderation

All moderation endpoints except `POST /flags` require the `jetonomy_moderate` capability (editor or higher). `POST /flags` requires `jetonomy_flag` (subscriber or higher).

---

#### `GET /moderation/queue`

Fetch all pending posts and replies awaiting moderator review, plus a count of open flags.

**Auth required**: Yes — `jetonomy_moderate`

**Response** `200 OK`:

```json
{
  "data": [
    { "id": 90, "object_type": "post", "status": "pending", "title": "...", ... }
  ],
  "pending_flags_count": 4,
  "meta": { "count": 2, "has_more": false }
}
```

---

#### `POST /moderation/approve/{type}/{id}`

Approve a pending post or reply (sets `status` to `publish`). Fires `jetonomy_content_moderated`.

**Auth required**: Yes — `jetonomy_moderate`

**URL parameters**: `type` is `post` or `reply`; `id` is the row ID.

**Response** `200 OK`:

```json
{ "approved": true, "object_type": "post", "id": 90 }
```

---

#### `POST /moderation/spam/{type}/{id}`

Mark a post or reply as spam. Sets `status` to `spam` and applies a -20 reputation penalty to the author.

**Auth required**: Yes — `jetonomy_moderate`

**Response** `200 OK`:

```json
{ "marked_spam": true, "object_type": "post", "id": 90 }
```

---

#### `POST /moderation/trash/{type}/{id}`

Soft-delete (trash) a post or reply as a moderation action.

**Auth required**: Yes — `jetonomy_moderate`

**Response** `200 OK`:

```json
{ "trashed": true, "object_type": "reply", "id": 204 }
```

---

#### `POST /flags`

Create a flag report on a post, reply, or user.

**Auth required**: Yes — `jetonomy_flag` (subscriber or higher)

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `object_type` | string | Yes | `post`, `reply`, or `user` |
| `object_id` | integer | Yes | |
| `reason` | string | Yes | `spam`, `offensive`, `off_topic`, `harassment`, or `other` |
| `description` | string | No | Additional context |

**Response** `201 Created`:

```json
{ "created": true, "id": 78 }
```

---

#### `GET /moderation/flags`

List all pending flags awaiting review.

**Auth required**: Yes — `jetonomy_moderate`

**Response** `200 OK`: Paginated list of flag objects.

---

#### `POST /moderation/flags/{id}/resolve`

Resolve a flag as `valid` (content was a real problem) or `dismissed` (no action needed).

**Auth required**: Yes — `jetonomy_moderate`

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `status` | string | Yes | `valid` or `dismissed` |

**Response** `200 OK`:

```json
{ "resolved": true, "id": 78, "status": "valid" }
```

---

#### `POST /moderation/ban`

Issue a ban, space ban, or silence restriction on a user.

**Auth required**: Yes — `jetonomy_moderate`

**Body**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `user_id` | integer | Yes | WordPress user ID to restrict |
| `type` | string | Yes | `global_ban`, `space_ban`, or `silence` |
| `reason` | string | No | |
| `space_id` | integer | No | Required for `space_ban` |
| `expires_at` | string | No | ISO 8601 or MySQL datetime; omit for permanent |

**Response** `201 Created`:

```json
{
  "banned": true,
  "restriction_id": 5,
  "user_id": 99,
  "type": "global_ban"
}
```

---

#### `DELETE /moderation/ban/{id}`

Lift a restriction by its restriction record ID.

**Auth required**: Yes — `jetonomy_moderate`

**Response** `200 OK`:

```json
{ "removed": true, "id": 5 }
```

---

### Updates (Polling)

The polling endpoint is a lightweight alternative to WebSockets. The frontend JS (WP Interactivity API store) calls it on a timer to check for new activity since a given timestamp.

---

#### `GET /updates`

Fetch activity since a timestamp. Results come from `jt_activity_log`.

**Auth required**: Yes

**Query parameters**:

| Parameter | Type | Required | Notes |
|---|---|---|---|
| `since` | string | Yes | ISO 8601 or MySQL datetime (`2026-03-20T09:00:00Z`) |
| `scope` | string | No | `global` (default), `space`, or `post` |
| `id` | integer | No | Required when `scope` is `space` or `post` |

**Response** `200 OK` (`scope=post`):

```json
{
  "data": [204, 205, 206],
  "since": "2026-03-20 09:00:00",
  "scope": "post",
  "meta": { "count": 3, "has_more": false }
}
```

For `scope=global` or `scope=space`, `data` is an array of activity log entries:

```json
{
  "data": [
    {
      "action": "post_created",
      "object_type": "post",
      "object_id": 91,
      "created_at": "2026-03-20 09:05:00"
    }
  ]
}
```

Sets `Cache-Control: no-cache` — do not cache this endpoint.

---

## 2. Abilities API

The Abilities API is a WordPress 6.9+ feature that lets 3rd-party tools (AI agents, automation platforms, CLI tools) discover what a WordPress site can do and execute actions against it with proper permission checking.

Jetonomy registers 18 abilities across 5 categories. All 18 are exposed as `show_in_rest: true`.

### Discovery and Execution

```
Discovery:  GET  /wp-json/wp-abilities/v1/abilities
Execution:  POST /wp-json/wp-abilities/v1/run/{ability-name}
```

**Discovery example**:

```bash
curl https://example.com/wp-json/wp-abilities/v1/abilities
```

Returns all registered abilities with their `input_schema`, `output_schema`, `annotations`, and `permission_callback` metadata. An AI agent uses this to understand what actions it can take and what inputs each requires.

**Execution example**:

```bash
curl -X POST https://example.com/wp-json/wp-abilities/v1/run/jetonomy%2Fcreate-post \
  -H "Content-Type: application/json" \
  -H "Authorization: Basic <base64-encoded-credentials>" \
  -d '{
    "space_id": 5,
    "title": "My question about shipping",
    "content": "<p>How long does shipping take?</p>",
    "type": "question",
    "tags": ["shipping", "orders"]
  }'
```

The ability name in the URL uses `%2F` to encode the `/` in `jetonomy/create-post`.

---

### Annotations

Every ability carries three boolean annotations in `meta.annotations`:

| Annotation | Meaning |
|---|---|
| `readonly: true` | This ability only reads data; it has no side effects |
| `destructive: true` | This ability can permanently change or remove data |
| `idempotent: true` | Calling this ability multiple times with the same input produces the same result |

An AI agent should use these to reason about risk before executing an ability. For example, it should require explicit user confirmation before running any ability with `destructive: true`.

---

### Ability Reference

#### Forum Content (`jetonomy-content`)

---

##### `jetonomy/create-post`

Create a new topic, question, or discussion post in a space.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: false`

**Auth**: Must be logged in with `jetonomy_create_posts` in the target space.

**Input schema**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `space_id` | integer | Yes | Target space ID |
| `title` | string | Yes | |
| `content` | string | Yes | HTML; sanitized via `wp_kses_post` |
| `type` | string | No | `topic`, `question`, `discussion`, `announcement` |
| `tags` | array of strings | No | Tag names to attach |

**Output**:

```json
{ "id": 88, "title": "My question", "url": "https://example.com/community/s/support/t/my-question/" }
```

---

##### `jetonomy/get-post`

Retrieve a single forum post by ID.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public for public spaces; space membership required for private/hidden spaces.

**Input**: `{ "post_id": 88 }`

**Output**: Post object including `title`, `content`, `author_name`, `vote_score`, `reply_count`, `status`, `created_at`.

---

##### `jetonomy/list-posts`

List posts in a space with cursor-based pagination.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public for public spaces.

**Input**:

| Field | Type | Default | Notes |
|---|---|---|---|
| `space_id` | integer | — | Required |
| `limit` | integer | 20 | Max 50 |
| `after` | integer | 0 | Cursor |
| `sort` | string | `latest` | `latest`, `top`, `active` |

**Output**: `{ "posts": [...], "has_more": true }`

---

##### `jetonomy/create-reply`

Reply to an existing post. Supports threading via `parent_id`. Blocked if the post is closed.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: false`

**Auth**: Must be logged in with `jetonomy_create_replies` in the post's space.

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `post_id` | integer | Yes | |
| `content` | string | Yes | HTML |
| `parent_id` | integer | No | For threaded replies |

**Output**: `{ "id": 204, "post_id": 88 }`

---

##### `jetonomy/list-replies`

List replies for a post with pagination.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public for public spaces.

**Input**: `post_id` (required), `limit`, `after`, `sort` (`oldest`, `newest`, `best`).

**Output**: `{ "replies": [...], "has_more": false }`

---

##### `jetonomy/vote`

Upvote or downvote a post or reply. Toggling the same direction again removes the vote.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: true`

**Auth**: Must be logged in with `jetonomy_vote`.

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `object_type` | string | Yes | `post` or `reply` |
| `object_id` | integer | Yes | |
| `value` | integer | Yes | `1` or `-1` |

**Output**: `{ "vote_score": 15, "user_vote": 1 }`

---

#### Community Spaces (`jetonomy-spaces`)

---

##### `jetonomy/list-spaces`

List all accessible community spaces, optionally filtered by category.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public (no login required).

**Input**: `{ "category_id": 1 }` (optional)

**Output**: Array of space objects with `id`, `title`, `slug`, `type`, `post_count`, `member_count`.

---

##### `jetonomy/get-space`

Get detailed information about a specific space.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public for public spaces; membership required for private/hidden.

**Input**: `{ "space_id": 5 }`

**Output**: Full space object including `description`, `type`, `visibility`, `post_count`, `member_count`.

---

##### `jetonomy/join-space`

Join a community space. For private spaces with approval policy, this submits a join request.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: true`

**Auth**: Must be logged in.

**Input**: `{ "space_id": 5 }`

**Output**: `{ "status": "joined" }` or `{ "status": "pending_approval" }`

---

##### `jetonomy/list-space-members`

List members of a space with roles, trust levels, and reputation.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public for public spaces.

**Input**: `{ "space_id": 5 }`

**Output**: Array of member objects with `user_id`, `display_name`, `role`, `trust_level`, `reputation`.

---

##### `jetonomy/create-space`

Create a new community space. Requires administrator or `jetonomy_manage_settings` capability.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: false`

**Auth**: Must be logged in with `jetonomy_manage_settings`.

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `title` | string | Yes | |
| `description` | string | No | |
| `type` | string | No | `forum` (default), `qa`, `ideas`, `social` |
| `visibility` | string | No | `public` (default), `private`, `hidden` |
| `category_id` | integer | No | |

**Output**: `{ "id": 6, "title": "New Space", "slug": "new-space" }`

---

#### Community Users (`jetonomy-users`)

---

##### `jetonomy/get-user-profile`

Retrieve a community member's public profile.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public (no login required).

**Input**: `{ "user_id": 12 }`

**Output**: `user_id`, `display_name`, `bio`, `trust_level`, `reputation`, `post_count`, `reply_count`, `joined_at`.

---

##### `jetonomy/list-notifications`

List the current user's notifications with read/unread status.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Must be logged in.

**Input**: `{ "unread_only": false, "limit": 20 }`

**Output**: Array of notification objects with `id`, `type`, `message`, `is_read`, `created_at`.

---

##### `jetonomy/mark-notifications-read`

Mark one or all notifications as read.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: true`

**Auth**: Must be logged in.

**Input**: `{ "notification_id": 301 }` (omit to mark all as read)

**Output**: `{ "marked": 1 }`

---

##### `jetonomy/get-activity`

Retrieve the community activity feed.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public.

**Input**: `{ "limit": 20 }`

**Output**: Array of activity entries with `user_id`, `action`, `object_type`, `object_id`, `created_at`.

---

#### Content Moderation (`jetonomy-moderation`)

---

##### `jetonomy/flag-content`

Flag a post or reply for moderator review.

**Annotations**: `readonly: false`, `destructive: false`, `idempotent: false`

**Auth**: Must be logged in.

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `object_type` | string | Yes | `post` or `reply` |
| `object_id` | integer | Yes | |
| `reason` | string | Yes | `spam`, `inappropriate`, `off-topic`, `other` |
| `details` | string | No | |

**Output**: `{ "flag_id": 78 }`

---

##### `jetonomy/moderate-content`

Take a moderation action on flagged content. Requires `jetonomy_moderate`.

**Annotations**: `readonly: false`, `destructive: true`, `idempotent: true`

**Auth**: Must be logged in with `jetonomy_moderate` (editor or higher).

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `object_type` | string | Yes | `post` or `reply` |
| `object_id` | integer | Yes | |
| `action` | string | Yes | `approve`, `trash`, or `spam` |

**Output**: `{ "success": true, "new_status": "publish" }`

---

##### `jetonomy/list-flags`

List content flags awaiting moderator review. Requires `jetonomy_moderate`.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Must be logged in with `jetonomy_moderate`.

**Input**: `{ "status": "pending", "limit": 20 }`

**Output**: Array of flag objects.

---

#### Community Search (`jetonomy-search`)

---

##### `jetonomy/search`

Full-text search across posts, spaces, and tags.

**Annotations**: `readonly: true`, `destructive: false`, `idempotent: true`

**Auth**: Public.

**Input**:

| Field | Type | Required | Notes |
|---|---|---|---|
| `query` | string | Yes | Min 2 characters |
| `filter` | string | No | `all` (default), `posts`, `spaces`, `tags` |
| `limit` | integer | No | Max 50 |

**Output**: `{ "posts": [...], "spaces": [...], "tags": [...], "total": 15 }`

---

### Example Flow

This example shows how an AI agent might use the Abilities API to post a question on behalf of a user.

**Step 1 — Discover available abilities**:

```bash
GET /wp-json/wp-abilities/v1/abilities
```

Parse the response to find `jetonomy/list-spaces` and `jetonomy/create-post`.

**Step 2 — Check permissions** (the discovery response includes `permission_callback` metadata; the agent evaluates against the current auth context):

The agent confirms that `jetonomy/create-post` does not have `readonly: true` and does not have `destructive: true`, so no special user confirmation is needed for this action.

**Step 3 — Find the right space**:

```bash
POST /wp-json/wp-abilities/v1/run/jetonomy%2Flist-spaces
Content-Type: application/json

{}
```

```json
[
  { "id": 5, "title": "General Discussion", "type": "forum" },
  { "id": 6, "title": "Q&A", "type": "qa" }
]
```

**Step 4 — Execute the action**:

```bash
POST /wp-json/wp-abilities/v1/run/jetonomy%2Fcreate-post
Content-Type: application/json
Authorization: Basic <base64-credentials>

{
  "space_id": 6,
  "title": "How do I cancel my subscription?",
  "content": "<p>I need to cancel before the next billing date.</p>",
  "type": "question",
  "tags": ["billing", "subscription"]
}
```

```json
{
  "id": 91,
  "title": "How do I cancel my subscription?",
  "url": "https://example.com/community/s/q-a/t/how-do-i-cancel-my-subscription/"
}
```

---

## 3. Authentication

### REST API

Jetonomy uses standard WordPress REST API authentication. Choose the method that fits your use case:

**Cookie authentication** (browser-based SPA / Interactivity API):

WordPress sets the auth cookie on login. All REST requests from the same browser session include it automatically. Mutation endpoints require a nonce:

```javascript
// Pass the nonce from wp_localize_script or wp_add_inline_script
fetch('/wp-json/jetonomy/v1/spaces/5/posts', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-WP-Nonce': jetonomy.nonce,
  },
  body: JSON.stringify({ title: 'Hello', content: '<p>World</p>' }),
});
```

Generate the nonce in PHP:

```php
wp_localize_script( 'my-script', 'jetonomy', [
    'nonce'   => wp_create_nonce( 'wp_rest' ),
    'rest_url' => rest_url( 'jetonomy/v1/' ),
] );
```

**Application Passwords** (server-to-server, CI, AI agents):

Generate an Application Password from your WordPress profile page (Users → Profile → Application Passwords). Use HTTP Basic Auth:

```bash
curl -X POST https://example.com/wp-json/jetonomy/v1/spaces/5/posts \
  -u "username:xxxx xxxx xxxx xxxx xxxx xxxx" \
  -H "Content-Type: application/json" \
  -d '{"title":"Hello","content":"<p>World</p>"}'
```

### Abilities API

The Abilities API uses the same WordPress authentication as the REST API. Pass either the `X-WP-Nonce` header (browser) or HTTP Basic Auth with Application Passwords (server).

### Permission Model

Jetonomy uses a 3-layer permission system evaluated in sequence:

**Layer 1 — WordPress Capabilities** (`jetonomy_*` caps):

Caps are assigned to WP roles at activation. The mapping is cumulative (each role inherits all caps from roles below it):

| WP Role | Jetonomy Capabilities |
|---|---|
| Subscriber | `jetonomy_read`, `jetonomy_create_posts`, `jetonomy_create_replies`, `jetonomy_edit_own_posts`, `jetonomy_delete_own_posts`, `jetonomy_vote`, `jetonomy_flag`, `jetonomy_join_spaces` |
| Contributor | + `jetonomy_upload_media` |
| Author | + `jetonomy_create_spaces` |
| Editor | + `jetonomy_edit_others_posts`, `jetonomy_delete_others_posts`, `jetonomy_moderate`, `jetonomy_manage_users`, `jetonomy_move_posts`, `jetonomy_close_posts`, `jetonomy_pin_posts` |
| Administrator | + `jetonomy_manage_settings`, `jetonomy_manage_categories`, `jetonomy_manage_badges`, `jetonomy_view_analytics`, `jetonomy_manage_extensions` |

**Layer 2 — Space Roles**:

Every space member has one of four roles: `viewer`, `member`, `moderator`, `admin`. Space roles are checked by `Permission_Engine::can($user_id, $action, $space_id)` and can grant additional privileges within that specific space (for example, a `moderator` role within a space grants `jetonomy_moderate` for that space even if the user is only a Subscriber globally).

**Layer 3 — Trust Levels**:

Trust levels (0–5) are calculated by the `Trust_Evaluator` based on reputation, post count, account age, and other signals. Higher trust levels raise rate limits and unlock features (such as voting with `trust_level >= 1`). Trust levels are stored on `jt_user_profiles` and recalculated automatically via a cron job.

---

## 4. Pagination

Jetonomy supports cursor-based pagination on all collection endpoints, with legacy offset pagination as a fallback.

### Cursor-based (recommended)

Use `after` to page forward through results. The `cursor_next` value in the response `meta` is the ID of the last item returned — pass it as `after` in your next request.

```bash
# First page
GET /wp-json/jetonomy/v1/spaces/5/posts?limit=20

# Next page — use cursor_next from the previous response meta
GET /wp-json/jetonomy/v1/spaces/5/posts?limit=20&after=108
```

When `has_more` is `false`, you have reached the last page. When `has_more` is `true`, there are more results available.

**Response meta shape**:

```json
{
  "meta": {
    "count": 20,
    "has_more": true,
    "cursor_next": 128,
    "total": 87
  }
}
```

### Available pagination parameters

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `limit` | integer | 20 | Min 1, max 100 |
| `after` | integer | 0 | Return items after this ID |
| `before` | integer | 0 | Return items before this ID |
| `offset` | integer | 0 | Legacy offset — use `after`/`before` instead |
| `sort` | string | `latest` | `latest`, `popular`, `oldest`, `newest` |

### Why cursor over offset?

Offset pagination breaks when items are added or removed between page requests — you may skip items or see duplicates. Cursor pagination anchors to a specific record ID, so pages stay stable as new content arrives.

---

## 5. Extending Jetonomy

### Template Overrides

Every Jetonomy PHP template can be overridden in your theme without touching plugin files. Copy the template file from the plugin into your theme under a `jetonomy/` subdirectory, preserving the relative path.

**Plugin template path**: `wp-content/plugins/jetonomy/templates/views/space.php`

**Theme override path**: `wp-content/themes/your-theme/jetonomy/views/space.php`

The `Template_Loader` class checks the theme directory first using the `jetonomy_template_map` filter.

You can also add entirely new template paths via the filter:

```php
add_filter( 'jetonomy_template_map', function ( array $map ): array {
    $map['my-custom-view'] = get_stylesheet_directory() . '/jetonomy/views/my-custom-view.php';
    return $map;
} );
```

Available views: `home`, `category`, `space`, `single-post`, `search`, `tag`, `user-profile`, `edit-profile`, `space-members`, `space-roadmap`, `leaderboard`, `notifications`, `moderation`.

Available partials: `header`, `sidebar`, `breadcrumb`, `avatar`, `pagination`, `post-card`, `reply-card`.

### Adding Custom Abilities

Use the `wp_abilities_api_init` action to register your own abilities. Follow the same pattern Jetonomy uses in `class-abilities.php`:

```php
add_action( 'wp_abilities_api_init', function () {
    wp_register_ability( 'my-plugin/do-something', [
        'label'       => __( 'Do Something', 'my-plugin' ),
        'description' => __( 'Explain what this ability does.', 'my-plugin' ),
        'category'    => 'my-plugin-category',
        'input_schema' => [
            'type'       => 'object',
            'required'   => true,
            'properties' => [
                'post_id' => [ 'type' => 'integer', 'required' => true ],
            ],
        ],
        'output_schema' => [
            'type'       => 'object',
            'properties' => [
                'success' => [ 'type' => 'boolean' ],
            ],
        ],
        'execute_callback' => function ( $input ) {
            // Your logic here.
            return [ 'success' => true ];
        },
        'permission_callback' => function () {
            return current_user_can( 'edit_posts' );
        },
        'meta' => [
            'annotations'  => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
            'show_in_rest' => true,
        ],
    ] );
} );
```

Register your ability categories first on `wp_abilities_api_categories_init`:

```php
add_action( 'wp_abilities_api_categories_init', function () {
    wp_register_ability_category( 'my-plugin-category', [
        'label'       => __( 'My Plugin', 'my-plugin' ),
        'description' => __( 'Actions provided by My Plugin.', 'my-plugin' ),
    ] );
} );
```

### Hooking Into Jetonomy Events

Jetonomy fires actions at key points you can hook into:

| Action | When it fires | Parameters |
|---|---|---|
| `jetonomy_after_create_post` | After a post is created | `$post_id`, `$space_id` |
| `jetonomy_after_create_reply` | After a reply is created | `$reply_id`, `$post_id` |
| `jetonomy_reply_accepted` | After a reply is accepted as an answer | `$reply_id`, `$post_id` |
| `jetonomy_after_vote` | After any vote is cast | `$type`, `$id`, `$user_id` |
| `jetonomy_content_moderated` | After a moderation action | `$action`, `$type`, `$id`, `$moderator_id` |
| `jetonomy_reputation_changed` | After a user's reputation changes | `$user_id`, `$delta`, `$reason` |

Use the `jetonomy_check_content` filter to integrate a custom content policy:

```php
add_filter( 'jetonomy_check_content', function ( $action, $data, $space_id, $user_id ) {
    if ( str_contains( $data['content'] ?? '', 'forbidden-word' ) ) {
        return 'block'; // Prevent submission entirely.
    }
    return $action; // null means no action.
}, 10, 4 );
```

Valid return values for `jetonomy_check_content`: `null` (no action), `'flag'`, `'hold'` (sets `status` to `pending`), `'block'` (returns a 400 error), `'spam'`.
