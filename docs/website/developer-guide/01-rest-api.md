Jetonomy exposes a full REST API under the `jetonomy/v1` namespace — 48+ endpoints in the free plugin, plus 40+ additional endpoints when Jetonomy Pro is active (90+ total). All endpoints return JSON and integrate with WordPress nonce authentication via the `wp_rest` nonce.

**Base URL:** `https://example.com/wp-json/jetonomy/v1/`

## Authentication

Public endpoints (marked **Public** below) return data without authentication. Write operations and moderation endpoints require a logged-in user and the `X-WP-Nonce` header:

```javascript
const nonce = window.wpApiSettings?.nonce
           ?? jetonomyState?.nonce;  // Injected via wp_interactivity_state()

fetch( '/wp-json/jetonomy/v1/spaces/1/posts', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': nonce,
    },
    body: JSON.stringify({ title: 'My topic', content: '<p>Hello</p>' }),
} );
```

The Interactivity API store exposes `apiBase` and `_nonce` in the `jetonomy` namespace so the bundled frontend needs no extra configuration.

---

## Categories

Manage the top-level taxonomy that groups Spaces.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/categories` | Public | List all categories |
| POST | `/categories` | `manage_options` | Create a category |
| GET | `/categories/{id}` | Public | Get a single category |
| PATCH | `/categories/{id}` | `manage_options` | Update a category |
| DELETE | `/categories/{id}` | `manage_options` | Delete a category |

**GET /categories — example**

```javascript
const res  = await fetch( '/wp-json/jetonomy/v1/categories' );
const data = await res.json();
// data.data → array of category objects
// { id, name, slug, description, position, space_count }
```

---

## Spaces

Spaces are the primary containers for posts (equivalent to forums or boards).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces` | Public | List spaces (paginated) |
| POST | `/spaces` | `manage_options` | Create a space |
| GET | `/spaces/{id}` | Public | Get a single space |
| PATCH | `/spaces/{id}` | Moderator / Admin | Update space settings |
| DELETE | `/spaces/{id}` | `manage_options` | Delete a space |
| GET | `/spaces/{id}/members` | Public / Members only if private | List space members |
| POST | `/spaces/{id}/members` | Logged in | Join a space |
| PATCH | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Change a member's role |
| DELETE | `/spaces/{id}/members/{user_id}` | Moderator / Admin | Remove a member |
| POST | `/spaces/{id}/invite` | Moderator / Admin | Generate an invite link |
| GET | `/invite/{token}` | Public | Resolve an invite token |

**GET /spaces — parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Results per page (max 100) |
| `category_id` | int | — | Filter by category |
| `search` | string | — | Search by title |
| `orderby` | string | `position` | `position`, `title`, `member_count`, `post_count` |

```javascript
const res  = await fetch( '/wp-json/jetonomy/v1/spaces?per_page=10&category_id=3' );
const data = await res.json();
// data.data → array of space objects
// data.meta → { total, pages, page }
```

---

## Posts

Posts are individual discussion threads (topics) inside a Space.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/spaces/{space_id}/posts` | Public | List posts in a space |
| POST | `/spaces/{space_id}/posts` | Logged in | Create a post |
| GET | `/posts/{id}` | Public | Get a single post |
| PATCH | `/posts/{id}` | Author / Moderator | Update a post |
| DELETE | `/posts/{id}` | Author / Moderator | Delete a post |
| POST | `/posts/{id}/close` | Moderator / Admin | Toggle closed status |
| POST | `/posts/{id}/pin` | Moderator / Admin | Toggle pinned status |
| POST | `/posts/{id}/move` | Moderator / Admin | Move to another space |
| POST | `/posts/{id}/merge` | Moderator / Admin | Merge into another post |
| GET | `/posts/drafts` | Logged in | List current user's drafts |
| GET | `/link-preview` | Public | Fetch OG metadata for a URL |

**GET /spaces/{space_id}/posts — parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 20 | Max 100 |
| `sort` | string | `latest` | `latest`, `oldest`, `votes`, `replies` |
| `type` | string | — | Filter by post type (`discussion`, `question`, `idea`) |
| `tag` | string | — | Filter by tag slug |
| `status` | string | `publish` | `publish`, `draft` (author/mod only) |

**POST /spaces/{space_id}/posts — body**

```javascript
await fetch( `/wp-json/jetonomy/v1/spaces/${spaceId}/posts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
    body: JSON.stringify({
        title:   'How do I configure caching?',
        content: '<p>Looking for recommendations...</p>',
        type:    'question',     // discussion | question | idea
        tags:    ['caching', 'performance'],
        status:  'publish',      // or 'draft'
    }),
} );
```

**POST /posts/{id}/move — body**

```javascript
{ target_space_id: 42 }
```

**POST /posts/{id}/merge — body**

```javascript
{ target_post_id: 17 }
```

**GET /link-preview — parameters**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `url` | string | Yes | URL to fetch OG data for |

Response: `{ title, description, image, domain }`

---

## Replies

Replies are threaded responses to a Post.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/posts/{post_id}/replies` | Public | List replies on a post |
| POST | `/posts/{post_id}/replies` | Logged in | Create a reply |
| GET | `/replies/{id}` | Public | Get a single reply |
| PATCH | `/replies/{id}` | Author / Moderator | Update a reply |
| DELETE | `/replies/{id}` | Author / Moderator | Delete a reply |
| POST | `/replies/{id}/accept` | Post author / Moderator | Accept as answer |

**GET /posts/{post_id}/replies — parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | 1 | Page number |
| `per_page` | int | 30 | Max 100 |
| `sort` | string | `oldest` | `oldest`, `newest`, `best` |

**POST /replies/{id}/accept**

Marks this reply as the accepted answer. Only the original post author or a moderator can call this. Fires the `jetonomy_reply_accepted` action hook and awards +15 reputation to the reply author.

```javascript
await fetch( `/wp-json/jetonomy/v1/replies/${replyId}/accept`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': nonce },
} );
```

---

## Votes

Votes record up/down signals on Posts and Replies. Calling the endpoint again with the same direction removes the vote (toggle).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| POST | `/posts/{id}/vote` | Logged in | Cast or toggle a post vote |
| POST | `/replies/{id}/vote` | Logged in | Cast or toggle a reply vote |

**Body for both vote endpoints**

```javascript
{ direction: 'up' }  // or 'down'
```

Response includes `vote_score` (current net score) and `user_vote` (the caller's current vote direction or `null`).

---

## Search

Full-text search across Posts, Replies, Spaces, and Tags. Uses MySQL `FULLTEXT` with Boolean Mode by default. Swap to a custom search adapter (Meilisearch, Algolia, etc.) via the Adapter System — see [05-adapters.md](./05-adapters.md).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/search` | Public | Search across content types |

**Parameters**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | Yes | — | Query string (min 2 chars) |
| `type` | string | — | `post` | `post`, `reply`, `space`, `tag`, `all` |
| `space_id` | int | — | — | Restrict to a specific space |
| `date_from` | string | — | — | ISO date `YYYY-MM-DD` |
| `date_to` | string | — | — | ISO date `YYYY-MM-DD` |
| `author_id` | int | — | — | Filter by author's WP user ID |
| `tag` | string | — | — | Filter by tag slug |
| `sort` | string | — | `relevance` | `relevance`, `newest`, `votes` |

Using `type=all` returns a grouped response with `posts`, `spaces`, and `tags` keys.

```javascript
const params = new URLSearchParams({
    q:        'caching strategies',
    type:     'post',
    space_id: 5,
    sort:     'votes',
} );

const res  = await fetch( `/wp-json/jetonomy/v1/search?${params}` );
const data = await res.json();
// data.data → array of matching post objects
// data.meta → { total, has_more }
```

---

## Tags

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/tags` | Public | List all global tags |
| POST | `/tags` | Logged in (trust level 1+) | Create a tag |
| GET | `/space-tags` | Public | List tags filtered to a space |

**GET /space-tags — parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `space_id` | int | Required. Filter tags by space. |

---

## Notifications

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/notifications` | Logged in | List notifications for current user |
| GET | `/notifications/unread-count` | Logged in | Get unread count (cached 30s) |
| POST | `/notifications/mark-all-read` | Logged in | Mark all notifications read |
| PATCH | `/notifications/{id}` | Logged in | Mark a single notification read |

**GET /notifications — parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | 20 | Max 50 |
| `unread_only` | bool | false | Return only unread notifications |

---

## Subscriptions

Subscriptions track which Spaces or Posts a user follows for new-content notifications.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/subscriptions` | Logged in | List current user's subscriptions |
| DELETE | `/subscriptions/{id}` | Logged in | Remove a subscription |

To create a subscription, use the `POST /spaces/{id}/members` endpoint (joining a space auto-subscribes you) or the follow/unfollow UI action in the frontend which calls this API internally.

---

## Moderation

All moderation endpoints require the `jetonomy_moderate` capability (granted to admins, editors, and users with Moderator role by default).

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/moderation/queue` | Moderator | List items pending review |
| POST | `/moderation/approve/{type}/{id}` | Moderator | Approve a flagged item |
| POST | `/moderation/spam/{type}/{id}` | Moderator | Mark as spam |
| POST | `/moderation/trash/{type}/{id}` | Moderator | Send to trash |
| POST | `/flags` | Logged in | Submit a flag on a post or reply |
| GET | `/moderation/flags` | Moderator | List all open flags |
| POST | `/moderation/flags/{id}/resolve` | Moderator | Resolve a flag |
| POST | `/moderation/ban` | Moderator | Ban a user |
| DELETE | `/moderation/ban/{id}` | Moderator | Remove a ban |

`{type}` in approve/spam/trash routes is either `post` or `reply`.

**POST /flags — body**

```javascript
{
    object_type: 'post',   // or 'reply'
    object_id:   42,
    reason:      'spam',   // spam | off-topic | inappropriate | other
}
```

**POST /moderation/ban — body**

```javascript
{
    user_id:  123,
    reason:   'Repeated spam',
    duration: 7,           // days — omit for permanent ban
}
```

---

## Leaderboards

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/leaderboards` | Public | Get top contributors |

**Parameters**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `week` | `week`, `month`, `all-time` |
| `per_page` | int | 10 | Max 50 |
| `space_id` | int | — | Restrict to a space |

---

## Users

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/users/me` | Logged in | Get the current user's profile |
| GET | `/users/{id}` | Public | Get a user's public profile |
| PATCH | `/users/{id}` | Owner / Admin | Update a user profile |
| GET | `/users/by-login/{login}` | Public | Look up a user by login slug |
| GET | `/users/{id}/posts` | Public | List posts by this user |

**PATCH /users/{id} — updatable fields**

```javascript
{
    bio:         'Forum moderator and PHP developer.',
    website:     'https://example.com',
    location:    'Berlin',
    twitter:     'janedoe',
    github:      'janedoe',
    avatar_url:  'https://example.com/avatar.jpg',
}
```

---

## Updates (Polling)

The Updates endpoint powers the "N new replies" banner in single-post view. It is polled periodically by the Interactivity API store.

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/updates` | Public | Check for new activity since a timestamp |

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `since` | string | ISO 8601 timestamp — returns items created after this |
| `post_id` | int | If provided, returns new reply count for that post |

---

## Pro Endpoints

The following endpoints are available only when **Jetonomy Pro** is active and the relevant extension is enabled.

### Private Messaging (`private-messaging` extension)

| Method | Route | Auth | Description |
|--------|-------|------|-------------|
| GET | `/conversations` | Logged in | List conversations |
| POST | `/conversations` | Trust Level 1+ | Start a new conversation |
| GET | `/conversations/{id}` | Participant | Get conversation details |
| PATCH | `/conversations/{id}` | Participant | Mute/unmute a conversation |
| GET | `/conversations/{id}/messages` | Participant | List messages (paginated) |
| POST | `/conversations/{id}/messages` | Participant + TL 1+ | Send a message |
| GET | `/conversations/unread-count` | Logged in | Unread message count (30s cache) |

**POST /conversations — body**

```javascript
{
    participants: [4, 17],          // WP user IDs
    title:        'Project sync',   // Optional for group conversations
    message:      'Hey, quick question...',
}
```

### Analytics (`analytics` extension)

All analytics endpoints require the `jetonomy_view_analytics` capability.

| Method | Route | Description |
|--------|-------|-------------|
| GET | `/analytics/overview` | Daily series + period comparison |
| GET | `/analytics/top-spaces` | Ranked by period activity |
| GET | `/analytics/top-contributors` | Ranked by posts + replies |
| GET | `/analytics/engagement` | Engagement rate, avg reply time, unanswered % |
| GET | `/analytics/moderation` | Flags, bans, spam actions |
| GET | `/analytics/export` | CSV download |

**Analytics parameters (all endpoints)**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `range` | string | `30d` | `7d`, `30d`, `90d`, `custom` |
| `start` | string | — | ISO date (required when `range=custom`) |
| `end` | string | — | ISO date (required when `range=custom`) |

```javascript
const res  = await fetch(
    '/wp-json/jetonomy/v1/analytics/overview?range=30d',
    { headers: { 'X-WP-Nonce': nonce } }
);
const data = await res.json();
```

---

## Error Responses

All errors follow the standard WP REST API format:

```json
{
  "code":    "rest_forbidden",
  "message": "You are not allowed to do this.",
  "data":    { "status": 403 }
}
```

Common error codes:

| Code | HTTP | Meaning |
|------|------|---------|
| `rest_forbidden` | 403 | Missing capability or nonce |
| `rest_not_found` | 404 | Resource does not exist |
| `validation_error` | 422 | Invalid or missing parameters |
| `rate_limited` | 429 | Too many requests from this user |

---

## What's Next?

- [Hooks Reference](./02-hooks-reference.md) — React to Jetonomy events in your own plugin
- [Template Overrides](./03-template-overrides.md) — Customize the frontend without touching plugin files
- [Adapter System](./05-adapters.md) — Swap the search backend or add a real-time layer
